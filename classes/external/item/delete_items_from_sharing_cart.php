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
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * delete_items_from_sharing_cart external API.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_items_from_sharing_cart extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'item_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, '', VALUE_REQUIRED),
            ),
        ]);
    }

    /**
     * execute.
     * @param array $itemids
     */
    public static function execute(array $itemids): array {
        global $USER;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'item_ids' => $itemids,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        $deleteditemids = [];

        foreach ($params['item_ids'] as $itemid) {
            $item = $basefactory->item()->repository()->get_by_id($itemid);
            if (!$item) {
                continue;
            }

            if ($item->get_user_id() !== (int)$USER->id) {
                continue;
            }

            if ($basefactory->item()->repository()->delete_by_id($item->get_id())) {
                $deleteditemids[] = $item->get_id();
            }
        }

        return $deleteditemids;
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_multiple_structure(
            new external_value(PARAM_INT, 'Item id which was deleted', VALUE_REQUIRED)
        );
    }
}
