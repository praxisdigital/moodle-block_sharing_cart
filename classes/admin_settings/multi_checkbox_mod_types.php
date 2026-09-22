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
 * multi_checkbox_mod_types.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\admin_settings;

use block_sharing_cart\app\factory as basefactory;

/**
 * multi_checkbox_mod_types class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multi_checkbox_mod_types extends multi_checkbox_with_icon
{
    /**
     * __construct
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param ?array $defaultsetting
     */
    public function __construct(string $name, string $visiblename, string $description, ?array $defaultsetting = null) {
        $basefactory = basefactory::make();
        $db = $basefactory->moodle()->db();
        $output = $basefactory->moodle()->output();

        $choices = [];
        $icons = [];

        foreach ($db->get_records('modules', [], 'name ASC') as $module) {
            $choices[$module->name] = get_string('modulename', $module->name);
            $icons[$module->name] = ' ' . $output->pix_icon('icon', '', $module->name, ['class' => 'icon']);
        }

        parent::__construct($name, $visiblename, $description, $defaultsetting, $choices, $icons);
    }
}
