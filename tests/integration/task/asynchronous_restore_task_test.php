<?php

namespace block_sharing_cart\integration\task;

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\task\asynchronous_restore_task;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class asynchronous_restore_task_test extends \advanced_testcase
{
    private factory $factory;

    protected function setUp(): void
    {
        $this->resetAfterTest();
        $this->factory = factory::make();
    }

    private function create_section(int $course_id, array $record = []): object
    {
        $db = $this->factory->moodle()->db();

        $record['course'] = $course_id;

        if (!isset($record['section'])) {
            $last_section_number = (int)$db->get_field(
                'course_sections',
                'MAX(section)',
                ['course' => $course_id]
            );
            $record['section'] = $last_section_number + 1;
        }

        $section = self::getDataGenerator()->create_course_section($record);
        return $db->get_record(
            'course_sections',
            ['id' => $section->id],
            '*',
            MUST_EXIST
        );
    }

    private function backup_label_into_cart(object $course, object $section, object $user): entity
    {
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

    public function test_queue_does_not_create_backup_controller_or_tempdir(): void
    {
        global $USER, $DB;

        self::setAdminUser();
        $user = $USER;

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $source_section = $this->create_section($source->id, ['name' => 'Source']);
        $target_section = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($user->id, $source->id, 'editingteacher');
        $generator->enrol_user($user->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $source_section, $user);

        $controllers_before = $DB->count_records('backup_controllers');

        $restore_task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $target_section->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restore_task);

        $customdata = $restore_task->get_custom_data();
        self::assertFalse(property_exists($customdata, 'backupid'));
        self::assertSame((int)$target->id, (int)$customdata->course_id);
        self::assertNotEmpty($customdata->item);

        self::assertSame($controllers_before, $DB->count_records('backup_controllers'));
    }

    public function test_restore_extracts_and_succeeds_on_task_execute(): void
    {
        global $USER, $DB;

        self::setAdminUser();
        $user = $USER;

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $source_section = $this->create_section($source->id, ['name' => 'Source']);
        $target_section = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($user->id, $source->id, 'editingteacher');
        $generator->enrol_user($user->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $source_section, $user);

        $cms_before = $DB->count_records('course_modules', ['course' => $target->id]);

        $restore_task = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $target_section->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restore_task);

        ob_start();
        $restore_task->execute();
        ob_get_clean();

        $cms_after = $DB->count_records('course_modules', ['course' => $target->id]);
        self::assertGreaterThan($cms_before, $cms_after);
    }
}
