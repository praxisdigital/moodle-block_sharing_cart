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

namespace block_sharing_cart\integration\app\item;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\app\item\repository;

/**
 * Batched cart subtree reads preserve ordering, scope and the existing collection contract.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(repository::class)]
final class repository_subtree_test extends \advanced_testcase {
    /**
     * Prepare isolated cart records.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Insert a cart section without needing a live source course.
     *
     * @param string $name item name
     * @param entity|null $parent parent item
     * @param int $sortorder sibling order
     * @return entity
     */
    private function item(string $name, ?entity $parent = null, int $sortorder = 0): entity {
        global $USER;

        $repository = factory::make()->item()->repository();
        $item = $repository->insert_child_section(100, $name, (int)$USER->id, $parent?->get_id() ?? 0, $sortorder);
        if ($parent === null) {
            $item->set_parent_item_id(null);
            $repository->update($item);
        }
        return $item;
    }

    /**
     * Batching must not reorder siblings or pull in another root's descendants.
     */
    public function test_subtree_keeps_its_original_order_and_scope(): void {
        $root = $this->item('Root');
        $b = $this->item('B', $root, 2);
        $a = $this->item('A', $root, 1);
        $this->item('B1', $b);
        $a2 = $this->item('A2', $a, 2);
        $a1 = $this->item('A1', $a, 1);
        $this->item('A1 leaf', $a1);
        $this->item('A2 leaf', $a2);
        $other = $this->item('Other root');
        $this->item('Other child', $other);

        $names = [];
        foreach (factory::make()->item()->repository()->get_recursively_by_parent_id($root->get_id()) as $item) {
            $names[] = $item->get_name();
        }
        $this->assertSame(['Root', 'A', 'B', 'A1', 'A2', 'A1 leaf', 'A2 leaf', 'B1'], $names);
    }

    /**
     * Supplying a collection still appends descendants only, without reinserting the root.
     */
    public function test_existing_collection_is_extended_in_place(): void {
        $root = $this->item('Root');
        $child = $this->item('Child', $root);
        $items = factory::make()->collection([$root]);

        $result = factory::make()->item()->repository()->get_recursively_by_parent_id($root->get_id(), $items);

        $this->assertSame($items, $result);
        $this->assertSame([$root->get_id(), $child->get_id()], $result->pluck(fn(entity $item) => $item->get_id())->to_array(true));
    }

    /**
     * Missing and empty roots must remain safe to render.
     */
    public function test_missing_and_empty_roots(): void {
        $repository = factory::make()->item()->repository();
        $this->assertCount(0, $repository->get_recursively_by_parent_id(-1));
        $root = $this->item('Empty');
        $this->assertCount(1, $repository->get_recursively_by_parent_id($root->get_id()));
    }

    /**
     * A corrupt parent cycle cannot cause an endless database traversal.
     */
    public function test_cycle_does_not_repeat_items(): void {
        $repository = factory::make()->item()->repository();
        $root = $this->item('Root');
        $a = $this->item('A', $root);
        $b = $this->item('B', $a);
        $root->set_parent_item_id($b->get_id());
        $repository->update($root);

        $this->assertCount(3, $repository->get_recursively_by_parent_id($root->get_id()));
    }
}
