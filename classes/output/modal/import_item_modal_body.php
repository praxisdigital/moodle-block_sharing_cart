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

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\item\entity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/format/lib.php');

/**
 * Class output\modal\import_item_modal_body for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_item_modal_body implements \core\output\named_templatable, \renderable {
    /** @var basefactory $basefactory */
    private basefactory $basefactory;
    /** @var entity $item */
    private entity $item;
    /** @var int $clipboardtargetid */
    private int $clipboardtargetid = 0;
    /** @var bool $asnewsection whether the item is inserted as a new section (no merge, so nothing to replace) */
    private bool $asnewsection = false;
    /** @var \moodle_database $db */
    private \moodle_database $db;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     * @param entity $item
     * @param int $clipboardtargetid
     * @param bool $asnewsection
     */
    public function __construct(
        basefactory $basefactory,
        entity $item,
        int $clipboardtargetid,
        bool $asnewsection = false
    ) {
        $this->basefactory = $basefactory;
        $this->item = $item;
        $this->clipboardtargetid = $clipboardtargetid;
        $this->asnewsection = $asnewsection;
        $this->db = $this->basefactory->moodle()->db();
    }

    /**
     * When a section merges into an existing one, core keeps the target's title and description unless they are
     * empty. Offer to replace them when the target actually has something to replace.
     *
     * @return array
     */
    private function get_replace_section_details_context(): array {
        $sourcename = '';
        if ($this->item->is_section()) {
            $file = $this->basefactory->item()->repository()->get_stored_file_by_item($this->item);
            if ($file) {
                $sourcename = $this->basefactory->backup()->handler()->get_section_custom_name(
                    $file,
                    (int)$this->item->get_old_instance_id()
                ) ?? '';
            }
        }
        $context = [
            'can_replace_section_details' => false,
            'target_section_name' => '',
            'restore_target_name' => '',
            'restore_source_name' => html_to_text(format_string($sourcename), 0, false),
            'restore_replacement_name' => html_to_text(format_string($sourcename), 0, false),
        ];

        if ($this->asnewsection) {
            $context['restore_target_name'] = $context['restore_source_name'];
            if ($context['restore_target_name'] === '') {
                $coursecontext = $this->basefactory->moodle()->page()->context->get_course_context(false);
                $courseid = $this->clipboardtargetid > 0
                    ? (int)$this->db->get_field('course_sections', 'course', ['id' => $this->clipboardtargetid])
                    : (int)($coursecontext->instanceid ?? 0);
                $context['restore_target_name'] = html_to_text(format_string(
                    $this->basefactory->restore()->section_planner()->new_section_destination_name(
                        $courseid,
                        $this->clipboardtargetid
                    )
                ), 0, false);
            }
            return $context;
        }

        if ($this->clipboardtargetid <= 0) {
            return $context;
        }

        $targetsection = $this->db->get_record('course_sections', ['id' => $this->clipboardtargetid]);
        if (!$targetsection) {
            return $context;
        }

        $targetformat = course_get_format($targetsection->course);
        $formattedtargetname = format_string($targetformat->get_section_name($targetsection));
        $context['target_section_name'] = $formattedtargetname;
        $context['restore_target_name'] = html_to_text($formattedtargetname, 0, false);
        $hasname = (string)$targetsection->name !== '';
        // Only a custom title from the backup can rename an unnamed merge target, never the cart's generated label.
        if (!$hasname && $sourcename !== '' && $this->item->is_section()) {
            $context['restore_target_name'] = $context['restore_source_name'];
        }

        if (!$this->item->is_section()) {
            return $context;
        }

        if ($sourcename === '') {
            // Replacing with an unnamed source clears a custom target title, revealing its format-derived name.
            $context['restore_replacement_name'] = html_to_text(
                format_string($targetformat->get_default_section_name($targetsection)),
                0,
                false
            );
        }

        $hassummary = trim(strip_tags((string)$targetsection->summary)) !== ''
            || str_contains((string)$targetsection->summary, '<img');

        $context['can_replace_section_details'] = $hasname || $hassummary;

        return $context;
    }

    /**
     * get_template_name
     *
     * @param \renderer_base $renderer
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'block_sharing_cart/modal/import_item_modal_body';
    }

    /**
     * can_configure_restore
     *
     * @return bool
     */
    private function can_configure_restore(): bool {
        $page = $this->basefactory->moodle()->page();

        return has_capability('moodle/restore:configure', $page->context);
    }

    /**
     * The tree is built from the cart items below the restored item (any depth): nested sections, core subsections
     * and activities. Checkboxes carry the item's original id and type so the block script can split them into
     * course_modules_to_include and sections_to_include.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $canconfigurerestore = $this->can_configure_restore();

        $childrenbyparent = [];
        $descendants = $this->basefactory->item()->repository()->get_recursively_by_parent_id($this->item->get_id());
        foreach ($descendants as $entity) {
            if ($entity->get_parent_item_id() === null) {
                continue;
            }
            $childrenbyparent[$entity->get_parent_item_id()][] = $entity;
        }
        foreach ($childrenbyparent as &$siblings) {
            usort($siblings, static function (entity $a, entity $b): int {
                return (($a->get_sortorder() ?? 0) <=> ($b->get_sortorder() ?? 0)) ?: ($a->get_id() <=> $b->get_id());
            });
        }
        unset($siblings);

        $section = $this->export_node($this->item, $childrenbyparent, $output, $canconfigurerestore);

        // The restored item itself is always included; it is the header of the form.
        $section->is_subsection = $this->item->is_subsection();
        $section->is_section = $this->item->is_section();
        $section->mod_icon = null;
        $section->module_is_disabled_on_site = false;
        $section->locked = false;

        return [
            'can_configure_restore' => $canconfigurerestore,
            'user_msgs' => $this->get_user_msgs($section),
            'sections' => [
                $section,
            ],
        ] + $this->get_replace_section_details_context();
    }

    /**
     * Template context for one cart item and, recursively, the items below it.
     *
     * @param entity $item
     * @param array $childrenbyparent child entities keyed by parent item id, in display order
     * @param \renderer_base $output
     * @param bool $canconfigurerestore
     * @return object
     */
    private function export_node(
        entity $item,
        array $childrenbyparent,
        \renderer_base $output,
        bool $canconfigurerestore
    ): object {
        $ismodule = $item->is_module();

        $moduleisdisabledonsite = false;
        if ($ismodule) {
            $moduleisdisabledonsite = (bool)$this->db->get_record('modules', [
                'name' => str_replace('mod_', '', $item->get_type()),
                'visible' => false,
            ]);
        }

        $formattedtitle = format_string($item->get_name());
        $node = (object)[
            'id' => $item->get_old_instance_id(),
            'title' => $this->truncate($formattedtitle),
            'plain_title' => html_to_text($formattedtitle, 0, false),
            'type' => $item->is_section() ? 'section' : 'coursemodule',
            'is_section' => $item->is_section(),
            'is_subsection' => $item->is_subsection(),
            'is_container' => $item->is_section() || $item->is_subsection(),
            'mod_icon' => $ismodule ? $output->image_url('icon', $item->get_type()) : null,
            'module_is_disabled_on_site' => $moduleisdisabledonsite,
            'locked' => $moduleisdisabledonsite || $canconfigurerestore === false,
            'course_modules' => [],
        ];

        foreach ($childrenbyparent[$item->get_id()] ?? [] as $child) {
            $node->course_modules[] = $this->export_node($child, $childrenbyparent, $output, $canconfigurerestore);
        }

        return $node;
    }

    /**
     * Shortens a title for display in the modal.
     *
     * @param string $title
     * @return string
     */
    private function truncate(string $title): string {
        return strlen($title) > 50 ? trim(substr($title, 0, 50)) . '...' : $title;
    }

    /**
     * get_user_msgs
     *
     * @param object $section
     * @return array
     */
    private function get_user_msgs(object $section): array {
        $usermsgs = [];

        if (empty($section->course_modules)) {
            $usermsgs[] = get_string('empty_section_restore', 'block_sharing_cart');
        }

        return $usermsgs;
    }
}
