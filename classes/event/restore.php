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
 * restore.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\event;

/**
 * restore class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class restore extends base
{
    /**
     * get_table
     *
     * @return ?string
     */
    protected function get_table(): ?string {
        return null;
    }

    /**
     * get_crud
     *
     * @return string
     */
    protected function get_crud(): string {
        return self::CRUD_CREATE;
    }

    /**
     * get_course_id
     *
     * @return int
     */
    public function get_course_id(): int {
        return $this->other['courseid'] ?? 0;
    }

    /**
     * get_start_time
     *
     * @return int
     */
    public function get_start_time(): int {
        return $this->other['starttime'] ?? 0;
    }

    /**
     * get_finish_time
     *
     * @return int
     */
    public function get_finish_time(): int {
        return $this->other['finishtime'] ?? 0;
    }

    /**
     * get_duration
     *
     * @return int
     */
    public function get_duration(): int {
        return $this->get_finish_time() - $this->get_start_time();
    }
}
