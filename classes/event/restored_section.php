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
 * Class event\restored_section for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restored_section extends restore {
    protected function get_table(): ?string {
        return 'course_sections';
    }

    public function get_section_id(): int {
        return $this->other['sectionid'] ?? 0;
    }

    public function get_description(): string {
        return "User with id {$this->relateduserid} has restored a section with id {$this->get_section_id()}"
            . " in course with id {$this->get_course_id()}"
            . " that takes {$this->get_duration()} seconds";
    }

    public static function create_by_section(
        int $courseid,
        int $sectionid,
        int $userid,
        int $starttime = 0,
        int $finishtime = 0
    ): static {
        return static::create([
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
