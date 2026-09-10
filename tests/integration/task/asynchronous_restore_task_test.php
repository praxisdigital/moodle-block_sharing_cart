<?php

namespace block_sharing_cart\integration\task;

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\app\restore\section_details_replacement;
use block_sharing_cart\external\backup\section_into_sharing_cart;
use block_sharing_cart\external\restore\item_into_section;
use block_sharing_cart\hook\backup\resolve_section_tree;
use block_sharing_cart\hook\restore\after_sections_restored;
use block_sharing_cart\hook\restore\before_sections_restored;
use block_sharing_cart\task\asynchronous_backup_task;
use block_sharing_cart\task\asynchronous_restore_task;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Nested section copy and restore, driven through the section hierarchy hooks with a test callback standing in for a
 * nesting course format. Uses plain topics courses: to the cart a "descendant" is just another section id.
 */
class asynchronous_restore_task_test extends \advanced_testcase
{
    private factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->factory = factory::make();

        // The cart tasks mtrace their progress.
        $this->expectOutputRegex('/.*/');
    }

    /**
     * @return object course with a 'sections' map: section number => course_sections record
     */
    private function create_course(int $numsections, array $names = []): object
    {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => $numsections]);
        foreach ($names as $number => $name) {
            $DB->set_field('course_sections', 'name', $name, ['course' => $course->id, 'section' => $number]);
        }

        $course->sections = [];
        foreach ($DB->get_records('course_sections', ['course' => $course->id], 'section ASC') as $section) {
            $course->sections[(int)$section->section] = $section;
        }

        return $course;
    }

    private function add_page(object $course, int $sectionnum, string $name): void
    {
        $this->getDataGenerator()->create_module('page', [
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
    private function declare_section_tree(array $tree): void
    {
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            resolve_section_tree::class,
            static function (resolve_section_tree $hook) use ($tree): void {
                foreach ($tree as [$section_id, $parent_section_id, $sort_order]) {
                    $hook->add_child((int)$section_id, (int)$parent_section_id, (int)$sort_order);
                }
            }
        );
    }

    private function copy_section_to_cart(int $section_id): int
    {
        $result = section_into_sharing_cart::execute($section_id, ['users' => false, 'anonymize' => false]);
        $this->runAdhocTasks(asynchronous_backup_task::class);

        $item = $this->factory->item()->repository()->get_by_id((int)$result->id);
        $this->assertSame(entity::STATUS_BACKEDUP, $item->get_status());

        return (int)$result->id;
    }

    private function restore(
        int $item_id,
        int $section_id,
        bool $insert_as_new_section = false,
        int $course_id = 0,
        bool $replace_section_details = false
    ): void {
        $this->assertTrue(item_into_section::execute(
            $item_id,
            $section_id,
            [],
            [],
            $insert_as_new_section,
            $course_id,
            $replace_section_details
        ));
        $this->runAdhocTasks(asynchronous_restore_task::class);
    }

    private function module_names(int $section_id): array
    {
        global $DB;

        $sequence = (string)$DB->get_field('course_sections', 'sequence', ['id' => $section_id], MUST_EXIST);
        $names = [];
        foreach (array_filter(explode(',', $sequence)) as $cm_id) {
            $names[] = get_coursemodule_from_id('', (int)$cm_id, 0, false, MUST_EXIST)->name;
        }
        sort($names);

        return $names;
    }

    /** @return array<string, object> course_sections records keyed by name */
    private function sections_by_name(int $course_id): array
    {
        global $DB;

        $by_name = [];
        foreach ($DB->get_records('course_sections', ['course' => $course_id], 'section ASC') as $section) {
            $by_name[(string)$section->name] = $section;
        }

        return $by_name;
    }

    /** @return array<int, entity[]> child items keyed by parent item id, keyed again by name */
    private function items_by_parent(int $root_item_id): array
    {
        $by_parent = [];
        foreach ($this->factory->item()->repository()->get_recursively_by_parent_id($root_item_id) as $item) {
            if ($item->get_parent_item_id() !== null) {
                $by_parent[$item->get_parent_item_id()][$item->get_name()] = $item;
            }
        }

        return $by_parent;
    }

    public function test_nested_sections_are_restored_after_the_target_section(): void
    {
        $source = $this->create_course(3, [1 => 'Root', 2 => 'Child', 3 => 'Grandchild']);
        $this->add_page($source, 1, 'Page root');
        $this->add_page($source, 2, 'Page child');
        $this->add_page($source, 3, 'Page grandchild');
        $this->declare_section_tree([
            [$source->sections[2]->id, $source->sections[1]->id, 1],
            [$source->sections[3]->id, $source->sections[2]->id, 1],
        ]);

        $root_item_id = $this->copy_section_to_cart((int)$source->sections[1]->id);

        // The cart holds the tree: Root > [Page root, Child > [Page child, Grandchild > [Page grandchild]]].
        $by_parent = $this->items_by_parent($root_item_id);
        $this->assertEqualsCanonicalizing(['Page root', 'Child'], array_keys($by_parent[$root_item_id]));
        $child_item = $by_parent[$root_item_id]['Child'];
        $this->assertTrue($child_item->is_section());
        $this->assertEqualsCanonicalizing(['Page child', 'Grandchild'], array_keys($by_parent[$child_item->get_id()]));
        $grandchild_item = $by_parent[$child_item->get_id()]['Grandchild'];
        $this->assertEqualsCanonicalizing(['Page grandchild'], array_keys($by_parent[$grandchild_item->get_id()]));

        $target = $this->create_course(2, [1 => 'Target', 2 => 'After']);
        $this->add_page($target, 1, 'Page target');
        $target_section_id = (int)$target->sections[1]->id;

        $payload = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$payload): void {
                $payload = $hook;
            }
        );

        $this->restore($root_item_id, $target_section_id);

        // The root merged into the target; the descendants are new sections placed directly after it.
        $this->assertSame(['Page root', 'Page target'], $this->module_names($target_section_id));

        $sections = $this->sections_by_name($target->id);
        $this->assertEqualsCanonicalizing(['', 'Target', 'Child', 'Grandchild', 'After'], array_keys($sections));
        $this->assertSame(2, (int)$sections['Child']->section);
        $this->assertSame(3, (int)$sections['Grandchild']->section);
        $this->assertSame(4, (int)$sections['After']->section);
        $this->assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
        $this->assertSame(['Page grandchild'], $this->module_names((int)$sections['Grandchild']->id));

        // The format is told the new hierarchy.
        $this->assertNotNull($payload);
        $this->assertSame($target->id, $payload->course_id);
        $this->assertSame($target_section_id, $payload->target_section_id);
        $this->assertCount(2, $payload->restored_sections);
        [$child, $grandchild] = $payload->restored_sections;
        $this->assertSame((int)$sections['Child']->id, $child->new_section_id);
        $this->assertSame($target_section_id, $child->new_parent_section_id);
        $this->assertSame((int)$sections['Grandchild']->id, $grandchild->new_section_id);
        $this->assertSame((int)$sections['Child']->id, $grandchild->new_parent_section_id);
    }

    public function test_section_without_activities_but_with_populated_descendants_can_be_copied(): void
    {
        $source = $this->create_course(2, [1 => 'Structural parent', 2 => 'Child']);
        $this->add_page($source, 2, 'Page child');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);

        $root_item_id = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $by_parent = $this->items_by_parent($root_item_id);
        $this->assertSame(['Child'], array_keys($by_parent[$root_item_id]));
        $this->assertSame(['Page child'], array_keys($by_parent[$by_parent[$root_item_id]['Child']->get_id()]));
    }

    public function test_empty_section_without_descendants_is_rejected(): void
    {
        $source = $this->create_course(1, [1 => 'Empty']);

        $this->expectException(\moodle_exception::class);
        section_into_sharing_cart::execute((int)$source->sections[1]->id, ['users' => false, 'anonymize' => false]);
    }

    public function test_replace_section_details_takes_the_copied_title_and_description(): void
    {
        global $DB;

        $source = $this->create_course(1, [1 => 'Copied']);
        $this->add_page($source, 1, 'Page copied');
        $DB->set_field('course_sections', 'summary', '<p>Copied summary</p>', ['id' => $source->sections[1]->id]);
        $root_item_id = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Target']);
        $target_section_id = (int)$target->sections[1]->id;
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $target_section_id]);
        $this->add_section_file($target->id, $target_section_id, 'old.txt');

        $this->restore($root_item_id, $target_section_id, replace_section_details: true);

        $section = $DB->get_record('course_sections', ['id' => $target_section_id], '*', MUST_EXIST);
        $this->assertSame('Copied', $section->name);
        $this->assertStringContainsString('Copied summary', $section->summary);
        $this->assertFalse($this->section_file_exists($target->id, $target_section_id, 'old.txt'));
        $this->assertFalse($this->factory->restore()->section_details_replacement()->has_snapshot($target->id, $target_section_id));
        $this->assertSame(['Page copied'], $this->module_names($target_section_id));
    }

    public function test_replace_section_details_is_rolled_back_when_the_restore_fails(): void
    {
        global $DB;

        $source = $this->create_course(1, [1 => 'Copied']);
        $this->add_page($source, 1, 'Page copied');
        $root_item_id = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Target']);
        $target_section_id = (int)$target->sections[1]->id;
        $DB->set_field('course_sections', 'summary', '<p>Target summary</p>', ['id' => $target_section_id]);
        $this->add_section_file($target->id, $target_section_id, 'keep.txt');

        // Fail after the details were blanked and before the plan runs.
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            before_sections_restored::class,
            static function (before_sections_restored $hook): void {
                throw new \Exception('Simulated restore failure');
            }
        );

        $this->restore($root_item_id, $target_section_id, replace_section_details: true);

        $section = $DB->get_record('course_sections', ['id' => $target_section_id], '*', MUST_EXIST);
        $this->assertSame('Target', $section->name);
        $this->assertSame('<p>Target summary</p>', $section->summary);
        $this->assertTrue($this->section_file_exists($target->id, $target_section_id, 'keep.txt'));
        $this->assertFalse($this->factory->restore()->section_details_replacement()->has_snapshot($target->id, $target_section_id));
        $this->assertSame([], $this->module_names($target_section_id));
    }

    public function test_insert_as_new_top_level_section(): void
    {
        $source = $this->create_course(2, [1 => 'Copied', 2 => 'Child']);
        $this->add_page($source, 1, 'Page copied');
        $this->add_page($source, 2, 'Page child');
        $this->declare_section_tree([[$source->sections[2]->id, $source->sections[1]->id, 1]]);
        $root_item_id = $this->copy_section_to_cart((int)$source->sections[1]->id);

        $target = $this->create_course(1, [1 => 'Existing']);

        $payload = null;
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            after_sections_restored::class,
            static function (after_sections_restored $hook) use (&$payload): void {
                $payload = $hook;
            }
        );

        $this->restore($root_item_id, 0, insert_as_new_section: true, course_id: (int)$target->id);

        $sections = $this->sections_by_name($target->id);
        $this->assertEqualsCanonicalizing(['', 'Existing', 'Copied', 'Child'], array_keys($sections));
        $this->assertSame(2, (int)$sections['Copied']->section);
        $this->assertSame(3, (int)$sections['Child']->section);
        $this->assertSame(['Page copied'], $this->module_names((int)$sections['Copied']->id));
        $this->assertSame(['Page child'], $this->module_names((int)$sections['Child']->id));
        $this->assertSame([], $this->module_names((int)$sections['Existing']->id));

        $this->assertSame(0, $payload->target_section_id);
        [$copied, $child] = $payload->restored_sections;
        $this->assertSame(0, $copied->new_parent_section_id);
        $this->assertSame((int)$sections['Copied']->id, $child->new_parent_section_id);
    }

    private function add_section_file(int $course_id, int $section_id, string $filename): void
    {
        get_file_storage()->create_file_from_string([
            'contextid' => \core\context\course::instance($course_id)->id,
            'component' => 'course',
            'filearea' => 'section',
            'itemid' => $section_id,
            'filepath' => '/',
            'filename' => $filename,
        ], 'content');
    }

    private function section_file_exists(int $course_id, int $section_id, string $filename): bool
    {
        return get_file_storage()->file_exists(
            \core\context\course::instance($course_id)->id,
            'course',
            'section',
            $section_id,
            '/',
            $filename
        );
    }
}
