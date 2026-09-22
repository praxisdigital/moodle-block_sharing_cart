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

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd


/**
 * Upgrade steps for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
function xmldb_block_sharing_cart_upgrade($oldversion = 0): bool {
    global $DB;

    $dbman = $DB->get_manager();

    $basefactory = \block_sharing_cart\app\factory::make();

    if ($oldversion < 2011111100) {
        $table = new xmldb_table('sharing_cart');

        $field = new xmldb_field('user', XMLDB_TYPE_INTEGER, 10, true, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'userid');

        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, 32, null, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'modname');

        $field = new xmldb_field('icon', XMLDB_TYPE_CHAR, 32, null, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'modicon');

        $field = new xmldb_field('text', XMLDB_TYPE_CHAR, 255, null, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'modtext');
        $field = new xmldb_field('modtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $dbman->change_field_type($table, $field);

        $field = new xmldb_field('time', XMLDB_TYPE_INTEGER, 10, true, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'ctime');

        $field = new xmldb_field('file', XMLDB_TYPE_CHAR, 255, null, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'filename');

        $field = new xmldb_field('sort', XMLDB_TYPE_INTEGER, 10, true, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'weight');
    }

    if ($oldversion < 2011111101) {
        $table = new xmldb_table('sharing_cart_plugins');

        $field = new xmldb_field('user', XMLDB_TYPE_INTEGER, 10, true, XMLDB_NOTNULL, null, null);
        $dbman->rename_field($table, $field, 'userid');
    }

    if ($oldversion < 2012050800) {
        $table = new xmldb_table('sharing_cart');
        $dbman->rename_table($table, 'block_sharing_cart');

        $table = new xmldb_table('sharing_cart_plugins');
        $dbman->rename_table($table, 'block_sharing_cart_plugins');
    }

    if ($oldversion < 2016032900) {
        // Define key userid (foreign) to be added to block_sharing_cart.
        $table = new xmldb_table('block_sharing_cart');
        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Launch add key userid.
        $dbman->add_key($table, $key);

        // Sharing_cart savepoint reached.
        upgrade_block_savepoint(true, 2016032900, 'sharing_cart');
    }

    if ($oldversion < 2017071111) {
        $table = new xmldb_table('block_sharing_cart');

        $field = new xmldb_field('course', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, 0);
        $key = new xmldb_key('course', XMLDB_KEY_FOREIGN, ['course'], 'course', ['id']);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $dbman->add_key($table, $key);
        }

        upgrade_block_savepoint(true, 2017071111, 'sharing_cart');
    }

    if ($oldversion < 2017121200) {
        $table = new xmldb_table('block_sharing_cart');
        $field = new xmldb_field('section', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, 0);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('block_sharing_cart_sections');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, 255, null, XMLDB_NOTNULL, null, null, 'id');
            $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'name');
            $table->add_field('summaryformat', XMLDB_TYPE_INTEGER, 2, null, XMLDB_NOTNULL, false, 0, 'summary');

            $table->add_key('id', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2017121200, 'sharing_cart');
    }

    // Fix default value incompatible with moodle database manager
    if ($oldversion < 2020073001) {
        $table = new xmldb_table('block_sharing_cart_sections');

        if ($dbman->table_exists($table)) {
            $fieldname = new xmldb_field('name', XMLDB_TYPE_CHAR, 255);
            $fieldsummary = new xmldb_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'name');
            $fieldsummaryformat = new xmldb_field(
                'summaryformat', XMLDB_TYPE_INTEGER, 2, null, XMLDB_NOTNULL, false, 0, 'summary'
            );

            if ($dbman->field_exists($table, $fieldname)) {
                $dbman->change_field_default($table, $fieldname);
            }
            if ($dbman->field_exists($table, $fieldsummary)) {
                $dbman->change_field_default($table, $fieldsummary);
            }
            if ($dbman->field_exists($table, $fieldsummaryformat)) {
                $dbman->change_field_default($table, $fieldsummaryformat);
            }

            upgrade_block_savepoint(true, 2020073001, 'sharing_cart');
        }
    }

    if ($oldversion < 2020112001) {
        $table = new xmldb_table('block_sharing_cart_sections');

        if ($dbman->table_exists($table)) {
            $fieldavailability = new xmldb_field(
                'availability', XMLDB_TYPE_TEXT, null, null, false, false, null, 'summaryformat'
            );
            if (!$dbman->field_exists($table, $fieldavailability)) {
                $dbman->add_field($table, $fieldavailability);
            }
        }

        upgrade_block_savepoint(true, 2020112001, 'sharing_cart');
    }

    if ($oldversion < 2022111100) {
        // Remove redundant table, if it was created.
        $table = new xmldb_table('block_sharing_cart_log');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Fix potential mismatch with name field nullability from previous upgrade step.
        $table = new xmldb_table('block_sharing_cart_sections');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        upgrade_block_savepoint(true, 2022111100, 'sharing_cart');
    }

    if ($oldversion < 2024011800) {
        // Remove records that have no owner.
        // (This could have been the leftover from the version before privacy api was introduced.)
        $sql = "SELECT i.id FROM {block_sharing_cart} i
                LEFT JOIN {user} u ON u.id = i.userid
                WHERE u.id IS NULL OR u.deleted = :deleted";
        $deletedsharingcartids = $DB->get_fieldset_sql($sql, ['deleted' => 1]);

        if (!empty($deletedsharingcartids)) {
            $DB->delete_records_list('block_sharing_cart', 'id', $deletedsharingcartids);
        }

        // Begin upgrade block_sharing_cart table.
        $table = new xmldb_table('block_sharing_cart');
        $field = new xmldb_field(
            'fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0
        );

        // Add file id field to sharing cart table - for accelerating backup file selection.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add indexes to sharing cart table
        $index = new xmldb_index('weight', XMLDB_INDEX_NOTUNIQUE, ['weight']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('tree', XMLDB_INDEX_NOTUNIQUE, ['tree']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('section', XMLDB_INDEX_NOTUNIQUE, ['section']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('fileid', XMLDB_INDEX_NOTUNIQUE, ['fileid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Mapping sharing cart records with user backup files.
        // This is for accelerating backup file selection and to eliminate uncertainty of the selection.
        $storage = get_file_storage();
        $sharingcartrecords = $DB->get_recordset('block_sharing_cart', [
            'fileid' => 0
        ], '', 'id, userid, filename');
        $userbackupfiles = [];
        $deletedsharingcartfiles = [];

        foreach ($sharingcartrecords as $record) {
            if (!isset($userbackupfiles[$record->userid])) {
                try {
                    $context = context_user::instance($record->userid);
                    $files = $storage->get_area_files(
                        $context->id,
                        'user',
                        'backup',
                        false,
                        'id',
                        false
                    );
                    foreach ($files as $file) {
                        $userbackupfiles[$record->userid][$file->get_filename()] = $file->get_id();
                    }
                } catch (moodle_exception $exception) {
                    if ($exception->errorcode === 'invaliduser') {
                        $deletedsharingcartfiles[] = $record->id;
                        continue;
                    }
                    throw $exception;
                }
            }

            if (isset($userbackupfiles[$record->userid][$record->filename])) {
                $record->fileid = $userbackupfiles[$record->userid][$record->filename];
                $DB->update_record('block_sharing_cart', $record);
            } else {
                $deletedsharingcartfiles[] = $record->id;
            }
        }
        $sharingcartrecords->close();

        // Remove sharing cart records that are not mapped with user backup files.
        if (!empty($deletedsharingcartfiles)) {
            $DB->delete_records_list('block_sharing_cart', 'id', $deletedsharingcartfiles);
        }

        upgrade_block_savepoint(true, 2024011800, 'sharing_cart');
    }

    if ($oldversion < 2024072901) {
        /**
         * Create block_sharing_cart_items table.
         */
        $xmldbtable = new xmldb_table('block_sharing_cart_items');

        $xmldbtable->add_field('id', XMLDB_TYPE_INTEGER, '10', true, true, true);
        $xmldbtable->add_field('user_id', XMLDB_TYPE_INTEGER, '10', true, true);
        $xmldbtable->add_field('file_id', XMLDB_TYPE_INTEGER, '10', true, false);
        $xmldbtable->add_field('parent_item_id', XMLDB_TYPE_INTEGER, '10', true, false);
        $xmldbtable->add_field('old_instance_id', XMLDB_TYPE_INTEGER, '10', true, true);
        $xmldbtable->add_field('type', XMLDB_TYPE_CHAR, '255', notnull: true);
        $xmldbtable->add_field('name', XMLDB_TYPE_CHAR, '255', notnull: true);
        $xmldbtable->add_field('status', XMLDB_TYPE_INTEGER, '10', true, true);
        $xmldbtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', notnull: true);
        $xmldbtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', notnull: true);

        $xmldbtable->add_key('id', XMLDB_KEY_PRIMARY, ['id']);

        $xmldbtable->add_index('user_id', XMLDB_INDEX_NOTUNIQUE, ['user_id']);
        $xmldbtable->add_index('file_id', XMLDB_INDEX_UNIQUE, ['file_id']);
        $xmldbtable->add_index('parent_item_id', XMLDB_INDEX_NOTUNIQUE, ['parent_item_id']);
        $xmldbtable->add_index('type', XMLDB_INDEX_NOTUNIQUE, ['type']);
        $xmldbtable->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($xmldbtable)) {
            $dbman->create_table($xmldbtable);
        }

        /**
         * Migrate data from block_sharing_cart_sections & block_sharing_cart to block_sharing_cart_items.
         */

        /**
         * @var \file_storage $fs
         */
        $fs = get_file_storage();

        if ($dbman->table_exists(new \xmldb_table('block_sharing_cart_sections')) && $dbman->table_exists(
                new \xmldb_table('block_sharing_cart')
            )) {
            $oldsectionrecords = $DB->get_recordset('block_sharing_cart_sections');
            foreach ($oldsectionrecords as $oldsectionrecord) {
                $time = time();

                $oldactivityrecords = $DB->get_recordset('block_sharing_cart', [
                    'section' => $oldsectionrecord->id
                ]);

                $oldactivityrecordsbyuserid = [];
                $userids = [];
                foreach ($oldactivityrecords as $oldactivityrecord) {
                    $oldactivityrecordsbyuserid[(int)$oldactivityrecord->userid][] = $oldactivityrecord;
                    $userids[(int)$oldactivityrecord->userid] = (int)$oldactivityrecord->userid;
                }
                $oldactivityrecords->close();
                unset($oldactivityrecords);

                foreach ($userids as $userid) {
                    try {
                        $oldactivityrecords = $oldactivityrecordsbyuserid[$userid] ?? [];
                        if (empty($oldactivityrecords)) {
                            continue;
                        }

                        $newsectionrecord = (object)[
                            'user_id' => $userid,
                            'file_id' => null,
                            'parent_item_id' => null,
                            'old_instance_id' => 0,
                            'type' => \block_sharing_cart\app\item\entity::TYPE_SECTION,
                            'name' => $oldsectionrecord->name,
                            'status' => \block_sharing_cart\app\item\entity::STATUS_BACKEDUP,
                            'timecreated' => $time,
                            'timemodified' => $time,
                        ];
                        $sectionitemid = $DB->insert_record('block_sharing_cart_items', $newsectionrecord);

                        foreach ($oldactivityrecords as $oldactivityrecord) {
                            try {
                                if ($oldactivityrecord->fileid === 0) {
                                    continue;
                                }

                                $backupfile = $fs->get_file_by_id($oldactivityrecord->fileid);
                                if ($backupfile === false) {
                                    continue;
                                }

                                $newactivityrecord = (object)[
                                    'user_id' => $userid,
                                    'file_id' => null,
                                    'parent_item_id' => $sectionitemid,
                                    'old_instance_id' => 0,
                                    'type' => "mod_{$oldactivityrecord->modname}",
                                    'name' => strip_tags($oldactivityrecord->modtext ?? 'Unknown'),
                                    'status' => \block_sharing_cart\app\item\entity::STATUS_BACKUP_FAILED,
                                    'timecreated' => $time,
                                    'timemodified' => $time,
                                ];
                                $newactivityrecord->id = $DB->insert_record(
                                    'block_sharing_cart_items',
                                    $newactivityrecord
                                );

                                $newfile = $fs->create_file_from_storedfile([
                                    'contextid' => \core\context\user::instance($userid)->id,
                                    'component' => 'block_sharing_cart',
                                    'filearea' => 'backup',
                                    'itemid' => $newactivityrecord->id,
                                    'filepath' => '/',
                                    'filename' => $backupfile->get_filename(),
                                ], $backupfile);

                                $newactivityrecord->file_id = $newfile->get_id();
                                $newactivityrecord->status = \block_sharing_cart\app\item\entity::STATUS_BACKEDUP;

                                $DB->update_record('block_sharing_cart_items', $newactivityrecord);
                            } catch (\Exception) {
                                if (isset($newactivityrecord->id)) {
                                    $DB->update_record(
                                        'block_sharing_cart_items',
                                        (object)[
                                            'id' => $newactivityrecord->id,
                                            'status' => \block_sharing_cart\app\item\entity::STATUS_BACKUP_FAILED
                                        ]
                                    );
                                }
                            }
                        }
                    } catch (\Exception) {
                        // Ignore failures for this optional step.
                    }
                }
            }
            $oldsectionrecords->close();

            $oldactivityrecords = $DB->get_recordset('block_sharing_cart', [
                'section' => 0
            ]);
            foreach ($oldactivityrecords as $oldactivityrecord) {
                try {
                    if ($oldactivityrecord->fileid === 0) {
                        continue;
                    }

                    $backupfile = $fs->get_file_by_id($oldactivityrecord->fileid);
                    if ($backupfile === false) {
                        continue;
                    }

                    if (file_exists($fs->get_file_system()->get_remote_path_from_storedfile($backupfile)) === false) {
                        continue;
                    }

                    $time = time();

                    $newactivityrecord = (object)[
                        'user_id' => $oldactivityrecord->userid,
                        'file_id' => null,
                        'parent_item_id' => null,
                        'old_instance_id' => 0,
                        'type' => "mod_{$oldactivityrecord->modname}",
                        'name' => strip_tags($oldactivityrecord->modtext ?? 'Unknown'),
                        'status' => \block_sharing_cart\app\item\entity::STATUS_BACKUP_FAILED,
                        'timecreated' => $time,
                        'timemodified' => $time,
                    ];
                    $newactivityrecord->id = $DB->insert_record(
                        'block_sharing_cart_items',
                        $newactivityrecord
                    );

                    $newfile = $fs->create_file_from_storedfile([
                        'contextid' => \core\context\user::instance($oldactivityrecord->userid)->id,
                        'component' => 'block_sharing_cart',
                        'filearea' => 'backup',
                        'itemid' => $newactivityrecord->id,
                        'filepath' => '/',
                        'filename' => $backupfile->get_filename(),
                    ], $backupfile);

                    $newactivityrecord->file_id = $newfile->get_id();
                    $newactivityrecord->status = \block_sharing_cart\app\item\entity::STATUS_BACKEDUP;

                    $DB->update_record('block_sharing_cart_items', $newactivityrecord);
                } catch (\Exception) {
                    if (isset($newactivityrecord->id)) {
                        $DB->update_record(
                            'block_sharing_cart_items',
                            (object)[
                                'id' => $newactivityrecord->id,
                                'status' => \block_sharing_cart\app\item\entity::STATUS_BACKUP_FAILED
                            ]
                        );
                    }
                }
            }
            $oldactivityrecords->close();
        }

        $table = new xmldb_table('block_sharing_cart');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $table = new xmldb_table('block_sharing_cart_sections');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $table = new xmldb_table('block_sharing_cart_plugins');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        $xmldbtable = new xmldb_table('block_sharing_cart_items');

        if (!$dbman->field_exists($xmldbtable, 'sortorder')) {
            $dbman->add_field(
                $xmldbtable,
                new xmldb_field(
                    'sortorder', XMLDB_TYPE_INTEGER, '10', notnull: false
                )
            );
        }

        upgrade_block_savepoint(true, 2024072901, 'sharing_cart');
    }

    if ($oldversion < 2024101800) {
        $xmldbtable = new xmldb_table('block_sharing_cart_items');

        if (!$dbman->field_exists($xmldbtable, 'original_course_fullname')) {
            $dbman->add_field(
                $xmldbtable,
                new xmldb_field(
                    'original_course_fullname', XMLDB_TYPE_CHAR, 255, notnull: false
                )
            );
        }

        $itemrecordset = $DB->get_recordset('block_sharing_cart_items', [
            'status' => \block_sharing_cart\app\item\entity::STATUS_BACKEDUP,
            'parent_item_id' => null
        ]);
        foreach ($itemrecordset as $item) {
            try {
                /**
                 * @var \file_storage $fs
                 */
                $fs = get_file_storage();
                $file = $fs->get_file_by_id($item->file_id);
                if (!$file) {
                    continue;
                }

                $courseinfo = $basefactory->backup()->handler()->get_backup_course_info($file);
                $item->original_course_fullname = $courseinfo['fullname'] ?? null;

                $DB->update_record(
                    'block_sharing_cart_items',
                    $item
                );
            } catch (\Exception) {
                // Ignore failures for this optional step.
            }
        }
        $itemrecordset->close();

        upgrade_block_savepoint(true, 2024101800, 'sharing_cart');
    }

    if ($oldversion < 2024111302) {
        $xmldbtable = new xmldb_table('block_sharing_cart_items');
        if ($dbman->table_exists($xmldbtable)) {
            $xmldbindex = new xmldb_index('file_id');
            if ($dbman->index_exists($xmldbtable, $xmldbindex)) {
                $dbman->drop_index(
                    $xmldbtable,
                    $xmldbindex
                );
            }

            $xmldbindex = new xmldb_index('user_id');
            if ($dbman->index_exists($xmldbtable, $xmldbindex)) {
                $dbman->drop_index(
                    $xmldbtable,
                    $xmldbindex
                );
            }

            $xmldbindex = new xmldb_index('user_id', XMLDB_INDEX_NOTUNIQUE, ['user_id']);
            if (!$dbman->index_exists($xmldbtable, $xmldbindex)) {
                $dbman->add_index(
                    $xmldbtable,
                    $xmldbindex
                );
            }

            $xmldbindex = new xmldb_index('file_id', XMLDB_INDEX_UNIQUE, ['file_id']);
            if (!$dbman->index_exists($xmldbtable, $xmldbindex)) {
                $dbman->add_index(
                    $xmldbtable,
                    $xmldbindex
                );
            }
        }

        upgrade_block_savepoint(true, 2024111302, 'sharing_cart');
    }

    if ($oldversion < 2025042202) {
        $xmldbtable = new xmldb_table('block_sharing_cart_items');

        if ($dbman->table_exists($xmldbtable)) {
            if (!$dbman->field_exists($xmldbtable, 'version')) {
                $dbman->add_field(
                    $xmldbtable,
                    new xmldb_field(
                        'version', XMLDB_TYPE_INTEGER, '10', true, XMLDB_NOTNULL,
                        null, 0, 'original_course_fullname'
                    )
                );
            }

            $itemrecords = $DB->get_recordset('block_sharing_cart_items');
            foreach ($itemrecords as $itemrecord) {
                if ($itemrecord->version !== null) {
                    continue;
                }

                $version = $itemrecord->old_instance_id === '0' ? 1 : 2;

                $DB->update_record(
                    'block_sharing_cart_items',
                    (object)[
                        'id' => $itemrecord->id,
                        'version' => $version
                    ]
                );
            }

            // Removes the default of field version of table block_sharing_cart_items
            $dbman->change_field_default(
                $xmldbtable,
                new xmldb_field(
                    'version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL,
                    null, null, 'original_course_fullname'
                )
            );

            $xmldbindex = new xmldb_index('version', XMLDB_INDEX_NOTUNIQUE, ['version']);
            if (!$dbman->index_exists($xmldbtable, $xmldbindex)) {
                $dbman->add_index($xmldbtable, $xmldbindex);
            }
        }

        // Sharing_cart savepoint reached.
        upgrade_block_savepoint(true, 2025042202, 'sharing_cart');
    }

    return true;
}
