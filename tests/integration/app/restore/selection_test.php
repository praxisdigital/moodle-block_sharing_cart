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

namespace block_sharing_cart\integration\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\app\restore\selection;

/**
 * Restore selection validation must not expand an empty or out-of-scope request to the shared archive.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selection::class)]
final class selection_test extends \advanced_testcase {
    /** @var entity Chosen section item. */
    private entity $root;

    /**
     * Build a cart-only fixture to prove validation does not depend on the source course still existing.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $repository = factory::make()->item()->repository();
        $this->root = $repository->insert_child_section(100, 'Root', 2, 0, 0);
        $this->root->set_parent_item_id(null);
        $repository->update($this->root);
        $child = $repository->insert_child_section(200, 'Child', 2, $this->root->get_id(), 1);
        $repository->insert_child_section(300, 'Other tree', 2, 0, 0);
        foreach ([[$this->root, 10], [$child, 20]] as [$parent, $oldid]) {
            $item = factory::make()->item()->entity((object)[
                'user_id' => 2,
                'parent_item_id' => $parent->get_id(),
                'old_instance_id' => $oldid,
                'type' => 'mod_page',
                'name' => "Page $oldid",
                'status' => entity::STATUS_BACKEDUP,
                'version' => entity::CURRENT_BACKUP_VERSION,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $repository->insert($item);
        }
    }

    /**
     * Explicit and legacy selections retain their meaning without escaping the item tree.
     *
     * @param array $settings selection settings
     * @param int[] $expected resolved module IDs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_provider')]
    public function test_valid_selection(array $settings, array $expected): void {
        $this->assertSame($expected, factory::make()->restore()->selection()->module_ids($this->root, $settings));
    }

    /**
     * Valid selection settings and their resolved modules.
     *
     * @return array<string, array{array, int[]}>
     */
    public static function valid_provider(): array {
        return [
            'default' => [[], [10, 20]],
            'legacy zero' => [['course_modules_to_include' => [0]], [10, 20]],
            'selected descendant' => [['course_modules_to_include' => [20]], [20]],
            'duplicate IDs' => [['course_modules_to_include' => [20, 20]], [20]],
            'section only' => [[
                'course_modules_to_include' => [], 'module_selection_explicit' => true, 'sections_to_include' => [200],
            ], []],
            'section only with explicit zero' => [[
                'course_modules_to_include' => [0], 'module_selection_explicit' => true, 'sections_to_include' => [200],
            ], []],
        ];
    }

    /**
     * Invalid IDs must fail rather than being dropped into an include-all fallback.
     *
     * @param array $settings invalid selection
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid_selection(array $settings): void {
        $this->expectException(\invalid_parameter_exception::class);
        factory::make()->restore()->selection()->module_ids($this->root, $settings);
    }

    /**
     * Invalid selection settings.
     *
     * @return array<string, array{array}>
     */
    public static function invalid_provider(): array {
        return [
            'foreign module' => [['course_modules_to_include' => [999]]],
            'foreign section' => [['sections_to_include' => [300]]],
            'section ID used as CM ID' => [['course_modules_to_include' => [200]]],
            'CM ID used as section ID' => [['sections_to_include' => [10]]],
            'mixed zero modules' => [['course_modules_to_include' => [0, 10]]],
            'mixed zero sections' => [['sections_to_include' => [0, 200]]],
            'negative module' => [['course_modules_to_include' => [-10]]],
        ];
    }

    /**
     * Explicitly selecting no content is rejected, including the old zero sentinel.
     *
     * @param int[] $moduleids empty module selection
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('empty_provider')]
    public function test_empty_selection(array $moduleids): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('atleast_one_course_module_must_be_included', 'block_sharing_cart'));
        factory::make()->restore()->selection()->module_ids($this->root, [
            'course_modules_to_include' => $moduleids,
            'sections_to_include' => [0],
            'module_selection_explicit' => true,
        ]);
    }

    /**
     * Explicit empty module selections.
     *
     * @return array<string, array{int[]}>
     */
    public static function empty_provider(): array {
        return ['empty' => [[]], 'zero' => [[0]]];
    }

    /**
     * Validation query counts must depend on batches/depth, not one query per activity.
     *
     * @param int $count number of activities in one section
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('large_selection_provider')]
    public function test_large_flat_selection_has_a_bounded_query_count(int $count): void {
        global $DB, $USER;

        $repository = factory::make()->item()->repository();
        $root = $repository->insert_child_section(500, 'Large root', (int)$USER->id, 0, 0);
        for ($i = 1; $i <= $count; $i++) {
            $repository->insert(factory::make()->item()->entity((object)[
                'user_id' => $USER->id, 'parent_item_id' => $root->get_id(),
                'old_instance_id' => 1000 + $i, 'type' => 'mod_page', 'name' => "Page $i",
                'status' => entity::STATUS_BACKEDUP, 'version' => entity::CURRENT_BACKUP_VERSION,
                'timecreated' => time(), 'timemodified' => time(),
            ]));
        }
        $before = $DB->perf_get_queries();
        $ids = factory::make()->restore()->selection()->module_ids($root, []);
        $queries = $DB->perf_get_queries() - $before;

        $this->assertCount($count, $ids);
        $this->assertLessThanOrEqual(5, $queries, "Validation of $count flat activities issued $queries queries.");
    }

    /**
     * Flat selection sizes, including more than one query batch.
     *
     * @return array<string, array{int}>
     */
    public static function large_selection_provider(): array {
        return ['100 activities' => [100], '1000 activities' => [1000]];
    }
}
