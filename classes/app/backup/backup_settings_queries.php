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

use block_sharing_cart\app\factory as basefactory;

/**
 * Class app\backup\backup_settings_queries for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_settings_queries
{
    /** @var basefactory $basefactory */
    private basefactory $basefactory;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     */
    public function __construct(basefactory $basefactory) {
        $this->basefactory = $basefactory;
    }

    /**
     * get_course_sections_by_section_id
     *
     * @param int $sectionid
     * @return array
     */
    public function get_course_sections_by_section_id(int $sectionid): array {
        $db = $this->basefactory->moodle()->db();
        // Get all sections in the course.
        $sql = "SELECT cs.id, cs.sequence
                   FROM {course_sections} cs
                  WHERE cs.course = (SELECT cs.course
                                       FROM {course_sections} cs
                                      WHERE cs.id = :section_id)";
        $params = [
            'section_id' => $sectionid,
        ];

        return $db->get_records_sql($sql, $params);
    }

    /**
     * get_course_modules_by_section_id
     *
     * @param int $sectionid
     * @return array
     */
    public function get_course_modules_by_section_id(int $sectionid): array {
        $db = $this->basefactory->moodle()->db();
        // Get all course_modules within course by section_id.
        $sql = "SELECT cm.id, cm.section, m.name
                FROM {course_modules} cm
                JOIN {modules} m ON cm.module = m.id
                WHERE cm.course = (SELECT cs.course
                                   FROM {course_sections} cs
                                   WHERE cs.id = :section_id)";
        $params = [
            'section_id' => $sectionid,
        ];

        return $db->get_records_sql($sql, $params);
    }

    /**
     * Returns the immediate child modules of a course section.
     *
     * On Moodle versions prior to 4.5, this method always returns an empty array.
     *
     * @param int $sectionid Course section ID
     * @return array List of child modules, or an empty array if unsupported
     */
    public function get_immediate_child_modules_of_section(int $sectionid): array {
        // Query is not supported until Moodle 4.5+.
        if (get_config('core', 'version') < 2024100700) {
            mtrace(
                'Tried querying database for immediate child modules of a section. ' .
                'Moodle version is too low for this call. Returning empty array.'
            );
            return [];
        }

        $db = $this->basefactory->moodle()->db();

        $sql = "WITH immediate_module_children AS
        (SELECT
            cm.id AS module_id,
            cm.section AS parent_section_id,
            m.name,
            cm.instance,
            cs.course
        FROM {course_sections} cs
        JOIN {course_modules} cm ON cm.section = cs.id AND cm.section = cs.id
        JOIN {modules} m ON m.id = cm.module)

        SELECT
               imc.module_id,
               imc.parent_section_id,
               cs2.id AS section_id,
               cs2.sequence AS child_module_ids
        FROM immediate_module_children imc
        LEFT JOIN {course_sections} cs2 ON imc.instance = cs2.itemid AND cs2.course = imc.course
        WHERE imc.parent_section_id = :section_id
        ";
        $params = [
            'section_id' => $sectionid,
        ];

        return $db->get_records_sql($sql, $params);
    }

    /**
     * get_mod_subsection_info
     *
     * @param int $subsectionsectionid
     * @return array
     */
    public function get_mod_subsection_info(int $subsectionsectionid): array {
        $db = $this->basefactory->moodle()->db();

        $sql = "SELECT cm.section AS parent_section_id, cm.id AS own_module_id
                FROM {course_sections} cs
                JOIN {course_modules} cm ON cs.itemid = cm.instance
                JOIN {modules} m ON cm.module = m.id
                WHERE cs.id = :subsection_section_id AND m.name = 'subsection'
        ";
        $params = [
            'subsection_section_id' => $subsectionsectionid,
        ];

        return $db->get_records_sql($sql, $params);
    }
}
