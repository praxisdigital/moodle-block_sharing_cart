<?php

namespace block_sharing_cart\output\modal;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;

class import_item_modal_body implements \renderable, \core\output\named_templatable
{
    private base_factory $base_factory;
    private entity $item;
    private int $clipboard_target_id = 0;
    private \moodle_database $db;

    public function __construct(base_factory $base_factory, entity $item, int $clipboard_target_id)
    {
        $this->base_factory = $base_factory;
        $this->item = $item;
        $this->clipboard_target_id = $clipboard_target_id;
        $this->db = $this->base_factory->moodle()->db();
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

    public function export_for_template(\renderer_base $output): array
    {
        $can_configure_restore = $this->can_configure_restore();
        $item_tree = array_values(
            $this->base_factory->backup()->handler()->get_backup_item_tree(
                $this->base_factory->item()->repository()->get_stored_file_by_item($this->item)
            )
        );

        $section = [];
        if(!empty($item_tree)) $section = $item_tree[array_key_first($item_tree)];

       foreach ($section->activities as $activity) {

            if($activity->modulename == "subsection"){

                foreach($activity->subsection_activities as $subsection_activity){

                    $subsection_activity->title = format_string($subsection_activity->title);
                    $subsection_activity->title = strlen($subsection_activity->title) > 50 ? substr(
                            $subsection_activity->title,
                            0,
                            50
                        ) . '...' : $subsection_activity->title;

                    $subsection_activity->id = $subsection_activity->moduleid;
                    $subsection_activity->type = 'coursemodule';
                    $subsection_activity->mod_icon = $output->image_url('icon', "mod_{$subsection_activity->modulename}");
                    $subsection_activity->module_is_disabled_on_site = $this->db->get_record('modules', [
                        'name' => $subsection_activity->modulename,
                        'visible' => false
                    ]);
                    $subsection_activity->locked = $subsection_activity->module_is_disabled_on_site || $can_configure_restore === false;
                    $subsection_activity->course_modules = [];
                }
                $activity->course_modules = $activity->subsection_activities;
                $activity->id = $activity->moduleid;
            }
            else {
                $activity->title = format_string($activity->title);
                $activity->title = strlen($activity->title) > 50 ? substr(
                        $activity->title,
                        0,
                        50
                    ) . '...' : $activity->title;

                $activity->id = $activity->moduleid;
                $activity->type = 'coursemodule';
                $activity->mod_icon = $output->image_url('icon', "mod_{$activity->modulename}");
                if(!isset($activity->course_modules)) $activity->course_modules = [];
                $activity->module_is_disabled_on_site = $this->db->get_record('modules', [
                    'name' => $activity->modulename,
                    'visible' => false
                ]);
                $activity->locked = $activity->module_is_disabled_on_site || $can_configure_restore === false;
            }

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

    private function get_user_msgs($section) : array{

        $user_msgs = [];

        if($this->is_subsection_imported_into_default_named_section($section)){
            $user_msgs[] = get_string('import_subsection_into_default_named_section_warning','block_sharing_cart');
        }

        if(empty($section)){
            $user_msgs[] = get_string('empty_section_restore','block_sharing_cart');
        }

        return $user_msgs;

    }

    private function is_subsection_imported_into_default_named_section($section) : bool{

        if(!isset($section)) return false;

        if($section->type == $this->item->is_subsection() && $this->clipboard_target_id != 0){

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
