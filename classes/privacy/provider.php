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

namespace block_sharing_cart\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * provider
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * get_metadata
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_sharing_cart_items', [
            'user_id' => 'privacy:metadata:sharing_cart_items:user_id',
            'file_id' => 'privacy:metadata:sharing_cart_items:file_id',
            'parent_item_id' => 'privacy:metadata:sharing_cart_items:parent_item_id',
            'old_instance_id' => 'privacy:metadata:sharing_cart_items:old_instance_id',
            'type' => 'privacy:metadata:sharing_cart_items:type',
            'name' => 'privacy:metadata:sharing_cart_items:name',
            'status' => 'privacy:metadata:sharing_cart_items:status',
            'sortorder' => 'privacy:metadata:sharing_cart_items:sortorder',
            'original_course_fullname' => 'privacy:metadata:sharing_cart_items:original_course_fullname',
            'timecreated' => 'privacy:metadata:sharing_cart_items:timecreated',
            'timemodified' => 'privacy:metadata:sharing_cart_items:timemodified',
        ], 'privacy:metadata:sharing_cart_items:tabledesc');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        return $collection;
    }

    /**
     * get_contexts_for_userid
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();
        $sql = "SELECT c.id FROM {context} c
                 WHERE c.contextlevel = :contextlevel AND c.instanceid = :userid
                   AND (EXISTS (SELECT 1 FROM {block_sharing_cart_items} i WHERE i.user_id = c.instanceid)
                        OR EXISTS (SELECT 1 FROM {files} f WHERE f.contextid = c.id
                            AND f.component = :component AND f.filearea = :filearea AND f.filename <> :directory))";
        $contextlist->add_from_sql($sql, [
            'userid' => $userid, 'contextlevel' => CONTEXT_USER, 'component' => 'block_sharing_cart',
            'filearea' => 'backup', 'directory' => '.',
        ]);
        $outcomes = \block_sharing_cart\app\factory::make()->restore()->outcomes();
        if ($contextlist->count() === 0 && $outcomes->has_user_outcomes($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * export_user_data
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        $context = \core\context\user::instance($userid);
        if (!in_array($context->id, array_map('intval', $contextlist->get_contextids()), true)) {
            return;
        }
        $sql = 'SELECT *
                  FROM {block_sharing_cart_items} i
                 WHERE i.user_id = :userid';

        $contextpath = [get_string('pluginname', 'block_sharing_cart')];
        $writer = writer::with_context($context);
        $exportedids = [];

        $recordset = $DB->get_recordset_sql($sql, ['userid' => $userid]);
        foreach ($recordset as $record) {
            $data = (object) [
                'user_id' => $record->user_id,
                'file_id' => $record->file_id,
                'parent_item_id' => $record->parent_item_id,
                'old_instance_id' => $record->old_instance_id,
                'type' => $record->type,
                'name' => format_string($record->name),
                'status' => $record->status,
                'sortorder' => $record->sortorder,
                'original_course_fullname' => format_string($record->original_course_fullname ?? ''),
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
            ];

            $itemid = (int)$record->id;
            $itempath = [...$contextpath, (string)$itemid];
            $writer->export_data($itempath, $data)->export_area_files($itempath, 'block_sharing_cart', 'backup', $itemid);
            $exportedids[$itemid] = true;
        }

        $recordset->close();
        // Include any archives left behind by a missing cart row.
        $files = $DB->get_fieldset_select(
            'files',
            'DISTINCT itemid',
            'contextid = :contextid AND component = :component AND filearea = :filearea AND filename <> :directory',
            [
                'contextid' => $context->id, 'component' => 'block_sharing_cart', 'filearea' => 'backup', 'directory' => '.',
            ]
        );
        foreach ($files as $itemid) {
            if (!isset($exportedids[$itemid])) {
                $writer->export_area_files([...$contextpath, (string)$itemid], 'block_sharing_cart', 'backup', (int)$itemid);
            }
        }
    }

    /**
     * delete_data_for_all_users_in_context
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        // Only delete data for a user context.
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }

        get_file_storage()->delete_area_files($context->id, 'block_sharing_cart', 'backup');
        $DB->delete_records('block_sharing_cart_items', ['user_id' => $context->instanceid]);
        \block_sharing_cart\app\factory::make()->restore()->outcomes()->delete_for_user((int)$context->instanceid);
    }

    /**
     * delete_data_for_user
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel !== CONTEXT_USER) {
                continue;
            }

            if ((int)$context->instanceid !== $userid) {
                continue;
            }

            self::delete_data_for_all_users_in_context($context);
        }
    }

    /**
     * get_users_in_context
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }

        if (
            !$DB->record_exists('block_sharing_cart_items', ['user_id' => $context->instanceid]) &&
            get_file_storage()->is_area_empty($context->id, 'block_sharing_cart', 'backup') &&
            !\block_sharing_cart\app\factory::make()->restore()->outcomes()->has_user_outcomes((int)$context->instanceid)
        ) {
            return;
        }

        $userlist->add_user($context->instanceid);
    }

    /**
     * delete_data_for_users
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }

        if (!in_array((int)$context->instanceid, array_map('intval', $userlist->get_userids()), true)) {
            return;
        }

        self::delete_data_for_all_users_in_context($context);
    }
}
