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

/**
 * restored_section.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\event;

/**
 * restored_section class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restored_section extends restore
{
    /**
     * get_table
     *
     * @return ?string
     */
    protected function get_table(): ?string {
        return 'course_sections';
    }

    /**
     * get_section_id
     *
     * @return int
     */
    public function get_section_id(): int {
        return $this->other['sectionid'] ?? 0;
    }

    /**
     * get_description
     *
     * @return string
     */
    public function get_description(): string {
        return "User with id {$this->relateduserid} has restored a section with id {$this->get_section_id()}"
            . " in course with id {$this->get_course_id()}"
            . " that takes {$this->get_duration()} seconds";
    }

    /**
     * create_by_section
     *
     * @param int $courseid
     * @param int $sectionid
     * @param int $userid
     * @param int $starttime
     * @param int $finishtime
     * @return static
     */
    public static function create_by_section(
        int $courseid,
        int $sectionid,
        int $userid,
        int $starttime = 0,
        int $finishtime = 0
    ): \core\event\base {
        return self::create([
            'objectid' => $sectionid,
            'context' => \core\context\course::instance($courseid),
            'relateduserid' => $userid,
            'other' => [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
                'starttime' => $starttime,
                'finishtime' => $finishtime,
            ],
        ]);
    }
}
