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
 * user_deleted.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\event;

use block_sharing_cart\app\factory;

/**
 * user_deleted class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_deleted
{
    /**
     * execute
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function execute(\core\event\user_deleted $event): void {
        $userid = $event->objectid;

        $basefactory = factory::make();
        $items = $basefactory->item()->repository()->get_by_user_id($userid);

        foreach ($items as $item) {
            $basefactory->item()->repository()->delete_by_id($item->get_id());
        }
    }
}
