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
 * multi_checkbox_q_types.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\admin_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * multi_checkbox_q_types class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multi_checkbox_q_types extends multi_checkbox_with_icon
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
        global $OUTPUT;

        $choices = [];
        $icons = [];
        $qtypes = \question_bank::get_all_qtypes();

        // Some qtypes do not need workaround.
        unset($qtypes['missingtype'], $qtypes['random']);

        $qtypenames = array_map(static function (\question_type $qtype) {
            return $qtype->local_name();
        }, $qtypes);
        foreach (\question_bank::sort_qtype_array($qtypenames) as $qtypename => $label) {
            $choices[$qtypename] = $label;
            $icons[$qtypename] = ' ' . $OUTPUT->pix_icon('icon', '', $qtypes[$qtypename]->plugin_name()) . ' ';
        }
        parent::__construct($name, $visiblename, $description, $defaultsetting, $choices, $icons);
    }
}
