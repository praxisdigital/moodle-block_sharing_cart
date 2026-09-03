<?php

namespace block_sharing_cart\output\modal;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;

global $CFG;
require_once($CFG->dirroot . '/course/format/lib.php');

class import_item_modal_body implements \renderable, \core\output\named_templatable
{
    private base_factory $base_factory;
    private entity $item;
    private int $clipboard_target_id = 0;
    private bool $as_new_section = false;
    private \moodle_database $db;

    public function __construct(
        base_factory $base_factory,
        entity $item,
        int $clipboard_target_id,
        bool $as_new_section = false
    ) {
        $this->base_factory = $base_factory;
        $this->item = $item;
        $this->clipboard_target_id = $clipboard_target_id;
        $this->as_new_section = $as_new_section;
        $this->db = $this->base_factory->moodle()->db();
    }

    /**
     * When a section merges into an existing one, core keeps the target's title and description unless they are
     * empty. Offer to replace them when the target actually has something to replace.
     */
    private function get_replace_section_details_context(): array
    {
        $context = ['can_replace_section_details' => false, 'target_section_name' => ''];

        if ($this->as_new_section || !$this->item->is_section() || $this->clipboard_target_id <= 0) {
            return $context;
        }

        $target_section = $this->db->get_record('course_sections', ['id' => $this->clipboard_target_id]);
        if (!$target_section) {
            return $context;
        }

        $has_name = (string)$target_section->name !== '';
        $has_summary = trim(strip_tags((string)$target_section->summary)) !== ''
            || str_contains((string)$target_section->summary, '<img');

        $context['can_replace_section_details'] = $has_name || $has_summary;
        $context['target_section_name'] = format_string(
            course_get_format($target_section->course)->get_section_name($target_section)
        );

        return $context;
    }

    public function get_template_name(\renderer_base $renderer): string
    {
        return 'block_sharing_cart/modal/import_item_modal_body';
    }

    private function can_configure_restore(): bool
    {
        $page = $this->base_factory->moodle()->page();

        return has_capability('moodle/restore:configure', $page->context);
    }

    /**
     * The tree is built from the cart items below the restored item (any depth): nested sections, core subsections
     * and activities. Checkboxes carry the item's original id and type so the block script can split them into
     * course_modules_to_include and sections_to_include.
     */
    public function export_for_template(\renderer_base $output): array
    {
        $can_configure_restore = $this->can_configure_restore();

        $children_by_parent = [];
        foreach ($this->base_factory->item()->repository()->get_recursively_by_parent_id($this->item->get_id()) as $entity) {
            if ($entity->get_parent_item_id() === null) {
                continue;
            }
            $children_by_parent[$entity->get_parent_item_id()][] = $entity;
        }
        foreach ($children_by_parent as &$siblings) {
            usort($siblings, static function (entity $a, entity $b): int {
                return (($a->get_sortorder() ?? 0) <=> ($b->get_sortorder() ?? 0)) ?: ($a->get_id() <=> $b->get_id());
            });
        }
        unset($siblings);

        $section = $this->export_node($this->item, $children_by_parent, $output, $can_configure_restore);

        // The restored item itself is always included; it is the header of the form.
        $section->is_subsection = $this->item->is_subsection();
        $section->is_section = $this->item->is_section();
        $section->mod_icon = null;
        $section->module_is_disabled_on_site = false;
        $section->locked = false;

        return [
            'can_configure_restore' => $can_configure_restore,
            'user_msgs' => $this->get_user_msgs($section),
            'sections' => [
                $section
            ]
        ] + $this->get_replace_section_details_context();
    }

    private function export_node(
        entity $item,
        array $children_by_parent,
        \renderer_base $output,
        bool $can_configure_restore
    ): object {
        $is_module = $item->is_module();

        $module_is_disabled_on_site = false;
        if ($is_module) {
            $module_is_disabled_on_site = (bool)$this->db->get_record('modules', [
                'name' => str_replace('mod_', '', $item->get_type()),
                'visible' => false
            ]);
        }

        $node = (object)[
            'id' => $item->get_old_instance_id(),
            'title' => $this->truncate(format_string($item->get_name())),
            'type' => $item->is_section() ? 'section' : 'coursemodule',
            'is_section' => $item->is_section(),
            'is_subsection' => $item->is_subsection(),
            'mod_icon' => $is_module ? $output->image_url('icon', $item->get_type()) : null,
            'module_is_disabled_on_site' => $module_is_disabled_on_site,
            'locked' => $module_is_disabled_on_site || $can_configure_restore === false,
            'course_modules' => [],
        ];

        foreach ($children_by_parent[$item->get_id()] ?? [] as $child) {
            $node->course_modules[] = $this->export_node($child, $children_by_parent, $output, $can_configure_restore);
        }

        return $node;
    }

    private function truncate(string $title): string
    {
        return strlen($title) > 50 ? trim(substr($title, 0, 50)) . '...' : $title;
    }

    private function get_user_msgs(object $section) : array{

        $user_msgs = [];

        if($this->is_subsection_imported_into_default_named_section($section)){
            $user_msgs[] = get_string('import_subsection_into_default_named_section_warning','block_sharing_cart');
        }

        if(empty($section->course_modules)){
            $user_msgs[] = get_string('empty_section_restore','block_sharing_cart');
        }

        return $user_msgs;

    }

    private function is_subsection_imported_into_default_named_section(object $section) : bool{

        if(!isset($section)) return false;

        if($this->item->is_subsection() && $this->clipboard_target_id !== 0){

            try{
                $target_section_name = $this->db->get_field('course_sections', 'name', ['id' => $this->clipboard_target_id],MUST_EXIST);
            }catch(\Exception $e){
                return false;
            }
            //Default named sections will have a null name.
            if(!$target_section_name) return true;

        }

        return false;

    }

}
