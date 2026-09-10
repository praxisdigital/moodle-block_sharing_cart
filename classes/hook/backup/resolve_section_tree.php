<?php

namespace block_sharing_cart\hook\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Lets a course format declare the descendant sections of a section that is being copied into the sharing cart.
 *
 * Callbacks call add_child() for every descendant (any depth). The cart includes those sections in the backup and
 * records the tree so it can be recreated on restore.
 */
#[\core\attribute\label('Lets a course format add the nested child sections of a section being copied to the sharing cart')]
#[\core\attribute\tags('backup')]
final class resolve_section_tree
{
    /** @var object[] keyed by section id: {section_id, parent_section_id, sort_order} */
    private array $children = [];

    public function __construct(
        public readonly int $course_id,
        public readonly int $section_id,
    ) {
    }

    public function add_child(int $section_id, int $parent_section_id, int $sort_order): void
    {
        if ($section_id === $this->section_id || $section_id === $parent_section_id) {
            return;
        }

        $this->children[$section_id] = (object)[
            'section_id' => $section_id,
            'parent_section_id' => $parent_section_id,
            'sort_order' => $sort_order,
        ];
    }

    /**
     * Descendants in depth-first order (parents before children, siblings by sort order). The copied section itself
     * is not part of the list, and nodes that cannot be reached from it are dropped.
     *
     * @return object[]
     */
    public function get_tree(): array
    {
        $by_parent = [];
        foreach ($this->children as $child) {
            $by_parent[$child->parent_section_id][] = $child;
        }
        foreach ($by_parent as &$siblings) {
            usort($siblings, static function (object $a, object $b): int {
                return ($a->sort_order <=> $b->sort_order) ?: ($a->section_id <=> $b->section_id);
            });
        }
        unset($siblings);

        $tree = [];
        $visited = [$this->section_id => true];
        $walk = function (int $parent_id) use (&$walk, &$tree, &$visited, $by_parent): void {
            foreach ($by_parent[$parent_id] ?? [] as $child) {
                if (isset($visited[$child->section_id])) {
                    continue;
                }
                $visited[$child->section_id] = true;
                $tree[] = $child;
                $walk($child->section_id);
            }
        };
        $walk($this->section_id);

        return $tree;
    }
}
