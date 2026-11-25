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
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\item\entity;

/**
 * Limits restore selections to the chosen cart item, not its shared backup archive.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selection {
    /** @var basefactory Plugin factory. */
    private basefactory $basefactory;

    /**
     * Construct the restore selection validator.
     *
     * @param basefactory $basefactory plugin factory
     */
    public function __construct(basefactory $basefactory) {
        $this->basefactory = $basefactory;
    }

    /**
     * Normalize ID lists without expanding the legacy include-all sentinel.
     *
     * @param array $settings Restore settings
     * @return array Normalized restore settings
     */
    public function normalize(array $settings): array {
        foreach (['course_modules_to_include', 'sections_to_include'] as $key) {
            $settings[$key] = array_values(array_unique(array_map('intval', (array)($settings[$key] ?? []))));
        }
        return $settings;
    }

    /**
     * Resolve legacy defaults and validate explicit IDs using the stored cart tree, never the live source course.
     *
     * @param entity $item chosen cart item
     * @param array $settings restore settings
     * @param object|null $backupinfo Already-loaded backup metadata, when running in the worker
     * @return int[] original course-module IDs to include
     */
    public function module_ids(entity $item, array $settings, ?object $backupinfo = null): array {
        $allowedmodules = [];
        $allowedsections = [];
        foreach ($this->basefactory->item()->repository()->get_recursively_by_parent_id($item->get_id()) as $child) {
            if ($child->get_id() === $item->get_id() && ($item->is_section() || $item->is_subsection())) {
                continue;
            }
            if ($child->is_section()) {
                $allowedsections[] = (int)$child->get_old_instance_id();
            } else {
                $allowedmodules[] = (int)$child->get_old_instance_id();
            }
        }

        $settings = $this->normalize($settings);
        $moduleids = $settings['course_modules_to_include'];
        $sectionids = $settings['sections_to_include'];
        if (!$item->is_section() && !$item->is_subsection() && !$item->get_old_instance_id() && $item->get_version() === 1) {
            $legacyid = $this->legacy_activity_id($item, $backupinfo);
            $allowedmodules = [$legacyid];
            // Older clients submit the migrated row's zero ID even with explicit selection enabled.
            if ($moduleids === [0]) {
                $moduleids = [$legacyid];
            }
        }
        if (array_diff($moduleids, $allowedmodules, [0]) || array_diff($sectionids, $allowedsections, [0])) {
            throw new \invalid_parameter_exception('The restore selection contains items outside the chosen cart item.');
        }
        if (
            (in_array(0, $moduleids, true) && count($moduleids) > 1)
            || (in_array(0, $sectionids, true) && count($sectionids) > 1)
        ) {
            throw new \invalid_parameter_exception('A restore selection cannot combine zero with content IDs.');
        }

        if (empty($settings['module_selection_explicit']) && ($moduleids === [] || $moduleids === [0])) {
            $moduleids = array_values(array_diff($allowedmodules, [0]));
        }
        if ($moduleids === [0]) {
            $moduleids = [];
        }
        if ($sectionids === []) {
            $sectionids = $allowedsections;
        }
        $emptysubsection = $item->is_subsection() && $allowedmodules === [] && $allowedsections === [];
        if ($moduleids === [] && array_diff($sectionids, [0]) === [] && !$emptysubsection) {
            throw new \moodle_exception('atleast_one_course_module_must_be_included', 'block_sharing_cart');
        }

        return $moduleids;
    }

    /**
     * Resolve a migrated activity from its own single-activity archive, never a shared archive.
     *
     * @param entity $item Migrated cart activity
     * @param object|null $backupinfo Already-loaded backup metadata
     * @return int Original course-module ID
     */
    private function legacy_activity_id(entity $item, ?object $backupinfo): int {
        $file = $this->basefactory->item()->repository()->get_stored_file_by_item($item);
        if (!$file || (int)$file->get_itemid() !== $item->get_id()) {
            throw new \invalid_parameter_exception('A migrated activity must have its own backup archive.');
        }
        $backupinfo ??= $this->basefactory->backup()->handler()->get_backup_info($file);
        $activities = array_values((array)($backupinfo->activities ?? []));
        $activity = $activities[0] ?? null;
        if (
            ($backupinfo->type ?? '') !== \backup::TYPE_1ACTIVITY || count($activities) !== 1 ||
            (int)($activity->moduleid ?? 0) <= 0 || $item->get_type() !== 'mod_' . ($activity->modulename ?? '')
        ) {
            throw new \invalid_parameter_exception('Cannot identify the activity in the migrated backup archive.');
        }
        return (int)$activity->moduleid;
    }
}
