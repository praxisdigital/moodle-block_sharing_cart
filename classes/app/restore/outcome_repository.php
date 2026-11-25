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

// @codeCoverageIgnoreStart
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded -- Required by the project's PHP file conventions.
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Recent task outcomes that remain available after Moodle drops the adhoc queue row.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outcome_repository {
    /** @var int Maximum recent outcomes per user and course. */
    private const MAX_OUTCOMES = 50;

    /**
     * Store one terminal outcome before the worker is removed from the queue.
     *
     * @param int $userid Task owner
     * @param int $courseid Destination course
     * @param int $taskid Moodle adhoc task ID
     * @param bool $succeeded Whether the restore completed
     * @param string $name Plain-text name of the item
     * @param array $destination Refresh metadata: integer to_section_id and boolean refresh_course
     * @return bool Whether the outcome was stored
     */
    public function save(int $userid, int $courseid, int $taskid, bool $succeeded, string $name, array $destination): bool {
        $key = $this->key($userid, $courseid);
        $lock = \core\lock\lock_config::get_lock_factory('block_sharing_cart_restore_outcomes')->get_lock($key, 5);
        if (!$lock) {
            return false;
        }
        try {
            $outcomes = $this->get_recent($userid, $courseid);
            unset($outcomes[$taskid]);
            $outcomes = [$taskid => [
                'id' => $taskid,
                'succeeded' => $succeeded,
                'name' => $succeeded ? '' : $name,
                'time' => time(),
            ] + $destination] + $outcomes;
            return \cache::make('block_sharing_cart', 'restore_outcomes')->set(
                $key,
                array_slice($outcomes, 0, self::MAX_OUTCOMES, true),
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Get unexpired restore outcomes for the user and destination course.
     *
     * @param int $userid Task owner
     * @param int $courseid Destination course
     * @return array<int, array{id: int, succeeded: bool, name: string, time: int, to_section_id?: int, refresh_course?: bool}>
     */
    public function get_recent(int $userid, int $courseid): array {
        $outcomes = \cache::make('block_sharing_cart', 'restore_outcomes')->get($this->key($userid, $courseid));
        $cutoff = time() - DAYSECS;
        return array_filter($outcomes ?: [], static fn(array $outcome): bool => $outcome['time'] > $cutoff);
    }

    /**
     * Build the cache and lock key for the user and destination course.
     *
     * @param int $userid Task owner
     * @param int $courseid Destination course
     * @return string Cache and lock key
     */
    private function key(int $userid, int $courseid): string {
        return $userid . '_' . $courseid;
    }
}
