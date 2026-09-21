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
 * asynchronous_restore_task.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\task;

defined('MOODLE_INTERNAL') || die();

use async_helper;
use block_sharing_cart\app\factory as basefactory;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * asynchronous_restore_task class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class asynchronous_restore_task extends \core\task\adhoc_task
{
    /**
     * Should always resemble
     * @see \core\task\asynchronous_restore_task::execute
     * with the addition of calling
     * @see self::before_restore_finished_hook
     * and
     * @see self::after_restore_finished_hook
     *
     * Controller and backup tempdir are created here (worker), not at queue time,
     * so multi-frontend sites do not depend on a shared backuptempdir.
     */
    public function execute(): void {
        $factory = basefactory::make();
        $db = $factory->moodle()->db();
        $started = time();

        $customdata = $this->get_custom_data();
        $courseid = (int)($customdata->course_id ?? 0);
        $userid = (int)$this->get_userid();

        if (empty($customdata->item) || $courseid <= 0 || $userid <= 0) {
            mtrace('Sharing cart restore task missing item, course_id, or userid; ending restore execution.');
            return;
        }

        $item = $factory->item()->entity((object)$customdata->item);
        $backupfile = $factory->item()->repository()->get_stored_file_by_item($item);
        if (!$backupfile) {
            mtrace(
                'Sharing cart backup file not found for item (id: ' . $item->get_id()
                . '); ending restore execution.'
            );
            return;
        }

        mtrace(implode(' | ', [
            'hostname=' . (gethostname() ?: 'unknown-host'),
            'itemid=' . $item->get_id(),
            'fileid=' . $backupfile->get_id(),
            'courseid=' . $courseid,
            'userid=' . $userid,
        ]));

        /** @var \restore_controller $rc */
        $rc = $factory->restore()->restore_controller($backupfile, $courseid, $userid);
        $restoreid = $rc->get_restoreid();

        mtrace('Processing asynchronous restore for id: ' . $restoreid);

        try {
            $restorerecord = $db->get_record(
                'backup_controllers',
                ['backupid' => $restoreid],
                'id, controller',
                MUST_EXIST
            );

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

            // Check that the restore is in the correct status and.
            // That is set for asynchronous execution.
            if ($status == \backup::STATUS_AWAITING && $execution == \backup::EXECUTION_DELAYED) {
                $this->before_restore_finished_hook($rc);

                // Execute the restore.
                $rc->execute_plan();

                $this->after_restore_finished_hook($rc);

                // Send message to user if enabled.
                $messageenabled = (bool)get_config('backup', 'backup_async_message_users');
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

    /**
     * retry_until_success
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }

    /**
     * after_restore_finished_hook
     *
     * @param \restore_controller $restorecontroller
     * @return void
     */
    private function after_restore_finished_hook(\restore_controller $restorecontroller): void {
        try {
            mtrace('Executing after_restore_finished_hook...');

            mtrace('Executing after_restore_finished_hook completed...');
        } catch (\Exception $e) {
            mtrace("An error occurred: " . $e->getMessage());
            mtrace($e->getTraceAsString());

            // Uh uhh, something went wrong.
            throw $e;
        }
    }

    /**
     * before_restore_finished_hook
     *
     * @param \restore_controller $restorecontroller
     * @return void
     */
    private function before_restore_finished_hook(\restore_controller $restorecontroller): void {
        try {
            mtrace('Executing before_restore_finished_hook...');

            $customdata = $this->get_custom_data();

            $backupsettings = $customdata->backup_settings ?? null;

            $movetosectionid = $backupsettings->move_to_section_id ?? null;
            if ($movetosectionid) {
                $this->update_section_number($restorecontroller, $movetosectionid);
            }

            $coursemodulestoinclude = array_map('intval', $backupsettings->course_modules_to_include ?? []);
            if (!empty($coursemodulestoinclude) && $coursemodulestoinclude !== [0]) {
                $this->only_include_specified_course_modules($restorecontroller, $coursemodulestoinclude);
            }

            $hasatleastonecoursemoduleincluded = false;
            foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
                if (($task instanceof \restore_activity_task) && $task->get_setting('included')->get_value()) {
                    $hasatleastonecoursemoduleincluded = true;
                    break;
                }
            }

            if (!$hasatleastonecoursemoduleincluded) {
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
     * update_section_number
     *
     * @param \restore_controller $restorecontroller
     * @param int $sectionid
     * @return void
     */
    private function update_section_number(\restore_controller $restorecontroller, int $sectionid): void {
        $db = basefactory::make()->moodle()->db();

        $newsectionnumber = $db->get_field(
            'course_sections',
            'section',
            ['id' => $sectionid],
            strictness: MUST_EXIST
        );

        // Dirty hack: update section numbers hardcoded in section.xml and module.xml.
        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            // Make sure we import into the correct section.
            if ($task instanceof \restore_activity_task) {
                $modulexmlpath = "{$task->get_taskbasepath()}/module.xml";

                $modulexml = simplexml_load_string(
                    file_get_contents($modulexmlpath)
                );
                $modulexml->sectionnumber = $newsectionnumber;

                $modulexml->asXML($modulexmlpath);
            }

            // Overwrite empty/missing section settings in the target section.
            if ($task instanceof \restore_section_task) {
                $sectionxmlpath = "{$task->get_taskbasepath()}/section.xml";

                $sectionxml = simplexml_load_string(
                    file_get_contents($sectionxmlpath)
                );
                $sectionxml->number = $newsectionnumber;

                $sectionxml->asXML($sectionxmlpath);
            }
        }
    }

    /**
     * get_section_name
     *
     * @param mixed $sectionid
     * @return ?string
     */
    private function get_section_name($sectionid): ?string {
        $db = basefactory::make()->moodle()->db();
        $sectionname = $db->get_field(
            'course_sections',
            'name',
            ['id' => $sectionid],
            strictness: IGNORE_MISSING
        );

        if (!$sectionname) {
            return null;
        }

        return $sectionname;
    }

    /**
     * update_section_name
     *
     * @param mixed $sectionid
     * @param mixed $sectionname
     * @return bool
     */
    private function update_section_name($sectionid, $sectionname): bool {
        $db = basefactory::make()->moodle()->db();

        return $db->set_field(
            'course_sections',
            'name',
            $sectionname,
            ['id' => $sectionid],
        );
    }

    /**
     * only_include_specified_course_modules
     *
     * @param \restore_controller $restorecontroller
     * @param array $coursemodulestoinclude
     * @return void
     */
    private function only_include_specified_course_modules(
        \restore_controller $restorecontroller,
        array $coursemodulestoinclude
    ): void {
        mtrace("Excluding/Including activities...");

        foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $cmid = (int)$task->get_old_moduleid();

                $includeactivity = in_array($cmid, $coursemodulestoinclude, true);
                mtrace(
                    '...' . ($includeactivity ? "Including activity: (id: $cmid)" : "Excluding activity: (id: $cmid)")
                );
                $task->get_setting('included')->set_value($includeactivity);
            }
        }
    }

    /**
     * trigger_restored_event
     *
     * @param \restore_controller $controller
     * @param int $started
     * @param int $finished
     * @return void
     */
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

    /**
     * trigger_restore_course_module_event
     *
     * @param \restore_activity_task $task
     * @param int $started
     * @param int $finished
     * @return void
     */
    private function trigger_restore_course_module_event(
        \restore_activity_task $task,
        int $started,
        int $finished
    ): void {
        if ($task->get_moduleid() === 0) {
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

    /**
     * trigger_restore_section_event
     *
     * @param \restore_section_task $task
     * @param int $started
     * @param int $finished
     * @return void
     */
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

    /**
     * get_name
     *
     * @return string
     */
    public function get_name(): string {
        return parent::get_name() . ' (block_sharing_cart)';
    }
}
