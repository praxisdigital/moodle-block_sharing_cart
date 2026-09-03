<?php

namespace block_sharing_cart\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\event\backup_course_module;
use block_sharing_cart\event\backup_section;
use block_sharing_cart\hook\backup\resolve_section_tree;
use block_sharing_cart\task\asynchronous_backup_task;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/format/lib.php');

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

    public function backup_section(object $section, entity $root_item, array $settings = []): array
    {
        global $USER;

        $course_id = $this->base_factory->moodle()->db()->get_record(
            'course_sections',
            ['id' =>  $section->id],
            'course',
            MUST_EXIST
        )->course;

        // Ask the course format for the descendant sections of the copied section (nested formats only).
        $settings['section_tree'] = $this->resolve_section_tree((int)$course_id, (int)$section->id);

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

        return ["task" => $task, "controller" => $backup_controller];

    }

    /**
     * Depth-first list of {section_id, parent_section_id, sort_order, name} describing the descendants of a section,
     * as declared by the course format through the resolve_section_tree hook. Empty for formats that do not nest.
     */
    public function resolve_section_tree(int $course_id, int $section_id): array
    {
        $hook = new resolve_section_tree($course_id, $section_id);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $tree = $hook->get_tree();
        if (empty($tree)) {
            return [];
        }

        // Record the display names now; the backup manifest only carries section numbers for unnamed sections.
        $course_format = course_get_format($course_id);
        $sections = $this->base_factory->moodle()->db()->get_records_list(
            'course_sections',
            'id',
            array_map(static fn(object $node): int => $node->section_id, $tree)
        );

        return array_map(static function (object $node) use ($course_format, $sections): array {
            $section = $sections[$node->section_id] ?? null;

            $name = '';
            if ($section) {
                $name = (string)$section->name !== ''
                    ? format_string($section->name)
                    : $course_format->get_section_name($section);
            }

            return [
                'section_id' => $node->section_id,
                'parent_section_id' => $node->parent_section_id,
                'sort_order' => $node->sort_order,
                'name' => $name,
            ];
        }, $tree);
    }

    public function get_backup_course_info(\stored_file $file): array
    {
        $info = $this->get_backup_info($file);

        return [
            'id' => $info->original_course_id,
            'fullname' => $info->original_course_fullname
        ];
    }

    /**
     * Sections contained in the backup, keyed by their original section id and ordered root first, then the stored
     * section tree. Core subsections (mod_subsection) are attached to the section owning their parent module and
     * appear in that section's activities with a 'subsection_activities' list of their own.
     *
     * @param \stored_file $file
     * @param array $section_tree depth-first list of {section_id, parent_section_id, sort_order} captured at backup
     *                            time; empty for backups of a single section.
     */
    public function get_backup_item_tree(\stored_file $file, array $section_tree = []): array
    {
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
                continue;
            }

            $sections[$section->sectionid] = (object)[
                'sectionid' => $section->sectionid,
                'title' => $section->title,
                'modulename' => $section->modname,
                'parent_section_id' => 0,
                'sort_order' => 0,
                'activities' => []
            ];
        }

        // Order the sections root first, then by the stored tree, and record the parent relations.
        if (!empty($section_tree) && !empty($sections)) {
            $ordered = [];
            foreach ($sections as $section_id => $section) {
                $in_tree = false;
                foreach ($section_tree as $node) {
                    if ((int)((object)$node)->section_id === (int)$section_id) {
                        $in_tree = true;
                        break;
                    }
                }
                if (!$in_tree) {
                    $ordered[$section_id] = $section;
                }
            }
            foreach ($section_tree as $node) {
                $node = (object)$node;
                if (!isset($sections[$node->section_id])) {
                    continue;
                }
                $sections[$node->section_id]->parent_section_id = (int)$node->parent_section_id;
                $sections[$node->section_id]->sort_order = (int)$node->sort_order;
                $ordered[$node->section_id] = $sections[$node->section_id];
            }
            $sections = $ordered;
        }

        // Attach core subsections to the section that owns their parent module.
        $module_sections = [];
        foreach ($info->activities as $activity) {
            $module_sections[$activity->moduleid] = $activity->sectionid;
        }
        foreach ($subsections as $subsection) {
            $owner_section_id = $module_sections[$subsection->moduleid] ?? null;
            if ($owner_section_id !== null && isset($sections[$owner_section_id])) {
                $sections[$owner_section_id]->activities['sub_' . $subsection->sectionid] = $subsection;
            } elseif (!empty($sections)) {
                $sections[array_key_first($sections)]->activities['sub_' . $subsection->sectionid] = $subsection;
            }
        }

        if (empty($sections)) {
            if (empty($subsections)) {
                //If no sections and no subsections are supplied, it's a single activity. Make artificial activities array.
                $sections["lone_activity"] = (object) ['activities' => []];
            } else {
                $sections = $subsections;
            }
        }

        foreach ($info->activities as $activity) {
            if (isset($activity->modulename) && $activity->modulename === 'subsection') {
                continue;
            }

            //Activities that live in a section
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

            //Activities that live under a core subsection
            if (isset($subsections[$activity->sectionid])) {
                $subsections[$activity->sectionid]->subsection_activities[] = $activity;
                continue;
            }

            if (isset($sections["lone_activity"])) {
                $sections["lone_activity"]->activities[] = $activity;
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

        $asynctask->set_id($task_id);

        return $asynctask;
    }
}
