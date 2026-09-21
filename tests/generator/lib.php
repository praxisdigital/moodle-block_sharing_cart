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
 * lib.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use block_sharing_cart\app\item\entity;


/**
 * block_sharing_cart_generator class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_sharing_cart_generator extends testing_data_generator
{
    /** @var array $createditemsidshistory */
    private array $createditemsidshistory = [];

    /**
     * create_sharing_cart_item
     *
     * @param array $sharingcartitem
     * @return void
     */
    public function create_sharing_cart_item(array $sharingcartitem): void {
        global $DB;

        $time = time();
        $item = [
            'user_id' => $sharingcartitem['user_id'],
            'file_id' => null,
            'parent_item_id' => empty($sharingcartitem['parent_item_name'])
                ? null
                : $this->createditemsidshistory[$sharingcartitem['parent_item_name']],
            'old_instance_id' => 1,
            'type' => $sharingcartitem['type'],
            'name' => $sharingcartitem['name'],
            'status' => 1,
            'version' => 3,
            'timecreated' => $time,
            'timemodified' => $time,
        ];

        $this->createditemsidshistory[$sharingcartitem['name']] = $DB->insert_record('block_sharing_cart_items', $item, true);
    }
}
