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

namespace block_sharing_cart\output\modal;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;

/**
 * Class output\modal\import_item_modal_body for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class import_item_modal_body implements \renderable, \core\output\named_templatable {
    private base_factory $basefactory;
    private entity $item;
    private int $clipboardtargetid = 0;
    private \moodle_database $db;

    public function __construct(base_factory $basefactory, entity $item, int $clipboardtargetid) {
        $this->basefactory = $basefactory;
        $this->item = $item;
        $this->clipboardtargetid = $clipboardtargetid;
        $this->db = $this->basefactory->moodle()->db();
    }

    public function get_template_name(\renderer_base $renderer): string {
        return 'block_sharing_cart/modal/import_item_modal_body';
    }

    private function can_configure_restore(): bool {
        $PAGE = $this->basefactory->moodle()->page();

        return has_capability('moodle/restore:configure', $PAGE->context);
    }

    public function export_for_template(\renderer_base $OUTPUT): array {
        $canconfigurerestore = $this->can_configure_restore();
        $itemtree = array_values(
            $this->basefactory->backup()->handler()->get_backup_item_tree(
                $this->basefactory->item()->repository()->get_stored_file_by_item($this->item)
            )
        );

        $section = [];
        if (!empty($itemtree)) $section = $itemtree[array_key_first($itemtree)];

       foreach ($section->activities as $activity) {
            if ($activity->modulename === "subsection") {
                foreach ($activity->subsection_activities as $subsectionactivity) {
                    $subsectionactivity->title = format_string($subsectionactivity->title);
                    $subsectionactivity->title = strlen($subsectionactivity->title) > 50 ? substr(
                            $subsectionactivity->title,
                            0,
                            50
                        ) . '...' : $subsectionactivity->title;

                    $subsectionactivity->id = $subsectionactivity->moduleid;
                    $subsectionactivity->type = 'coursemodule';
                    $subsectionactivity->mod_icon = $OUTPUT->image_url('icon', "mod_{$subsectionactivity->modulename}");
                    $subsectionactivity->module_is_disabled_on_site = $this->db->get_record('modules', [
                        'name' => $subsectionactivity->modulename,
                        'visible' => false
                    ]);
                    $subsectionactivity->locked = $subsectionactivity->module_is_disabled_on_site || $canconfigurerestore === false;
                    $subsectionactivity->course_modules = [];
                }
                $activity->course_modules = $activity->subsection_activities;
                $activity->id = $activity->moduleid;

                continue;
            }

            $activity->title = format_string($activity->title);
            $activity->title = strlen($activity->title) > 50 ? substr(
                    $activity->title,
                    0,
                    50
                ) . '...' : $activity->title;

            $activity->id = $activity->moduleid;
            $activity->type = 'coursemodule';
            $activity->mod_icon = $OUTPUT->image_url('icon', "mod_{$activity->modulename}");
            if (!isset($activity->course_modules)) $activity->course_modules = [];
            $activity->module_is_disabled_on_site = $this->db->get_record('modules', [
                'name' => $activity->modulename,
                'visible' => false
            ]);
            $activity->locked = $activity->module_is_disabled_on_site || $canconfigurerestore === false;
        }

        $section->title = $this->item->get_name();
        $section->title = strlen($section->title) > 50 ? trim(
                substr($section->title, 0, 50)
            ) . '...' : $section->title;

        $section->id = $section->sectionid;
        $section->type = $this->item->get_type();
        $section->is_subsection = $this->item->is_subsection();
        $section->is_section = $this->item->is_section();

        $section->mod_icon = null;
        $section->course_modules = array_values($section->activities);
        $section->module_is_disabled_on_site = false;
        $section->locked = false;

        return [
            'can_configure_restore' => $this->can_configure_restore(),
            'user_msgs' => $this->get_user_msgs($section),
            'sections' => [
                $section
            ]
        ];
    }

    private function get_user_msgs(object $section) : array{
        $usermsgs = [];

        if ($this->is_subsection_imported_into_default_named_section($section)) {
            $usermsgs[] = get_string('import_subsection_into_default_named_section_warning', 'block_sharing_cart');
        }

        if (empty($section)) {
            $usermsgs[] = get_string('empty_section_restore', 'block_sharing_cart');
        }

        return $usermsgs;
    }

    private function is_subsection_imported_into_default_named_section(object $section) : bool{
        if (!isset($section)) return false;

        if ($this->item->is_subsection() && $this->clipboardtargetid !== 0) {
            try {
                $targetsectionname = $this->db->get_field('course_sections', 'name', ['id' => $this->clipboardtargetid], MUST_EXIST);
            } catch (\Exception $e) {
                return false;
            }
            // Default named sections will have a null name.
            if (!$targetsectionname) return true;
        }

        return false;
    }
}
