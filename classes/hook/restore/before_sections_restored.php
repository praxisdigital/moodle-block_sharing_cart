<?php

namespace block_sharing_cart\hook\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Dispatched inside the sharing cart restore task right before the restore plan executes.
 *
 * A course format can use it to mark the restore as "section structure managed by the sharing cart", so that its own
 * format restore plugin does not merge or reposition the sections that the cart is about to create.
 */
#[\core\attribute\label('Dispatched before a sharing cart restore executes; lets a course format take over section hierarchy handling')]
#[\core\attribute\tags('restore')]
final class before_sections_restored
{
    /**
     * @param object[] $planned_sections depth-first list of
     *                                   {old_section_id, old_parent_section_id, sort_order, new_section_number}
     */
    public function __construct(
        public readonly string $restore_id,
        public readonly int $course_id,
        public readonly int $target_section_id,
        public readonly array $planned_sections,
    ) {
    }
}
