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
 * get_item_from_sharing_cart external API.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_item_from_sharing_cart extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'item_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'course_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
        ]);
    }

    /**
     * Format the response for a sharing cart item.
     *
     * @param entity $item
     * @param int $courseid
     * @return object
     */
    private static function format_response(entity $item, int $courseid): object {
        global $USER, $DB;

        $backuptasks = $DB->get_records('task_adhoc', [
            'userid' => $USER->id,
            'classname' => "\\block_sharing_cart\\task\\asynchronous_backup_task",
        ]);
        array_walk($backuptasks, static function (object $task) {
            $task->item_id = json_decode($task->customdata)?->item?->id;
            unset($task->customdata);
        });
        $backuptasks = array_combine(
            array_column($backuptasks, 'item_id'),
            $backuptasks
        );
        $backuptask = $backuptasks[$item->get_id()] ?? null;

        $isrunning = $backuptask && $backuptask->timestarted !== null;
        $isfailed = $backuptask && $backuptask->faildelay > 0;
        $haswaited5seconds = $backuptask && time() - $backuptask->timecreated > 5;

        if ($isfailed || ($backuptask === null && $item->get_status() !== entity::STATUS_BACKEDUP)) {
            $item->set_status(entity::STATUS_BACKUP_FAILED);
            factory::make()->item()->repository()->update($item);
        }

        $response = (object)$item->to_array();

        $allowtorunnow = has_capability('block/sharing_cart:manual_run_task', \core\context\system::instance(), $USER);
        $response->show_run_now = $allowtorunnow && !$isrunning && !$isfailed && $haswaited5seconds;
        $response->can_copy_to_course = has_capability(
            'moodle/restore:restoreactivity',
            \core\context\course::instance($courseid),
            $USER
        );

        return $response;
    }

    /**
     * execute.
     * @param int $itemid
     * @param int $courseid
     */
    public static function execute(int $itemid, int $courseid): ?object {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'item_id' => $itemid,
            'course_id' => $courseid,
        ]);

        self::validate_context(
            \core\context\course::instance($courseid)
        );
        $item = factory::make()->item()->repository()->get_by_id($params['item_id']);
        if (!$item) {
            return null;
        }

        if ($item->get_user_id() !== (int)$USER->id) {
            return null;
        }

        return self::format_response($item, $courseid);
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The id of the item in the sharing cart', VALUE_REQUIRED),
            'user_id' => new external_value(PARAM_INT, 'The id of the user who owns the item', VALUE_REQUIRED),
            'file_id' => new external_value(PARAM_INT, 'The id of the backup file', VALUE_OPTIONAL),
            'parent_item_id' => new external_value(PARAM_INT, 'The id of the parent item', VALUE_OPTIONAL),
            'old_instance_id' => new external_value(PARAM_INT, 'The old instance id', VALUE_REQUIRED),
            'type' => new external_value(PARAM_TEXT, 'The type of the item', VALUE_REQUIRED),
            'name' => new external_value(PARAM_TEXT, 'The name of the item', VALUE_REQUIRED),
            'status' => new external_value(PARAM_INT, 'The status of the item', VALUE_REQUIRED),
            'show_run_now' => new external_value(PARAM_BOOL, 'Whether the item can be run now', VALUE_REQUIRED),
            'can_copy_to_course' => new external_value(PARAM_BOOL, 'Whether the item can be copied to the course', VALUE_REQUIRED),
            'timecreated' => new external_value(PARAM_INT, 'The time the item was created', VALUE_REQUIRED),
            'timemodified' => new external_value(PARAM_INT, 'The time the item was last modified', VALUE_REQUIRED),
        ]);
    }
}
