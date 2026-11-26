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
    /** @var string The restore did not reach Moodle's copy plan. */
    public const COPY_NOT_STARTED = 'not_started';
    /** @var string Moodle started copying but did not confirm completion. */
    public const COPY_UNCERTAIN = 'uncertain';
    /** @var string Moodle finished copying before the format's finishing steps. */
    public const COPY_COMPLETED = 'completed';

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
     * @param string $copystate How far Moodle got in copying the content
     * @return bool Whether the outcome was stored
     */
    public function save(
        int $userid,
        int $courseid,
        int $taskid,
        bool $succeeded,
        string $name,
        array $destination,
        string $copystate
    ): bool {
        $key = $this->key($userid);
        $lock = \core\lock\lock_config::get_lock_factory('block_sharing_cart_restore_outcomes')->get_lock($key, 5);
        if (!$lock) {
            return false;
        }
        try {
            $bycourse = $this->get_user_outcomes($userid);
            $outcomes = $bycourse[$courseid] ?? [];
            unset($outcomes[$taskid]);
            $outcomes = [$taskid => [
                'id' => $taskid,
                'succeeded' => $succeeded,
                'copy_state' => $copystate,
                'name' => $succeeded ? '' : $name,
                'time' => time(),
            ] + $destination] + $outcomes;
            $bycourse[$courseid] = array_slice($outcomes, 0, self::MAX_OUTCOMES, true);
            return \cache::make('block_sharing_cart', 'restore_outcomes')->set($key, $bycourse);
        } finally {
            $lock->release();
        }
    }

    /**
     * Get unexpired restore outcomes for the user and destination course.
     *
     * @param int $userid Task owner
     * @param int $courseid Destination course
     * @return array<int, array> Restore results keyed by task ID
     */
    public function get_recent(int $userid, int $courseid): array {
        return $this->get_user_outcomes($userid)[$courseid] ?? [];
    }

    /**
     * Whether this user has unexpired outcomes, even if a destination or cart item no longer exists.
     *
     * @param int $userid Task owner
     * @return bool
     */
    public function has_user_outcomes(int $userid): bool {
        return $this->get_user_outcomes($userid) !== [];
    }

    /**
     * Erase all outcomes for the user without affecting other users.
     *
     * @param int $userid Task owner
     */
    public function delete_for_user(int $userid): void {
        $key = $this->key($userid);
        $lock = \core\lock\lock_config::get_lock_factory('block_sharing_cart_restore_outcomes')->get_lock($key, 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            \cache::make('block_sharing_cart', 'restore_outcomes')->delete($key);
        } finally {
            $lock->release();
        }
    }

    /**
     * Read nonexpired outcomes for all the user's destination courses.
     *
     * @param int $userid Task owner
     * @return array<int, array> Outcomes grouped by destination course ID
     */
    private function get_user_outcomes(int $userid): array {
        $bycourse = \cache::make('block_sharing_cart', 'restore_outcomes')->get($this->key($userid)) ?: [];
        $cutoff = time() - DAYSECS;
        $recent = [];
        foreach ($bycourse as $courseid => $outcomes) {
            $outcomes = array_filter($outcomes, static fn(array $outcome): bool => $outcome['time'] > $cutoff);
            if ($outcomes !== []) {
                $recent[$courseid] = $outcomes;
            }
        }
        return $recent;
    }

    /**
     * Build the user-scoped cache and lock key. Older per-course notices expire without being read.
     *
     * @param int $userid Task owner
     * @return string Cache and lock key
     */
    private function key(int $userid): string {
        return 'user_' . $userid;
    }
}
