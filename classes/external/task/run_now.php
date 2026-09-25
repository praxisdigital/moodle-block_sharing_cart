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

namespace block_sharing_cart\external\task;

use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_value;

/**
 * run_now external API.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_now extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
        ]);
    }

    /**
     * execute.
     * @param int $taskid
     */
    public static function execute(
        int $taskid,
    ): bool {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'taskid' => $taskid,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        if (CLI_MAINTENANCE) {
            throw new \Exception(
                get_string('sitemaintenance', 'admin')
            );
        }

        if (moodle_needs_upgrading()) {
            throw new \Exception(
                get_string('cliupgradepending', 'admin')
            );
        }

        if (!get_config('core', 'cron_enabled')) {
            throw new \Exception(
                get_string('crondisabled', 'tool_task')
            );
        }

        $task = $DB->get_record(
            'task_adhoc',
            [
                'id' => $params['taskid'],
                'component' => 'block_sharing_cart',
                'faildelay' => 0,
                'timestarted' => null,
                'userid' => $USER->id,
            ]
        );

        if (!$task) {
            return false;
        }

        ob_start();
        \core\task\manager::run_adhoc_from_cli($task->id);
        ob_end_clean();

        return true;
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_value(PARAM_BOOL, '', VALUE_REQUIRED);
    }
}
