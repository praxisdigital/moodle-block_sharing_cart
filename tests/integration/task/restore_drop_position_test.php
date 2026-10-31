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
use block_sharing_cart\external\restore\item_into_section;
use block_sharing_cart\task\asynchronous_restore_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for restoring an item at the position it was dragged to in the course.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_sharing_cart\task\asynchronous_restore_task
 * @covers    \block_sharing_cart\app\restore\handler
 * @covers    \block_sharing_cart\external\restore\item_into_section
 */
final class restore_drop_position_test extends \advanced_testcase {
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
     * Backs up a label from a new source course into the current user's sharing cart.
     *
     * @return entity
     */
    private function label_in_cart(): entity {
        global $USER;

        $generator = self::getDataGenerator();
        $source = $generator->create_course(['numsections' => 1]);
        $label = $generator->create_module('label', ['course' => $source->id, 'section' => 1, 'name' => 'Cart label']);
        $generator->enrol_user($USER->id, $source->id, 'editingteacher');

        $item = $this->factory->item()->repository()->insert_activity(
            $label->cmid,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );
        $task = $this->factory->backup()->handler()->backup_course_module(
            $label->cmid,
            $item,
            ['users' => false, 'anonymize' => false]
        );
        $this->execute_backup_task($task);

        return $this->factory->item()->repository()->get_by_id($item->get_id());
    }

    /**
     * Backs up a section named "Cart section" with one label into the current user's sharing cart.
     *
     * @return array [entity $item, int $labelcmid]
     */
    private function section_in_cart(): array {
        global $USER, $DB;

        $generator = self::getDataGenerator();
        $source = $generator->create_course(['numsections' => 1]);
        $label = $generator->create_module('label', ['course' => $source->id, 'section' => 1, 'name' => 'Section label']);
        $DB->set_field('course_sections', 'name', 'Cart section', ['course' => $source->id, 'section' => 1]);
        $section = $DB->get_record(
            'course_sections',
            ['course' => $source->id, 'section' => 1],
            'id, section, sequence, course, itemid',
            MUST_EXIST
        );

        $item = $this->factory->item()->repository()->insert_section(
            $section,
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );
        $result = $this->factory->backup()->handler()->backup_section(
            $section,
            $item,
            ['users' => false, 'anonymize' => false]
        );
        $this->execute_backup_task($result['task']);

        return [$this->factory->item()->repository()->get_by_id($item->get_id()), (int)$label->cmid];
    }

    /**
     * execute_backup_task
     *
     * @param \core\task\adhoc_task $task
     * @return void
     */
    private function execute_backup_task(\core\task\adhoc_task $task): void {
        $ref = new \ReflectionProperty($task, 'output');
        $ref->setValue($task, false);
        $task->execute();
    }

