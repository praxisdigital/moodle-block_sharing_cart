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

namespace block_sharing_cart\app;

/**
 * factory class
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory
{
    /**
     * make
     *
     * @return self
     */
    public static function make(): self {
        return new self();
    }

    /**
     * collection
     *
     * @param array $records
     * @return collection
     */
    public function collection(array $records = []): collection {
        return new collection($records);
    }

    /**
     * backup
     *
     * @return backup\factory
     */
    public function backup(): backup\factory {
        return new backup\factory($this);
    }

    /**
     * restore
     *
     * @return restore\factory
     */
    public function restore(): restore\factory {
        return new restore\factory($this);
    }

    /**
     * item
     *
     * @return item\factory
     */
    public function item(): item\factory {
        return new item\factory($this);
    }

    /**
     * moodle
     *
     * @return moodle\factory
     */
    public function moodle(): moodle\factory {
        return new moodle\factory($this);
    }
}
