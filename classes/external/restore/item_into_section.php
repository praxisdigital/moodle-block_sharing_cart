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

namespace block_sharing_cart\external\restore;


// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * item_into_section external API.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_into_section extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'item_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'section_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'course_modules_to_include' => new external_multiple_structure(
                new external_value(PARAM_INT, '', VALUE_REQUIRED)
            ),
        ]);
    }

    /**
     * execute.
     * @param int $itemid
     * @param int $sectionid
     * @param array $coursemodulestoinclude
     */
    public static function execute(
        int $itemid,
        int $sectionid,
        array $coursemodulestoinclude
    ): bool {
        global $USER, $DB;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'item_id' => $itemid,
            'section_id' => $sectionid,
            'course_modules_to_include' => $coursemodulestoinclude,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        $item = $basefactory->item()->repository()->get_by_id($params['item_id']);
        if (!$item) {
            return false;
        }

        if ($item->get_user_id() !== (int)$USER->id) {
            return false;
        }

        $courseid = (int)$DB->get_field('course_sections', 'course', ['id' => $params['section_id']], MUST_EXIST);
        $context = \core\context\course::instance($courseid);

        $settings = [];

        // Only pass include/exclude list when the user can configure restore in the target course context.
        if (has_capability('moodle/restore:configure', $context)) {
            $settings['course_modules_to_include'] = $params['course_modules_to_include'] ?? [];
        }

        $result = $basefactory->restore()->handler()->restore_item_into_section(
            $item,
            $params['section_id'],
            $params['item_id'],
            $settings
        );

        return $result !== null;
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_value(PARAM_BOOL, '', VALUE_REQUIRED);
    }
}
