<?php

namespace block_sharing_cart\unit\hook;

use block_sharing_cart\hook\backup\resolve_section_tree;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

class resolve_section_tree_test extends \basic_testcase
{
    public function test_tree_is_depth_first_and_ordered_by_sort_order(): void
    {
        $hook = new resolve_section_tree(course_id: 1, section_id: 10);

        // Added out of order on purpose.
        $hook->add_child(section_id: 12, parent_section_id: 10, sort_order: 2);
        $hook->add_child(section_id: 111, parent_section_id: 11, sort_order: 1);
        $hook->add_child(section_id: 11, parent_section_id: 10, sort_order: 1);
        $hook->add_child(section_id: 1111, parent_section_id: 111, sort_order: 1);

        $ids = array_map(static fn(object $node): int => $node->section_id, $hook->get_tree());

        $this->assertSame([11, 111, 1111, 12], $ids);
    }

    public function test_unreachable_nodes_and_self_references_are_dropped(): void
    {
        $hook = new resolve_section_tree(course_id: 1, section_id: 10);

        $hook->add_child(section_id: 11, parent_section_id: 10, sort_order: 1);
        $hook->add_child(section_id: 99, parent_section_id: 42, sort_order: 1); // Parent is not in the tree.
        $hook->add_child(section_id: 10, parent_section_id: 11, sort_order: 1); // The root itself.
        $hook->add_child(section_id: 13, parent_section_id: 13, sort_order: 1); // Self reference.

        $ids = array_map(static fn(object $node): int => $node->section_id, $hook->get_tree());

        $this->assertSame([11], $ids);
    }

    public function test_empty_tree_for_flat_formats(): void
    {
        $hook = new resolve_section_tree(course_id: 1, section_id: 10);

        $this->assertSame([], $hook->get_tree());
    }
}
