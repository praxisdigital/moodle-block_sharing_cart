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
 * Admin settings for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

if ($ADMIN->fulltree) {
    $settings->add(
        new admin_setting_configcheckbox(
            'block_sharing_cart/show_sharing_cart_basket',
            get_string('settings:show_sharing_cart_basket', 'block_sharing_cart'),
            get_string('settings:show_sharing_cart_basket_desc', 'block_sharing_cart'),
            1,
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'block_sharing_cart/show_copy_section_in_block',
            get_string('settings:show_copy_section_in_block', 'block_sharing_cart'),
            get_string('settings:show_copy_section_in_block_desc', 'block_sharing_cart'),
            false
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'block_sharing_cart/backup_async_message_users',
            new lang_string('asyncemailenable', 'backup'),
            new lang_string('asyncemailenabledetail', 'backup'),
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'block_sharing_cart/show_copies_queued_segment_when_empty',
            get_string('settings:show_copies_queued_segment_when_empty', 'block_sharing_cart'),
            get_string('settings:show_copies_queued_segment_when_empty_desc', 'block_sharing_cart'),
            1
        )
    );
}
