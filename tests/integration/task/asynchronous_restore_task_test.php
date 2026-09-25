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
use block_sharing_cart\task\asynchronous_restore_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Async restore tests for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_sharing_cart\task\asynchronous_restore_task
 */
final class asynchronous_restore_task_test extends \advanced_testcase
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
     * backup_label_into_cart
     *
     * @param object $course
     * @param object $section
     * @param object $user
     * @return entity
     */
    private function backup_label_into_cart(object $course, object $section, object $user): entity {
        $generator = self::getDataGenerator();
        $label = $generator->create_module('label', [
            'course' => $course->id,
            'section' => $section->section,
            'name' => 'Cart Label',
            'intro' => 'Cart label intro',
        ]);

        $item = $this->factory->item()->repository()->insert_activity(
            $label->cmid,
            $user->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $task = $this->factory->backup()->handler()->backup_course_module(
            $label->cmid,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        );
        $ref = new \ReflectionProperty($task, 'output');
        $ref->setAccessible(true);
        $ref->setValue($task, false);
        $task->execute();

        $item = $this->factory->item()->repository()->get_by_id($item->get_id());
        self::assertNotNull($item);
        self::assertSame(entity::STATUS_BACKEDUP, $item->get_status());
        self::assertNotNull(
            $this->factory->item()->repository()->get_stored_file_by_item($item)
        );

        return $item;
    }

    /**
     * test_queue_does_not_create_backup_controller_or_tempdir
     *
     * @return void
     */
    public function test_queue_does_not_create_backup_controller_or_tempdir(): void {
        global $USER, $DB;

        self::setAdminUser();

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $sourcesection = $this->create_section($source->id, ['name' => 'Source']);
        $targetsection = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($USER->id, $source->id, 'editingteacher');
        $generator->enrol_user($USER->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $sourcesection, $USER);

        $controllersbefore = $DB->count_records('backup_controllers');

        $restoretask = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $targetsection->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restoretask);

        $customdata = $restoretask->get_custom_data();
        self::assertObjectNotHasProperty('backupid', $customdata);
        self::assertSame((int)$target->id, (int)$customdata->courseid);
        self::assertNotEmpty($customdata->item);
        self::assertSame(
            (int)$targetsection->id,
            (int)($customdata->backup_settings->move_to_section_id ?? 0)
        );
        self::assertSame(
            [(int)$item->get_old_instance_id()],
            array_map('intval', (array)($customdata->backup_settings->course_modules_to_include ?? []))
        );

        self::assertSame($controllersbefore, $DB->count_records('backup_controllers'));
    }

    /**
     * test_restore_extracts_and_succeeds_on_task_execute
     *
     * @return void
     */
    public function test_restore_extracts_and_succeeds_on_task_execute(): void {
        global $USER, $DB;

        self::setAdminUser();

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $sourcesection = $this->create_section($source->id, ['name' => 'Source']);
        $targetsection = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($USER->id, $source->id, 'editingteacher');
        $generator->enrol_user($USER->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $sourcesection, $USER);

        $cmsbefore = $DB->count_records('course_modules', ['course' => $target->id]);

        $restoretask = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $targetsection->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restoretask);

        ob_start();
        $restoretask->execute();
        ob_get_clean();

        $cmsafter = $DB->count_records('course_modules', ['course' => $target->id]);
        self::assertGreaterThan($cmsbefore, $cmsafter);
    }
}
