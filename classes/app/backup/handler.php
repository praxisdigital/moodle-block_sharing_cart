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

namespace block_sharing_cart\app\backup;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\event\backup_course_module;
use block_sharing_cart\event\backup_section;
use block_sharing_cart\task\asynchronous_backup_task;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Class app\backup\handler for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class handler {
    private base_factory $basefactory;

    public function __construct(base_factory $basefactory) {
        $this->basefactory = $basefactory;
    }

    private function get_backup_info(\stored_file $file): object {
        /**
         * @var \file_storage $fs
         */
        $fs = get_file_storage();
        $filepath = $fs->get_file_system()->get_local_path_from_storedfile($file, true);

        /** @var object $info */
        $info = \backup_general_helper::get_backup_information_from_mbz($filepath);

        return $info;
    }

    public function backup_course_module(
        int $coursemoduleid,
        entity $rootitem,
        array $settings = []
    ): asynchronous_backup_task {
        global $USER;

        $record = $this->basefactory->moodle()->db()->get_record(
            'course_modules',
            ['id' =>  $coursemoduleid],
            'id, course',
            MUST_EXIST
        );

        $backupcontroller = $this->basefactory->backup()->backup_controller(
            \backup::TYPE_1ACTIVITY,
            $coursemoduleid,
            $USER->id
        );

        backup_course_module::create_by_course_module(
            $record->course,
            $coursemoduleid,
            $USER->id
        )->trigger();

        return $this->queue_async_backup($backupcontroller, $rootitem, $settings);
    }

    public function backup_section(object $section, entity $rootitem, array $settings = []): array {
        global $USER;

        $courseid = $this->basefactory->moodle()->db()->get_record(
            'course_sections',
            ['id' =>  $section->id],
            'course',
            MUST_EXIST
        )->course;

        $backupcontroller = $this->basefactory->backup()->backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            $USER->id
        );

        $task = $this->queue_async_backup($backupcontroller, $rootitem, $settings);

        backup_section::create_by_section(
            $courseid,
            $section->id,
            $USER->id
        )->trigger();

        return ["task" => $task, "controller" => $backupcontroller];
    }

    public function get_backup_course_info(\stored_file $file): array {
        $info = $this->get_backup_info($file);

        return [
            'id' => $info->original_course_id,
            'fullname' => $info->original_course_fullname
        ];
    }

    public function get_backup_item_tree(\stored_file $file): array {
        $info = $this->get_backup_info($file);

        $sections = [];
        $subsections = [];

        foreach ($info->sections as $section) {
            if (isset($section->modname) && $section->modname === 'subsection') {
                $subsections[$section->sectionid] = (object)[
                    'moduleid' => $section->parentcmid,
                    'sectionid' => $section->sectionid,
                    'title' => $section->title,
                    'modulename' => $section->modname,
                    'subsection_activities' => []
                    ];
            } else {
                $sections[$section->sectionid] = (object)[
                    'sectionid' => $section->sectionid,
                    'title' => $section->title,
                    'modulename' => $section->modname,
                    'activities' => []
                ];
            }
        }

        // Add all subsections under the section's activities.
        if (!empty($sections)) {
            $sections[array_key_first($sections)]->activities = $subsections;
        }

        if (empty($sections)) {
            if (empty($subsections)) {
                // If no sections and no subsections are supplied, it's a single activity. Make artificial activities array.
                $sections["lone_activity"] = (object) ['activities' => []];
            } else $sections = $subsections;
        }

        foreach ($info->activities as $activity) {
            if (isset($activity->modulename) && $activity->modulename === 'subsection' ) continue;

            // Activities that live in the section.
            if (isset($sections[$activity->sectionid])) {
                $sections[$activity->sectionid]->activities[$activity->moduleid] = (object)[
                    'moduleid' => $activity->moduleid,
                    'sectionid' => $activity->sectionid,
                    'modulename' => $activity->modulename,
                    'title' => $activity->title,
                    'activities' => []
                ];
                continue;
            }

            if (isset($sections["lone_activity"])) {
                $sections["lone_activity"]->activities[] = $activity;
                continue;
            }

            // Activities that live under subsections.
            $sections[array_key_first($sections)]->activities[$activity->sectionid]->subsection_activities[] = $activity;
        }

        return $sections;
    }

    private function queue_async_backup(
        \backup_controller $backupcontroller,
        entity $rootitem,
        array $settings = []
    ): asynchronous_backup_task {
        $asynctask = new asynchronous_backup_task();
        $asynctask->set_custom_data([
            'backupid' => $backupcontroller->get_backupid(),
            'item' => $rootitem->to_array(),
            'backup_settings' => $settings
        ]);
        $asynctask->set_userid($backupcontroller->get_userid());
        $taskid = \core\task\manager::queue_adhoc_task($asynctask);

        $asynctask->set_id($taskid);

        return $asynctask;
    }
}
