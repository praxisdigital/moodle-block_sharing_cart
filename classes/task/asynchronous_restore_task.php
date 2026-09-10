<?php

namespace block_sharing_cart\task;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use async_helper;
use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\restore\section_plan;
use block_sharing_cart\hook\restore\after_sections_restored;
use block_sharing_cart\hook\restore\before_sections_restored;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class asynchronous_restore_task extends \core\task\adhoc_task
{
    private ?section_plan $section_plan = null;

    /** Section whose title and description were blanked for replacement; 0 when none. */
    private int $replaced_section_id = 0;

    protected function factory(): base_factory
    {
        return base_factory::make();
    }

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
        $db = $this->factory()->moodle()->db();
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

                $this->discard_replaced_section_details($rc);

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
            $this->rollback_replaced_section_details($rc);

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

    /**
     * Runs before execute_plan(): decides which sections are restored and where, and lets the course format prepare.
     */
    private function before_restore_finished_hook(\restore_controller $restore_controller): void
    {
        try {
            mtrace('Executing before_restore_finished_hook...');

            $customdata = $this->get_custom_data();
            $backup_settings = $customdata->backup_settings ?? (object)[];

            $course_modules_to_include = array_map('intval', $backup_settings->course_modules_to_include ?? []);
            if (!empty($course_modules_to_include) && $course_modules_to_include !== [0]) {
                $this->only_include_specified_course_modules($restore_controller, $course_modules_to_include);
            }

            $move_to_section_id = (int)($backup_settings->move_to_section_id ?? 0);
            $insert_as_new_section = !empty($backup_settings->insert_as_new_section);
            if ($move_to_section_id > 0 || $insert_as_new_section) {
                $planner = $this->factory()->restore()->section_planner();

                $this->section_plan = $planner->plan(
                    $restore_controller,
                    $this->factory()->item()->repository()->get_by_id((int)($customdata->item->id ?? 0)),
                    $move_to_section_id,
                    $insert_as_new_section,
                    array_map('intval', (array)($backup_settings->sections_to_include ?? []))
                );
                $planner->apply($restore_controller, $this->section_plan);

                if (!empty($backup_settings->replace_section_details) && $this->section_plan->merges_into_target()) {
                    $this->replace_section_details($restore_controller);
                }

                \core\di::get(\core\hook\manager::class)->dispatch(new before_sections_restored(
                    restore_id: $restore_controller->get_restoreid(),
                    course_id: (int)$restore_controller->get_courseid(),
                    target_section_id: $this->section_plan->target_section_id,
                    planned_sections: array_values($this->section_plan->sections),
                ));
            }

            $has_atleast_one_course_module_included = false;
            foreach ($restore_controller->get_plan()->get_tasks() as $task) {
                if (($task instanceof \restore_activity_task) && $task->get_setting('included')->get_value()) {
                    $has_atleast_one_course_module_included = true;
                    break;
                }
            }

            if (!$has_atleast_one_course_module_included && !($this->section_plan?->has_sections() ?? false)) {
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
     * Runs after execute_plan(): places the new sections and lets the course format attach them to its hierarchy.
     */
    private function after_restore_finished_hook(\restore_controller $restore_controller): void
    {
        try {
            mtrace('Executing after_restore_finished_hook...');

            if ($this->section_plan !== null) {
                $planner = $this->factory()->restore()->section_planner();
                $course_id = (int)$restore_controller->get_courseid();
                $target_section_id = $this->section_plan->target_section_id;

                $restored_sections = $planner->resolve_restored_sections($restore_controller, $this->section_plan);

                mtrace('Placing ' . count($restored_sections) . ' restored nested section(s) after the target section...');
                $planner->place_after_target($course_id, $target_section_id, $restored_sections);

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

    private function replace_section_details(\restore_controller $restore_controller): void
    {
        $section_id = $this->section_plan->target_section_id;
        mtrace("...Replacing the title and description of section (id: $section_id) with the copied section's");

        $this->factory()->restore()->section_details_replacement()->snapshot_and_blank(
            (int)$restore_controller->get_courseid(),
            $section_id
        );
        $this->replaced_section_id = $section_id;
    }

    private function discard_replaced_section_details(\restore_controller $restore_controller): void
    {
        if ($this->replaced_section_id === 0) {
            return;
        }

        $this->factory()->restore()->section_details_replacement()->discard(
            (int)$restore_controller->get_courseid(),
            $this->replaced_section_id
        );
        $this->replaced_section_id = 0;
    }

    private function rollback_replaced_section_details(\restore_controller $restore_controller): void
    {
        if ($this->replaced_section_id === 0) {
            return;
        }

        try {
            mtrace("Restore failed, putting back the title and description of section (id: {$this->replaced_section_id})...");
            $this->factory()->restore()->section_details_replacement()->rollback(
                (int)$restore_controller->get_courseid(),
                $this->replaced_section_id
            );
        } catch (\Exception $e) {
            mtrace('Could not put back the section details: ' . $e->getMessage());
        }
        $this->replaced_section_id = 0;
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
