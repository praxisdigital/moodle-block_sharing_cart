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
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Class external\item\reorder_sharing_cart_items for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class reorder_sharing_cart_items extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Item ID', VALUE_REQUIRED), 'Item IDs', VALUE_REQUIRED,
            )
        ]);
    }

    public static function execute(array $itemids): bool {
        global $USER;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'itemids' => $itemids,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        /**
         * @var \block_sharing_cart\app\item\entity[] $items
         */
        $items = $basefactory->item()->repository()->get_by_user_id($USER->id);
        foreach ($items as $item) {
            if ($item->get_parent_item_id()) {
                continue;
            }

            $item->set_sortorder(array_search($item->get_id(), $params['itemids'], true));
            $basefactory->item()->repository()->update($item);
        }

        return true;
    }

    public static function execute_returns(): external_description {
        return new external_value(PARAM_BOOL, 'Whether the item was deleted', VALUE_REQUIRED);
    }
}
