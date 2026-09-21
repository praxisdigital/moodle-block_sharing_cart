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
 * services.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

$functions = [
    // Backup.
    'block_sharing_cart_backup_course_module_into_sharing_cart' => [
        'classname' => \block_sharing_cart\external\backup\course_module_into_sharing_cart::class,
        'methodname' => 'execute',
        'description' => 'Takes a course module id and creates a sharing cart backup.' .
            ' Returns the item placeholder sharing cart item',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],
    'block_sharing_cart_backup_section_into_sharing_cart' => [
        'classname' => \block_sharing_cart\external\backup\section_into_sharing_cart::class,
        'methodname' => 'execute',
        'description' => 'Takes a section id and creates a sharing cart backup. Returns the item placeholder sharing cart item',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],

    // Restore.
    'block_sharing_cart_restore_item_from_sharing_cart_into_section' => [
        'classname' => \block_sharing_cart\external\restore\item_into_section::class,
        'methodname' => 'execute',
        'description' => 'Takes a sharing cart item and restores it into a section',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],

    'block_sharing_cart_reorder_sharing_cart_items' => [
        'classname' => \block_sharing_cart\external\item\reorder_sharing_cart_items::class,
        'methodname' => 'execute',
        'description' => 'Reorder sharing cart items',
        'type' => 'write',
        'readonlysession' => false,
        'ajax' => true,
        'capabilities' => '',
    ],
    'block_sharing_cart_delete_item_from_sharing_cart' => [
        'classname' => \block_sharing_cart\external\item\delete_item_from_sharing_cart::class,
        'methodname' => 'execute',
        'description' => 'Deletes an item from the sharing cart',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],
    'block_sharing_cart_delete_items_from_sharing_cart' => [
        'classname' => \block_sharing_cart\external\item\delete_items_from_sharing_cart::class,
        'methodname' => 'execute',
        'description' => 'Deletes items from the sharing cart',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],
    'block_sharing_cart_get_item_from_sharing_cart' => [
        'classname' => \block_sharing_cart\external\item\get_item_from_sharing_cart::class,
        'methodname' => 'execute',
        'description' => 'Get an item from the sharing cart',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    'block_sharing_cart_run_task_now' => [
        'classname' => \block_sharing_cart\external\task\run_now::class,
        'methodname' => 'execute',
        'description' => 'Run a block_sharing_cart task now',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => false,
        'capabilities' => '',
    ],
];
