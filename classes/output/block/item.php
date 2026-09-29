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

use block_sharing_cart\app\collection;
use block_sharing_cart\app\factory;
use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\item\entity;

/**
 * Class output\block\item for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item implements \core\output\named_templatable, \renderable {
    /** @var basefactory $basefactory */
    private basefactory $basefactory;
    /** @var entity $item */
    private entity $item;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     * @param entity $item
     */
    public function __construct(basefactory $basefactory, entity $item) {
        $this->basefactory = $basefactory;
        $this->item = $item;
    }

    /**
     * get_template_name
     *
     * @param \renderer_base $renderer
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'block_sharing_cart/block/item';
    }

    /**
     * export_item_for_template
     *
     * @param entity $item
     * @param array $backuptasks
     * @return object
     */
    public static function export_item_for_template(entity $item, array $backuptasks): object {
        global $USER, $PAGE;

        $allowtorunnow = has_capability('block/sharing_cart:manual_run_task', \core\context\system::instance(), $USER);

        $basefactory = basefactory::make();
        $db = $basefactory->moodle()->db();

        $backuptask = $backuptasks[$item->get_id()] ?? null;
        $isrunning = $backuptask && $backuptask->timestarted !== null;
        $isfailed = $backuptask && $backuptask->faildelay > 0;
        $haswaited5seconds = $backuptask && time() - $backuptask->timecreated > 5;

        if ($isfailed || ($backuptask === null && $item->get_status() !== entity::STATUS_BACKEDUP)) {
            $item->set_status(entity::STATUS_BACKUP_FAILED);
            $basefactory->item()->repository()->update($item);
        }

        $itemcontext = (object)$item->to_array();

        $itemcontext->is_root = $item->get_parent_item_id() === null;

        $itemcontext->is_section = $item->is_section();
        $itemcontext->is_subsection = $item->is_subsection();
        $itemcontext->is_module = $item->is_module();

        $itemcontext->mod_icon = self::get_mod_icon($item);
        $itemcontext->can_copy_to_course = has_capability('moodle/restore:restoreactivity', $PAGE->context, $USER);

        $itemcontext->show_run_now = $allowtorunnow && !$isrunning && !$isfailed && $haswaited5seconds;
        $itemcontext->task_id = $itemcontext->show_run_now ? $backuptask->id : null;
        $itemcontext->has_file_id = $item->get_file_id() !== null
            || factory::make()->item()->repository()->get_parent_item_recursively_by_item($item)->get_file_id() !== null;
        $itemcontext->status_finished = $item->get_status() === entity::STATUS_BACKEDUP;
        $itemcontext->status_awaiting = $item->get_status() === entity::STATUS_AWAITING_BACKUP;
        $itemcontext->status_failed = $item->get_status() === entity::STATUS_BACKUP_FAILED;
        $itemcontext->is_current_version = $item->get_version() === entity::CURRENT_BACKUP_VERSION;

        $itemcontext->module_is_disabled_on_site = $item->is_module() === true && $db->get_record('modules', [
            'name' => str_replace('mod_', '', $item->get_type()),
            'visible' => false,
        ]);

        return $itemcontext;
    }

    /**
     * get_item_children
     *
     * @param object $itemcontext
     * @param collection $allitemcontexts
     * @return collection
     */
    public static function get_item_children(object $itemcontext, collection $allitemcontexts): collection {
        $children = $allitemcontexts->filter(static function (object $childitem) use ($itemcontext) {
            return $childitem->parent_item_id === $itemcontext->id;
        });
        $children->map(function (object $child) use ($allitemcontexts) {
            $child->children = self::get_item_children($child, $allitemcontexts);
        });

        return $children;
    }

    /**
     * get_mod_icon
     *
     * @param entity $item
     * @return ?string
     */
    public static function get_mod_icon(entity $item): ?string {
        global $OUTPUT;

        if (!$item->is_module()) {
            return null;
        }

        return $OUTPUT->image_url('icon', $item->get_type());
    }

    /**
     * export_for_template
     *
     * @param \renderer_base $OUTPUT
     * @return array
     */
    public function export_for_template(\renderer_base $OUTPUT): array {
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

        $allitemcontexts = $this->basefactory->item()->repository()->get_recursively_by_parent_id(
            $this->item->get_id()
        )->map(
            function (entity $item) use ($backuptasks) {
                return $this->export_item_for_template($item, $backuptasks);
            }
        );

        // Root item context for the template.
        $rootitemcontext = $allitemcontexts->filter(function (object $itemcontext) {
            return $itemcontext->id === $this->item->get_id();
        })->first();

        $rootitemcontext->children = self::get_item_children($rootitemcontext, $allitemcontexts);

        return (array)$rootitemcontext;
    }
}
