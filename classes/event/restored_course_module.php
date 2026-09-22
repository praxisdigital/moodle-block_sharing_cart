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
 * Class event\restored_course_module for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restored_course_module extends restore {
    protected function get_table(): ?string {
        return 'course_modules';
    }

    public function get_course_module_id(): int {
        return $this->other['cmid'] ?? 0;
    }

    public function get_description(): string {
        return "User with id {$this->relateduserid} has restored"
            . " a course module with id {$this->get_course_module_id()}"
            . " in the course with id {$this->get_course_id()}"
            . " that takes {$this->get_duration()} seconds";
    }

    public static function create_by_course_module(
        int $courseid,
        int $coursemoduleid,
        string $type,
        int $userid,
        int $starttime = 0,
        int $finishtime = 0
    ): static {
        return static::create([
            'objectid' => $coursemoduleid,
            'context' => \core\context\module::instance($coursemoduleid),
            'relateduserid' => $userid,
            'other' => [
                'courseid' => $courseid,
                'cmid' => $coursemoduleid,
                'module' => $type,
                'starttime' => $starttime,
                'finishtime' => $finishtime
            ],
        ]);
    }
}
