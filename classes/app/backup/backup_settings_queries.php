<?php

namespace block_sharing_cart\app\backup;

use block_sharing_cart\app\factory as base_factory;

class backup_settings_queries
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    public function get_course_sections_by_section_id(int $section_id): array
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

    public function get_course_modules_by_section_id(int $section_id): array
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

    /**
     * Returns the immediate child modules of a course section.
     *
     * On Moodle versions prior to 4.5, this method always returns an empty array.
     *
     * @param int $section_id Course section ID
     * @return array List of child modules, or an empty array if unsupported
     */
    public function get_immediate_child_modules_of_section(int $section_id): array
    {
        // query is not supported until moodle 4.5+
        if(get_config('core', 'version') < 2024100700){
            mtrace("Tried querying database for immediate child modules of a section. Moodle version is too low for this call. Returning empty array.");
            return [];
        }

        $db = $this->base_factory->moodle()->db();

        $sql = "WITH immediate_module_children AS (SELECT cm.id AS module_id, cm.section AS parent_section_id, m.name, cm.instance, cs.course
        FROM {course_sections} AS cs
        JOIN {course_modules} AS cm ON cm.section = cs.id AND cm.section = cs.id
        JOIN {modules} AS m ON m.id = cm.module)

        SELECT 
               imc.module_id,
               imc.parent_section_id,
               cs2.id AS section_id ,
               cs2.sequence AS child_module_ids
        FROM immediate_module_children AS imc
        LEFT JOIN {course_sections} AS cs2 ON imc.instance = cs2.itemid AND cs2.course = imc.course
        WHERE imc.parent_section_id = :section_id 
        ";
        $params = [
            'section_id' => $section_id,
        ];

        return $db->get_records_sql($sql, $params);
    }

    public function get_mod_subsection_info(int $subsection_section_id): array
    {
        $db = $this->base_factory->moodle()->db();

        $sql = "SELECT cm.section AS parent_section_id, cm.id AS own_module_id
                FROM {course_sections} AS cs
                JOIN {course_modules} AS cm ON cs.itemid = cm.instance
                JOIN {modules} AS m ON cm.module = m.id
                WHERE cs.id = :subsection_section_id AND m.name = 'subsection'
        ";
        $params = [
            'subsection_section_id' => $subsection_section_id,
        ];

        return $db->get_records_sql($sql, $params);
    }

}