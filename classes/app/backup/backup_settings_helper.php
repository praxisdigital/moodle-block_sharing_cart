<?php

namespace block_sharing_cart\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\item\entity;
use block_sharing_cart\app\factory as base_factory;
use format_theunittest\output\courseformat\state\course;

class backup_settings_helper
{
    private base_factory $base_factory;
    private backup_settings_queries $backup_settings_repository;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
        $this->backup_settings_repository = $base_factory->backup()->settings_repository();
    }

    public function construct_backup_plan_settings(mixed $custom_data, \core\context $backup_controller_context, false|entity $item_entity) : array{

        if(!$item_entity) {
            throw new \Exception("Item entity not specified. Could not construct backup plan settings.");
        }

        //Base settings
        $backup_plan_settings = [
            'role_assignments' => false,
            'activities' => true,
            'blocks' => false,
            'filters' => false,
            'comments' => false,
            'calendarevents' => false,
            'userscompletion' => false,
            'logs' => false,
            'grade_histories' => false,
            'users' => false,
            'anonymize' => false,
            'badges' => false,
            'filename' => 'sharing_cart_backup-' . $item_entity->get_id() . '.mbz'
        ];

        if ($custom_data->backup_settings->users) {
            require_capability('moodle/backup:userinfo', $backup_controller_context);
            $backup_plan_settings['users'] = true;
        }

        if ($custom_data->backup_settings->anonymize && $backup_plan_settings['users']) {
            require_capability('moodle/backup:anonymise', $backup_controller_context);
            $backup_plan_settings['anonymize'] = true;
        }

        $section_id = $this->get_section_id($item_entity);

        //Returns all course modules with the same course number as $item_entity.
        $course_modules = $this->backup_settings_repository->get_course_modules_by_section_id($section_id);

        //Returns all sections with the same course number as $item_entity.
        $course_sections = $this->backup_settings_repository->get_course_sections_by_section_id($section_id);

        //Add module settings
        $backup_plan_settings += $this->get_course_module_settings($course_modules, $item_entity, $section_id, $backup_plan_settings['users']);
        //Add section settings
        $backup_plan_settings += $this->get_section_settings($course_sections, $section_id, $backup_plan_settings['users']);

        return $backup_plan_settings;
    }

    public function apply_backup_plan_settings(array $backup_plan_settings,\backup_plan $backup_plan){

        foreach ($backup_plan_settings as $name => $value) {

            if ($backup_plan->setting_exists($name)) {

                $setting = $backup_plan->get_setting($name);

                if (\base_setting::NOT_LOCKED !== $setting->get_status()) {
                    continue;
                }

                $setting->set_value($value);
            }

        }

    }

    private function get_section_id(entity $item_entity): string
    {

        if($item_entity->get_type() == $item_entity::TYPE_SECTION || $item_entity->get_type() == $item_entity::TYPE_MOD_SUBSECTION) {
            return $item_entity->old_instance_id;
        }

        return $this->base_factory->moodle()->db()->get_record(
            'course_modules',
            ['id' => $item_entity->old_instance_id],
            'section',
            MUST_EXIST
        )->section;

    }

    private function get_section_settings(array $sections, int $section_id, bool $include_users): array
    {
        $settings = [];

        foreach ($sections as $section){
            $settings["section_".$section->id."_userinfo"] = false;
            $settings["section_".$section->id."_included"] = false;
        }

        $settings["section_".$section_id."_userinfo"] = $include_users;
        $settings["section_".$section_id."_included"] = true;

        return $settings;
    }

    private function get_course_module_settings(
        array $course_modules,
        entity $item_entity,
        int $section_id,
        bool $include_users
    ): array
    {
        $settings = [];

        foreach($course_modules as $course_module) {
            //Include all immediate child modules of section(section_id) in the backup plan settings.
            $settings = [...$settings, ...$this->set_setting($course_module->name,
                $course_module->id,
                $course_module->section == $section_id,
                ($course_module->section == $section_id) ? $include_users : false)];
        }

        $immediate_child_modules = $this->backup_settings_repository->get_immediate_child_modules_of_section($section_id);
        mtrace(print_r($immediate_child_modules, true));

        $child_module_ids = [];
        foreach($immediate_child_modules as $immediate_child_module) {

            //Include the section (The corresponding section of the subsection module, must be included.)
            $settings = [...$settings, ...$this->set_setting("section",$immediate_child_module->section_id,true,$include_users)];

            if(!empty($immediate_child_module->child_module_ids)){
                //Add the module ids of the childrens child modules.
                $child_module_ids = array_merge(
                    $child_module_ids,
                    explode(',', $immediate_child_module->child_module_ids)
                );

            }
        }

        $subsection_child_modules = array_filter($course_modules, function($course_module) use($child_module_ids) {
            return in_array($course_module->id, $child_module_ids);
        });

        mtrace(print_r($subsection_child_modules, true));

        //Include all subsections, child modules.
        foreach($subsection_child_modules as $subsection_child_module) {
            $settings = [...$settings, ...$this->set_setting($subsection_child_module->name,$subsection_child_module->id,true,$include_users)];
        }


        //Subsection's parent section must be included for the backup to work regardless of backup type.
        if($item_entity->get_type() == $item_entity::TYPE_MOD_SUBSECTION){

            $subsection_info = $this->backup_settings_repository->get_mod_subsection_info($section_id);

            if(empty($subsection_info)){
                //ERROR
            }

            $parent_section_id = $subsection_info[array_key_first($subsection_info)]->parent_section_id;
            $own_module_id = $subsection_info[array_key_first($subsection_info)]->own_module_id;

            //Include the subsections parent section id (A course section).
            $settings = [...$settings, ...$this->set_setting("section",$parent_section_id,true,$include_users)];

            //The subsection's own module id must also be included.
            $settings = [...$settings, ...$this->set_setting("subsection",$own_module_id,true,$include_users)];

        }

        mtrace(print_r($settings, true));
        return $settings;
    }

    private function set_setting(string $setting_name, string $setting_id, bool $setting_value, bool $include_users) : array{
        return [
            $setting_name."_".$setting_id."_userinfo" => $include_users,
            $setting_name."_".$setting_id."_included" => $setting_value
        ];
    }

}