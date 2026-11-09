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

namespace block_sharing_cart\app\restore;

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\task\asynchronous_restore_task;

/**
 * Restore handler for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handler
{
    /** @var basefactory $basefactory */
    private basefactory $basefactory;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     */
    public function __construct(basefactory $basefactory) {
        $this->basefactory = $basefactory;
    }

    /**
     * restore_item_into_section
     *
     * @param entity $item
     * @param int $sectionid target section; 0 means the top level of $courseid (only with insert_as_new_section)
     * @param int $itemid
     * @param array $settings restore selections and nested-section options
     * @param int $beforecmid Place the restored activities before this course module (0 = end of the section).
     * @param bool $asnewsection Create a new drop-target section after $sectionid.
     * @param int $courseid required when $sectionid is 0
     * @return asynchronous_restore_task|null
     */
    public function restore_item_into_section(
        entity $item,
        int $sectionid,
        int $itemid,
        array $settings = [],
        int $beforecmid = 0,
        bool $asnewsection = false,
        int $courseid = 0
    ): asynchronous_restore_task|null {
        global $USER, $DB;

        if ($sectionid > 0) {
            $courseid = (int)$DB->get_field('course_sections', 'course', ['id' => $sectionid], MUST_EXIST);
        }

        if ($courseid <= 0) {
            return null;
        }

        // The new section is created by the restore task, so its position is worked out when the restore runs.
        if ($asnewsection) {
            $settings['insert_after_section_id'] = $sectionid;
        } else {
            $settings['move_to_section_id'] = $sectionid;
        }
        if ($beforecmid && !$asnewsection) {
            $settings['before_cm_id'] = $beforecmid;
        }
        $settings['move_to_course_id'] = $courseid;

        $backupfile = $this->basefactory->item()->repository()->get_stored_file_by_item($item);
        if (!$backupfile) {
            throw new \Exception('Backup file not found for item (id: ' . $item->get_id() . ')');
        }

        $this->basefactory->restore()->assert_backup_file_looks_valid($backupfile);

        if (!$this->restore_is_valid(
            $itemid,
            $sectionid,
            $asnewsection,
            !empty($settings['insert_as_new_section'])
        )) {
            return null;
        }

        return $this->queue_async_restore($item, $courseid, (int)$USER->id, $settings);
    }

    /**
     * Restores are valid and to be queued only if they are valid according to the conditions in the function body.
     * The UI presents the user with the options of where to restore the item copied from the clipboard.
     * This function is the backend check of that logic.
     * @param int $itemid
     * @param int $targetsectionid 0 means the top level of the course
     * @param bool $asnewsection whether to create a section after the target for drag/drop
     * @param bool $insertasnewsection
     */
    private function restore_is_valid(
        int $itemid,
        int $targetsectionid,
        bool $asnewsection = false,
        bool $insertasnewsection = false
    ): bool {
        global $DB;

        if ($asnewsection && $insertasnewsection) {
            return false;
        }

        $sql = "SELECT
                I1.type AS own_type
                ,I2.type AS parent_type
                FROM {block_sharing_cart_items} I1
                LEFT JOIN {block_sharing_cart_items} I2 ON I1.parent_item_id = I2.id
                WHERE I1.id = :itemid";
        $params = [
            'itemid' => $itemid,
        ];

        $subjectitem = $DB->get_record_sql($sql, $params, MUST_EXIST);

        // Creating a new section only makes sense for section items.
        if ($insertasnewsection && $subjectitem->own_type !== 'section') {
            return false;
        }

        // A drag/drop-created destination must be anchored to a top-level section.
        if ($asnewsection && $subjectitem->own_type !== 'section') {
            return false;
        }

        // Top level of the course.
        if ($targetsectionid === 0) {
            return $insertasnewsection;
        }

        $targetsection = $DB->get_record(
            'course_sections',
            ['id' => $targetsectionid],
            'id, component, itemid',
            MUST_EXIST
        );

        $istargetasection = empty($targetsection->component) && empty($targetsection->itemid);
        $istargetasubsection = !empty($targetsection->component) && $targetsection->component == 'mod_subsection';

        // Drag/drop-created sections are inserted after a top-level section only.
        if ($asnewsection) {
            return $subjectitem->own_type === 'section' && $istargetasection;
        }

        // A section inserted through the modal cannot be merged into a subsection.
        if ($insertasnewsection && !$istargetasection) {
            return false;
        }

        // Attempt to restore a section into a non-section?
        if (!$istargetasection) {
            if ($subjectitem->own_type === 'section') {
                return false;
            }
        }

        // Attempt to restore a subsection into a subsection?
        if ($istargetasubsection) {
            if ($subjectitem->own_type === 'mod_subsection') {
                return false;
            }

            // Attempt to restore a subsections's child into a subsection?
            if ($subjectitem->parent_type === 'mod_subsection') {
                return false;
            }
        }

        return true;
    }

    /**
     * queue_async_restore
     *
     * @param entity $item
     * @param int $courseid
     * @param int $userid
     * @param array $settings
     * @return asynchronous_restore_task
     */
    private function queue_async_restore(
        entity $item,
        int $courseid,
        int $userid,
        array $settings = []
    ): asynchronous_restore_task {
        $asynctask = new asynchronous_restore_task();
        $asynctask->set_custom_data([
            'item' => $item->to_array(),
            'courseid' => $courseid,
            'backup_settings' => $settings,
        ]);
        $asynctask->set_userid($userid);
        \core\task\manager::queue_adhoc_task($asynctask);

        return $asynctask;
    }
}
