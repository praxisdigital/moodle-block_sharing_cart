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

namespace block_sharing_cart\event;

/**
 * Class event\backup_section for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_section extends backup {
    public function get_section_id(): int {
        return $this->other['sectionid'] ?? 0;
    }

    public function get_description(): string {
        return "User with id {$this->relateduserid} has backed up a section with id {$this->get_section_id()}"
            . " in course with id {$this->get_course_id()}";
    }

    public static function create_by_section(
        int $courseid,
        int $sectionid,
        int $userid
    ): static {
        return static::create([
            'context' => \core\context\user::instance($userid),
            'relateduserid' => $userid,
            'other' => [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
            ],
        ]);
    }
}
