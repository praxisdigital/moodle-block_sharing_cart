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

namespace block_sharing_cart\external\backup;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Class external\backup\course_module_into_sharing_cart for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class course_module_into_sharing_cart extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'settings' => new external_single_structure([
                'users' => new external_value(PARAM_BOOL, 'Whether to include user data in the backup', VALUE_REQUIRED),
                'anonymize' => new external_value(
                    PARAM_BOOL, 'Whether to anonymize user data in the backup', VALUE_REQUIRED
                ),
            ], 'The settings of the item')
        ]);
    }

    public static function execute(int $coursemoduleid, array $settings): object {
        global $USER;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'coursemoduleid' => $coursemoduleid,
            'settings' => $settings,
        ]);

        self::validate_context(
            \context_module::instance($params['coursemoduleid'])
        );

        $item = $basefactory->item()->repository()->insert_activity(
            $params['coursemoduleid'],
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $backuptask = $basefactory->backup()->handler()->backup_course_module($coursemoduleid, $item, $settings);

        $return = $item->to_array();
        $return['taskid'] = $backuptask->get_id();

        return (object)$return;
    }

    public static function execute_returns(): external_description {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The id of the item in the sharing cart', VALUE_REQUIRED),
            'user_id' => new external_value(PARAM_INT, 'The id of the user who owns the item', VALUE_REQUIRED),
            'file_id' => new external_value(PARAM_INT, 'The id of the backup file', VALUE_OPTIONAL),
            'parent_item_id' => new external_value(PARAM_INT, 'The id of the parent item', VALUE_OPTIONAL),
            'old_instance_id' => new external_value(PARAM_INT, 'The old instance id', VALUE_REQUIRED),
            'taskid' => new external_value(PARAM_INT, 'The task id of backup adhoc task', VALUE_REQUIRED),
            'type' => new external_value(PARAM_TEXT, 'The type of the item', VALUE_REQUIRED),
            'name' => new external_value(PARAM_TEXT, 'The name of the item', VALUE_REQUIRED),
            'status' => new external_value(PARAM_INT, 'The status of the item', VALUE_REQUIRED),
            'version' => new external_value(PARAM_INT, 'The version of the item', VALUE_REQUIRED),
            'timecreated' => new external_value(PARAM_INT, 'The time the item was created', VALUE_REQUIRED),
            'timemodified' => new external_value(PARAM_INT, 'The time the item was last modified', VALUE_REQUIRED),
        ]);
    }
}