    /**
     * execute_restore_task
     *
     * @param asynchronous_restore_task $task
     * @return void
     */
    private function execute_restore_task(asynchronous_restore_task $task): void {
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * Course module names of a section in display order.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return string[]
     */
    private function section_cm_names(int $courseid, int $sectionnum): array {
        rebuild_course_cache($courseid, true);
        $modinfo = get_fast_modinfo($courseid);

        return array_map(
            fn(int $cmid) => $modinfo->get_cm($cmid)->name,
            $modinfo->get_sections()[$sectionnum] ?? []
        );
    }

    /**
     * Regular section names in display order.
     *
     * @param int $courseid
     * @return string[]
     */
    private function section_names(int $courseid): array {
        global $DB;

        return array_values($DB->get_records_menu(
            'course_sections',
            ['course' => $courseid, 'component' => null],
            'section',
            'section, name'
        ));
    }

    /**
     * Creates a target course with three named sections, each holding two labels.
     *
     * @return object
     */
    private function create_target_course(): object {
        global $DB, $USER;

        $generator = self::getDataGenerator();
        $course = $generator->create_course(['numsections' => 3, 'format' => 'topics']);
        foreach ([1, 2, 3] as $num) {
            $DB->set_field('course_sections', 'name', "S$num", ['course' => $course->id, 'section' => $num]);
            foreach (['a', 'b'] as $suffix) {
                $generator->create_module('label', ['course' => $course->id, 'section' => $num, 'name' => "S$num$suffix"]);
            }
        }
        $generator->enrol_user($USER->id, $course->id, 'editingteacher');

        return $course;
    }

    /**
     * section_id
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return int
     */
    private function section_id(int $courseid, int $sectionnum): int {
        global $DB;

        return (int)$DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $sectionnum], MUST_EXIST);
    }

    /**
     * cm_id
     *
     * @param int $courseid
     * @param string $name
     * @return int
     */
    private function cm_id(int $courseid, string $name): int {
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->name === $name) {
                return (int)$cm->id;
            }
        }

        throw new \coding_exception("No course module named $name");
    }

    public function test_activity_is_placed_before_the_course_module_it_was_dropped_on(): void {
        self::setAdminUser();
        $course = $this->create_target_course();
        $item = $this->label_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $this->section_id($course->id, 2),
            $item->get_id(),
            [],
            $this->cm_id($course->id, 'S2b')
        );
        $this->execute_restore_task($task);

        self::assertSame(['S2a', 'Cart label', 'S2b'], $this->section_cm_names($course->id, 2));
    }

    public function test_activity_goes_to_the_end_when_the_anchor_left_the_section_before_the_restore_ran(): void {
        self::setAdminUser();
        $course = $this->create_target_course();
        $item = $this->label_in_cart();
        $anchorcmid = $this->cm_id($course->id, 'S2a');

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $this->section_id($course->id, 2),
            $item->get_id(),
            [],
            $anchorcmid
        );
        \core_courseformat\formatactions::cm($course->id)->move_end_section($anchorcmid, $this->section_id($course->id, 3));
        $this->execute_restore_task($task);

        self::assertSame(['S2b', 'Cart label'], $this->section_cm_names($course->id, 2));
    }

    public function test_section_is_inserted_as_a_new_section_after_the_anchor_section(): void {
        self::setAdminUser();
        $course = $this->create_target_course();
        [$item, $labelcmid] = $this->section_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $this->section_id($course->id, 1),
            $item->get_id(),
            ['course_modules_to_include' => [$labelcmid]],
            0,
            true
        );
        self::assertSame(
            $this->section_id($course->id, 1),
            (int)$task->get_custom_data()->backup_settings->insert_after_section_id
        );
        $this->execute_restore_task($task);

        self::assertSame(['', 'S1', 'Cart section', 'S2', 'S3'], array_map('strval', $this->section_names($course->id)));
        self::assertSame(['Section label'], $this->section_cm_names($course->id, 2));
        self::assertSame(['S2a', 'S2b'], $this->section_cm_names($course->id, 3));
    }

    public function test_new_section_after_the_last_section_stays_before_subsections(): void {
        global $DB;

        self::setAdminUser();
        $course = $this->create_target_course();
        $subsection = self::getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        [$item, $labelcmid] = $this->section_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $this->section_id($course->id, 3),
            $item->get_id(),
            ['course_modules_to_include' => [$labelcmid]],
            0,
            true
        );
        $this->execute_restore_task($task);

        $newsection = $DB->get_record('course_sections', ['course' => $course->id, 'name' => 'Cart section'], '*', MUST_EXIST);
        $delegated = $DB->get_record(
            'course_sections',
            ['component' => 'mod_subsection', 'itemid' => $subsection->id],
            '*',
            MUST_EXIST
        );
        self::assertSame(4, (int)$newsection->section);
        self::assertNull($newsection->component);
        self::assertGreaterThan(4, (int)$delegated->section);
    }

    public function test_activity_cannot_be_inserted_as_a_new_section(): void {
        self::setAdminUser();
        $course = $this->create_target_course();
        $item = $this->label_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $this->section_id($course->id, 1),
            $item->get_id(),
            [],
            0,
            true
        );

        self::assertNull($task);
    }

    public function test_new_section_cannot_be_anchored_on_a_subsection(): void {
        global $DB;

        self::setAdminUser();
        $course = $this->create_target_course();
        $subsection = self::getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        $delegatedid = (int)$DB->get_field('course_sections', 'id', ['component' => 'mod_subsection', 'itemid' => $subsection->id]);
        [$item, $labelcmid] = $this->section_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $delegatedid,
            $item->get_id(),
            ['course_modules_to_include' => [$labelcmid]],
            0,
            true
        );

        self::assertNull($task);
    }

    public function test_section_cannot_be_restored_into_a_subsection(): void {
        global $DB;

        self::setAdminUser();
        $course = $this->create_target_course();
        $subsection = self::getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        $delegatedid = (int)$DB->get_field('course_sections', 'id', ['component' => 'mod_subsection', 'itemid' => $subsection->id]);
        [$item, $labelcmid] = $this->section_in_cart();

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $delegatedid,
            $item->get_id(),
            ['course_modules_to_include' => [$labelcmid]]
        );

        self::assertNull($task);
    }

    public function test_service_requires_restore_capability_in_the_target_course(): void {
        $generator = self::getDataGenerator();
        $user = $generator->create_user();
        self::setUser($user);
        $item = $this->label_in_cart();

        $course = $generator->create_course(['numsections' => 1]);
        $generator->enrol_user($user->id, $course->id, 'student');

        $this->expectException(\required_capability_exception::class);
        item_into_section::execute($item->get_id(), $this->section_id($course->id, 1), [], 0, false);
    }

    public function test_service_rejects_a_course_module_from_another_section(): void {
        self::setAdminUser();
        $course = $this->create_target_course();
        $item = $this->label_in_cart();

        $this->expectException(\invalid_parameter_exception::class);
        item_into_section::execute(
            $item->get_id(),
            $this->section_id($course->id, 1),
            [],
            $this->cm_id($course->id, 'S2a'),
            false
        );
    }
}
