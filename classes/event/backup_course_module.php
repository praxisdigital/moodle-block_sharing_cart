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
 * backup_course_module.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\event;

/**
 * backup_course_module class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_course_module extends backup
{
    /**
     * get_course_module_id
     *
     * @return int
     */
    public function get_course_module_id(): int {
        return $this->other['cmid'] ?? 0;
    }

    /**
     * get_description
     *
     * @return string
     */
    public function get_description(): string {
        return "User with id {$this->relateduserid} has backed up"
            . " a course module with id {$this->get_course_module_id()}"
            . " in the course with id {$this->get_course_id()}.";
    }

    /**
     * create_by_course_module
     *
     * @param int $courseid
     * @param int $coursemoduleid
     * @param int $userid
     * @return static
     */
    public static function create_by_course_module(
        int $courseid,
        int $coursemoduleid,
        int $userid
    ): static {
        global $USER;

        $userid ??= $USER->id;

        return static::create([
            'context' => \core\context\user::instance($userid),
            'relateduserid' => $userid,
            'other' => [
                'courseid' => $courseid,
                'cmid' => $coursemoduleid,
            ],
        ]);
    }
}
