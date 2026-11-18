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

namespace block_sharing_cart\integration\task;

// @codeCoverageIgnoreStart
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\external\backup\course_module_into_sharing_cart;
use block_sharing_cart\external\backup\section_into_sharing_cart;
use block_sharing_cart\external\restore\item_into_section;
use block_sharing_cart\hook\backup\resolve_section_tree;
use block_sharing_cart\task\asynchronous_backup_task;
use block_sharing_cart\task\asynchronous_restore_task;

/**
 * Real backup/restore coverage for item scope, subsections and the generic section hierarchy hooks.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(asynchronous_restore_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_sharing_cart\app\restore\section_planner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_sharing_cart\app\restore\selection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_into_section::class)]
final class restore_item_scope_test extends \advanced_testcase {
    /**
     * Prepare isolated courses and capture the async task progress output.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        self::setAdminUser();
        $this->expectOutputRegex('/.*/');
    }

    /**
     * Create a core Custom sections course containing a named regular section.
     *
     * @param string $name section name
     * @param int $numsections number of regular sections
     * @return array{object, int} course and section ID
     */
    private function course(string $name, int $numsections = 1): array {
        global $DB;

        $course = self::getDataGenerator()->create_course(['format' => 'topics', 'numsections' => $numsections]);
        $sectionid = (int)$DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1], MUST_EXIST);
        course_update_section($course->id, (object)['id' => $sectionid], ['name' => $name]);
        return [$course, $sectionid];
    }

    /**
     * Declare a nested source tree through the public hook without relying on an external course format.
     *
     * @param object $course source course with three regular sections
     * @param int $rootid copied root section
     * @return void
     */
    private function nested_children(object $course, int $rootid): void {
        global $DB;

        $child = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2], '*', MUST_EXIST);
        $grandchild = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 3], '*', MUST_EXIST);
        course_update_section($course, $child, ['name' => 'Unrelated custom child']);
        course_update_section($course, $grandchild, ['name' => 'Custom grandchild']);
        $this->activity($course, (int)$child->id, 'Unrelated custom page');
        $this->activity($course, (int)$grandchild->id, 'Grandchild page');
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            resolve_section_tree::class,
            static function (resolve_section_tree $hook) use ($course, $rootid, $child, $grandchild): void {
                if ($hook->courseid === (int)$course->id && $hook->sectionid === $rootid) {
                    $hook->add_child((int)$child->id, $rootid, 1);
                    $hook->add_child((int)$grandchild->id, (int)$child->id, 1);
                }
            }
        );
    }

    /**
     * Create an activity in a section by ID, not its mutable number.
     *
     * @param object $course course record
     * @param int $sectionid destination section
     * @param string $name activity name
     * @param string $type activity type
     * @return object generated activity
     */
    private function activity(object $course, int $sectionid, string $name, string $type = 'page'): object {
        global $DB;

        return self::getDataGenerator()->create_module($type, [
            'course' => $course->id,
            'section' => $DB->get_field('course_sections', 'section', ['id' => $sectionid], MUST_EXIST),
            'name' => $name,
        ]);
    }

    /**
     * Exercise child items in shared archives as well as independently backed-up subsections.
     *
     * @param string $kind copied item and selection mode
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('copy_provider')]
    public function test_item_restore_stays_within_its_selected_tree(string $kind): void {
        global $DB;

        [$source, $rootid] = $this->course('Source root', 3);
        $this->activity($source, $rootid, 'Unrelated root page');
        $subsection = $this->activity($source, $rootid, 'Chosen subsection', 'subsection');
        $delegatedid = (int)$DB->get_field('course_sections', 'id', [
            'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
        ], MUST_EXIST);
        $DB->set_field('course_sections', 'summary', '<p>Unrelated parent summary</p>', ['id' => $rootid]);
        $first = $this->activity($source, $delegatedid, 'Chosen page');
        $second = $this->activity($source, $delegatedid, 'Other subsection page');
        $this->nested_children($source, $rootid);

        $standalone = in_array($kind, ['standalone', 'legacy standalone', 'legacy partial'], true);
        $result = $standalone
            ? course_module_into_sharing_cart::execute($subsection->cmid, ['users' => false, 'anonymize' => false])
            : section_into_sharing_cart::execute($rootid, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $repository = factory::make()->item()->repository();
        $items = [];
        foreach ($repository->get_recursively_by_parent_id((int)$result->id) as $item) {
            $items[$item->get_name()] = $item;
        }
        $chosen = $kind === 'activity' ? $items['Chosen page'] : $items['Chosen subsection'];
        if (str_starts_with($kind, 'legacy ')) {
            self::assertNotSame((int)$subsection->cmid, $delegatedid);
            // Older root subsection records used their owner's CM ID, with no ID-kind field or migration.
            $chosen->set_old_instance_id((int)$subsection->cmid);
            $repository->update($chosen);
        }
        if (in_array($kind, ['root full', 'root flattened', 'root subsection'], true)) {
            $chosen = $repository->get_by_id((int)$result->id);
        }

        $targetname = $standalone ? '' : 'Keep target';
        [$target, $targetid] = $this->course($targetname);
        $DB->set_field('course_sections', 'summary', '<p>Keep target summary</p>', ['id' => $targetid]);
        $this->activity($target, $targetid, 'Existing page');
        $beforesections = $DB->count_records('course_sections', ['course' => $target->id]);
        $moduleids = match ($kind) {
            'partial', 'root flattened', 'legacy partial' => [(int)$first->cmid],
            'root subsection' => [(int)$subsection->cmid, (int)$first->cmid],
            default => [],
        };
        $explicit = in_array($kind, ['partial', 'root flattened', 'root subsection', 'legacy partial'], true);
        if ($kind === 'empty') {
            try {
                item_into_section::execute($chosen->get_id(), $targetid, [], moduleselectionexplicit: true);
                self::fail('An explicit empty subsection copy was accepted.');
            } catch (\moodle_exception $exception) {
                self::assertSame('atleast_one_course_module_must_be_included', $exception->errorcode);
            }
            self::assertCount(0, \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));
            self::assertSame(1, $DB->count_records('course_modules', ['course' => $target->id]));
            self::assertSame($beforesections, $DB->count_records('course_sections', ['course' => $target->id]));
            return;
        }
        self::assertTrue(item_into_section::execute(
            $chosen->get_id(),
            $targetid,
            $moduleids,
            sectionstoinclude: in_array($kind, ['root flattened', 'root subsection'], true) ? [0] : [],
            moduleselectionexplicit: $explicit
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        self::assertStringNotContainsString('Exception thrown during restore execution', $this->getActualOutputForAssertion());

        $names = [];
        rebuild_course_cache($target->id, true);
        foreach (get_fast_modinfo($target->id)->get_cms() as $cm) {
            $names[] = $cm->name;
        }
        $expected = ['Existing page', 'Chosen page'];
        if (in_array($kind, ['default', 'standalone', 'legacy standalone', 'root full'], true)) {
            $expected[] = 'Other subsection page';
        }
        $hassubsection = !in_array($kind, ['activity', 'root flattened'], true);
        if ($hassubsection) {
            $expected[] = 'Chosen subsection';
            $restoreddelegated = $DB->get_record('course_sections', [
                'course' => $target->id, 'component' => 'mod_subsection',
            ], '*', MUST_EXIST);
            $owner = get_coursemodule_from_instance('subsection', $restoreddelegated->itemid, $target->id, false, MUST_EXIST);
            self::assertSame($targetid, (int)$owner->section);
            $expectedchildren = in_array($kind, ['default', 'standalone', 'legacy standalone', 'root full'], true) ? 2 : 1;
            self::assertSame($expectedchildren, $DB->count_records('course_modules', ['section' => $restoreddelegated->id]));
            self::assertTrue($DB->record_exists('page', [
                'course' => $target->id, 'name' => 'Chosen page',
            ]));
        }
        if ($kind === 'root full') {
            $expected[] = 'Unrelated root page';
            $expected[] = 'Unrelated custom page';
            $expected[] = 'Grandchild page';
            $child = $DB->get_record('course_sections', [
                'course' => $target->id, 'name' => 'Unrelated custom child',
            ], '*', MUST_EXIST);
            $grandchild = $DB->get_record('course_sections', [
                'course' => $target->id, 'name' => 'Custom grandchild',
            ], '*', MUST_EXIST);
            self::assertEmpty($child->component);
            self::assertEmpty($grandchild->component);
            self::assertLessThan((int)$grandchild->section, (int)$child->section);
        } else {
            self::assertSame(
                $beforesections + (int)$hassubsection,
                $DB->count_records('course_sections', ['course' => $target->id])
            );
            self::assertSame(
                $hassubsection ? 2 : count($expected),
                $DB->count_records('course_modules', ['section' => $targetid])
            );
        }
        self::assertEqualsCanonicalizing($expected, $names);
        self::assertSame($targetname, (string)$DB->get_field('course_sections', 'name', ['id' => $targetid]));
        self::assertSame('<p>Keep target summary</p>', $DB->get_field('course_sections', 'summary', ['id' => $targetid]));
        self::assertTrue($DB->record_exists('course_modules', ['id' => $first->cmid, 'section' => $delegatedid]));
        self::assertTrue($DB->record_exists('course_modules', ['id' => $second->cmid, 'section' => $delegatedid]));
    }

    /**
     * Important restore selections, independent of external course-format integrations.
     *
     * @return array<string, array{string}>
     */
    public static function copy_provider(): array {
        $cases = [];
        foreach (
            ['default', 'partial', 'standalone', 'activity', 'root full',
                'root flattened', 'root subsection', 'empty', 'legacy standalone', 'legacy partial'] as $kind
        ) {
            $cases[$kind] = [$kind];
        }
        return $cases;
    }

    /**
     * Scoping subsection activities must retain the selected quiz's question dependencies.
     *
     * @param bool $deletesource whether the source course and its questions are removed before restoring
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quiz_provider')]
    public function test_selected_subsection_quiz_keeps_its_questions(bool $deletesource): void {
        global $DB;

        [$source, $rootid] = $this->course('Quiz source');
        $this->activity($source, $rootid, 'Unrelated page');
        $subsection = $this->activity($source, $rootid, 'Quiz subsection', 'subsection');
        $delegatedid = (int)$DB->get_field('course_sections', 'id', [
            'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
        ], MUST_EXIST);
        $quiz = $this->activity($source, $delegatedid, 'Selected quiz', 'quiz');
        $questions = self::getDataGenerator()->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_course::instance($source->id)->id]);
        $question = $questions->create_question('shortanswer', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $result = section_into_sharing_cart::execute($rootid, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $subsectionitem = null;
        foreach (factory::make()->item()->repository()->get_recursively_by_parent_id((int)$result->id) as $item) {
            if ($item->get_name() === 'Quiz subsection') {
                $subsectionitem = $item;
            }
        }
        self::assertNotNull($subsectionitem);
        if ($deletesource) {
            delete_course($source, false);
            self::assertFalse($DB->record_exists('question', ['id' => $question->id]));
        }
        [$target, $targetid] = $this->course('Quiz target');
        self::assertTrue(item_into_section::execute(
            $subsectionitem->get_id(),
            $targetid,
            [(int)$quiz->cmid],
            moduleselectionexplicit: true
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        self::assertStringNotContainsString('Exception thrown during restore execution', $this->getActualOutputForAssertion());

        $restoredquiz = $DB->get_record('quiz', ['course' => $target->id], '*', MUST_EXIST);
        $slot = $DB->get_record('quiz_slots', ['quizid' => $restoredquiz->id], '*', MUST_EXIST);
        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
        ], '*', MUST_EXIST);
        $restoredquestionid = (int)$DB->get_field('question_versions', 'questionid', [
            'questionbankentryid' => $reference->questionbankentryid,
        ], MUST_EXIST);
        // Moodle may reuse an existing question on the same site; the restored slot must still resolve its content.
        self::assertGreaterThan(0, $restoredquestionid);
        self::assertSame($question->name, $DB->get_field('question', 'name', ['id' => $restoredquestionid], MUST_EXIST));
        self::assertTrue($DB->record_exists('question_answers', ['question' => $restoredquestionid]));
        $restoredcm = get_coursemodule_from_instance('quiz', $restoredquiz->id, $target->id, false, MUST_EXIST);
        $restoreddelegated = $DB->get_record('course_sections', [
            'course' => $target->id, 'component' => 'mod_subsection',
        ], '*', MUST_EXIST);
        self::assertSame((int)$restoreddelegated->id, (int)$restoredcm->section);
        self::assertFalse($DB->record_exists('page', ['course' => $target->id]));
    }

    /**
     * Real attempts, responses, quiz grades and gradebook grades survive item scoping when user data was backed up.
     *
     * @param string $kind Individual quiz, subsection owner, or a flattened selection.
     * @param bool $users Whether the archive includes user data.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quiz_user_data_provider')]
    public function test_selected_quiz_preserves_its_backed_up_user_data(string $kind, bool $users): void {
        global $DB;

        [$source, $rootid] = $this->course('Unrelated source title');
        $DB->set_field('course_sections', 'summary', '<p>Unrelated source summary</p>', ['id' => $rootid]);
        $this->activity($source, $rootid, 'Unrelated page');
        $quizsectionid = $rootid;
        if ($kind !== 'individual quiz') {
            $subsection = $this->activity($source, $rootid, 'Chosen subsection', 'subsection');
            $quizsectionid = (int)$DB->get_field('course_sections', 'id', [
                'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
            ], MUST_EXIST);
        }
        $quiz = $this->activity($source, $quizsectionid, 'Selected quiz', 'quiz');
        $generator = self::getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_course::instance($source->id)->id]);
        $question = $questions->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $source->id, 'student');
        self::setUser($student);
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $quizgenerator->submit_responses($attempt->id, [1 => get_string('true', 'qtype_truefalse')], false, true);
        self::setAdminUser();
        $sourceattempt = $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $sourcegrade = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $student->id], '*', MUST_EXIST);
        self::assertSame('finished', $sourceattempt->state);
        self::assertGreaterThan(0, (float)$sourcegrade->grade);

        $backup = section_into_sharing_cart::execute($rootid, ['users' => $users, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $repository = factory::make()->item()->repository();
        $items = [];
        foreach ($repository->get_recursively_by_parent_id((int)$backup->id) as $item) {
            $items[$item->get_name()] = $item;
        }
        $chosen = match ($kind) {
            'subsection' => $items['Chosen subsection'],
            'flattened quiz' => $items['Unrelated source title'],
            default => $items['Selected quiz'],
        };
        [$target, $targetid] = $this->course('Keep target title');
        $DB->set_field('course_sections', 'summary', '<p>Keep target summary</p>', ['id' => $targetid]);
        $generator->enrol_user($student->id, $target->id, 'student');
        self::assertTrue(item_into_section::execute(
            $chosen->get_id(),
            $targetid,
            [(int)$quiz->cmid],
            sectionstoinclude: $kind === 'flattened quiz' ? [0] : [],
            moduleselectionexplicit: true
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        self::assertStringNotContainsString('Exception thrown during restore execution', $this->getActualOutputForAssertion());

        $restoredquiz = $DB->get_record('quiz', ['course' => $target->id], '*', MUST_EXIST);
        $restoredcm = get_coursemodule_from_instance('quiz', $restoredquiz->id, $target->id, false, MUST_EXIST);
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $restoredquiz->id]);
        $grades = $DB->get_records('quiz_grades', ['quiz' => $restoredquiz->id]);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $target->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $restoredquiz->id,
        ], MUST_EXIST);
        self::assertCount((int)$users, $attempts);
        self::assertCount((int)$users, $grades);
        if ($users) {
            $restoredattempt = reset($attempts);
            $restoredgrade = reset($grades);
            self::assertSame((int)$student->id, (int)$restoredattempt->userid);
            self::assertSame('finished', $restoredattempt->state);
            self::assertEqualsWithDelta((float)$sourceattempt->sumgrades, (float)$restoredattempt->sumgrades, 0.00001);
            self::assertSame((int)$student->id, (int)$restoredgrade->userid);
            self::assertEqualsWithDelta((float)$sourcegrade->grade, (float)$restoredgrade->grade, 0.00001);
            $usage = \question_engine::load_questions_usage_by_activity($restoredattempt->uniqueid);
            self::assertSame('1', (string)$usage->get_question_attempt(1)->get_last_qt_var('answer'));
            $gradebookgrade = $DB->get_record('grade_grades', [
                'itemid' => $gradeitemid, 'userid' => $student->id,
            ], '*', MUST_EXIST);
            self::assertEqualsWithDelta((float)$sourcegrade->grade, (float)$gradebookgrade->finalgrade, 0.00001);
        } else {
            self::assertFalse($DB->record_exists_select(
                'grade_grades',
                'itemid = ? AND userid = ? AND finalgrade IS NOT NULL',
                [$gradeitemid, $student->id]
            ));
        }
        self::assertFalse($DB->record_exists('page', ['course' => $target->id]));
        self::assertSame('Keep target title', $DB->get_field('course_sections', 'name', ['id' => $targetid]));
        self::assertSame('<p>Keep target summary</p>', $DB->get_field('course_sections', 'summary', ['id' => $targetid]));
        // Core may create a question-bank activity to hold restored questions; it is a quiz dependency, not a sibling copy.
        self::assertSame($kind === 'subsection' ? 2 : 1, $DB->count_records_sql(
            'SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = ? AND m.name <> ?',
            [$target->id, 'qbank']
        ));
        if ($kind === 'subsection') {
            $delegated = $DB->get_record('course_sections', [
                'course' => $target->id, 'component' => 'mod_subsection',
            ], '*', MUST_EXIST);
            self::assertSame((int)$delegated->id, (int)$restoredcm->section);
        } else {
            self::assertSame($targetid, (int)$restoredcm->section);
            self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'component' => 'mod_subsection']));
        }
    }

    /**
     * Container dependencies must not alter either an enabled or a disabled user-data choice.
     *
     * @return array
     */
    public static function quiz_user_data_provider(): array {
        $cases = [];
        foreach (['individual quiz', 'quiz inside subsection', 'subsection', 'flattened quiz'] as $kind) {
            $cases[$kind . ' with user data'] = [$kind, true];
            $cases[$kind . ' without user data'] = [$kind, false];
        }
        return $cases;
    }

    /**
     * Preserving activity settings must not opt in to user data disabled by a caller or a restore restriction.
     *
     * @param string $restriction The effective choice or restriction to preserve.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('user_data_restriction_provider')]
    public function test_preserving_user_data_does_not_override_restore_restrictions(string $restriction): void {
        [$source, $rootid] = $this->course('Source section');
        $page = $this->activity($source, $rootid, 'Selected page');
        $backup = section_into_sharing_cart::execute($rootid, ['users' => true, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $factory = factory::make();
        $repository = $factory->item()->repository();
        $root = $repository->get_by_id((int)$backup->id);
        $selected = $repository->get_by_parent_item_id($root->get_id())->first();
        self::assertSame('Selected page', $selected->get_name());
        [$target, $targetid] = $this->course('Target section');
        $controller = $factory->restore()->restore_controller(
            $repository->get_stored_file_by_item($root),
            (int)$target->id,
            (int)get_admin()->id
        );
        try {
            self::assertTrue($controller->execute_precheck(true));
            $activitytask = null;
            foreach ($controller->get_plan()->get_tasks() as $task) {
                if ($task instanceof \restore_activity_task && (int)$task->get_old_moduleid() === (int)$page->cmid) {
                    $activitytask = $task;
                }
            }
            self::assertNotNull($activitytask);
            $userinfo = $activitytask->get_setting('userinfo');
            self::assertTrue((bool)$userinfo->get_value(), 'The fixture must initially allow user data.');
            $planner = $factory->restore()->section_planner();
            if ($restriction === 'global users disabled') {
                // A later global restriction must also take precedence over a previously enabled snapshot.
                $saved = $planner->activity_userinfo_settings($controller);
                $controller->get_plan()->get_setting('users')->set_value(false);
                self::assertTrue($saved[(int)$page->cmid]);
            } else {
                $userinfo->set_value(false);
                if ($restriction !== 'activity disabled') {
                    $userinfo->set_status($restriction === 'permission lock'
                        ? \base_setting::LOCKED_BY_PERMISSION : \base_setting::LOCKED_BY_CONFIG);
                }
                $saved = $planner->activity_userinfo_settings($controller);
                self::assertFalse($saved[(int)$page->cmid]);
            }
            $plan = $planner->plan($controller, $selected, $targetid, false, [], [(int)$page->cmid]);
            $planner->apply($controller, $plan, $saved);
            self::assertTrue((bool)$activitytask->get_setting('included')->get_value());
            self::assertFalse((bool)$userinfo->get_value());
        } finally {
            $controller->destroy();
        }
    }

    /**
     * Existing effective exclusions must not be mistaken for dependency-induced user-data loss.
     *
     * @return array
     */
    public static function user_data_restriction_provider(): array {
        return [
            'activity disabled' => ['activity disabled'],
            'global users disabled' => ['global users disabled'],
            'permission lock' => ['permission lock'],
            'configuration lock' => ['configuration lock'],
        ];
    }

    /**
     * Quiz copies with and without the source still present.
     *
     * @return array<string, array{bool}>
     */
    public static function quiz_provider(): array {
        return ['source present' => [false], 'source deleted' => [true]];
    }
}
