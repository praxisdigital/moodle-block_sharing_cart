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
 * Class event\base for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * @method static static create(array $data = null)
 */
abstract class base extends \core\event\base {
    public const CRUD_CREATE = 'c';

    abstract protected function get_crud(): string;

    protected function get_table(): ?string {
        return 'files';
    }

    protected function init() {
        $table = $this->get_table();
        if (!empty($table)) {
            $this->data['objecttable'] = $table;
        }
        $this->data['edulevel'] = static::LEVEL_PARTICIPATING;
        $this->data['crud'] = $this->get_crud();
    }
}
