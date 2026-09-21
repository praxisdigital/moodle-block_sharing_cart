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

/**
 * items.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\output\block\queue;

use block_sharing_cart\app\factory as basefactory;
use core\context\system;

/**
 * items class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class items implements \core\output\named_templatable, \renderable {
    /** @var basefactory $basefactory */
    private basefactory $basefactory;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     */
    public function __construct(
        basefactory $basefactory
    ) {
        $this->basefactory = $basefactory;
    }

    /**
     * get_template_name
     *
     * @param \renderer_base $renderer
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'block_sharing_cart/block/queue/items';
    }

    /**
     * export_for_template
     *
     * @param \renderer_base $OUTPUT
     * @return array
     */
    public function export_for_template(\renderer_base $OUTPUT): array {
        global $USER, $DB, $OUTPUT, $COURSE;

        $queueitems = [];

        $records = $DB->get_records('task_adhoc', [
            'userid' => $USER->id,
            'classname' => "\\block_sharing_cart\\task\\asynchronous_restore_task",
        ]);
        foreach ($records as $record) {
            $customdata = json_decode($record->customdata);

            $backupsettings = $customdata->backup_settings ?? null;

            $item = $customdata->item ?? null;
            $courseid = $customdata->course_id ?? null;

            if ($courseid !== (int)$COURSE->id) {
                continue;
            }

            $isrunning = $record->timestarted !== null;
            $isfailed = $record->faildelay > 0;
            $haswaited5seconds = time() - $record->timecreated > 5;

            $queueitems[] = [
                'id' => $record->id,
                'name' => strlen($item->name) > 50 ? substr($item->name, 0, 50) . '...' : $item->name,
                'is_section' => $item->type === \block_sharing_cart\app\item\entity::TYPE_SECTION,
                'is_module' => $item->type !== \block_sharing_cart\app\item\entity::TYPE_SECTION,
                'mod_icon' => $item->type !== \block_sharing_cart\app\item\entity::TYPE_SECTION ? $OUTPUT->image_url(
                    'icon',
                    $item->type
                ) : null,
                'to_section_id' => $backupsettings->move_to_section_id ?? null,
                'is_running' => $isrunning,
                'is_failed' => $isfailed,
                'show_run_now' => $this->allow_to_run_now() && !$isrunning && !$isfailed && $haswaited5seconds,
            ];
        }

        return [
            'queue_items' => array_values($queueitems),
        ];
    }

    /**
     * allow_to_run_now
     *
     * @return bool
     */
    private function allow_to_run_now(): bool {
        global $USER;

        return has_capability(
            'block/sharing_cart:manual_run_task',
            \core\context\system::instance(),
            $USER
        );
    }
}
