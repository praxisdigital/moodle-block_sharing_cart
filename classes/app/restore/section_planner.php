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

/**
 * Builds and applies the section plan of a cart restore.
 *
 * Core places a restored section by the <number> in its section.xml: an existing number is reused (merge), an unused
 * number creates a new section. Modules follow the course_section mapping first and only fall back to their
 * <sectionnumber>. The section number cannot be changed through the restore_controller API, hence the rewrite of the
 * extracted backup on disk.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class section_planner {
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
     * Names an unnamed new root without borrowing a generated label from the source course.
     *
     * @param int $courseid destination course, or 0 when unavailable
     * @param int $targetsectionid 0 for a top-level insertion
     * @return string Format-derived name where predictable, otherwise a generic label for the new section.
     */
    public function new_section_destination_name(int $courseid, int $targetsectionid): string {
        $fallback = get_string('newsection', 'format_topics');
        if ($courseid <= 0) {
            return $fallback;
        }

        $format = course_get_format($courseid);
        if ($format->get_format() === 'topics') {
            // Topics gives every unnamed section the same generated name, regardless of its eventual number.
            return $format->get_format_string('newsection');
        }

        if ($format->get_format() === 'weeks' && $targetsectionid === 0) {
            // Top-level sections stay at the end, using the same initial number as plan().
            $nextnumber = $this->max_regular_section_number($courseid) + 1;
            return $format->get_default_section_name((object)['section' => $nextnumber]);
        }

        // Moving a section or applying a format's hierarchy hooks can change its number and generated name.
        return $fallback;
    }

    /**
     * Find the last regular section number, ignoring delegated subsections.
     *
     * @param int $courseid Destination course
     * @return int
     */
    private function max_regular_section_number(int $courseid): int {
        return (int)$this->basefactory->moodle()->db()->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ? AND (component IS NULL OR component = ?)',
            [$courseid, '']
        );
    }

    /**
     * Decides which sections of the backup are restored and the section number each of them gets.
     *
     * @param \restore_controller $restorecontroller
     * @param entity|false $item the restored cart item
     * @param int $targetsectionid 0 = top level of the course
     * @param bool $insertasnewsection
     * @param int[] $sectionstoinclude old ids of nested sections to restore; empty = all
     * @param int[] $coursemodulestoinclude selected module ids; empty or [0] = legacy include-all unless selection is explicit
     * @param bool $moduleselectionexplicit whether the module list is explicit, including an empty selection
     * @return section_plan
     */
    public function plan(
        \restore_controller $restorecontroller,
        entity|false $item,
        int $targetsectionid,
        bool $insertasnewsection,
        array $sectionstoinclude,
        array $coursemodulestoinclude = [],
        bool $moduleselectionexplicit = false
    ): section_plan {
        $db = $this->basefactory->moodle()->db();
        $courseid = (int)$restorecontroller->get_courseid();

        if ($item && $item->is_section()) {
            $this->compact_section_numbers($courseid);
        }

        $targetsectionnumber = 0;
        if ($targetsectionid > 0) {
            $targetsectionnumber = (int)$db->get_field(
                'course_sections',
                'section',
                ['id' => $targetsectionid, 'course' => $courseid],
                MUST_EXIST
            );
        }

        $plan = new section_plan($targetsectionid, $targetsectionnumber);

        // Individual items never restore siblings: subsections retain their owner module and delegated section.
        if (!$item || !$item->is_section()) {
            if ($targetsectionid === 0) {
                throw new \Exception('Only section items can be restored to the top level of a course.');
            }
            $plan->coursemodulestoinclude = $coursemodulestoinclude;
            if ($item && $item->is_subsection()) {
                $task = $this->subsection_task($restorecontroller, $item, $coursemodulestoinclude);
                $plan->rootoldsectionid = $this->old_section_id_of_task($task);
                // The dialog header implicitly includes this container, not just its selected contents.
                $plan->coursemodulestoinclude[] = $task->get_delegated_cm();
            }
            return $plan;
        }

        $plan->rootoldsectionid = (int)$item->get_old_instance_id();
        $plan->selective = true;
        $plan->insertasnewsection = $insertasnewsection;
        $plan->explicitsectionids = array_values(array_filter($sectionstoinclude, static fn(int $id): bool => $id > 0));
        if ($moduleselectionexplicit) {
            // The dialog also explicitly selects the root item, even when none of its activities are checked.
            $plan->explicitsectionids[] = $plan->rootoldsectionid;
        }
        if ($moduleselectionexplicit || (!empty($coursemodulestoinclude) && $coursemodulestoinclude !== [0])) {
            $plan->coursemodulestoinclude = $coursemodulestoinclude;
        }
        $plan->sectionparents[$plan->rootoldsectionid] = 0;

        $maxnumber = $this->max_regular_section_number($courseid);
        $counter = 0;

        // As a new section the root is planned like a descendant, with the target (or the top level) as parent.
        if ($insertasnewsection) {
            $plan->add_section(
                $plan->rootoldsectionid,
                0,
                (int)($item->get_sortorder() ?? 0),
                $maxnumber + (++$counter)
            );
        }

        $childrenbyparent = $this->section_children_by_parent_item($item);

        $walk = function (
            entity $parent,
            int $effectiveparentid
        ) use (
            &$walk,
            $plan,
            &$counter,
            $childrenbyparent,
            $sectionstoinclude,
            $maxnumber
        ): void {
            foreach ($childrenbyparent[$parent->get_id()] ?? [] as $child) {
                $oldsectionid = (int)$child->get_old_instance_id();
                $plan->sectionparents[$oldsectionid] = (int)$parent->get_old_instance_id();

                // Excluding a section drops it, but explicitly selected descendants can be restored independently.
                if (!empty($sectionstoinclude) && !in_array($oldsectionid, $sectionstoinclude, true)) {
                    $walk($child, $effectiveparentid);
                    continue;
                }

                $plan->add_section(
                    $oldsectionid,
                    $effectiveparentid,
                    (int)($child->get_sortorder() ?? 0),
                    $maxnumber + (++$counter)
                );

                $walk($child, $oldsectionid);
            }
        };
        $walk($item, $plan->rootoldsectionid);

        return $plan;
    }

    /**
     * Resolve both historical root CM IDs and modern delegated-section IDs without consulting the source course.
     *
     * @param \restore_controller $controller backup being restored
     * @param entity $item selected subsection
     * @param int[] $moduleids selected child module IDs
     * @return \restore_section_task
     */
    private function subsection_task(\restore_controller $controller, entity $item, array $moduleids): \restore_section_task {
        $candidates = [];
        $oldid = (int)$item->get_old_instance_id();
        foreach ($controller->get_plan()->get_tasks() as $task) {
            if (!$task instanceof \restore_section_task || $task->get_delegated_cm() === null) {
                continue;
            }
            $sectionid = $this->old_section_id_of_task($task);
            // Nested items always use CM IDs. Root items have used both representations over time.
            if ($task->get_delegated_cm() === $oldid || ($item->get_parent_item_id() === null && $sectionid === $oldid)) {
                $candidates[$sectionid] = $task;
            }
        }
        if ($candidates === []) {
            throw new \coding_exception('The copied subsection was not found in its backup.');
        }
        if (count($candidates) > 1) {
            // IDs from separate tables can collide. Only the section containing all selected children is valid.
            $modulesections = $this->module_section_ids($controller);
            $candidates = array_filter($candidates, function (\restore_section_task $task) use (
                $moduleids,
                $modulesections
            ): bool {
                $sectionid = $this->old_section_id_of_task($task);
                if ($moduleids === []) {
                    return false;
                }
                foreach ($moduleids as $moduleid) {
                    if (($modulesections[$moduleid] ?? null) !== $sectionid) {
                        return false;
                    }
                }
                return true;
            });
        }
        if (count($candidates) !== 1) {
            throw new \coding_exception('The copied subsection identity is ambiguous in its backup.');
        }
        return reset($candidates);
    }

    /**
     * Omits empty structural sections, but preserves explicit selections, when the target format will not recreate nesting.
     *
     * @param \restore_controller $restorecontroller
     * @param section_plan $plan
     * @param bool $targetisnewsection whether the target is a temporary section created for this restore
     * @return void
     */
    public function omit_empty_sections(
        \restore_controller $restorecontroller,
        section_plan $plan,
        bool $targetisnewsection
    ): void {
        if (empty($plan->sections)) {
            return;
        }

        $modulesections = $this->module_section_ids($restorecontroller);
        [$delegatedowners, $delegatedsectionincluded] = $this->delegated_section_context(
            $restorecontroller,
            $plan,
            $modulesections
        );

        // Explicit section choices must survive even when their activity selection is empty.
        $sectionswithcontent = array_fill_keys($plan->explicitsectionids, true);
        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $oldmoduleid = (int)$task->get_old_moduleid();
                $selectedexplicitly = $plan->coursemodulestoinclude !== null
                    && in_array($oldmoduleid, $plan->coursemodulestoinclude, true);
                if ($task->get_setting('included')->get_value() || $selectedexplicitly) {
                    $oldsectionid = $modulesections[$oldmoduleid] ?? 0;
                    $destinationid = $this->nearest_included_section_id(
                        $oldsectionid,
                        $plan,
                        $delegatedowners,
                        $delegatedsectionincluded
                    );
                    // Selected modules disabled by their container's dependencies can be re-enabled by apply().
                    // Count their effective destinations now, before an apparently empty destination is omitted.
                    if ($destinationid !== null && ($destinationid === $oldsectionid || $selectedexplicitly)) {
                        $sectionswithcontent[$destinationid] = true;
                    }
                }
            }
            if ($task instanceof \restore_section_task && $this->section_has_summary($task)) {
                $sectionswithcontent[$this->old_section_id_of_task($task)] = true;
            }
        }

        $rootisnew = $plan->insertasnewsection || $targetisnewsection;
        $omitroot = $rootisnew && empty($sectionswithcontent[$plan->rootoldsectionid]);
        foreach (array_keys($plan->sections) as $oldsectionid) {
            if ((int)$oldsectionid === $plan->rootoldsectionid && $omitroot) {
                continue;
            }
            if (empty($sectionswithcontent[(int)$oldsectionid])) {
                mtrace("...Excluding empty section (id: $oldsectionid)");
                unset($plan->sections[$oldsectionid]);
            }
        }

        if ($omitroot) {
            $plan->omit_root();
        }

        if (!empty($plan->sections)) {
            $maxnumber = $this->max_regular_section_number((int)$restorecontroller->get_courseid());
            $plan->renumber_sections($maxnumber + 1);
        }
    }

    /**
     * Compact existing section numbers before core restore fills any gaps with empty sections.
     *
     * Moodle's restore cache step creates a regular section for every missing number up to the maximum. Moving the
     * last section to the first gap through the course-format action preserves section order while renumbering all
     * existing sections contiguously.
     *
     * @param int $courseid
     * @return void
     */
    private function compact_section_numbers(int $courseid): void {
        $db = $this->basefactory->moodle()->db();
        $sections = array_values($db->get_records(
            'course_sections',
            ['course' => $courseid],
            'section ASC, id ASC',
            'id, section'
        ));

        if (count($sections) < 2 || (int)$sections[0]->section !== 0) {
            return;
        }

        $expectednumber = 0;
        $firstmissingnumber = null;
        foreach ($sections as $section) {
            if ((int)$section->section !== $expectednumber) {
                $firstmissingnumber = $expectednumber;
                break;
            }
            $expectednumber++;
        }

        if ($firstmissingnumber === null || $firstmissingnumber === 0) {
            return;
        }

        $course = $db->get_record('course', ['id' => $courseid], 'id, marker', MUST_EXIST);
        $highlightedsectionid = $course->marker > 0
            ? $db->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $course->marker])
            : false;

        // Moving the last section to the missing position leaves the existing order unchanged and compacts all numbers.
        $lastsection = $sections[array_key_last($sections)];
        get_fast_modinfo($courseid, 0, true);
        $lastsectioninfo = get_fast_modinfo($courseid)->get_section_info_by_id((int)$lastsection->id, IGNORE_MISSING);
        if (
            !$lastsectioninfo
            || !\core_courseformat\formatactions::section($courseid)->move_at(
                $lastsectioninfo,
                $firstmissingnumber
            )
        ) {
            throw new \Exception('Could not compact destination section numbers before the Sharing Cart restore.');
        }

        if ($highlightedsectionid) {
            $modinfo = get_fast_modinfo($courseid);
            $highlightedsection = $modinfo->get_section_info_by_id((int)$highlightedsectionid);
            if ($highlightedsection) {
                \core_courseformat\formatactions::section($courseid)->set_marker($highlightedsection, true);
            }
        }
    }

    /**
     * Capture effective user-data choices before excluding containers can clear their dependent activity settings.
     *
     * @param \restore_controller $restorecontroller
     * @return array Original module id => effective userinfo setting.
     */
    public function activity_userinfo_settings(\restore_controller $restorecontroller): array {
        $settings = [];
        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $settings[(int)$task->get_old_moduleid()] = (bool)$task->get_setting('userinfo')->get_value();
            }
        }
        return $settings;
    }

    /**
     * Rewrite the section numbers in the extracted backup and switch off the section (and module) tasks that are not
     * part of the plan.
     *
     * @param \restore_controller $restorecontroller
     * @param section_plan $plan
     * @param array $activityuserinfo Effective activity user-data settings captured before scoping.
     * @return void
     */
    public function apply(\restore_controller $restorecontroller, section_plan $plan, array $activityuserinfo = []): void {
        $targetnumber = $plan->targetsectionnumber;
        $planned = $plan->sections;

        // Old module id => old section id, and delegated section id => owner section / inclusion.
        $modulesections = $this->module_section_ids($restorecontroller);
        [$delegatedowners, $delegatedsectionincluded] = $this->delegated_section_context(
            $restorecontroller,
            $plan,
            $modulesections
        );

        $moduleexplicitlyselected = static function (int $oldmoduleid) use ($plan): bool {
            return $plan->coursemodulestoinclude !== null
                && in_array($oldmoduleid, $plan->coursemodulestoinclude, true);
        };
        $includedsectionids = $plan->included_section_ids();

        $isincluded = static function (int $oldsectionid) use ($includedsectionids, $delegatedsectionincluded): bool {
            if (isset($delegatedsectionincluded[$oldsectionid])) {
                return $delegatedsectionincluded[$oldsectionid];
            }
            return in_array($oldsectionid, $includedsectionids, true);
        };

        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $oldmoduleid = (int)$task->get_old_moduleid();
                $oldsectionid = $modulesections[$oldmoduleid] ?? 0;
                $selectedexplicitly = $moduleexplicitlyselected($oldmoduleid);
                if ($selectedexplicitly && !$task->get_setting('included')->get_value()) {
                    // A checked item can have been disabled by a dependency on an excluded section or delegated section.
                    $task->get_setting('included')->set_value(true);
                }

                if ($plan->selective) {
                    $nearestincludedsectionid = $this->nearest_included_section_id(
                        $oldsectionid,
                        $plan,
                        $delegatedowners,
                        $delegatedsectionincluded
                    );

                    if ($nearestincludedsectionid === null) {
                        // The activity is not in the copied section tree.
                        $task->get_setting('included')->set_value(false);
                        continue;
                    }

                    if (!$isincluded($oldsectionid)) {
                        if (!$selectedexplicitly) {
                            // Only explicitly selected modules can be moved out of an excluded section.
                            $task->get_setting('included')->set_value(false);
                            continue;
                        }

                        // Excluding an ancestor section can disable a selected activity through core task dependencies.
                        $task->get_setting('included')->set_value(true);

                        // The user explicitly selected an activity under an excluded section; flatten it into the
                        // nearest included section rather than dropping it or restoring its excluded ancestors.
                        $plan->flatteneddestinations[$oldmoduleid] = $nearestincludedsectionid;
                        $this->rewrite_xml_number(
                            "{$task->get_taskbasepath()}/module.xml",
                            'sectionnumber',
                            $this->planned_section_number($nearestincludedsectionid, $plan)
                        );
                        continue;
                    }
                }
                $hasincludeddelegatedsection = !empty($delegatedsectionincluded[$oldsectionid]);
                if (!$plan->selective || (!isset($planned[$oldsectionid]) && !$hasincludeddelegatedsection)) {
                    $sectionnumber = $plan->selective
                        ? $this->planned_section_number($nearestincludedsectionid, $plan)
                        : $targetnumber;
                    $this->rewrite_xml_number("{$task->get_taskbasepath()}/module.xml", 'sectionnumber', $sectionnumber);
                }
                continue;
            }

            if (!($task instanceof \restore_section_task)) {
                continue;
            }

            $oldsectionid = $this->old_section_id_of_task($task);
            $sectionxmlpath = "{$task->get_taskbasepath()}/section.xml";

            if (!$plan->selective) {
                // Retain only the chosen delegated section. Core creates it and maps its selected children to it.
                // Its owner module is placed in the target; unrelated regular section details must not be merged.
                $included = $oldsectionid === $plan->rootoldsectionid;
                $setting = $task->get_setting('included');
                if (
                    !$included && $plan->rootoldsectionid > 0 && $task->get_delegated_cm() === null &&
                    $setting->get_value() && $setting->get_status() === \base_setting::LOCKED_BY_PERMISSION
                ) {
                    // This is the archive's wrapper, not part of the subsection the user is restoring.
                    // Moodle locks configuration, but excluding the wrapper enforces the cart item's scope.
                    $setting->set_status(\base_setting::NOT_LOCKED);
                    try {
                        $setting->set_value(false);
                    } finally {
                        $setting->set_status(\base_setting::LOCKED_BY_PERMISSION);
                    }
                } else if ((bool)$setting->get_value() !== $included) {
                    $setting->set_value($included);
                }
                continue;
            }

            if ($task->get_delegated_cm() !== null) {
                // Core numbers delegated sections itself. Keep it when its own parent module is selected, even if an
                // ancestor custom section is excluded; otherwise it is excluded with the unchecked subsection.
                $included = $isincluded($oldsectionid);
                $setting = $task->get_setting('included');
                if ((bool)$setting->get_value() !== $included) {
                    if (!$included) {
                        mtrace("...Excluding delegated section (id: $oldsectionid)");
                    }
                    $setting->set_value($included);
                }
                continue;
            }

            if ($plan->rootomitted && $oldsectionid === $plan->rootoldsectionid) {
                mtrace("...Excluding empty structural root section (id: $oldsectionid)");
                $task->get_setting('included')->set_value(false);
                continue;
            }

            if (isset($planned[$oldsectionid])) {
                // A descendant, or the root itself when it is inserted as a new section.
                $newnumber = (int)$planned[$oldsectionid]->new_section_number;
                mtrace("...Restoring section (id: $oldsectionid) as new section number $newnumber");
                $this->rewrite_xml_number($sectionxmlpath, 'number', $newnumber);
            } else if ($oldsectionid === $plan->rootoldsectionid) {
                $this->rewrite_xml_number($sectionxmlpath, 'number', $targetnumber);
            } else {
                mtrace("...Excluding section (id: $oldsectionid)");
                $task->get_setting('included')->set_value(false);
            }
        }

        // Disabling section tasks can disable their activities through core setting dependencies.
        // Restore only the scoped content (and the chosen subsection's owner) after applying all section settings.
        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $oldmoduleid = (int)$task->get_old_moduleid();
                if (!$plan->selective) {
                    $included = $moduleexplicitlyselected($oldmoduleid);
                    $setting = $task->get_setting('included');
                    if ((bool)$setting->get_value() !== $included) {
                        $setting->set_value($included);
                    }
                }
                $userinfo = $task->get_setting('userinfo');
                if (
                    $task->get_setting('included')->get_value()
                    && isset($activityuserinfo[$oldmoduleid])
                    && $restorecontroller->get_plan()->get_setting('users')->get_value()
                    && !in_array(
                        $userinfo->get_status(),
                        [\base_setting::LOCKED_BY_PERMISSION, \base_setting::LOCKED_BY_CONFIG],
                        true
                    )
                ) {
                    // Removing an unrelated section must not discard the selected activity's attempts or grades.
                    $userinfo->set_value($activityuserinfo[$oldmoduleid]);
                }
            }
        }
    }

    /**
     * Verify flattened placements after all destination sections have been created by Moodle.
     *
     * @param \restore_controller $restorecontroller The completed restore
     * @param section_plan $plan Its Sharing Cart placement plan
     */
    public function place_flattened_activities(\restore_controller $restorecontroller, section_plan $plan): void {
        if ($plan->flatteneddestinations === []) {
            return;
        }

        $sectionids = [];
        $tasks = $restorecontroller->get_plan()->get_tasks();
        foreach ($tasks as $task) {
            if ($task instanceof \restore_section_task && $task->get_setting('included')->get_value()) {
                $sectionids[$this->old_section_id_of_task($task)] = (int)$task->get_sectionid();
            }
        }
        if ($plan->merges_into_target()) {
            $sectionids[$plan->rootoldsectionid] = $plan->targetsectionid;
        }

        $courseid = (int)$restorecontroller->get_courseid();
        $modinfo = get_fast_modinfo($courseid);
        $actions = \core_courseformat\formatactions::cm($courseid);
        foreach ($tasks as $task) {
            if (!$task instanceof \restore_activity_task || !$task->get_setting('included')->get_value()) {
                continue;
            }
            $oldtarget = $plan->flatteneddestinations[(int)$task->get_old_moduleid()] ?? null;
            if ($oldtarget === null) {
                continue;
            }
            $cmid = (int)$task->get_moduleid();
            $target = $sectionids[$oldtarget] ?? 0;
            if (!$target || !$modinfo->get_section_info_by_id($target) || !$cmid || !isset($modinfo->get_cms()[$cmid])) {
                throw new \coding_exception('Could not locate the restored activity or its planned destination.');
            }
            // An earlier activity task may have used Moodle's fallback when this section did not yet exist.
            if ((int)$modinfo->get_cm($cmid)->section !== $target && !$actions->move_end_section($cmid, $target)) {
                throw new \coding_exception('Could not place the restored activity in its planned destination.');
            }
        }
    }

    /**
     * Shared subsection ownership and inclusion rules for empty-section detection and activity placement.
     *
     * @param \restore_controller $restorecontroller
     * @param section_plan $plan
     * @param array $modulesections Old module id => old section id.
     * @return array{array<int, int>, array<int, bool>} Delegated owners and inclusion, keyed by old section id.
     */
    private function delegated_section_context(
        \restore_controller $restorecontroller,
        section_plan $plan,
        array $modulesections
    ): array {
        $owners = [];
        $included = [];
        $includedsectionids = $plan->included_section_ids();
        foreach ($restorecontroller->get_info()->sections ?? [] as $section) {
            $parentmoduleid = (int)($section->parentcmid ?? 0);
            if ($parentmoduleid && isset($modulesections[$parentmoduleid])) {
                $sectionid = (int)$section->sectionid;
                $owners[$sectionid] = $modulesections[$parentmoduleid];
                $included[$sectionid] = $plan->coursemodulestoinclude === null
                    ? in_array($owners[$sectionid], $includedsectionids, true)
                    : in_array($parentmoduleid, $plan->coursemodulestoinclude, true);
            }
        }
        return [$owners, $included];
    }

    /**
     * Finds the nearest planned section for an original section, following delegated subsection ownership and the
     * cart's section tree through excluded ancestors.
     *
     * @param int $oldsectionid original section id, possibly delegated
     * @param section_plan $plan
     * @param array $delegatedowners delegated section id => section containing its parent module
     * @param array $delegatedsectionincluded delegated section id => whether its parent module is selected
     * @return int|null original id of the nearest included section, or null when it is outside the copied tree
     */
    private function nearest_included_section_id(
        int $oldsectionid,
        section_plan $plan,
        array $delegatedowners,
        array $delegatedsectionincluded
    ): ?int {
        if (!empty($delegatedsectionincluded[$oldsectionid])) {
            return $oldsectionid;
        }

        $oldsectionid = $delegatedowners[$oldsectionid] ?? $oldsectionid;
        $visited = [];

        while ($oldsectionid > 0 && !isset($visited[$oldsectionid])) {
            if ($oldsectionid === $plan->rootoldsectionid || isset($plan->sections[$oldsectionid])) {
                return $oldsectionid;
            }

            $visited[$oldsectionid] = true;
            $oldsectionid = $plan->sectionparents[$oldsectionid] ?? 0;
        }

        return null;
    }

    /**
     * Target course section number for an included original section.
     *
     * @param int $oldsectionid original section id
     * @param section_plan $plan
     * @return int
     */
    private function planned_section_number(int $oldsectionid, section_plan $plan): int {
        if (isset($plan->sections[$oldsectionid])) {
            return (int)$plan->sections[$oldsectionid]->new_section_number;
        }

        return $plan->targetsectionnumber;
    }

    /**
     * Map the planned sections to the sections core created, in depth-first order.
     *
     * The backup_ids temp table is dropped by the last restore step, but every section task still holds the id of
     * the section it created or merged into (set by restore_section_structure_step::process_section).
     *
     * @param \restore_controller $restorecontroller
     * @param section_plan $plan
     * @return object[] {old_section_id, new_section_id, new_parent_section_id, sort_order}
     */
    public function resolve_restored_sections(\restore_controller $restorecontroller, section_plan $plan): array {
        $createdids = [];
        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_section_task) {
                $createdids[$this->old_section_id_of_task($task)] = (int)$task->get_sectionid();
            }
        }

        $newids = [];
        $restored = [];
        foreach ($plan->sections as $planned) {
            $newsectionid = $createdids[(int)$planned->old_section_id] ?? 0;

            if ($newsectionid === 0 || $newsectionid === $plan->targetsectionid) {
                mtrace("...No new section found for nested section (id: {$planned->old_section_id}), skipping");
                continue;
            }

            $newids[$planned->old_section_id] = $newsectionid;

            // A flat format may omit an otherwise planned parent after preflight. Walk past any such sections.
            // The root either has a new ID or falls back to the selected target section (0 = top level).
            $parentoldsectionid = (int)$planned->old_parent_section_id;
            $visited = [];
            while (
                $parentoldsectionid > 0 &&
                !isset($newids[$parentoldsectionid]) &&
                $parentoldsectionid !== $plan->rootoldsectionid &&
                !isset($visited[$parentoldsectionid])
            ) {
                $visited[$parentoldsectionid] = true;
                $parentoldsectionid = $plan->sectionparents[$parentoldsectionid] ?? 0;
            }

            $restored[] = (object)[
                'old_section_id' => (int)$planned->old_section_id,
                'new_section_id' => $newsectionid,
                'new_parent_section_id' => $newids[$parentoldsectionid] ?? $plan->targetsectionid,
                'sort_order' => (int)$planned->sort_order,
            ];
        }

        return $restored;
    }

    /**
     * Generic placement: put the restored sections directly after the target section, keeping depth-first order.
     * Nesting formats reorder afterwards through the after_sections_restored hook.
     *
     * @param int $courseid
     * @param int $targetsectionid 0 = top level of the course
     * @param object[] $restoredsections as returned by resolve_restored_sections()
     * @return void
     */
    public function place_after_target(int $courseid, int $targetsectionid, array $restoredsections): void {
        // Top level of the course: the fresh numbers already put the new sections at the end.
        if (empty($restoredsections) || $targetsectionid === 0) {
            return;
        }

        $actions = \core_courseformat\formatactions::section($courseid);

        $precedingsectionid = $targetsectionid;
        foreach ($restoredsections as $restored) {
            $modinfo = get_fast_modinfo($courseid);
            $section = $modinfo->get_section_info_by_id($restored->new_section_id, IGNORE_MISSING);
            $preceding = $modinfo->get_section_info_by_id($precedingsectionid, IGNORE_MISSING);
            if (!$section || !$preceding) {
                continue;
            }

            $actions->move_after($section, $preceding);
            $precedingsectionid = $restored->new_section_id;
        }
    }

    /**
     * Child section items keyed by parent item id, siblings in cart order.
     *
     * @param entity $item the restored cart item
     * @return array<int, entity[]>
     */
    private function section_children_by_parent_item(entity $item): array {
        $childrenbyparent = [];
        foreach ($this->basefactory->item()->repository()->get_recursively_by_parent_id($item->get_id()) as $descendant) {
            if (!$descendant->is_section() || $descendant->get_parent_item_id() === null) {
                continue;
            }
            $childrenbyparent[$descendant->get_parent_item_id()][] = $descendant;
        }
        foreach ($childrenbyparent as &$siblings) {
            usort($siblings, static function (entity $a, entity $b): int {
                return (($a->get_sortorder() ?? 0) <=> ($b->get_sortorder() ?? 0)) ?: ($a->get_id() <=> $b->get_id());
            });
        }
        unset($siblings);

        return $childrenbyparent;
    }

    /**
     * Original (backup side) id of the section a section task restores. restore_task::get_info() returns the whole
     * backup manifest, so the id is read from the task directory, which core names sections/section_<oldid>.
     *
     * @param \restore_section_task $task
     * @return int
     */
    private function old_section_id_of_task(\restore_section_task $task): int {
        return (int)substr(basename($task->get_taskbasepath()), strlen('section_'));
    }

    /**
     * Original section IDs for activity tasks, keyed by original course-module ID.
     *
     * Prefer the activity's own backup XML and fall back to restore info for older backups.
     *
     * @param \restore_controller $restorecontroller
     * @return int[]
     */
    private function module_section_ids(\restore_controller $restorecontroller): array {
        $modulesections = [];
        foreach ($restorecontroller->get_info()->activities ?? [] as $activity) {
            $modulesections[(int)$activity->moduleid] = (int)$activity->sectionid;
        }

        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if (!$task instanceof \restore_activity_task) {
                continue;
            }

            $modulexml = simplexml_load_string(file_get_contents("{$task->get_taskbasepath()}/module.xml"));
            if ($modulexml !== false && isset($modulexml->sectionid)) {
                $modulesections[(int)$task->get_old_moduleid()] = (int)$modulexml->sectionid;
            }
        }

        return $modulesections;
    }

    /**
     * Whether a restored section has a nonblank summary in its backup data.
     *
     * @param \restore_section_task $task
     * @return bool
     */
    private function section_has_summary(\restore_section_task $task): bool {
        $sectionxml = file_get_contents("{$task->get_taskbasepath()}/section.xml");
        if ($sectionxml === false) {
            return false;
        }

        $data = simplexml_load_string($sectionxml);
        return $data !== false && trim((string)$data->summary) !== '';
    }

    /**
     * Sets a numeric element of an extracted backup XML file.
     *
     * @param string $path
     * @param string $element
     * @param int $value
     * @return void
     */
    private function rewrite_xml_number(string $path, string $element, int $value): void {
        $xml = simplexml_load_string(file_get_contents($path));
        $xml->{$element} = $value;
        $xml->asXML($path);
    }
}
