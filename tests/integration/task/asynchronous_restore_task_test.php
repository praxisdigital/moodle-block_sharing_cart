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

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\external\backup\course_module_into_sharing_cart;
use block_sharing_cart\external\backup\section_into_sharing_cart;
use block_sharing_cart\external\restore\item_into_section;
use block_sharing_cart\hook\backup\resolve_section_tree;
use block_sharing_cart\hook\restore\after_sections_restored;
use block_sharing_cart\hook\restore\before_sections_restored;
use block_sharing_cart\task\asynchronous_backup_task;
use block_sharing_cart\task\asynchronous_restore_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Async restore tests for the Sharing Cart block.
 *
 * Nested section copy and restore, driven through the section hierarchy hooks with a test callback standing in for a
 * nesting course format. Uses plain topics courses: to the cart a "descendant" is just another section id.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(asynchronous_restore_task::class)]
final class asynchronous_restore_task_test extends \advanced_testcase
{
    /** @var factory $factory */
    private factory $factory;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        self::setAdminUser();
        $this->factory = factory::make();

        // The cart tasks mtrace their progress.
        $this->expectOutputRegex('/.*/');
    }

    /**
     * Topics course with the given number of sections, optionally named.
     *
     * @param int $numsections
     * @param array $names section number => name
     * @return object course with a 'sections' map: section number => course_sections record
     */
    private function create_course(int $numsections, array $names = []): object {
        global $DB;

        $course = self::getDataGenerator()->create_course(['format' => 'topics', 'numsections' => $numsections]);
        foreach ($names as $number => $name) {
            $DB->set_field('course_sections', 'name', $name, ['course' => $course->id, 'section' => $number]);
        }

        $course->sections = [];
        foreach ($DB->get_records('course_sections', ['course' => $course->id], 'section ASC') as $section) {
            $course->sections[(int)$section->section] = $section;
        }

        return $course;
    }

    /**
     * Adds a page activity to a section of the course.
     *
     * @param object $course as returned by create_course()
     * @param int $sectionnum
     * @param string $name
     * @return object created page course module
     */
    private function add_page(object $course, int $sectionnum, string $name): object {
        return self::getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => $sectionnum,
            'name' => $name,
        ]);
    }

    /**
     * Stand in for a nesting course format.
     *
     * @param array $tree list of [section_id, parent_section_id, sort_order]
     */
    private function declare_section_tree(array $tree): void {
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            resolve_section_tree::class,
            static function (resolve_section_tree $hook) use ($tree): void {
                foreach ($tree as [$sectionid, $parentsectionid, $sortorder]) {
                    $hook->add_child((int)$sectionid, (int)$parentsectionid, (int)$sortorder);
                }
            }
        );
    }

    /**
     * Copies a section into the cart through the web service and runs the backup.
     *
     * @param int $sectionid
     * @return int id of the root cart item
     */
    private function copy_section_to_cart(int $sectionid): int {
        $result = section_into_sharing_cart::execute($sectionid, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);

        $item = $this->factory->item()->repository()->get_by_id((int)$result->id);
        self::assertSame(entity::STATUS_BACKEDUP, $item->get_status());

        return (int)$result->id;
    }

    /**
     * Verify that a missing archive leaves a failure result after cron drops the queue row.
     */
    public function test_missing_archive_leaves_a_failure_outcome(): void {
        global $USER;

        $source = $this->create_course(1, [1 => 'Failure test source']);
        $this->add_page($source, 1, 'Source page');
        $itemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1);
        self::assertTrue(item_into_section::execute($itemid, (int)$target->sections[1]->id, []));
        $tasks = \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class);
        self::assertCount(1, $tasks);
        $taskid = (int)reset($tasks)->get_id();

        $items = $this->factory->item()->repository();
        $file = $items->get_stored_file_by_item($items->get_by_id($itemid));
        self::assertNotNull($file);
        $file->delete();
        $this->runAdhocTasks(asynchronous_restore_task::class);

        self::assertEmpty(\core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));
        $outcomes = $this->factory->restore()->outcomes();
        $outcome = $outcomes->get_recent((int)$USER->id, (int)$target->id)[$taskid];
        self::assertFalse($outcome['succeeded']);
        self::assertSame('not_started', $outcome['copy_state']);
        self::assertSame('Failure test source', $outcome['name']);
        self::assertArrayNotHasKey($taskid, $outcomes->get_recent((int)$USER->id, (int)$source->id));
    }

    /**
     * A format hook can fail after Moodle has already copied the content.
     */
    public function test_post_restore_hook_error_preserves_copy_and_records_warning(): void {
        global $USER;

        $source = $this->create_course(1, [1 => 'Source section']);
        $this->add_page($source, 1, 'Copied page');
        $itemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1);
        $sectionid = (int)$target->sections[1]->id;
        self::assertTrue(item_into_section::execute($itemid, $sectionid, []));
        $tasks = \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class);
        self::assertCount(1, $tasks);
        $taskid = (int)reset($tasks)->get_id();
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (): void {
                throw new \RuntimeException('Test failure after copying');
            }
        );

        $this->runAdhocTasks(asynchronous_restore_task::class);

        self::assertEmpty(\core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));
        self::assertSame(['Copied page'], $this->module_names($sectionid));
        $outcome = $this->factory->restore()->outcomes()->get_recent((int)$USER->id, (int)$target->id)[$taskid];
        self::assertFalse($outcome['succeeded']);
        self::assertSame('completed', $outcome['copy_state']);
        self::assertSame($sectionid, $outcome['to_section_id']);
        self::assertTrue($outcome['refresh_course']);
    }

    /**
     * An empty drag/drop destination is not disposable when the copy restored its details and a nested section.
     */
    public function test_post_restore_hook_error_preserves_new_root_with_only_nested_activities(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Selected child']);
        $this->add_page($source, 1, 'Unselected root page');
        $childpage = $this->add_page($source, 2, 'Selected page');
        $DB->set_field('course_sections', 'summary', '<p>Copied root details</p>', ['id' => $source->sections[1]->id]);
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Drop anchor']);

        $createdrootid = 0;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$createdrootid): void {
                $createdrootid = $hook->targetsectionid;
                throw new \RuntimeException('Test failure after copying nested content');
            }
        );

        self::assertTrue(item_into_section::execute(
            $rootitemid,
            (int)$target->sections[1]->id,
            [(int)$childpage->cmid],
            [(int)$source->sections[2]->id],
            asnewsection: true,
            moduleselectionexplicit: true
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);

        self::assertStringContainsString('Test failure after copying nested content', $this->getActualOutputForAssertion());
        $sections = $this->sections_by_name((int)$target->id);
        self::assertEqualsCanonicalizing(['', 'Drop anchor', 'Copied root', 'Selected child'], array_keys($sections));
        self::assertSame($createdrootid, (int)$sections['Copied root']->id);
        self::assertStringContainsString('Copied root details', $sections['Copied root']->summary);
        self::assertSame([], $this->module_names($createdrootid));
        self::assertSame(['Selected page'], $this->module_names((int)$sections['Selected child']->id));
    }

    /**
     * Copies a three-level section tree whose only activity is in the last section.
     *
     * @return int id of the root cart item
     */
    private function copy_tree_with_content_only_in_leaf(): int {
        $source = $this->create_course(4, [2 => 'Section 2', 3 => 'Section 3', 4 => 'Section 4']);
        $this->add_page($source, 4, 'Leaf activity');
        $this->declare_section_tree([
            [$source->sections[3]->id, $source->sections[2]->id, 1],
            [$source->sections[4]->id, $source->sections[3]->id, 1],
        ]);

        return $this->copy_section_to_cart((int)$source->sections[2]->id);
    }

    /**
     * Queues a restore through the web service and runs it.
     *
     * @param int $itemid
     * @param int $sectionid
     * @param bool $insertasnewsection
     * @param int $courseid
     * @param bool $replacesectiondetails
     * @param bool $expectfailure whether the task is expected to log a restore failure
     * @param int[] $moduleids course modules explicitly selected for restore
     * @param int[] $sectionids nested sections explicitly selected for restore
     */
    private function restore(
        int $itemid,
        int $sectionid,
        bool $insertasnewsection = false,
        int $courseid = 0,
        bool $replacesectiondetails = false,
        bool $expectfailure = false,
        array $moduleids = [],
        array $sectionids = []
    ): void {
        // Keep this legacy positional call: optional external parameters must remain backwards compatible.
        self::assertTrue(item_into_section::execute(
            $itemid,
            $sectionid,
            $moduleids,
            $sectionids,
            $insertasnewsection,
            $courseid,
            $replacesectiondetails
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        $output = $this->getActualOutputForAssertion();
        self::assertSame(
            $expectfailure,
            str_contains($output, 'Exception thrown during restore execution'),
            'Unexpected restore outcome. Task output: ' . $output
        );
    }

    /**
     * Names of the course modules in a section, sorted alphabetically.
     *
     * @param int $sectionid
     * @return string[]
     */
    private function module_names(int $sectionid): array {
        global $DB;

        $sequence = (string)$DB->get_field('course_sections', 'sequence', ['id' => $sectionid], MUST_EXIST);
        $names = [];
        foreach (array_filter(explode(',', $sequence)) as $cmid) {
            $names[] = get_coursemodule_from_id('', (int)$cmid, 0, false, MUST_EXIST)->name;
        }
        sort($names);

        return $names;
    }

    /**
     * Sections of a course keyed by name.
     *
     * @param int $courseid
     * @return array<string, object> course_sections records keyed by name
     */
    private function sections_by_name(int $courseid): array {
        global $DB;

        $byname = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid], 'section ASC') as $section) {
            $byname[(string)$section->name] = $section;
        }

        return $byname;
    }

    /**
     * Cart items below a root item, grouped by parent.
     *
     * @param int $rootitemid
     * @return array<int, entity[]> child items keyed by parent item id, keyed again by name
     */
    private function items_by_parent(int $rootitemid): array {
        $byparent = [];
        foreach ($this->factory->item()->repository()->get_recursively_by_parent_id($rootitemid) as $item) {
            if ($item->get_parent_item_id() !== null) {
                $byparent[$item->get_parent_item_id()][$item->get_name()] = $item;
            }
        }

        return $byparent;
    }

    public function test_nested_sections_are_restored_after_the_target_section(): void {
        $source = $this->create_course(3, [1 => 'Root', 2 => 'Child', 3 => 'Grandchild']);
        $this->add_page($source, 1, 'Page root');
        $this->add_page($source, 2, 'Page child');
        $this->add_page($source, 3, 'Page grandchild');
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[2]->id, 1],
        ]);

        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        // The cart holds the tree: Root > [Page root, Child > [Page child, Grandchild > [Page grandchild]]].
        $byparent = $this->items_by_parent($rootitemid);
        self::assertEqualsCanonicalizing(['Page root', 'Child'], array_keys($byparent[$rootitemid]));
        $childitem = $byparent[$rootitemid]['Child'];
        self::assertTrue($childitem->is_section());
        self::assertEqualsCanonicalizing(['Page child', 'Grandchild'], array_keys($byparent[$childitem->get_id()]));
        $grandchilditem = $byparent[$childitem->get_id()]['Grandchild'];
        self::assertEqualsCanonicalizing(['Page grandchild'], array_keys($byparent[$grandchilditem->get_id()]));

        $target = $this->create_course(2, [1 => 'Target', 2 => 'After']);
        $this->add_page($target, 1, 'Page target');
        $targetsectionid = (int)$target->sections[1]->id;

        $payload = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$payload): void {
                $payload = $hook;
            }
        );

        $this->restore($rootitemid, $targetsectionid);

        // The root merged into the target; the descendants are new sections placed directly after it.
        self::assertSame(['Page root', 'Page target'], $this->module_names($targetsectionid));

        $sections = $this->sections_by_name($target->id);
        self::assertEqualsCanonicalizing(['', 'Target', 'Child', 'Grandchild', 'After'], array_keys($sections));
        self::assertSame(2, (int)$sections['Child']->section);
        self::assertSame(3, (int)$sections['Grandchild']->section);
        self::assertSame(4, (int)$sections['After']->section);
        self::assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
        self::assertSame(['Page grandchild'], $this->module_names((int)$sections['Grandchild']->id));

        // The format is told the new hierarchy.
        self::assertNotNull($payload);
        self::assertSame((int)$target->id, $payload->courseid);
        self::assertSame($targetsectionid, $payload->targetsectionid);
        self::assertCount(2, $payload->restoredsections);
        [$child, $grandchild] = $payload->restoredsections;
        self::assertSame((int)$sections['Child']->id, $child->new_section_id);
        self::assertSame($targetsectionid, $child->new_parent_section_id);
        self::assertSame((int)$sections['Grandchild']->id, $grandchild->new_section_id);
        self::assertSame((int)$sections['Child']->id, $grandchild->new_parent_section_id);
    }

    /**
     * A format's generic hierarchy-managed hook preserves empty structural ancestors for its own hierarchy rebuild.
     */
    public function test_managed_hierarchy_preserves_structural_ancestors_and_reports_the_parent_chain(): void {
        $rootitemid = $this->copy_tree_with_content_only_in_leaf();
        $target = $this->create_course(1, [1 => 'Existing']);
        $planned = [];
        $restored = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            before_sections_restored::class,
            static function (before_sections_restored $hook) use (&$planned): void {
                $planned = $hook->plannedsections;
                $hook->managessectionhierarchy = true;
            }
        );
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$restored): void {
                $restored = $hook;
            }
        );

        $this->restore($rootitemid, 0, insertasnewsection: true, courseid: (int)$target->id);

        $sections = $this->sections_by_name((int)$target->id);
        self::assertEqualsCanonicalizing(['', 'Existing', 'Section 2', 'Section 3', 'Section 4'], array_keys($sections));
        self::assertSame([], $this->module_names((int)$sections['Section 2']->id));
        self::assertSame([], $this->module_names((int)$sections['Section 3']->id));
        self::assertSame(['Leaf activity'], $this->module_names((int)$sections['Section 4']->id));
        self::assertCount(3, $planned);
        self::assertNotNull($restored);
        self::assertSame((int)$target->id, $restored->courseid);
        self::assertSame(0, $restored->targetsectionid);
        self::assertCount(3, $restored->restoredsections);
        [$root, $child, $grandchild] = $restored->restoredsections;
        self::assertSame((int)$sections['Section 2']->id, $root->new_section_id);
        self::assertSame(0, $root->new_parent_section_id);
        self::assertSame((int)$sections['Section 3']->id, $child->new_section_id);
        self::assertSame($root->new_section_id, $child->new_parent_section_id);
        self::assertSame((int)$sections['Section 4']->id, $grandchild->new_section_id);
        self::assertSame($child->new_section_id, $grandchild->new_parent_section_id);
    }

    /**
     * An explicit empty subsection selection must not queue its parent's shared backup.
     */
    public function test_empty_subsection_selection_is_rejected_before_queueing(): void {
        global $DB;

        $source = $this->create_course(1);
        $this->add_page($source, 1, 'Unrelated root page');
        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id, 'section' => 1, 'name' => 'Chosen subsection',
        ]);
        $delegated = $DB->get_record('course_sections', [
            'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $this->add_page($source, (int)$delegated->section, 'Subsection page');
        $rootid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->items_by_parent($rootid)[$rootid]['Chosen subsection'];
        $target = $this->create_course(1, [1 => 'Keep target']);

        try {
            item_into_section::execute(
                $item->get_id(),
                (int)$target->sections[1]->id,
                [],
                moduleselectionexplicit: true
            );
            self::fail('An explicit empty subsection selection was accepted.');
        } catch (\moodle_exception $exception) {
            self::assertSame('atleast_one_course_module_must_be_included', $exception->errorcode);
        }
        self::assertCount(0, \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));
        self::assertSame([], $this->module_names((int)$target->sections[1]->id));
    }

    /**
     * A default subsection restore must never include siblings from the shared archive.
     */
    public function test_subsection_default_selection_is_scoped_to_its_own_children(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Unrelated root', 2 => 'Unrelated child']);
        $this->add_page($source, 1, 'Unrelated root page');
        $this->add_page($source, 2, 'Unrelated child page');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);
        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id, 'section' => 1, 'name' => 'Chosen subsection',
        ]);
        $delegated = $DB->get_record('course_sections', [
            'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $this->add_page($source, (int)$delegated->section, 'Subsection page');
        $rootid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->items_by_parent($rootid)[$rootid]['Chosen subsection'];
        $target = $this->create_course(1, [1 => 'Keep target']);
        $this->add_page($target, 1, 'Existing page');

        $this->restore($item->get_id(), (int)$target->sections[1]->id);

        self::assertSame(['Chosen subsection', 'Existing page'], $this->module_names((int)$target->sections[1]->id));
        $restoreddelegated = $DB->get_record('course_sections', [
            'course' => $target->id, 'component' => 'mod_subsection',
        ], '*', MUST_EXIST);
        self::assertSame(['Subsection page'], $this->module_names((int)$restoreddelegated->id));
        self::assertSame(3, $DB->count_records('course_modules', ['course' => $target->id]));
        self::assertSame(3, $DB->count_records('course_sections', ['course' => $target->id]));
        self::assertSame('Keep target', $DB->get_field('course_sections', 'name', ['id' => $target->sections[1]->id]));
    }

    public function test_flat_restore_preserves_summary_on_empty_nested_section(): void {
        global $DB;

        $source = $this->create_course(3, [1 => 'Root', 2 => 'Instructions', 3 => 'Child']);
        $this->add_page($source, 1, 'Page root');
        $this->add_page($source, 3, 'Page child');
        $DB->set_field('course_sections', 'summary', '<p>Parent instructions</p>', ['id' => $source->sections[2]->id]);
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[2]->id, 1],
        ]);

        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Target']);
        $this->restore($rootitemid, (int)$target->sections[1]->id);

        $sections = $this->sections_by_name((int)$target->id);
        self::assertArrayHasKey('Instructions', $sections);
        self::assertStringContainsString('Parent instructions', (string)$sections['Instructions']->summary);
        self::assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
    }

    public function test_flat_restore_preserves_summary_on_empty_root_inserted_as_new_section(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Instructions', 2 => 'Child']);
        $this->add_page($source, 2, 'Page child');
        $DB->set_field('course_sections', 'summary', '<p>Root instructions</p>', ['id' => $source->sections[1]->id]);
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Existing']);
        $this->restore($rootitemid, 0, insertasnewsection: true, courseid: (int)$target->id);

        $sections = $this->sections_by_name((int)$target->id);
        self::assertArrayHasKey('Instructions', $sections);
        self::assertStringContainsString('Root instructions', (string)$sections['Instructions']->summary);
        self::assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
    }

    public function test_selected_custom_grandchild_is_reparented_when_its_custom_parent_is_excluded(): void {
        global $DB;

        $source = $this->create_course(3, [1 => 'Copied root', 2 => 'Excluded parent', 3 => 'Selected grandchild']);
        $this->add_page($source, 1, 'Root activity');
        $this->add_page($source, 2, 'Excluded parent activity');
        $this->add_page($source, 3, 'Grandchild activity');
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[2]->id, 1],
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $byparent = $this->items_by_parent($rootitemid);
        $parentitem = $byparent[$rootitemid]['Excluded parent'];
        $grandchilditem = $byparent[$parentitem->get_id()]['Selected grandchild'];
        $moduleids = [
            (int)$byparent[$rootitemid]['Root activity']->get_old_instance_id(),
            (int)$byparent[$grandchilditem->get_id()]['Grandchild activity']->get_old_instance_id(),
        ];
        $selectedsectionids = [(int)$grandchilditem->get_old_instance_id()];

        $target = $this->create_course(1, [1 => 'Merge target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $payload = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$payload): void {
                $payload = $hook;
            }
        );
        $this->restore(
            $rootitemid,
            $targetsectionid,
            moduleids: $moduleids,
            sectionids: $selectedsectionids
        );

        $sections = $this->sections_by_name($target->id);
        self::assertArrayHasKey('Selected grandchild', $sections);
        $grandchildid = (int)$sections['Selected grandchild']->id;
        self::assertSame(['Grandchild activity'], $this->module_names($grandchildid));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $target->id,
            'name' => 'Excluded parent',
        ]));
        self::assertNotNull($payload);
        self::assertCount(1, $payload->restoredsections);
        self::assertSame($grandchildid, $payload->restoredsections[0]->new_section_id);
        self::assertSame($targetsectionid, $payload->restoredsections[0]->new_parent_section_id);

        $newtarget = $this->create_course(1, [1 => 'Existing section']);
        $payload = null;
        $this->restore(
            $rootitemid,
            0,
            insertasnewsection: true,
            courseid: (int)$newtarget->id,
            moduleids: $moduleids,
            sectionids: $selectedsectionids
        );

        $sections = $this->sections_by_name($newtarget->id);
        $newrootid = (int)$sections['Copied root']->id;
        $newgrandchildid = (int)$sections['Selected grandchild']->id;
        self::assertSame(['Grandchild activity'], $this->module_names($newgrandchildid));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $newtarget->id,
            'name' => 'Excluded parent',
        ]));
        self::assertNotNull($payload);
        self::assertCount(2, $payload->restoredsections);
        self::assertSame($newgrandchildid, $payload->restoredsections[1]->new_section_id);
        self::assertSame($newrootid, $payload->restoredsections[1]->new_parent_section_id);
    }

    /**
     * Verify a flattened activity reaches a destination restored later in the plan.
     */
    public function test_flattened_activity_moves_to_later_restored_ancestor(): void {
        global $DB;

        $source = $this->create_course(3, [1 => 'Root', 2 => 'Early excluded child', 3 => 'Later included parent']);
        $early = $this->add_page($source, 2, 'Early page');
        $later = $this->add_page($source, 3, 'Later page');
        $this->declare_section_tree([
            [$source->sections[3]->id, $source->sections[1]->id, 1],
            [$source->sections[2]->id, $source->sections[3]->id, 1],
        ]);
        $itemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Merge target']);

        $this->restore(
            $itemid,
            (int)$target->sections[1]->id,
            moduleids: [(int)$early->cmid, (int)$later->cmid],
            sectionids: [(int)$source->sections[3]->id]
        );

        $destinationid = (int)$DB->get_field('course_sections', 'id', [
            'course' => $target->id, 'name' => 'Later included parent',
        ], MUST_EXIST);
        self::assertSame(['Early page', 'Later page'], $this->module_names($destinationid));
        self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'name' => 'Early excluded child']));
    }

    public function test_selected_activities_under_excluded_sections_flatten_to_the_nearest_included_section(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Excluded child']);
        $rootpage = $this->add_page($source, 1, 'Page root');
        $childpage = $this->add_page($source, 2, 'Page child');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id,
            'section' => 1,
            'name' => 'Subsection',
        ]);
        $delegatedsection = $DB->get_record('course_sections', [
            'course' => $source->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $subsectionpage = self::getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'section' => $delegatedsection->section,
            'name' => 'Page subsection',
        ]);

        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $moduleids = [(int)$rootpage->cmid, (int)$childpage->cmid, (int)$subsectionpage->cmid];

        $target = $this->create_course(1, [1 => 'Merge target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $this->restore(
            $rootitemid,
            $targetsectionid,
            moduleids: $moduleids,
            sectionids: [0]
        );

        self::assertSame(['Page child', 'Page root', 'Page subsection'], $this->module_names($targetsectionid));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $target->id,
            'name' => 'Excluded child',
        ]));
        self::assertFalse($DB->record_exists('course_modules', [
            'course' => $target->id,
            'module' => (int)$DB->get_field('modules', 'id', ['name' => 'subsection'], MUST_EXIST),
        ]));

        $newtarget = $this->create_course(1, [1 => 'Existing section']);
        $this->restore(
            $rootitemid,
            0,
            insertasnewsection: true,
            courseid: (int)$newtarget->id,
            moduleids: $moduleids,
            sectionids: [0]
        );

        $sections = $this->sections_by_name($newtarget->id);
        self::assertArrayHasKey('Copied root', $sections);
        self::assertSame(
            ['Page child', 'Page root', 'Page subsection'],
            $this->module_names((int)$sections['Copied root']->id)
        );
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $newtarget->id,
            'name' => 'Excluded child',
        ]));
        self::assertFalse($DB->record_exists('course_modules', [
            'course' => $newtarget->id,
            'module' => (int)$DB->get_field('modules', 'id', ['name' => 'subsection'], MUST_EXIST),
        ]));
    }

    /**
     * Legacy API calls omit moduleselectionexplicit but still explicitly select a non-empty module list.
     *
     * @param string $kind Selected content under an excluded container.
     * @param bool $dropafter Whether the task creates the target for a section drag/drop.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('flattened_only_content_provider')]
    public function test_new_root_receives_flattened_content_without_the_optional_selection_flag(
        string $kind,
        bool $dropafter
    ): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Excluded child']);
        $this->add_page($source, 1, 'Unselected root page');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);
        if ($kind === 'custom child') {
            $selected = $this->add_page($source, 2, 'Selected page');
            $moduleids = [(int)$selected->cmid];
        } else {
            $subsection = self::getDataGenerator()->create_module('subsection', [
                'course' => $source->id,
                'section' => 2,
                'name' => 'Selected subsection',
            ]);
            $delegatedsection = $DB->get_record('course_sections', [
                'course' => $source->id,
                'component' => 'mod_subsection',
                'itemid' => $subsection->id,
            ], '*', MUST_EXIST);
            $selected = $this->add_page($source, (int)$delegatedsection->section, 'Selected page');
            $moduleids = [(int)$selected->cmid];
            if ($kind === 'subsection with owner') {
                $moduleids[] = (int)$subsection->cmid;
            }
        }
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Existing target']);
        $generalsectionid = (int)$target->sections[0]->id;
        $targetsectionid = (int)$target->sections[1]->id;
        $this->add_page($target, 0, 'Existing General page');
        $this->add_page($target, 1, 'Existing target page');

        $request = [
            'itemid' => $rootitemid,
            'sectionid' => $dropafter ? $targetsectionid : 0,
            'coursemodulestoinclude' => $moduleids,
            'sectionstoinclude' => [0],
            'insertasnewsection' => !$dropafter,
            'courseid' => (int)$target->id,
            'asnewsection' => $dropafter,
        ];
        // Exercise the real external-parameter defaults, not a helper that supplies the optional flag.
        self::assertArrayNotHasKey('moduleselectionexplicit', $request);
        $params = item_into_section::validate_parameters(item_into_section::execute_parameters(), $request);
        self::assertFalse($params['moduleselectionexplicit']);
        self::assertTrue(item_into_section::execute(...array_values($params)));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        self::assertStringNotContainsString('Exception thrown during restore execution', $this->getActualOutputForAssertion());

        $sections = $this->sections_by_name((int)$target->id);
        self::assertArrayHasKey('Copied root', $sections, 'Flattened content must keep its new root alive.');
        $newrootid = (int)$sections['Copied root']->id;
        self::assertGreaterThan(0, (int)$sections['Copied root']->section);
        self::assertSame(['Existing General page'], $this->module_names($generalsectionid));
        self::assertSame(['Existing target page'], $this->module_names($targetsectionid));
        self::assertArrayNotHasKey('Excluded child', $sections);
        self::assertFalse($DB->record_exists('page', ['course' => $target->id, 'name' => 'Unselected root page']));
        self::assertSame(3, $DB->count_records('page', ['course' => $target->id]));

        if ($kind === 'subsection with owner') {
            self::assertSame(['Selected subsection'], $this->module_names($newrootid));
            $restoreddelegated = $DB->get_record('course_sections', [
                'course' => $target->id, 'component' => 'mod_subsection',
            ], '*', MUST_EXIST);
            self::assertSame(['Selected page'], $this->module_names((int)$restoreddelegated->id));
            self::assertSame(4, $DB->count_records('course_modules', ['course' => $target->id]));
        } else {
            self::assertSame(['Selected page'], $this->module_names($newrootid));
            self::assertFalse($DB->record_exists('course_sections', [
                'course' => $target->id, 'component' => 'mod_subsection',
            ]));
            self::assertSame(3, $DB->count_records('course_modules', ['course' => $target->id]));
        }
    }

    /**
     * Both new-root creation paths must account for flattened content, including subsection ownership.
     *
     * @return array<string, array{string, bool}>
     */
    public static function flattened_only_content_provider(): array {
        $cases = [];
        foreach (['custom child', 'subsection child', 'subsection with owner'] as $kind) {
            $cases[$kind . ' inserted at course level'] = [$kind, false];
            $cases[$kind . ' dropped after a section'] = [$kind, true];
        }
        return $cases;
    }

    public function test_selected_subsection_inside_excluded_custom_section_keeps_its_child_activity_nested(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Excluded custom child']);
        $rootpage = $this->add_page($source, 1, 'Root activity');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id,
            'section' => 2,
            'name' => 'Subsection',
        ]);
        $delegatedsection = $DB->get_record('course_sections', [
            'course' => $source->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $subsectionpage = self::getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'section' => $delegatedsection->section,
            'name' => 'Subsection activity',
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $byparent = $this->items_by_parent($rootitemid);
        $customchilditem = $byparent[$rootitemid]['Excluded custom child'];
        $subsectionitem = $byparent[$customchilditem->get_id()]['Subsection'];
        $subsectionpageitem = $byparent[$subsectionitem->get_id()]['Subsection activity'];
        self::assertSame('mod_subsection', $subsectionitem->get_type());
        $moduleids = [
            (int)$rootpage->cmid,
            (int)$subsectionitem->get_old_instance_id(),
            (int)$subsectionpageitem->get_old_instance_id(),
        ];

        $target = $this->create_course(1, [1 => 'Merge target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $this->restore($rootitemid, $targetsectionid, moduleids: $moduleids, sectionids: [0]);

        $targetdelegatedsection = $DB->get_record('course_sections', [
            'course' => $target->id,
            'component' => 'mod_subsection',
        ], '*', MUST_EXIST);
        $targetsubsection = get_coursemodule_from_instance(
            'subsection',
            (int)$targetdelegatedsection->itemid,
            (int)$target->id,
            false,
            MUST_EXIST
        );

        self::assertSame(['Root activity', 'Subsection'], $this->module_names($targetsectionid));
        self::assertSame($targetsectionid, (int)$targetsubsection->section);
        self::assertSame(['Subsection activity'], $this->module_names((int)$targetdelegatedsection->id));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $target->id,
            'name' => 'Excluded custom child',
        ]));

        $newtarget = $this->create_course(1, [1 => 'Existing section']);
        $this->restore(
            $rootitemid,
            0,
            insertasnewsection: true,
            courseid: (int)$newtarget->id,
            moduleids: $moduleids,
            sectionids: [0]
        );

        $sections = $this->sections_by_name($newtarget->id);
        $newrootsection = $sections['Copied root'];
        $newdelegatedsection = $DB->get_record('course_sections', [
            'course' => $newtarget->id,
            'component' => 'mod_subsection',
        ], '*', MUST_EXIST);
        $newsubsection = get_coursemodule_from_instance(
            'subsection',
            (int)$newdelegatedsection->itemid,
            (int)$newtarget->id,
            false,
            MUST_EXIST
        );

        self::assertSame(['Root activity', 'Subsection'], $this->module_names((int)$newrootsection->id));
        self::assertSame((int)$newrootsection->id, (int)$newsubsection->section);
        self::assertSame(['Subsection activity'], $this->module_names((int)$newdelegatedsection->id));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $newtarget->id,
            'name' => 'Excluded custom child',
        ]));
    }

    /**
     * Empty explicit selections must not inherit the legacy include-all behaviour.
     *
     * @param bool $insertasnewsection
     * @param bool $explicitselection
     * @param int[] $moduleids
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('empty_module_selection_provider')]
    public function test_empty_module_selection_with_selected_custom_section(
        bool $insertasnewsection,
        bool $explicitselection,
        array $moduleids
    ): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Custom child']);
        $this->add_page($source, 1, 'Root activity');
        $this->add_page($source, 2, 'Custom child activity');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id,
            'section' => 1,
            'name' => 'Root subsection',
        ]);
        $delegatedsection = $DB->get_record('course_sections', [
            'course' => $source->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        self::getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'section' => $delegatedsection->section,
            'name' => 'Subsection activity',
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $byparent = $this->items_by_parent($rootitemid);
        $childitem = $byparent[$rootitemid]['Custom child'];

        $target = $this->create_course(1, [1 => 'Merge target']);
        // Validate and dispatch in schema order, just as Moodle's AJAX external-function dispatcher does.
        $request = [
            'itemid' => $rootitemid,
            'sectionid' => $insertasnewsection ? 0 : (int)$target->sections[1]->id,
            'coursemodulestoinclude' => $moduleids,
            'sectionstoinclude' => [(int)$childitem->get_old_instance_id()],
            'insertasnewsection' => $insertasnewsection,
            'courseid' => (int)$target->id,
        ];
        if ($explicitselection) {
            $request['moduleselectionexplicit'] = true;
        }
        $params = item_into_section::validate_parameters(item_into_section::execute_parameters(), $request);
        self::assertTrue(item_into_section::execute(...array_values($params)));
        $queuedtasks = array_values(\core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));
        self::assertCount(1, $queuedtasks);
        $queuedtask = $queuedtasks[0];
        self::assertSame($moduleids, $queuedtask->get_custom_data()->backup_settings->course_modules_to_include);
        $this->runAdhocTasks(asynchronous_restore_task::class);

        $sections = $this->sections_by_name((int)$target->id);
        self::assertArrayHasKey('Custom child', $sections);
        $rootsectionid = (int)($insertasnewsection ? $sections['Copied root']->id : $target->sections[1]->id);

        if ($explicitselection) {
            self::assertSame([], $this->module_names($rootsectionid));
            self::assertSame([], $this->module_names((int)$sections['Custom child']->id));
            self::assertFalse($DB->record_exists('course_modules', ['course' => $target->id]));
            self::assertFalse($DB->record_exists('course_sections', [
                'course' => $target->id,
                'component' => 'mod_subsection',
            ]));
            self::assertTrue((bool)$queuedtask->get_custom_data()->backup_settings->module_selection_explicit);
        } else {
            self::assertSame(['Root activity', 'Root subsection'], $this->module_names($rootsectionid));
            self::assertSame(['Custom child activity'], $this->module_names((int)$sections['Custom child']->id));
            $restoreddelegatedsection = $DB->get_record('course_sections', [
                'course' => $target->id,
                'component' => 'mod_subsection',
            ], '*', MUST_EXIST);
            self::assertSame(['Subsection activity'], $this->module_names((int)$restoreddelegatedsection->id));
        }
    }

    /**
     * Provides explicit and legacy empty selections for merge and new-section restores.
     *
     * @return array<string, array{bool, bool, int[]}>
     */
    public static function empty_module_selection_provider(): array {
        return [
            'merge explicit empty' => [false, true, []],
            'new section explicit empty' => [true, true, []],
            'merge legacy empty' => [false, false, []],
            'new section legacy empty' => [true, false, []],
            'merge legacy zero' => [false, false, [0]],
            'new section legacy zero' => [true, false, [0]],
        ];
    }

    public function test_explicit_empty_grandchild_is_preserved_without_its_excluded_parent(): void {
        global $DB;

        $source = $this->create_course(3, [1 => 'Copied root', 2 => 'Excluded parent', 3 => 'Selected grandchild']);
        $this->add_page($source, 1, 'Root activity');
        $this->add_page($source, 2, 'Parent activity');
        $this->add_page($source, 3, 'Grandchild activity');
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[2]->id, 1],
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $target = $this->create_course(1, [1 => 'Existing section']);

        self::assertTrue(item_into_section::execute(
            $rootitemid,
            0,
            [],
            [(int)$source->sections[3]->id],
            insertasnewsection: true,
            courseid: (int)$target->id,
            moduleselectionexplicit: true
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);

        $sections = $this->sections_by_name((int)$target->id);
        self::assertArrayHasKey('Copied root', $sections);
        self::assertArrayHasKey('Selected grandchild', $sections);
        self::assertArrayNotHasKey('Excluded parent', $sections);
        self::assertSame([], $this->module_names((int)$sections['Copied root']->id));
        self::assertSame([], $this->module_names((int)$sections['Selected grandchild']->id));
        self::assertFalse($DB->record_exists('course_modules', ['course' => $target->id]));
    }

    public function test_legacy_empty_module_list_does_not_restore_subsection_from_excluded_custom_section(): void {
        global $DB;

        $source = $this->create_course(2, [1 => 'Copied root', 2 => 'Excluded custom child']);
        $rootpage = $this->add_page($source, 1, 'Root activity');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id,
            'section' => 2,
            'name' => 'Subsection',
        ]);
        $delegatedsection = $DB->get_record('course_sections', [
            'course' => $source->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        self::getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'section' => $delegatedsection->section,
            'name' => 'Subsection activity',
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Merge target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $this->restore($rootitemid, $targetsectionid, sectionids: [0]);

        self::assertSame(['Root activity'], $this->module_names($targetsectionid));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $target->id,
            'component' => 'mod_subsection',
        ]));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $target->id,
            'name' => 'Excluded custom child',
        ]));

        $newtarget = $this->create_course(1, [1 => 'Existing section']);
        $this->restore(
            $rootitemid,
            0,
            insertasnewsection: true,
            courseid: (int)$newtarget->id,
            sectionids: [0]
        );

        $sections = $this->sections_by_name($newtarget->id);
        self::assertSame(['Root activity'], $this->module_names((int)$sections['Copied root']->id));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $newtarget->id,
            'component' => 'mod_subsection',
        ]));
        self::assertFalse($DB->record_exists('course_sections', [
            'course' => $newtarget->id,
            'name' => 'Excluded custom child',
        ]));
    }

    public function test_section_without_activities_but_with_populated_descendants_can_be_copied(): void {
        $source = $this->create_course(2, [1 => 'Structural parent', 2 => 'Child']);
        $this->add_page($source, 2, 'Page child');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $byparent = $this->items_by_parent($rootitemid);
        self::assertSame(['Child'], array_keys($byparent[$rootitemid]));
        self::assertSame(['Page child'], array_keys($byparent[$byparent[$rootitemid]['Child']->get_id()]));
    }

    public function test_empty_section_without_descendants_is_rejected(): void {
        $source = $this->create_course(1, [1 => 'Empty']);

        $this->expectException(\moodle_exception::class);
        section_into_sharing_cart::execute((int)$source->sections[1]->id, ['users' => false, 'anonymize' => false]);
    }

    /**
     * Keep cache reads real, but fail if dialog rendering attempts to extract archive metadata.
     *
     * @return factory
     */
    private function dialog_factory_without_archive_extraction(): factory {
        $handler = $this->getMockBuilder(\block_sharing_cart\app\backup\handler::class)
            ->setConstructorArgs([$this->factory])
            ->onlyMethods(['cache_section_custom_names'])
            ->getMock();
        $handler->expects($this->never())->method('cache_section_custom_names');
        $backupfactory = $this->getMockBuilder(\block_sharing_cart\app\backup\factory::class)
            ->setConstructorArgs([$this->factory])
            ->onlyMethods(['handler'])
            ->getMock();
        $backupfactory->method('handler')->willReturn($handler);
        $factory = $this->getMockBuilder(factory::class)->onlyMethods(['backup'])->getMock();
        $factory->method('backup')->willReturn($backupfactory);
        return $factory;
    }

    /**
     * Rendering the dialog must not read or decompress the archive.
     */
    public function test_restore_dialog_does_not_read_the_backup_archive(): void {
        global $PAGE;

        $source = $this->create_course(1, [1 => 'Backed-up title']);
        $this->add_page($source, 1, 'Copied page');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->factory->item()->repository()->get_by_id($rootitemid);
        $target = $this->create_course(1);
        $PAGE->set_context(\context_course::instance($target->id));

        $body = new \block_sharing_cart\output\modal\import_item_modal_body(
            $this->dialog_factory_without_archive_extraction(),
            $item,
            (int)$target->sections[1]->id
        );
        $context = $body->export_for_template($PAGE->get_renderer('core'));

        self::assertSame('Backed-up title', $context['restore_source_name']);
        self::assertSame('Backed-up title', $context['restore_target_name']);
    }

    /**
     * Descendants need their own raw titles, rather than the root title or the generated cart labels.
     */
    public function test_nested_section_custom_names_are_cached_from_the_backup(): void {
        $source = $this->create_course(3, [1 => 'Root & title', 2 => null, 3 => 'Section 3']);
        $this->add_page($source, 1, 'Root page');
        $this->add_page($source, 2, 'Unnamed child page');
        $this->add_page($source, 3, 'Named child page');
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[1]->id, 2],
        ]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $rootitem = $this->factory->item()->repository()->get_by_id($rootitemid);
        $file = $this->factory->item()->repository()->get_stored_file_by_item($rootitem);
        $handler = $this->factory->backup()->handler();

        $names = [];
        foreach ($this->factory->item()->repository()->get_recursively_by_parent_id($rootitemid) as $item) {
            if ($item->is_section()) {
                $names[$item->get_old_instance_id()] = $handler->get_section_custom_name($file, $item->get_old_instance_id());
            }
        }
        self::assertSame([
            (int)$source->sections[1]->id => 'Root & title',
            (int)$source->sections[2]->id => '',
            (int)$source->sections[3]->id => 'Section 3',
        ], $names);
    }

    /**
     * Cache eviction must not trigger synchronous extraction or inference from a mutable cart name.
     */
    public function test_uncached_dialog_uses_generic_hints_without_reading_the_backup(): void {
        global $DB, $PAGE;

        $source = $this->create_course(1, [1 => 'Original custom title']);
        $this->add_page($source, 1, 'Copied page');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $repository = $this->factory->item()->repository();
        $item = $repository->get_by_id($rootitemid);
        $item->set_name('Misleading cart label');
        $repository->update($item);
        $item = $repository->get_by_id($rootitemid);
        \cache::make('block_sharing_cart', 'section_names')->purge();
        $file = $repository->get_stored_file_by_item($item);
        self::assertNull($this->factory->backup()->handler()->get_section_custom_name($file, $item->get_old_instance_id()));

        $target = $this->create_course(2, [1 => 'Known target', 2 => null]);
        $PAGE->set_context(\context_course::instance($target->id));
        $factory = $this->dialog_factory_without_archive_extraction();
        foreach ([1 => 'Known target', 2 => get_string('section')] as $sectionnum => $expectedname) {
            $body = new \block_sharing_cart\output\modal\import_item_modal_body(
                $factory,
                $item,
                (int)$target->sections[$sectionnum]->id
            );
            $context = $body->export_for_template($PAGE->get_renderer('core'));
            self::assertSame($expectedname, $context['restore_target_name']);
            self::assertSame(get_string('section'), $context['restore_replacement_name']);
        }
        $body = new \block_sharing_cart\output\modal\import_item_modal_body($factory, $item, 0, true);
        $context = $body->export_for_template($PAGE->get_renderer('core'));
        self::assertSame(get_string('section'), $context['restore_target_name']);

        // The fallback affects hints only: restoration must still take the real title from section.xml.
        $this->restore($rootitemid, (int)$target->sections[2]->id);
        self::assertSame('Original custom title', $DB->get_field('course_sections', 'name', [
            'id' => $target->sections[2]->id,
        ]));
    }

    /**
     * A different archive must not reuse titles for the same original section id or be extracted on a miss.
     */
    public function test_section_title_cache_is_scoped_to_archive_content(): void {
        global $USER;

        $source = $this->create_course(1, [1 => 'Cached title']);
        $this->add_page($source, 1, 'Copied page');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->factory->item()->repository()->get_by_id($rootitemid);
        $file = $this->factory->item()->repository()->get_stored_file_by_item($item);
        $handler = $this->factory->backup()->handler();
        $sectionid = (int)$source->sections[1]->id;
        self::assertSame('Cached title', $handler->get_section_custom_name($file, $sectionid));
        self::assertNull($handler->get_section_custom_name($file, $sectionid + 1000));

        // Intentionally not an archive: a cache miss must not attempt to open it.
        $otherfile = get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'block_sharing_cart',
            'filearea' => 'backup',
            'itemid' => $rootitemid,
            'filepath' => '/',
            'filename' => 'different-backup.mbz',
        ], 'Not a backup archive');
        self::assertNull($handler->get_section_custom_name($otherfile, $sectionid));
        self::assertSame('Cached title', $handler->get_section_custom_name($file, $sectionid));
    }

    /**
     * Placement names must follow the backed-up title, not a cart label or the current source section name.
     *
     * @param string|null $sourcename
     * @param string|null $targetname
     * @param bool $replacedetails
     * @param string $expectedname
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('placement_destination_name_provider')]
    public function test_placement_destination_name_matches_the_restored_section(
        ?string $sourcename,
        ?string $targetname,
        bool $replacedetails,
        string $expectedname
    ): void {
        global $DB, $PAGE;

        $source = $this->create_course(1, [1 => $sourcename]);
        $this->add_page($source, 1, 'Copied page');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->factory->item()->repository()->get_by_id($rootitemid);
        if ($sourcename === null || $sourcename === '') {
            self::assertSame('New section', $item->get_name());
        }
        $item->set_name('Cart display label');
        $this->factory->item()->repository()->update($item);
        $DB->set_field('course_sections', 'name', 'Source renamed after backup', ['id' => $source->sections[1]->id]);

        $target = $this->create_course(2, [2 => $targetname]);
        $targetsectionid = (int)$target->sections[2]->id;
        // Make the replacement option available even when the target has no custom title.
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $targetsectionid]);
        $PAGE->set_context(\context_course::instance($target->id));
        $body = new \block_sharing_cart\output\modal\import_item_modal_body($this->factory, $item, $targetsectionid);
        $context = $body->export_for_template($PAGE->get_renderer('core'));
        self::assertTrue($context['can_replace_section_details']);
        self::assertSame($expectedname, $context[$replacedetails ? 'restore_replacement_name' : 'restore_target_name']);
        self::assertSame(html_to_text(format_string($sourcename ?? ''), 0, false), $context['restore_source_name']);

        $this->restore($rootitemid, $targetsectionid, replacesectiondetails: $replacedetails);

        $restoredsection = $DB->get_record('course_sections', ['id' => $targetsectionid], '*', MUST_EXIST);
        $actualname = course_get_format($target->id)->get_section_name($restoredsection);
        self::assertSame($expectedname, html_to_text(format_string($actualname), 0, false));
        self::assertSame(['Copied page'], $this->module_names($targetsectionid));
    }

    /**
     * Provides named and unnamed source/target combinations, with and without explicit replacement.
     *
     * @return array<string, array{?string, ?string, bool, string}>
     */
    public static function placement_destination_name_provider(): array {
        return [
            'null source and target' => [null, null, false, 'New section'],
            'empty source and target' => ['', '', false, 'New section'],
            'null source replacing unnamed target' => [null, null, true, 'New section'],
            'null source keeps named target' => [null, 'Target title', false, 'Target title'],
            'null source replaces named target' => [null, 'Target title', true, 'New section'],
            'custom source names unnamed target' => ['Copied title', null, false, 'Copied title'],
            'custom source keeps named target' => ['Copied title', 'Target title', false, 'Target title'],
            'custom source replaces named target' => ['Copied title', 'Target title', true, 'Copied title'],
            'custom title resembling generated label' => ['Section 1', null, false, 'Section 1'],
            'numeric custom title' => ['1', null, false, '1'],
            'formatted custom title' => ['Copied & <em>title</em>', null, false, 'Copied & title'],
        ];
    }

    /**
     * Unnamed roots inserted as new sections still need a destination label, independent of the cart name.
     *
     * @param string|null $sourcename
     * @param string $targetformat
     * @param bool $toplevel
     * @param bool $withsubsection Whether the weeks course also has a delegated section.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('new_section_destination_name_provider')]
    public function test_new_section_destination_name_is_available(
        ?string $sourcename,
        string $targetformat,
        bool $toplevel,
        bool $withsubsection = false
    ): void {
        global $DB, $PAGE;

        $source = $this->create_course(1, [1 => $sourcename]);
        $this->add_page($source, 1, 'Copied page');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);
        $item = $this->factory->item()->repository()->get_by_id($rootitemid);
        $item->set_name('Misleading cart label');
        $this->factory->item()->repository()->update($item);

        $target = self::getDataGenerator()->create_course(['format' => $targetformat, 'numsections' => 2]);
        if ($withsubsection) {
            self::getDataGenerator()->create_module('subsection', ['course' => $target->id, 'section' => 1]);
            self::assertTrue($DB->record_exists('course_sections', ['course' => $target->id, 'component' => 'mod_subsection']));
        }
        $targetsectionid = $toplevel ? 0 : (int)$DB->get_field('course_sections', 'id', [
            'course' => $target->id,
            'section' => 2,
        ], MUST_EXIST);
        $PAGE->set_context(\context_course::instance($target->id));
        $body = new \block_sharing_cart\output\modal\import_item_modal_body($this->factory, $item, $targetsectionid, true);
        $context = $body->export_for_template($PAGE->get_renderer('core'));
        self::assertNotSame('', $context['restore_target_name']);
        self::assertNotSame($item->get_name(), $context['restore_target_name']);
        self::assertSame(html_to_text(format_string($sourcename ?? ''), 0, false), $context['restore_source_name']);
        self::assertFalse($context['can_replace_section_details']);

        $this->restore($rootitemid, $targetsectionid, insertasnewsection: true, courseid: (int)$target->id);

        $pageid = $DB->get_field('page', 'id', ['course' => $target->id, 'name' => 'Copied page'], MUST_EXIST);
        $module = get_coursemodule_from_instance('page', (int)$pageid, (int)$target->id, false, MUST_EXIST);
        $restoredsection = $DB->get_record('course_sections', ['id' => $module->section], '*', MUST_EXIST);
        if ($withsubsection) {
            self::assertSame(3, (int)$restoredsection->section, 'The delegated subsection must not count as a week.');
        }
        $actualname = course_get_format($target->id)->get_section_name($restoredsection);
        if ($targetformat === 'weeks' && !$toplevel && ($sourcename ?? '') === '') {
            // Week numbers can change when a section is moved; a generic label is safer than a guessed date range.
            self::assertSame('New section', $context['restore_target_name']);
        } else {
            self::assertSame(html_to_text(format_string($actualname), 0, false), $context['restore_target_name']);
        }
        if (($sourcename ?? '') === '') {
            self::assertSame('', (string)$restoredsection->name);
        }
    }

    /**
     * Provides unnamed and custom titles across top-level, nested and date-based new-section restores.
     *
     * @return array<string, array{0: ?string, 1: string, 2: bool, 3?: bool}>
     */
    public static function new_section_destination_name_provider(): array {
        return [
            'unnamed topics root at top level' => [null, 'topics', true],
            'unnamed topics root under a target' => [null, 'topics', false],
            'empty topics root at top level' => ['', 'topics', true],
            'named topics root at top level' => ['Copied title', 'topics', true],
            'named topics root under a target' => ['Copied & <em>title</em>', 'topics', false],
            'unnamed weeks root at top level' => [null, 'weeks', true],
            'unnamed weeks root at top level with a delegated subsection' => [null, 'weeks', true, true],
            'unnamed weeks root under a target' => [null, 'weeks', false],
        ];
    }

    public function test_replace_section_details_takes_the_copied_title_and_description(): void {
        global $DB;

        $source = $this->create_course(1, [1 => 'Copied']);
        $this->add_page($source, 1, 'Page copied');
        $DB->set_field('course_sections', 'summary', '<p>Copied summary</p>', ['id' => $source->sections[1]->id]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $targetsectionid]);
        $this->add_section_file($target->id, $targetsectionid, 'old.txt');

        $hookreplacement = null;
        $snapshotseen = null;
        $hooktargetname = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            before_sections_restored::class,
            static function (before_sections_restored $hook) use (&$hookreplacement, &$snapshotseen, &$hooktargetname): void {
                $hookreplacement = $hook->replacesectiondetails;
                $snapshotseen = factory::make()->restore()->section_details_replacement()
                    ->has_snapshot($hook->courseid, $hook->targetsectionid);
                $hooktargetname = factory::make()->moodle()->db()->get_field('course_sections', 'name', [
                    'id' => $hook->targetsectionid,
                ]);
            }
        );
        $this->restore($rootitemid, $targetsectionid, replacesectiondetails: true);

        self::assertTrue($hookreplacement, 'Course formats must see the pending replacement before preparing the restore.');
        self::assertFalse($snapshotseen, 'Format validation must run before the rollback snapshot is taken.');
        self::assertSame('Target', $hooktargetname, 'Format validation must still see the original target details.');
        $section = $DB->get_record('course_sections', ['id' => $targetsectionid], '*', MUST_EXIST);
        self::assertSame('Copied', $section->name);
        self::assertStringContainsString('Copied summary', $section->summary);
        self::assertFalse($this->section_file_exists($target->id, $targetsectionid, 'old.txt'));
        self::assertFalse($this->factory->restore()->section_details_replacement()->has_snapshot($target->id, $targetsectionid));
        self::assertSame(['Page copied'], $this->module_names($targetsectionid));
    }

    public function test_rejected_replacement_preserves_target_edit_made_during_preflight(): void {
        global $DB;

        $source = $this->create_course(1, [1 => 'Copied']);
        $this->add_page($source, 1, 'Page copied');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $targetsectionid]);
        $this->add_section_file($target->id, $targetsectionid, 'keep.txt');

        $hookreplacement = null;
        $snapshotseen = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            before_sections_restored::class,
            static function (before_sections_restored $hook) use ($DB, &$hookreplacement, &$snapshotseen): void {
                $hookreplacement = $hook->replacesectiondetails;
                $snapshotseen = factory::make()->restore()->section_details_replacement()
                    ->has_snapshot($hook->courseid, $hook->targetsectionid);
                $DB->update_record('course_sections', (object)[
                    'id' => $hook->targetsectionid,
                    'name' => 'Edited target',
                    'summary' => '<p>Edited target summary</p>',
                ]);
                throw new \RuntimeException('Test rejection before copying');
            }
        );

        $this->restore($rootitemid, $targetsectionid, replacesectiondetails: true, expectfailure: true);

        self::assertStringContainsString('Test rejection before copying', $this->getActualOutputForAssertion());
        self::assertTrue($hookreplacement);
        self::assertFalse($snapshotseen);
        $section = $DB->get_record('course_sections', ['id' => $targetsectionid], '*', MUST_EXIST);
        self::assertSame('Edited target', $section->name);
        self::assertSame('<p>Edited target summary</p>', $section->summary);
        self::assertTrue($this->section_file_exists($target->id, $targetsectionid, 'keep.txt'));
        self::assertFalse($this->factory->restore()->section_details_replacement()->has_snapshot($target->id, $targetsectionid));
        self::assertFalse($DB->record_exists('course_modules', ['course' => $target->id]));
    }

    /**
     * If the restore plan fails after blanking, rollback must restore the details left by successful preflight.
     */
    public function test_failed_replacement_rolls_back_to_details_edited_during_preflight(): void {
        global $DB, $USER;

        $source = $this->create_course(1, [1 => 'Copied']);
        $this->add_page($source, 1, 'Page copied');
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $targetsectionid]);
        $this->add_section_file($target->id, $targetsectionid, 'old.txt');

        $groupsxml = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            before_sections_restored::class,
            static function (before_sections_restored $hook) use ($DB, &$groupsxml): void {
                $DB->update_record('course_sections', (object)[
                    'id' => $hook->targetsectionid,
                    'name' => 'Edited target',
                    'summary' => 'Edited target summary: @@PLUGINFILE@@/edited.txt',
                    'summaryformat' => FORMAT_PLAIN,
                ]);
                $contextid = \core\context\course::instance($hook->courseid)->id;
                get_file_storage()->delete_area_files($contextid, 'course', 'section', $hook->targetsectionid);
                get_file_storage()->create_file_from_string([
                    'contextid' => $contextid,
                    'component' => 'course',
                    'filearea' => 'section',
                    'itemid' => $hook->targetsectionid,
                    'filepath' => '/',
                    'filename' => 'edited.txt',
                ], 'Edited file');

                // Core restores groups early in the plan, after Sharing Cart has blanked the destination.
                $controller = \restore_controller::load_controller($hook->restoreid);
                $path = $controller->get_plan()->get_basepath() . '/groups.xml';
                $controller->destroy();
                if (is_file($path) && unlink($path)) {
                    $groupsxml = $path;
                }
            }
        );

        self::assertTrue(item_into_section::execute($rootitemid, $targetsectionid, [], replacesectiondetails: true));
        $tasks = \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class);
        self::assertCount(1, $tasks);
        $taskid = (int)reset($tasks)->get_id();
        try {
            $this->runAdhocTasks(asynchronous_restore_task::class);
        } finally {
            // The failed plan never reaches Moodle's step that drops the restore temp tables.
            $manager = $DB->get_manager();
            foreach (['backup_files_temp', 'backup_ids_temp'] as $name) {
                if ($manager->table_exists($name)) {
                    $manager->drop_table(new \xmldb_table($name));
                }
            }
            \restore_dbops::reset_backup_ids_cached();
        }

        self::assertNotNull($groupsxml, 'Preflight must successfully remove the extracted groups XML.');
        self::assertStringContainsString(
            get_string('missing_moodle_backup_xml_file', 'error', $groupsxml),
            $this->getActualOutputForAssertion()
        );
        $outcome = $this->factory->restore()->outcomes()->get_recent((int)$USER->id, (int)$target->id)[$taskid];
        self::assertFalse($outcome['succeeded']);
        self::assertSame('uncertain', $outcome['copy_state'], 'The failure must occur inside the restore plan.');

        $section = $DB->get_record('course_sections', ['id' => $targetsectionid], '*', MUST_EXIST);
        self::assertSame('Edited target', $section->name);
        self::assertSame('Edited target summary: @@PLUGINFILE@@/edited.txt', $section->summary);
        self::assertSame((int)FORMAT_PLAIN, (int)$section->summaryformat);
        self::assertTrue($this->section_file_exists($target->id, $targetsectionid, 'edited.txt'));
        self::assertFalse($this->section_file_exists($target->id, $targetsectionid, 'old.txt'));
        self::assertFalse($this->factory->restore()->section_details_replacement()->has_snapshot($target->id, $targetsectionid));
    }

    public function test_insert_as_new_top_level_section(): void {
        $source = $this->create_course(2, [1 => 'Copied', 2 => 'Child']);
        $this->add_page($source, 1, 'Page copied');
        $this->add_page($source, 2, 'Page child');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Existing']);

        $payload = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$payload): void {
                $payload = $hook;
            }
        );

        $this->restore($rootitemid, 0, insertasnewsection: true, courseid: (int)$target->id);

        $sections = $this->sections_by_name($target->id);
        self::assertEqualsCanonicalizing(['', 'Existing', 'Copied', 'Child'], array_keys($sections));
        self::assertSame(2, (int)$sections['Copied']->section);
        self::assertSame(3, (int)$sections['Child']->section);
        self::assertSame(['Page copied'], $this->module_names((int)$sections['Copied']->id));
        self::assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
        self::assertSame([], $this->module_names((int)$sections['Existing']->id));

        self::assertSame(0, $payload->targetsectionid);
        [$copied, $child] = $payload->restoredsections;
        self::assertSame(0, $copied->new_parent_section_id);
        self::assertSame((int)$sections['Copied']->id, $child->new_parent_section_id);
    }

    public function test_flat_restore_omits_empty_ancestors_and_keeps_only_populated_leaf(): void {
        global $DB;

        $rootitemid = $this->copy_tree_with_content_only_in_leaf();
        $target = $this->create_course(1, [1 => 'Existing']);

        $this->restore($rootitemid, 0, insertasnewsection: true, courseid: (int)$target->id);

        $sections = $DB->get_records('course_sections', ['course' => $target->id], 'section ASC');
        self::assertCount(3, $sections);
        self::assertEqualsCanonicalizing(['', 'Existing', 'Section 4'], array_map(
            static fn(object $section): string => (string)$section->name,
            array_values($sections)
        ));
        self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'name' => 'Section 2']));
        self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'name' => 'Section 3']));

        $leaf = $DB->get_record('course_sections', ['course' => $target->id, 'name' => 'Section 4'], '*', MUST_EXIST);
        self::assertSame(2, (int)$leaf->section);
        self::assertSame(['Leaf activity'], $this->module_names((int)$leaf->id));
    }

    public function test_flat_drop_restore_removes_empty_placeholder_and_ancestors(): void {
        global $DB;

        $rootitemid = $this->copy_tree_with_content_only_in_leaf();
        $target = $this->create_course(2, [1 => 'Before', 2 => 'After']);
        $restoreitem = $this->factory->item()->repository()->get_by_id($rootitemid);

        $task = $this->factory->restore()->handler()->restore_item_into_section(
            item: $restoreitem,
            sectionid: (int)$target->sections[1]->id,
            itemid: $rootitemid,
            asnewsection: true
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $task);
        $this->runAdhocTasks(asynchronous_restore_task::class);

        $sections = array_values($DB->get_records('course_sections', ['course' => $target->id], 'section ASC'));
        self::assertCount(4, $sections);
        self::assertEqualsCanonicalizing(['', 'Before', 'Section 4', 'After'], array_map(
            static fn(object $section): string => (string)$section->name,
            $sections
        ));
        self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'name' => 'Section 2']));
        self::assertFalse($DB->record_exists('course_sections', ['course' => $target->id, 'name' => 'Section 3']));

        $leaf = $DB->get_record('course_sections', ['course' => $target->id, 'name' => 'Section 4'], '*', MUST_EXIST);
        self::assertSame(2, (int)$leaf->section);
        self::assertSame(['Leaf activity'], $this->module_names((int)$leaf->id));
    }

    /**
     * Adds a file to a section's summary file area.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param string $filename
     */
    private function add_section_file(int $courseid, int $sectionid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \core\context\course::instance($courseid)->id,
            'component' => 'course',
            'filearea' => 'section',
            'itemid' => $sectionid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'content');
    }

    /**
     * Whether a section's summary file area holds the file.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param string $filename
     * @return bool
     */
    private function section_file_exists(int $courseid, int $sectionid, string $filename): bool {
        return get_file_storage()->file_exists(
            \core\context\course::instance($courseid)->id,
            'course',
            'section',
            $sectionid,
            '/',
            $filename
        );
    }

    /**
     * create_section
     *
     * @param int $courseid
     * @param array $record
     * @return object
     */
    private function create_section(int $courseid, array $record = []): object {
        $db = $this->factory->moodle()->db();

        $record['course'] = $courseid;

        if (!isset($record['section'])) {
            $lastsectionnumber = (int)$db->get_field(
                'course_sections',
                'MAX(section)',
                ['course' => $courseid]
            );
            $record['section'] = $lastsectionnumber + 1;
        }

        $section = self::getDataGenerator()->create_course_section($record);
        return $db->get_record(
            'course_sections',
            ['id' => $section->id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * backup_label_into_cart
     *
     * @param object $course
     * @param object $section
     * @param object $user
     * @return entity
     */
    private function backup_label_into_cart(object $course, object $section, object $user): entity {
        $generator = self::getDataGenerator();
        $label = $generator->create_module('label', [
            'course' => $course->id,
            'section' => $section->section,
            'name' => 'Cart Label',
            'intro' => 'Cart label intro',
        ]);

        $item = $this->factory->item()->repository()->insert_activity(
            $label->cmid,
            $user->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $task = $this->factory->backup()->handler()->backup_course_module(
            $label->cmid,
            $item,
            [
                'users' => false,
                'anonymize' => false,
            ]
        );
        $ref = new \ReflectionProperty($task, 'output');
        $ref->setAccessible(true);
        $ref->setValue($task, false);
        $task->execute();

        $item = $this->factory->item()->repository()->get_by_id($item->get_id());
        self::assertNotNull($item);
        self::assertSame(entity::STATUS_BACKEDUP, $item->get_status());
        self::assertNotNull(
            $this->factory->item()->repository()->get_stored_file_by_item($item)
        );

        return $item;
    }

    /**
     * test_queue_does_not_create_backup_controller_or_tempdir
     *
     * @return void
     */
    public function test_queue_does_not_create_backup_controller_or_tempdir(): void {
        global $USER, $DB;

        self::setAdminUser();

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $sourcesection = $this->create_section($source->id, ['name' => 'Source']);
        $targetsection = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($USER->id, $source->id, 'editingteacher');
        $generator->enrol_user($USER->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $sourcesection, $USER);

        $controllersbefore = $DB->count_records('backup_controllers');

        $restoretask = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $targetsection->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restoretask);

        $customdata = $restoretask->get_custom_data();
        self::assertObjectNotHasProperty('backupid', $customdata);
        self::assertSame((int)$target->id, (int)$customdata->courseid);
        self::assertNotEmpty($customdata->item);
        self::assertSame(
            (int)$targetsection->id,
            (int)($customdata->backup_settings->move_to_section_id ?? 0)
        );
        self::assertSame(
            [(int)$item->get_old_instance_id()],
            array_map('intval', (array)($customdata->backup_settings->course_modules_to_include ?? []))
        );

        self::assertSame($controllersbefore, $DB->count_records('backup_controllers'));
    }

    /**
     * test_restore_extracts_and_succeeds_on_task_execute
     *
     * @return void
     */
    public function test_restore_extracts_and_succeeds_on_task_execute(): void {
        global $USER, $DB;

        self::setAdminUser();

        $generator = self::getDataGenerator();
        $source = $generator->create_course();
        $target = $generator->create_course();
        $sourcesection = $this->create_section($source->id, ['name' => 'Source']);
        $targetsection = $this->create_section($target->id, ['name' => 'Target']);
        $generator->enrol_user($USER->id, $source->id, 'editingteacher');
        $generator->enrol_user($USER->id, $target->id, 'editingteacher');

        $item = $this->backup_label_into_cart($source, $sourcesection, $USER);

        $cmsbefore = $DB->count_records('course_modules', ['course' => $target->id]);

        $restoretask = $this->factory->restore()->handler()->restore_item_into_section(
            $item,
            $targetsection->id,
            $item->get_id(),
            [
                'course_modules_to_include' => [(int)$item->get_old_instance_id()],
            ]
        );
        self::assertInstanceOf(asynchronous_restore_task::class, $restoretask);

        ob_start();
        $restoretask->execute();
        ob_get_clean();

        $cmsafter = $DB->count_records('course_modules', ['course' => $target->id]);
        self::assertGreaterThan($cmsbefore, $cmsafter);
    }

    /**
     * A standalone activity can be restored using the default selection without permission to configure it.
     */
    public function test_standalone_activity_restore_succeeds_without_configure_capability(): void {
        $source = $this->create_course(1);
        $page = $this->add_page($source, 1, 'Standalone page');
        $target = $this->create_course(1, [1 => 'Target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $this->set_teacher_without_restore_configure((int)$source->id, (int)$target->id);

        $result = course_module_into_sharing_cart::execute((int)$page->cmid, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $item = $this->factory->item()->repository()->get_by_id((int)$result->id);
        self::assertSame(entity::STATUS_BACKEDUP, $item->get_status());

        $this->restore($item->get_id(), $targetsectionid);

        self::assertSame(['Standalone page'], $this->module_names($targetsectionid));
    }

    /**
     * A default section restore must keep delegated content nested even when the teacher cannot configure the selection.
     */
    public function test_default_section_restore_preserves_subsection_without_configure_capability(): void {
        global $DB;

        $source = $this->create_course(1, [1 => 'Source']);
        $this->add_page($source, 1, 'Root page');
        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $source->id, 'section' => 1, 'name' => 'Copied subsection',
        ]);
        $delegated = $DB->get_record('course_sections', [
            'course' => $source->id, 'component' => 'mod_subsection', 'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $this->add_page($source, (int)$delegated->section, 'Nested page');
        $target = $this->create_course(1, [1 => 'Target']);
        $targetsectionid = (int)$target->sections[1]->id;
        $this->set_teacher_without_restore_configure((int)$source->id, (int)$target->id);
        $rootitemid = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $this->restore($rootitemid, $targetsectionid);

        self::assertSame(['Copied subsection', 'Root page'], $this->module_names($targetsectionid));
        $restoredsubsection = $DB->get_record('subsection', [
            'course' => $target->id, 'name' => 'Copied subsection',
        ], '*', MUST_EXIST);
        $restoreddelegated = $DB->get_record('course_sections', [
            'course' => $target->id, 'component' => 'mod_subsection', 'itemid' => $restoredsubsection->id,
        ], '*', MUST_EXIST);
        self::assertSame(['Nested page'], $this->module_names((int)$restoreddelegated->id));
        self::assertSame(3, $DB->count_records('course_modules', ['course' => $target->id]));
    }

    /**
     * Set the current user to an editing teacher who can restore into the target but cannot configure the restore.
     *
     * @param int $sourcecourseid Source course ID.
     * @param int $targetcourseid Target course ID.
     */
    private function set_teacher_without_restore_configure(int $sourcecourseid, int $targetcourseid): void {
        global $DB;

        $generator = self::getDataGenerator();
        $teacher = $generator->create_user();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $generator->enrol_user($teacher->id, $sourcecourseid, $roleid);
        $generator->enrol_user($teacher->id, $targetcourseid, $roleid);
        $targetcontext = \core\context\course::instance($targetcourseid);
        assign_capability('moodle/restore:configure', CAP_PREVENT, $roleid, $targetcontext->id);
        self::setUser($teacher);
        self::assertTrue(has_capability('moodle/restore:restoreactivity', $targetcontext));
        self::assertFalse(has_capability('moodle/restore:configure', $targetcontext));
    }

    /**
     * Verify that a migrated Page under a cart folder uses its own archive's module ID.
     */
    public function test_migrated_page_in_legacy_folder_restores_explicit_zero(): void {
        $item = $this->copy_migrated_page_into_legacy_folder();
        $target = $this->create_course(1);
        $sectionid = (int)$target->sections[1]->id;

        self::assertTrue(item_into_section::execute($item->get_id(), $sectionid, [0], moduleselectionexplicit: true));
        $this->runAdhocTasks(asynchronous_restore_task::class);
        self::assertSame(['Migrated page'], $this->module_names($sectionid));
    }

    /**
     * Queue validation checks archive ownership and request shape without opening a migrated backup.
     */
    public function test_migrated_page_queue_validates_without_opening_the_archive(): void {
        global $USER;

        $item = $this->copy_migrated_page_into_legacy_folder();
        $itemid = $item->get_id();
        $target = $this->create_course(1);
        $sectionid = (int)$target->sections[1]->id;
        $repository = $this->factory->item()->repository();
        $file = $repository->get_stored_file_by_item($item);
        self::assertNotNull($file);
        $record = [
            'contextid' => $file->get_contextid(),
            'component' => 'block_sharing_cart',
            'filearea' => 'backup',
            'itemid' => $item->get_parent_item_id(),
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ];
        $fs = get_file_storage();
        $handler = $this->factory->restore()->handler();
        $sharedfile = $fs->create_file_from_string($record, 'Not a backup archive');
        $file->replace_file_with($sharedfile);
        try {
            $handler->restore_item_into_section($item, $sectionid, $itemid, [
                'course_modules_to_include' => [0, 123],
                'module_selection_explicit' => true,
            ]);
            self::fail('A migrated activity was queued with more than one module ID.');
        } catch (\invalid_parameter_exception $exception) {
            self::assertStringContainsString('must contain only its own module', $exception->debuginfo);
        }
        self::assertEmpty(\core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class));

        self::assertInstanceOf(asynchronous_restore_task::class, $handler->restore_item_into_section($item, $sectionid, $itemid, [
            'course_modules_to_include' => [0],
            'module_selection_explicit' => true,
        ]));
        $tasks = \core\task\manager::get_adhoc_tasks(asynchronous_restore_task::class);
        self::assertCount(1, $tasks);
        $taskid = (int)reset($tasks)->get_id();
        $this->assertDebuggingNotCalled();
        $this->runAdhocTasks(asynchronous_restore_task::class);
        // Core's ZIP reader reports the corrupt content when the worker finally opens the file.
        $this->assertDebuggingCalled(debuglevel: DEBUG_DEVELOPER);

        $outcome = $this->factory->restore()->outcomes()->get_recent((int)$USER->id, (int)$target->id)[$taskid];
        self::assertFalse($outcome['succeeded']);
        self::assertSame('not_started', $outcome['copy_state']);
        self::assertSame([], $this->module_names($sectionid));

        // Deleting the owned file also removes the cart row via the file observer. Check ownership on the saved entity.
        $file->delete();
        self::assertSame((int)$sharedfile->get_id(), (int)$repository->get_stored_file_by_item($item)->get_id());
        $this->expectException(\invalid_parameter_exception::class);
        $handler->restore_item_into_section($item, $sectionid, $itemid, [
            'course_modules_to_include' => [0],
            'module_selection_explicit' => true,
        ]);
    }

    /**
     * Creates a migrated cart activity in a legacy folder, with its own single-activity archive.
     *
     * @return entity Migrated activity.
     */
    private function copy_migrated_page_into_legacy_folder(): entity {
        global $DB, $USER;

        $source = $this->create_course(1);
        $page = $this->add_page($source, 1, 'Migrated page');
        $result = course_module_into_sharing_cart::execute((int)$page->cmid, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);
        $folder = $this->factory->item()->repository()->insert_child_section(0, 'Legacy folder', (int)$USER->id, 0, 0);
        $DB->update_record('block_sharing_cart_items', (object)[
            'id' => $result->id,
            'parent_item_id' => $folder->get_id(),
            'version' => 1,
            'old_instance_id' => 0,
        ]);

        $item = $this->factory->item()->repository()->get_by_id((int)$result->id);
        self::assertSame(entity::STATUS_BACKEDUP, $item->get_status());
        return $item;
    }
}
