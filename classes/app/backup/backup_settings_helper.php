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

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    public function get_course_settings_by_item(entity $item, bool $include_users): array
    {

        $settings = [];

        [$section_id, $course_module_id] = $this->get_ids_by_item($item);

        //Returns all course modules with the same course number as $item.
        $course_modules = $this->get_course_modules_by_section_id($section_id);
        //Returns all sections with the same course number as $item.
        $sections = $this->get_course_sections_by_section_id($section_id);

        //Add module settings
        $settings += $this->get_course_module_settings($course_modules, $item, $section_id, $include_users);
        //Add section settings
        $settings += $this->get_section_settings($sections, $section_id, $include_users);

        return $settings;
    }

    private function get_ids_by_item(entity $item): array
    {
        $course_module_id = null;

        if($item->type === 'section' || $item->type === 'mod_subsection') {
            return [$item->old_instance_id, $course_module_id];
        }
        $course_module_id = $item->old_instance_id;

        $section_id = $this->base_factory->moodle()->db()->get_record(
            'course_modules',
            ['id' => $course_module_id],
            'section',
            MUST_EXIST
        )->section;

        return [$section_id, $course_module_id];
    }

    private function get_course_sections_by_section_id(int $section_id): array
    {
        $db = $this->base_factory->moodle()->db();
        // Get all sections in the course.
        $sql = "SELECT cs.id, cs.sequence
                   FROM {course_sections} cs
                  WHERE cs.course = (SELECT cs.course
                                       FROM {course_sections} cs
                                      WHERE cs.id = :section_id)";
        $params =  [
            'section_id' => $section_id
        ];

        return $db->get_records_sql($sql, $params);
    }

    private function get_course_modules_by_section_id(int $section_id): array
    {
        $db = $this->base_factory->moodle()->db();
        // Get all course_modules within course by section_id
        $sql = "SELECT cm.id, cm.section, m.name
                FROM {course_modules} cm
                JOIN {modules} as m on cm.module = m.id
                WHERE cm.course = (SELECT cs.course
                                   FROM {course_sections} cs
                                   WHERE cs.id = :section_id)";
        $params = [
            'section_id' => $section_id
        ];

        return $db->get_records_sql($sql, $params);
    }

    private function get_immediate_child_modules_of_section(int $section_id): array
    {
        $db = $this->base_factory->moodle()->db();

        $sql = "WITH immediate_module_children AS (SELECT cm.id AS id, cm.section AS parent_section_id, m.name, cm.instance
        FROM {course_sections} AS cs
        JOIN {course_modules} AS cm ON cm.section = cs.id AND cm.section = cs.id
        JOIN {modules} AS m ON m.id = cm.module)

        SELECT 
               imc.id,
               imc.name,
               imc.parent_section_id,
               cs2.id AS section_id ,
               imc.instance,
               cs2.itemid,
               cs2.sequence AS child_module_ids
        FROM immediate_module_children AS imc
        JOIN {course_sections} AS cs2 ON imc.instance = cs2.itemid
        WHERE imc.parent_section_id = :section_id 
        ";
        $params = [
            'section_id' => $section_id
        ];

        return $db->get_records_sql($sql, $params);
    }

    private function get_parent_section_id(int $subsection_section_id): array
    {
        $db = $this->base_factory->moodle()->db();

        $sql = "SELECT cm.section AS parent_section_id
                FROM mdl_course_sections AS cs
                JOIN mdl_course_modules AS cm ON cs.itemid = cm.instance
                WHERE cs.id = :subsection_section_id AND cm.module = :module
        ";
        $params = [
            'subsection_section_id' => $subsection_section_id,
            'module' => "20"
        ];

        return $db->get_records_sql($sql, $params);
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
        entity $item,
        int $section_id,
        bool $include_users
    ): array
    {
        $settings = [];


        //REFACTOR THIS,
            // SET ALL SETTINGS TO FALSE PER DEFAULT, EXPLICITLY HERE.
            // THE SQL QUERY SHOULD OUTPUT THE SETTING NAME AND THE VALUE



        //WORKING WITH A section_id that points to a nested module, like a subsection or a book under a subsection
        // YOU MUST WALK BACKWARDS UP TO THE NEAREST REAL SECTION, and include all the way up.
        //TRY WITH CHANGING THE BACKUP TYPE TO ACTIVITY INSTEAD OF COURSE??

        foreach($course_modules as $course_module) {
            //Include all immediate child modules of section(section_id) in the backup plan settings.
            $settings[$course_module->name . "_" . $course_module->id . "_userinfo"] = ($course_module->section == $section_id) ? $include_users : false;
            $settings[$course_module->name . "_" . $course_module->id . "_included"] = $course_module->section == $section_id;
        }

        $immediate_child_modules = $this->get_immediate_child_modules_of_section($section_id);
        mtrace(print_r($immediate_child_modules, true));

        $child_module_ids = [];
        foreach($immediate_child_modules as $immediate_child_module) {

            //Include the section (The corresponding section of the subsection module, must be included.)
            $settings["section" . "_" . $immediate_child_module->section_id . "_userinfo"] = $include_users;
            $settings["section" . "_" . $immediate_child_module->section_id . "_included"] = true;

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
            $settings[$subsection_child_module->name . "_" . $subsection_child_module->id . "_userinfo"] = $include_users;
            $settings[$subsection_child_module->name . "_" . $subsection_child_module->id . "_included"] = true;
        }


        //Subsection's parent section must be included for the backup to work regardless of backup type.
        if($item->get_type() == "mod_subsection"){

            $parent_section = $this->get_parent_section_id($section_id);
            if(empty($parent_section)){
                //ERROR
            }

            $parent_section_id = $parent_section[array_key_first($parent_section)]->parent_section_id;

            $settings["section" . "_" . $parent_section_id . "_userinfo"] = $include_users;
            $settings["section" . "_" . $parent_section_id . "_included"] = true;

            //The subsection's own module id must also be included.
            $settings["subsection" . "_" . "24" . "_userinfo"] = $include_users;
            $settings["subsection" . "_" . "24" . "_included"] = true;
        }

        mtrace(print_r($settings, true));
        return $settings;
    }
}