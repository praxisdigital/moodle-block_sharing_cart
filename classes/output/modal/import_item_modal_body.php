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

    public function __construct(base_factory $base_factory, entity $item)
    {
        $this->base_factory = $base_factory;
        $this->item = $item;
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
        $db = $this->base_factory->moodle()->db();

        $can_configure_restore = $this->can_configure_restore();
        $section = array_values(
            $this->base_factory->backup()->handler()->get_backup_item_tree(
                $this->base_factory->item()->repository()->get_stored_file_by_item($this->item)
            )
        )[0];

        

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
                    $subsection_activity->module_is_disabled_on_site = $db->get_record('modules', [
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
                $activity->module_is_disabled_on_site = $db->get_record('modules', [
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
        $section->type = isset($section->type) ? $section->modulename : 'section';

        $section->mod_icon = null;
        $section->course_modules = array_values($section->activities);
        $section->module_is_disabled_on_site = false;
        $section->locked = false;

        unset($section->sectionid, $section->activities);

        return [
            'can_configure_restore' => $this->can_configure_restore(),
            'sections' => [
                $section
            ],
        ];
    }

    public function export_for_template_test(): array
    {
        $db = $this->base_factory->moodle()->db();

        $can_configure_restore = $this->can_configure_restore();
        $section = array_values(
            $this->base_factory->backup()->handler()->get_backup_item_tree(
                $this->base_factory->item()->repository()->get_stored_file_by_item($this->item)
            )
        )[0];

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
                    $subsection_activity->mod_icon = "";
                    $subsection_activity->module_is_disabled_on_site = $db->get_record('modules', [
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
                $activity->mod_icon = "";
                if(!isset($activity->course_modules)) $activity->course_modules = [];
                $activity->module_is_disabled_on_site = $db->get_record('modules', [
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
        $section->type = isset($section->type) ? $section->modulename : 'section';

        $section->mod_icon = null;
        $section->course_modules = array_values($section->activities);
        $section->module_is_disabled_on_site = false;
        $section->locked = false;

        unset($section->sectionid, $section->activities);

        return [
            'can_configure_restore' => $this->can_configure_restore(),
            'sections' => [
                $section
            ],
        ];
    }
}
