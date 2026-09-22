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

namespace block_sharing_cart\output\block;

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\item\entity;

/**
 * Class output\block\content for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content implements \core\output\named_templatable, \renderable {
    /** @var basefactory $basefactory */
    private basefactory $basefactory;
    /** @var int $userid */
    private int $userid;
    /** @var int $courseid */
    private int $courseid;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     * @param int $userid
     * @param int $courseid
     */
    public function __construct(basefactory $basefactory, int $userid, int $courseid) {
        $this->basefactory = $basefactory;
        $this->userid = $userid;
        $this->courseid = $courseid;
    }

    /**
     * get_template_name
     *
     * @param \renderer_base $renderer
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'block_sharing_cart/block/content';
    }

    /**
     * export_items_for_template
     *
     * @return array
     */
    private function export_items_for_template(): array {
        global $USER, $DB;

        $backuptasks = $DB->get_records('task_adhoc', [
            'userid' => $USER->id,
            'classname' => "\\block_sharing_cart\\task\\asynchronous_backup_task",
        ]);
        array_walk($backuptasks, static function (object $task) {
            $task->itemid = json_decode($task->customdata)?->item?->id;
            unset($task->customdata);
        });
        $backuptasks = array_combine(
            array_column($backuptasks, 'item_id'),
            $backuptasks
        );

        $allitemcontexts = $this->basefactory->item()->repository()->get_by_user_id($this->userid)->map(
            static function (entity $item) use ($backuptasks) {
                return item::export_item_for_template($item, $backuptasks);
            }
        );

        $rootitemcontexts = $allitemcontexts->filter(static function (object $itemcontext) {
            return $itemcontext->is_root;
        });

        $rootitemcontexts = $rootitemcontexts->map(function (object $rootitemcontext) use ($allitemcontexts) {
            $rootitemcontext->children = item::get_item_children($rootitemcontext, $allitemcontexts);
            return $rootitemcontext;
        });

        return $rootitemcontexts->to_array(true);
    }

    /**
     * export_for_template
     *
     * @param \renderer_base $OUTPUT
     * @return array
     */
    public function export_for_template(\renderer_base $OUTPUT): array {
        $coursecontext = \core\context\course::instance($this->courseid);

        return [
            'items' => $this->export_items_for_template(),
            'canBackupUserdata' => has_capability('moodle/backup:userinfo', $coursecontext),
            'canAnonymizeUserdata' => has_capability('moodle/backup:anonymise', $coursecontext),
            'canBackup' => has_capability('moodle/backup:backupactivity', $coursecontext),
            'showCopiesQueuedSegmentWhenEmpty' => get_config('block_sharing_cart', 'show_copies_queued_segment_when_empty'),
            'showSharingCartBasket' => get_config('block_sharing_cart', 'show_sharing_cart_basket'),
            'showCopySectionInBlock' => (bool)get_config('block_sharing_cart', 'show_copy_section_in_block'),
            'courseContextId' => $coursecontext->id,
        ];
    }
}
