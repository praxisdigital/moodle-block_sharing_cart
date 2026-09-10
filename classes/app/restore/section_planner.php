<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;

/**
 * Builds and applies the section plan of a cart restore.
 *
 * Core places a restored section by the <number> in its section.xml: an existing number is reused (merge), an unused
 * number creates a new section. Modules follow the course_section mapping first and only fall back to their
 * <sectionnumber>. The section number cannot be changed through the restore_controller API, hence the rewrite of the
 * extracted backup on disk.
 */
final class section_planner
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    /**
     * @param \restore_controller $restore_controller
     * @param entity|false $item the restored cart item
     * @param int $target_section_id 0 = top level of the course
     * @param bool $insert_as_new_section
     * @param int[] $sections_to_include old ids of nested sections to restore; empty = all
     */
    public function plan(
        \restore_controller $restore_controller,
        entity|false $item,
        int $target_section_id,
        bool $insert_as_new_section,
        array $sections_to_include
    ): section_plan {
        $db = $this->base_factory->moodle()->db();
        $course_id = (int)$restore_controller->get_courseid();

        $target_section_number = 0;
        if ($target_section_id > 0) {
            $target_section_number = (int)$db->get_field(
                'course_sections',
                'section',
                ['id' => $target_section_id, 'course' => $course_id],
                MUST_EXIST
            );
        }

        $plan = new section_plan($target_section_id, $target_section_number);

        // Core subsection items and single activity items keep the legacy behaviour.
        if (!$item || !$item->is_section()) {
            if ($target_section_id === 0) {
                throw new \Exception('Only section items can be restored to the top level of a course.');
            }
            return $plan;
        }

        $plan->root_old_section_id = (int)$item->get_old_instance_id();
        $plan->selective = true;
        $plan->insert_as_new_section = $insert_as_new_section;

        $max_number = (int)$db->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ?',
            [$course_id]
        );
        $counter = 0;

        // As a new section the root is planned like a descendant, with the target (or the top level) as parent.
        if ($insert_as_new_section) {
            $plan->add_section(
                $plan->root_old_section_id,
                0,
                (int)($item->get_sortorder() ?? 0),
                $max_number + (++$counter)
            );
        }

        $children_by_parent = $this->section_children_by_parent_item($item);

        $walk = function (entity $parent) use (&$walk, $plan, &$counter, $children_by_parent, $sections_to_include, $max_number): void {
            foreach ($children_by_parent[$parent->get_id()] ?? [] as $child) {
                $old_section_id = (int)$child->get_old_instance_id();

                // An excluded section drops its whole branch.
                if (!empty($sections_to_include) && !in_array($old_section_id, $sections_to_include, true)) {
                    continue;
                }

                $plan->add_section(
                    $old_section_id,
                    (int)$parent->get_old_instance_id(),
                    (int)($child->get_sortorder() ?? 0),
                    $max_number + (++$counter)
                );

                $walk($child);
            }
        };
        $walk($item);

        return $plan;
    }

    /**
     * Rewrite the section numbers in the extracted backup and switch off the section (and module) tasks that are not
     * part of the plan.
     */
    public function apply(\restore_controller $restore_controller, section_plan $plan): void
    {
        $target_number = $plan->target_section_number;
        $planned = $plan->sections;

        // Old module id => old section id, and delegated (core subsection) section id => the section owning its
        // parent module, used to tell whether something belongs to an included branch.
        $module_sections = [];
        foreach ($restore_controller->get_info()->activities ?? [] as $activity) {
            $module_sections[(int)$activity->moduleid] = (int)$activity->sectionid;
        }
        $delegated_owners = [];
        foreach ($restore_controller->get_info()->sections ?? [] as $section) {
            if (!empty($section->parentcmid) && isset($module_sections[(int)$section->parentcmid])) {
                $delegated_owners[(int)$section->sectionid] = $module_sections[(int)$section->parentcmid];
            }
        }

        $included_section_ids = $plan->included_section_ids();
        $is_included = static function (int $old_section_id) use ($included_section_ids, $delegated_owners): bool {
            $old_section_id = $delegated_owners[$old_section_id] ?? $old_section_id;
            return in_array($old_section_id, $included_section_ids, true);
        };

        foreach ($restore_controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $old_section_id = $module_sections[(int)$task->get_old_moduleid()] ?? 0;
                if ($plan->selective && !$is_included($old_section_id)) {
                    // Its section is not restored, so the module must not be either (whatever the modal sent).
                    $task->get_setting('included')->set_value(false);
                    continue;
                }
                if (!$plan->selective || !isset($planned[$old_section_id])) {
                    $this->rewrite_xml_number("{$task->get_taskbasepath()}/module.xml", 'sectionnumber', $target_number);
                }
                continue;
            }

            if (!($task instanceof \restore_section_task)) {
                continue;
            }

            $old_section_id = $this->old_section_id_of_task($task);
            $section_xml_path = "{$task->get_taskbasepath()}/section.xml";

            if (!$plan->selective) {
                // Legacy behaviour: everything merges into the target section.
                $this->rewrite_xml_number($section_xml_path, 'number', $target_number);
                continue;
            }

            if ($task->get_delegated_cm() !== null) {
                // Core numbers delegated sections itself; only make sure excluded branches stay excluded.
                if (!$is_included($old_section_id)) {
                    mtrace("...Excluding delegated section (id: $old_section_id)");
                    $task->get_setting('included')->set_value(false);
                }
                continue;
            }

            if (isset($planned[$old_section_id])) {
                // A descendant, or the root itself when it is inserted as a new section.
                $new_number = (int)$planned[$old_section_id]->new_section_number;
                mtrace("...Restoring section (id: $old_section_id) as new section number $new_number");
                $this->rewrite_xml_number($section_xml_path, 'number', $new_number);
            } elseif ($old_section_id === $plan->root_old_section_id) {
                $this->rewrite_xml_number($section_xml_path, 'number', $target_number);
            } else {
                mtrace("...Excluding section (id: $old_section_id)");
                $task->get_setting('included')->set_value(false);
            }
        }
    }

    /**
     * Map the planned sections to the sections core created, in depth-first order.
     *
     * The backup_ids temp table is dropped by the last restore step, but every section task still holds the id of
     * the section it created or merged into (set by restore_section_structure_step::process_section).
     *
     * @return object[] {old_section_id, new_section_id, new_parent_section_id, sort_order}
     */
    public function resolve_restored_sections(\restore_controller $restore_controller, section_plan $plan): array
    {
        $created_ids = [];
        foreach ($restore_controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_section_task) {
                $created_ids[$this->old_section_id_of_task($task)] = (int)$task->get_sectionid();
            }
        }

        $new_ids = [];
        $restored = [];
        foreach ($plan->sections as $planned) {
            $new_section_id = $created_ids[(int)$planned->old_section_id] ?? 0;

            if ($new_section_id === 0 || $new_section_id === $plan->target_section_id) {
                mtrace("...No new section found for nested section (id: {$planned->old_section_id}), skipping");
                continue;
            }

            $new_ids[$planned->old_section_id] = $new_section_id;

            // The parent is the new id of the parent section when that was created in this restore (a descendant,
            // or the root inserted as a new section); otherwise it is the target section (or 0 = top level).
            $restored[] = (object)[
                'old_section_id' => (int)$planned->old_section_id,
                'new_section_id' => $new_section_id,
                'new_parent_section_id' => $new_ids[(int)$planned->old_parent_section_id] ?? $plan->target_section_id,
                'sort_order' => (int)$planned->sort_order,
            ];
        }

        return $restored;
    }

    /**
     * Generic placement: put the restored sections directly after the target section, keeping depth-first order.
     * Nesting formats reorder afterwards through the after_sections_restored hook.
     *
     * @param object[] $restored_sections as returned by resolve_restored_sections()
     */
    public function place_after_target(int $course_id, int $target_section_id, array $restored_sections): void
    {
        // Top level of the course: the fresh numbers already put the new sections at the end.
        if (empty($restored_sections) || $target_section_id === 0) {
            return;
        }

        $actions = \core_courseformat\formatactions::section($course_id);

        $preceding_section_id = $target_section_id;
        foreach ($restored_sections as $restored) {
            $modinfo = get_fast_modinfo($course_id);
            $section = $modinfo->get_section_info_by_id($restored->new_section_id, IGNORE_MISSING);
            $preceding = $modinfo->get_section_info_by_id($preceding_section_id, IGNORE_MISSING);
            if (!$section || !$preceding) {
                continue;
            }

            $actions->move_after($section, $preceding);
            $preceding_section_id = $restored->new_section_id;
        }
    }

    /**
     * Child section items keyed by parent item id, siblings in cart order.
     *
     * @return array<int, entity[]>
     */
    private function section_children_by_parent_item(entity $item): array
    {
        $children_by_parent = [];
        foreach ($this->base_factory->item()->repository()->get_recursively_by_parent_id($item->get_id()) as $descendant) {
            if (!$descendant->is_section() || $descendant->get_parent_item_id() === null) {
                continue;
            }
            $children_by_parent[$descendant->get_parent_item_id()][] = $descendant;
        }
        foreach ($children_by_parent as &$siblings) {
            usort($siblings, static function (entity $a, entity $b): int {
                return (($a->get_sortorder() ?? 0) <=> ($b->get_sortorder() ?? 0)) ?: ($a->get_id() <=> $b->get_id());
            });
        }
        unset($siblings);

        return $children_by_parent;
    }

    /**
     * Original (backup side) id of the section a section task restores. restore_task::get_info() returns the whole
     * backup manifest, so the id is read from the task directory, which core names sections/section_<oldid>.
     */
    private function old_section_id_of_task(\restore_section_task $task): int
    {
        return (int)substr(basename($task->get_taskbasepath()), strlen('section_'));
    }

    private function rewrite_xml_number(string $path, string $element, int $value): void
    {
        $xml = simplexml_load_string(file_get_contents($path));
        $xml->{$element} = $value;
        $xml->asXML($path);
    }
}
