<?php

namespace block_sharing_cart\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\event\backup_course_module;
use block_sharing_cart\event\backup_section;
use block_sharing_cart\task\asynchronous_backup_task;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class handler
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    private function get_backup_info(\stored_file $file): object
    {
        /**
         * @var \file_storage $fs
         */
        $fs = get_file_storage();
        $file_path = $fs->get_file_system()->get_local_path_from_storedfile($file, true);

        /** @var object $info */
        $info = \backup_general_helper::get_backup_information_from_mbz($file_path);

        return $info;
    }

    public function backup_course_module(
        int $course_module_id,
        entity $root_item,
        array $settings = []
    ): asynchronous_backup_task {
        global $USER;

        $record = $this->base_factory->moodle()->db()->get_record(
            'course_modules',
            ['id' =>  $course_module_id],
            'id, course',
            MUST_EXIST
        );

        $backup_controller = $this->base_factory->backup()->backup_controller(
            \backup::TYPE_1ACTIVITY,
            $course_module_id,
            $USER->id
        );

        backup_course_module::create_by_course_module(
            $record->course,
            $course_module_id,
            $USER->id
        )->trigger();

        return $this->queue_async_backup($backup_controller, $root_item, $settings);
    }

    public function backup_section(mixed $section, entity $root_item, array $settings = []): array
    {
        global $USER;

        $course_id = $this->base_factory->moodle()->db()->get_record(
            'course_sections',
            ['id' =>  $section->id],
            'course',
            MUST_EXIST
        )->course;

        $backup_controller = $this->base_factory->backup()->backup_controller(
            \backup::TYPE_1COURSE,
            $course_id,
            $USER->id
        );

        $task = $this->queue_async_backup($backup_controller, $root_item, $settings);

        backup_section::create_by_section(
            $course_id,
            $section->id,
            $USER->id
        )->trigger();

        return array("task" => $task, "controller" => $backup_controller);
    }

    public function get_backup_course_info(\stored_file $file): array
    {
        $info = $this->get_backup_info($file);

        return [
            'id' => $info->original_course_id,
            'fullname' => $info->original_course_fullname
        ];
    }

    public function get_backup_item_tree(\stored_file $file): array
    {

        $info = $this->get_backup_info($file);

        $sections = [];
        $subsections = [];

        foreach ($info->sections as $section) {

            if(isset($section->modname) && $section->modname == 'subsection') {
                $subsections[$section->sectionid] = (object)[
                    'moduleid' => $section->parentcmid,
                    'sectionid' => $section->sectionid,
                    'title' => $section->title,
                    'modulename' => $section->modname,
                    'subsection_activities' => []
                    ];
            }
            else{
                $sections[$section->sectionid] = (object)[
                    'sectionid' => $section->sectionid,
                    'title' => $section->title,
                    'modulename' => $section->modname,
                    'activities' => []
                ];
            }

        }

        //Add all subsections under the section's activities.
        if(!empty($sections)){
            $sections[array_key_first($sections)]->activities = $subsections;
        }

        if(empty($sections)){
            if(empty($subsections)){
                //If no sections and no subsections are supplied, it's a single activity. Make artificial activities array.
                $sections["lone_activity"] = (object) ['activities' => []];
            }
            else $sections = $subsections;
        }

        foreach ($info->activities as $activity) {

            if(isset($activity->modulename) && $activity->modulename == 'subsection' ) continue;

            //Activities that live in the section
            if(isset($sections[$activity->sectionid])){
                $sections[$activity->sectionid]->activities[$activity->moduleid] = (object)[
                    'moduleid' => $activity->moduleid,
                    'sectionid' => $activity->sectionid,
                    'modulename' => $activity->modulename,
                    'title' => $activity->title,
                    'activities' => []
                ];
            }
            else if(isset($sections["lone_activity"])){
                $sections["lone_activity"]->activities[] = $activity;
            }
            else{
                //Activities that live under subsections
                $sections[array_key_first($sections)]->activities[$activity->sectionid]->subsection_activities[] = $activity;
            }
        }

        return $sections;
    }

    private function queue_async_backup(
        \backup_controller $backup_controller,
        entity $root_item,
        array $settings = []
    ): asynchronous_backup_task {
        $asynctask = new asynchronous_backup_task();
        $asynctask->set_custom_data([
            'backupid' => $backup_controller->get_backupid(),
            'item' => $root_item->to_array(),
            'backup_settings' => $settings
        ]);
        $asynctask->set_userid($backup_controller->get_userid());
        $task_id = \core\task\manager::queue_adhoc_task($asynctask);

        //This is not in the core way to do it???
        $asynctask->set_id($task_id);

        return $asynctask;
    }
}
