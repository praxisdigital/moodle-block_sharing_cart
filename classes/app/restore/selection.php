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
     * Resolve legacy defaults and validate explicit IDs using the stored cart tree, never the live source course.
     *
     * @param entity $item chosen cart item
     * @param array $settings restore settings
     * @return int[] original course-module IDs to include
     */
    public function module_ids(entity $item, array $settings): array {
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

        $moduleids = array_values(array_unique(array_map('intval', (array)($settings['course_modules_to_include'] ?? []))));
        $sectionids = array_values(array_unique(array_map('intval', (array)($settings['sections_to_include'] ?? []))));
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
            $moduleids = $allowedmodules;
        }
        if ($moduleids === [0]) {
            $moduleids = [];
        }
        if ($sectionids === []) {
            $sectionids = $allowedsections;
        }
        if ($moduleids === [] && array_diff($sectionids, [0]) === []) {
            throw new \moodle_exception('atleast_one_course_module_must_be_included', 'block_sharing_cart');
        }

        return $moduleids;
    }
}
