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

namespace block_sharing_cart\external\item;


// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * delete_item_from_sharing_cart external API.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_item_from_sharing_cart extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'item_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
        ]);
    }

    /**
     * execute.
     * @param int $itemid
     */
    public static function execute(int $itemid): bool {
        global $USER;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'item_id' => $itemid,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        $item = $basefactory->item()->repository()->get_by_id($params['item_id']);
        if (!$item) {
            return true;
        }

        if ($item->get_user_id() !== (int)$USER->id) {
            return false;
        }

        return $basefactory->item()->repository()->delete_by_id($item->get_id());
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_value(PARAM_BOOL, 'Whether the item was deleted', VALUE_REQUIRED);
    }
}
