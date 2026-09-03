<?php

namespace block_sharing_cart\task;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use async_helper;
use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\hook\restore\after_sections_restored;
use block_sharing_cart\hook\restore\before_sections_restored;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class asynchronous_restore_task extends \core\task\adhoc_task
{
    /**
     * Section plan built before the restore plan executes and consumed after it finished.
     *
     * Shape:
     *   root_old_section_id   int   original id of the restored item's own section (0 for legacy items)
     *   target_section_id     int   section chosen by the user; the root section merges into it
     *   target_section_number int
     *   selective             bool  true when the restored item is a 'section' item and other sections in the backup
     *                               must be excluded
     *   sections              array keyed by old section id: descendant sections in depth-first order,
     *                               {old_section_id, old_parent_section_id, sort_order, new_section_number}
     */
    private array $section_plan = [];

    /**
     * Should always resemble
     * @see \core\task\asynchronous_restore_task::execute
     * with the addition of calling
     * @see self::before_restore_finished_hook
     * and
     * @see self::after_restore_finished_hook
     */
    public function execute(): void
    {
        $db = base_factory::make()->moodle()->db();
        $started = time();

        $customdata = $this->get_custom_data();
        $restoreid = $customdata->backupid;
        $restorerecord = $db->get_record(
            'backup_controllers',
            ['backupid' => $restoreid],
            'id, controller',
            IGNORE_MISSING
        );
        // If the record doesn't exist, the backup controller failed to create. Unable to proceed.
        if (empty($restorerecord)) {
            mtrace('Unable to find restore controller, ending restore execution.');
            return;
        }

        mtrace('Processing asynchronous restore for id: ' . $restoreid);

        // Get the backup controller by backup id. If controller is invalid, this task can never complete.
        if ($restorerecord->controller === '') {
            mtrace('Bad restore controller status, invalid controller, ending restore execution.');
            return;
        }

        /** @var \restore_controller $rc */
        $rc = \restore_controller::load_controller($restoreid);
        try {
           if (!$rc->execute_precheck(true)) {
                $results = $rc->get_precheck_results();
                if (!empty($results['errors'])) {
                    throw new \Exception("Errors found during restore precheck:\n" . implode("\n", $results['errors']));
                }
            }

            $rc->set_progress(new \core\progress\db_updater($restorerecord->id, 'backup_controllers', 'progress'));

            // Do some preflight checks on the restore.
            $status = $rc->get_status();
            $execution = $rc->get_execution();

            // Check that the restore is in the correct status and
            // that is set for asynchronous execution.
            if ($status == \backup::STATUS_AWAITING && $execution == \backup::EXECUTION_DELAYED) {
                $this->before_restore_finished_hook($rc);

                // Execute the restore.
                $rc->execute_plan();

                $this->after_restore_finished_hook($rc);

                // Send message to user if enabled.
                $coremessageenabled = (bool)get_config('backup', 'backup_async_message_users');
                $cartmessageenabled = (bool)get_config('block_sharing_cart', 'backup_async_message_users');
                $messageenabled = ($coremessageenabled && $cartmessageenabled);
                if ($messageenabled && $rc->get_status() == \backup::STATUS_FINISHED_OK) {
                    $asynchelper = new async_helper('restore', $restoreid);
                    $asynchelper->send_message();
                }
            } else {
                // If status isn't 700, it means the process has failed.
                // Retrying isn't going to fix it, so marked operation as failed.
                $rc->set_status(\backup::STATUS_FINISHED_ERR);
                mtrace('Bad backup controller status, is: ' . $status . ' should be 700, marking job as failed.');
            }

            $finished = time();
            $duration = $finished - $started;
            mtrace('Restore completed in: ' . $duration . ' seconds');

            $this->trigger_restored_event(
                $rc,
                $started,
                $finished
            );

        } catch (\Exception $e) {
            // If an exception is thrown, mark the restore as failed.
            $rc->set_status(\backup::STATUS_FINISHED_ERR);

            // Retrying isn't going to fix this, so add a no-retry flag to customdata.
            // We can cancel the task in the task manager.
            $customdata->noretry = true;
            $this->set_custom_data($customdata);

            mtrace('Exception thrown during restore execution, marking job as failed.');
            mtrace($e->getMessage());
        } finally {
            // Cleanup.
            // Always destroy the controller.
            $rc->destroy();
        }
    }

    public function retry_until_success(): bool
    {
        return false;
    }

    private function after_restore_finished_hook(\restore_controller $restore_controller): void
    {
        try {
            mtrace('Executing after_restore_finished_hook...');

            if (!empty($this->section_plan)) {
                $course_id = (int)$restore_controller->get_courseid();
                $target_section_id = (int)$this->section_plan['target_section_id'];

                $restored_sections = $this->resolve_restored_sections($restore_controller);

                mtrace('Placing ' . count($restored_sections) . ' restored nested section(s) after the target section...');
                $this->place_sections_after_target($course_id, $target_section_id, $restored_sections);

                \core\di::get(\core\hook\manager::class)->dispatch(new after_sections_restored(
                    course_id: $course_id,
                    target_section_id: $target_section_id,
                    restored_sections: $restored_sections,
                ));

                rebuild_course_cache($course_id, true);
            }

            mtrace('Executing after_restore_finished_hook completed...');
        } catch (\Exception $e) {
            mtrace("An error occurred: " . $e->getMessage());
            mtrace($e->getTraceAsString());

            // Uh uhh, something went wrong.
            throw $e;
        }
    }

    private function before_restore_finished_hook(\restore_controller $restore_controller): void
    {
        try {
            mtrace('Executing before_restore_finished_hook...');

            $customdata = $this->get_custom_data();

            $backup_settings = $customdata->backup_settings ?? null;

            $course_modules_to_include = array_map('intval', $backup_settings->course_modules_to_include ?? []);
            if (!empty($course_modules_to_include) && $course_modules_to_include !== [0]) {
                $this->only_include_specified_course_modules($restore_controller, $course_modules_to_include);
            }

            $move_to_section_id = $backup_settings->move_to_section_id ?? null;
            if ($move_to_section_id) {
                $this->section_plan = $this->plan_sections($restore_controller, $customdata, (int)$move_to_section_id);
                $this->apply_section_plan($restore_controller, $this->section_plan);

                \core\di::get(\core\hook\manager::class)->dispatch(new before_sections_restored(
                    restore_id: $restore_controller->get_restoreid(),
                    course_id: (int)$restore_controller->get_courseid(),
                    target_section_id: (int)$this->section_plan['target_section_id'],
                    planned_sections: array_values($this->section_plan['sections']),
                ));
            }

            $has_atleast_one_course_module_included = false;
            foreach ($restore_controller->get_plan()->get_tasks() as $task) {
                if (($task instanceof \restore_activity_task) && $task->get_setting('included')->get_value()) {
                    $has_atleast_one_course_module_included = true;
                    break;
                }
            }

            if (!$has_atleast_one_course_module_included && empty($this->section_plan['sections'])) {
                throw new \Exception('No course modules were included in the restore.');
            }

            mtrace('Executing before_restore_finished_hook completed, continuing with restore');
        } catch (\Exception $e) {
            mtrace("An error occurred: " . $e->getMessage());
            mtrace($e->getTraceAsString());

            // Uh uhh, something went wrong.
            throw $e;
        }
    }

    /**
     * Decide which sections of the backup are restored and which section number each of them gets.
     *
     * The restored item's own section is merged into the target section. Descendant section items (as recorded by a
     * nesting course format at backup time) become new sections with fresh numbers above the course's current
     * maximum, in depth-first order. Everything else in the backup is excluded.
     */
    private function plan_sections(\restore_controller $restore_controller, object $customdata, int $target_section_id): array
    {
        $db = base_factory::make()->moodle()->db();

        $target = $db->get_record('course_sections', ['id' => $target_section_id], 'id, course, section', MUST_EXIST);

        $plan = [
            'root_old_section_id' => 0,
            'target_section_id' => (int)$target->id,
            'target_section_number' => (int)$target->section,
            'selective' => false,
            'sections' => [],
        ];

        $repository = base_factory::make()->item()->repository();
        $item = $repository->get_by_id((int)($customdata->item->id ?? 0));

        // Core subsection items and single activity items keep the legacy behaviour.
        if (!$item || !$item->is_section()) {
            return $plan;
        }

        $plan['root_old_section_id'] = (int)$item->get_old_instance_id();
        $plan['selective'] = true;

        $sections_to_include = array_map('intval', (array)($customdata->backup_settings->sections_to_include ?? []));

        $children_by_parent = [];
        foreach ($repository->get_recursively_by_parent_id($item->get_id()) as $descendant) {
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

        $max_number = (int)$db->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ?',
            [$target->course]
        );

        $counter = 0;
        $walk = function (entity $parent) use (&$walk, &$plan, &$counter, $children_by_parent, $sections_to_include, $max_number): void {
            foreach ($children_by_parent[$parent->get_id()] ?? [] as $child) {
                $old_section_id = (int)$child->get_old_instance_id();

                // An excluded section drops its whole branch.
                if (!empty($sections_to_include) && !in_array($old_section_id, $sections_to_include, true)) {
                    continue;
                }

                $plan['sections'][$old_section_id] = (object)[
                    'old_section_id' => $old_section_id,
                    'old_parent_section_id' => (int)$parent->get_old_instance_id(),
                    'sort_order' => (int)($child->get_sortorder() ?? 0),
                    'new_section_number' => $max_number + (++$counter),
                ];

                $walk($child);
            }
        };
        $walk($item);

        return $plan;
    }

    /**
     * Rewrite the section numbers in the extracted backup and switch off the section tasks that are not part of the
     * plan.
     *
     * The section number is hardcoded in section.xml and module.xml and cannot be changed through the
     * restore_controller API, hence the rewrite on disk. Core places a section by that number: an existing number is
     * reused (merge), an unused number creates a new section. Modules are placed through the course_section mapping
     * first and only fall back to their sectionnumber, so only modules of the root section need rewriting.
     */
    private function apply_section_plan(\restore_controller $restore_controller, array $plan): void
    {
        $target_number = (int)$plan['target_section_number'];
        $selective = (bool)$plan['selective'];
        $root_old_section_id = (int)$plan['root_old_section_id'];
        $planned = $plan['sections'];

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

        $included_section_ids = array_merge([$root_old_section_id], array_keys($planned));
        $is_included = static function (int $old_section_id) use ($included_section_ids, $delegated_owners): bool {
            $old_section_id = $delegated_owners[$old_section_id] ?? $old_section_id;
            return in_array($old_section_id, $included_section_ids, true);
        };

        foreach ($restore_controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $old_section_id = $module_sections[(int)$task->get_old_moduleid()] ?? 0;
                if ($selective && !$is_included($old_section_id)) {
                    // Its section is not restored, so the module must not be either (whatever the modal sent).
                    $task->get_setting('included')->set_value(false);
                    continue;
                }
                if (!$selective || !isset($planned[$old_section_id])) {
                    $this->rewrite_xml_number("{$task->get_taskbasepath()}/module.xml", 'sectionnumber', $target_number);
                }
                continue;
            }

            if (!($task instanceof \restore_section_task)) {
                continue;
            }

            $old_section_id = $this->old_section_id_of_task($task);
            $section_xml_path = "{$task->get_taskbasepath()}/section.xml";

            if (!$selective) {
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

            if ($old_section_id === $root_old_section_id) {
                $this->rewrite_xml_number($section_xml_path, 'number', $target_number);
            } elseif (isset($planned[$old_section_id])) {
                mtrace("...Restoring nested section (id: $old_section_id) as section number {$planned[$old_section_id]->new_section_number}");
                $this->rewrite_xml_number($section_xml_path, 'number', (int)$planned[$old_section_id]->new_section_number);
            } else {
                mtrace("...Excluding section (id: $old_section_id)");
                $task->get_setting('included')->set_value(false);
            }
        }
    }

    /**
     * Original (backup side) id of the section a section task restores. restore_task::get_info() returns the whole
     * backup manifest, so the id is read from the task directory, which core names sections/section_<oldid>.
     */
    private function old_section_id_of_task(\restore_section_task $task): int
    {
        $directory = basename($task->get_taskbasepath());

        return (int)substr($directory, strlen('section_'));
    }

    private function rewrite_xml_number(string $path, string $element, int $value): void
    {
        $xml = simplexml_load_string(file_get_contents($path));
        $xml->{$element} = $value;
        $xml->asXML($path);
    }

    /**
     * Map the planned descendant sections to the sections core created, in depth-first order.
     *
     * @return object[] {old_section_id, new_section_id, new_parent_section_id, sort_order}
     */
    private function resolve_restored_sections(\restore_controller $restore_controller): array
    {
        $root_old_section_id = (int)$this->section_plan['root_old_section_id'];
        $target_section_id = (int)$this->section_plan['target_section_id'];

        // The backup_ids temp table is dropped by the last restore step, but every section task still holds the id
        // of the section it created or merged into (set by restore_section_structure_step::process_section).
        $created_ids = [];
        foreach ($restore_controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_section_task) {
                $created_ids[$this->old_section_id_of_task($task)] = (int)$task->get_sectionid();
            }
        }

        $new_ids = [];
        $restored = [];
        foreach ($this->section_plan['sections'] as $planned) {
            $new_section_id = $created_ids[(int)$planned->old_section_id] ?? 0;

            if ($new_section_id === 0 || $new_section_id === $target_section_id) {
                mtrace("...No new section found for nested section (id: {$planned->old_section_id}), skipping");
                continue;
            }

            $new_ids[$planned->old_section_id] = $new_section_id;

            $old_parent_section_id = (int)$planned->old_parent_section_id;
            $new_parent_section_id = $old_parent_section_id === $root_old_section_id
                ? $target_section_id
                : ($new_ids[$old_parent_section_id] ?? $target_section_id);

            $restored[] = (object)[
                'old_section_id' => (int)$planned->old_section_id,
                'new_section_id' => $new_section_id,
                'new_parent_section_id' => $new_parent_section_id,
                'sort_order' => (int)$planned->sort_order,
            ];
        }

        return $restored;
    }

    /**
     * Generic placement: put the restored sections directly after the target section, keeping depth-first order.
     * Nesting formats reorder afterwards through the after_sections_restored hook.
     */
    private function place_sections_after_target(int $course_id, int $target_section_id, array $restored_sections): void
    {
        if (empty($restored_sections)) {
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

    private function get_section_name($section_id) : ?string {

        $db = base_factory::make()->moodle()->db();
        $section_name = $db->get_field(
            'course_sections',
            'name',
            ['id' => $section_id],
            strictness: IGNORE_MISSING
        );

        if(!$section_name){
            return null;
        }

        return $section_name;
    }

    private function update_section_name($section_id, $section_name) : bool {

        $db = base_factory::make()->moodle()->db();

        return $db->set_field(
            'course_sections',
            'name',
            $section_name,
            ['id' => $section_id],
        );

    }

    private function only_include_specified_course_modules(
        \restore_controller $restore_controller,
        array $course_modules_to_include
    ): void {
        mtrace("Excluding/Including activities...");

        foreach ($restore_controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $cm_id = (int)$task->get_old_moduleid();

                $include_activity = in_array($cm_id, $course_modules_to_include, true);
                mtrace(
                    '...' . ($include_activity ? "Including activity: (id: $cm_id)" : "Excluding activity: (id: $cm_id)")
                );
                $task->get_setting('included')->set_value($include_activity);
            }
        }

    }

    private function trigger_restored_event(
        \restore_controller $controller,
        int $started,
        int $finished
    ): void {
        foreach ($controller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $this->trigger_restore_course_module_event($task, $started, $finished);
                continue;
            }

            if ($task instanceof \restore_section_task) {
                $this->trigger_restore_section_event($task, $started, $finished);
            }
        }
    }

    private function trigger_restore_course_module_event(
        \restore_activity_task $task,
        int $started,
        int $finished
    ): void {

        if($task->get_moduleid() === 0){
            mtrace("Course module id was 0. Skipping event creation for this module.");
            return;
        }

        $event = \block_sharing_cart\event\restored_course_module::create_by_course_module(
            $task->get_courseid(),
            $task->get_moduleid(),
            $task->get_modulename(),
            $task->get_userid(),
            $started,
            $finished
        );
        $event->trigger();
    }

    private function trigger_restore_section_event(
        \restore_section_task $task,
        int $started,
        int $finished
    ): void {
        $event = \block_sharing_cart\event\restored_section::create_by_section(
            $task->get_courseid(),
            $task->get_sectionid(),
            $task->get_userid(),
            $started,
            $finished
        );
        $event->trigger();
    }

    public function get_name(): string
    {
        return parent::get_name() . ' (block_sharing_cart)';
    }
}
