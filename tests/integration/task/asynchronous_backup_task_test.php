<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_sharing_cart\integration\task;

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\task\asynchronous_backup_task;

/**
 * Async backup tests for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_sharing_cart\task\asynchronous_backup_task
 */
final class asynchronous_backup_task_test extends \advanced_testcase
{
    /** @var factory $factory */
    private factory $factory;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->factory = factory::make();
    }

    /**
     * create_section
     *
     * @param int $courseid
     * @param array $record
     * @return object
     */
    private function create_section(int $courseid, array $record = []): object {
        $db = $this->factory->moodle()->db();

        $record['course'] = $courseid;

        if (!isset($record['section'])) {
            $lastsectionnumber = (int)$db->get_field(
                'course_sections',
                'MAX(section)',
                ['course' => $courseid]
            );
            $record['section'] = $lastsectionnumber + 1;
        }

        $section = self::getDataGenerator()->create_course_section($record);
        return $db->get_record(
            'course_sections',
            ['id' => $section->id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * create_task
     *
     * @param asynchronous_backup_task $task
     * @return asynchronous_backup_task
     */
    private function create_task(asynchronous_backup_task $task): asynchronous_backup_task {
        return new class ($task) extends asynchronous_backup_task {
            /** @var asynchronous_backup_task $task */
            private asynchronous_backup_task $task;

            /**
             * __construct
             *
             * @param asynchronous_backup_task $task
             */
            public function __construct(asynchronous_backup_task $task) {
                $this->task = $task;
                $this->task->output = false;
            }

            /**
             * factory
             *
             * @return factory
             */
            protected function factory(): factory {
                return $this->task->factory();
            }

            /**
             * db
             *
             * @return \moodle_database
             */
            protected function db(): \moodle_database {
                return $this->task->db();
            }

            /**
             * get_backup_id
             *
             * @return string
             */
            protected function get_backup_id(): string {
                return $this->task->get_backup_id();
            }

            /**
             * get_backup_controller
             *
             * @return ?\backup_controller
             */
            public function get_backup_controller(): ?\backup_controller {
                return $this->task->get_backup_controller();
            }

            /**
             * execute
             *
             * @return void
             */
            public function execute(): void {
                $this->task->execute();
            }

            /**
             * retry_until_success
             *
             * @return bool
             */
            public function retry_until_success(): bool {
                return $this->task->retry_until_success();
            }

            /**
             * before_backup_started_hook
             *
             * @param \backup_controller $backupcontroller
             * @return void
             */
            protected function before_backup_started_hook(\backup_controller $backupcontroller): void {
                $this->task->before_backup_started_hook($backupcontroller);
            }

            /**
             * after_backup_finished_hook
             *
             * @param \backup_controller $backupcontroller
             * @return void
             */
            protected function after_backup_finished_hook(\backup_controller $backupcontroller): void {
                $this->task->after_backup_finished_hook($backupcontroller);
            }

            /**
             * Call a method on the task under test.
             *
             * @param string $method
             * @param mixed ...$args
             * @return mixed
             */
            public function call(string $method, mixed ...$args): mixed {
                return $this->task->{$method}(...$args);
            }
        };
    }

    /**
     * test_backup_section_with_quiz_expected_questionbank_setting_value_returns_true
     *
     * @return void
     */
    public function test_backup_section_with_quiz_expected_questionbank_setting_value_returns_true(): void {
        global $USER;
        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $section = $this->create_section($course->id, [
            'name' => 'Test Section 1',
        ]);
        $generator->create_module('quiz', [
            'course' => $course->id,
            'section' => $section->section,
            'name' => 'Test Quiz 1',
        ]);
        $generator->create_module('label', [
            'course' => $course->id,
            'section' => $section->section,
            'name' => 'Test Label 1',
        ]);
        $generator->enrol_user($USER->id, $course->id, 'editingteacher');

        $item = $this->factory->item()->repository()->insert_section(
            $section,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $handler = $this->factory->backup()->handler();
        $task = $this->create_task($handler->backup_section(
            $section,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        )["task"]);

        $controller = $task->get_backup_controller();
        self::assertNotEmpty($controller);

        $plan = $controller->get_plan();

        if (!$plan->setting_exists('questionbank')) {
            self::markTestSkipped(
                "Skip the test because the 'questionbank' setting does not exist in the backup plan."
            );
        }

        $task->call('before_backup_started_hook', $controller);

        $setting = $plan->get_setting('questionbank');
        self::assertTrue(
            (bool)$setting->get_value(),
            'Expected questionbank setting to be true when quiz is present in the section backup'
        );
    }

    /**
     * test_backup_section_with_no_quiz_expected_questionbank_setting_value_returns_false
     *
     * @return void
     */
    public function test_backup_section_with_no_quiz_expected_questionbank_setting_value_returns_false(): void {
        global $USER;
        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $section = $this->create_section($course->id, [
            'name' => 'Test Section 1',
        ]);
        $generator->create_module(
            'label', // Using label so questionbank setting is absent.
            [
                'course' => $course->id,
                'section' => $section->section,
                'name' => 'Test Label 1',
            ]
        );

        // Enroll the user as an editing teacher in the course.
        $generator->enrol_user($USER->id, $course->id, 'editingteacher');

        $item = $this->factory->item()->repository()->insert_section(
            $section,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $handler = $this->factory->backup()->handler();
        $task = $this->create_task($handler->backup_section(
            $section,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        )["task"]);

        $controller = $task->get_backup_controller();
        self::assertNotEmpty($controller);

        $plan = $controller->get_plan();
        if (!$plan->setting_exists('questionbank')) {
            self::markTestSkipped(
                "Skip the test because the 'questionbank' setting does not exist in the backup plan."
            );
        }

        $task->call('before_backup_started_hook', $controller);

        $setting = $plan->get_setting('questionbank');
        self::assertFalse(
            (bool)$setting->get_value(),
            'Expected questionbank setting to be false when no quiz is present in the section backup'
        );
    }

    /**
     * A subsection backup includes its quiz question bank and completes successfully.
     *
     * @return void
     */
    public function test_backup_subsection_with_quiz_executes_and_includes_questionbank(): void {
        global $DB, $USER;
        self::setAdminUser();

        if (!$DB->record_exists('modules', ['name' => 'subsection'])) {
            self::markTestSkipped('Subsections are not supported on this Moodle version.');
        }

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $subsection = $generator->create_module('subsection', [
            'course' => $course->id,
            'section' => 1,
        ]);
        $subsectioncm = $DB->get_record(
            'course_modules',
            ['id' => $subsection->cmid],
            'id, instance',
            MUST_EXIST
        );
        $delegatedsection = $DB->get_record(
            'course_sections',
            [
                'course' => $course->id,
                'component' => 'mod_subsection',
                'itemid' => $subsectioncm->instance,
            ],
            '*',
            MUST_EXIST
        );

        $quiz = $generator->create_module('quiz', [
            'course' => $course->id,
            'section' => $delegatedsection->section,
            'name' => 'Quiz in subsection',
        ]);
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
        ]);
        $question = $questiongenerator->create_question(
            'shortanswer',
            null,
            ['category' => $category->id]
        );
        quiz_add_quiz_question($question->id, $quiz);

        $generator->enrol_user($USER->id, $course->id, 'editingteacher');
        $item = $this->factory->item()->repository()->insert_section(
            $delegatedsection,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        // Subsection backup uses a course backup with the delegated section selected.
        $handler = $this->factory->backup()->handler();
        $backup = $handler->backup_section(
            $delegatedsection,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        );
        $task = $this->create_task($backup['task']);

        $controller = $backup['controller'];
        self::assertNotEmpty($controller);
        $plan = $controller->get_plan();
        $hasquestionbanksetting = $plan->setting_exists('questionbank');

        $task->call('before_backup_started_hook', $controller);
        if ($hasquestionbanksetting) {
            self::assertTrue(
                (bool)$plan->get_setting('questionbank')->get_value(),
                'Expected the quiz question bank to be included for a subsection backup'
            );
        }

        // Exercise the real asynchronous backup, including creation of the Sharing Cart backup file.
        $task->execute();

        $backedupitem = $this->factory->item()->repository()->get_by_id($item->get_id());
        self::assertSame(entity::STATUS_BACKEDUP, $backedupitem->get_status());
        self::assertNotEmpty($backedupitem->get_file_id());

        $childitems = $this->factory->item()->repository()->get_by_parent_item_id($item->get_id());
        self::assertCount(1, $childitems);
        self::assertSame('mod_quiz', $childitems->first()->get_type());
    }

    /**
     * test_backup_quiz_activity_expected_questionbank_setting_value_returns_true
     *
     * @return void
     */
    public function test_backup_quiz_activity_expected_questionbank_setting_value_returns_true(): void {
        global $USER;
        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $section = $this->create_section($course->id, [
            'name' => 'Test Section 1',
        ]);
        $activity = $generator->create_module(
            // Using quiz to ensure questionbank setting is present.
            'quiz',
            [
                'course' => $course->id,
                'section' => $section->section,
                'name' => 'Test Quiz 1',
            ]
        );

        // Enroll the user as an editing teacher in the course.
        $generator->enrol_user($USER->id, $course->id, 'editingteacher');

        $item = $this->factory->item()->repository()->insert_activity(
            $activity->cmid,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $handler = $this->factory->backup()->handler();
        $task = $this->create_task($handler->backup_course_module(
            $activity->cmid,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        ));

        $controller = $task->get_backup_controller();
        self::assertNotEmpty($controller);

        $plan = $controller->get_plan();
        if (!$plan->setting_exists('questionbank')) {
            self::markTestSkipped(
                "Skip the test because the 'questionbank' setting does not exist in the backup plan."
            );
        }

        $task->call('before_backup_started_hook', $controller);

        $setting = $plan->get_setting('questionbank');
        self::assertTrue(
            (bool)$setting->get_value(),
            'Expected questionbank setting to be true when quiz is present in the course module backup'
        );
    }

    /**
     * test_backup_label_activity_expected_questionbank_setting_value_returns_false
     *
     * @return void
     */
    public function test_backup_label_activity_expected_questionbank_setting_value_returns_false(): void {
        global $USER;
        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $section = $this->create_section($course->id, [
            'name' => 'Test Section 1',
        ]);
        $activity = $generator->create_module(
            'label', // Using label so questionbank setting is absent.
            [
                'course' => $course->id,
                'section' => $section->section,
                'name' => 'Test Label 1',
            ]
        );

        // Enroll the user as an editing teacher in the course.
        $generator->enrol_user($USER->id, $course->id, 'editingteacher');

        $item = $this->factory->item()->repository()->insert_activity(
            $activity->cmid,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $handler = $this->factory->backup()->handler();
        $task = $this->create_task($handler->backup_course_module(
            $activity->cmid,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        ));

        $controller = $task->get_backup_controller();
        self::assertNotEmpty($controller);

        $plan = $controller->get_plan();
        if (!$plan->setting_exists('questionbank')) {
            self::markTestSkipped(
                "Skip the test because the 'questionbank' setting does not exist in the backup plan."
            );
        }

        $task->call('before_backup_started_hook', $controller);

        $setting = $plan->get_setting('questionbank');
        self::assertFalse(
            (bool)$setting->get_value(),
            'Expected questionbank setting to be false when no quiz is present in the course module backup'
        );
    }
}
