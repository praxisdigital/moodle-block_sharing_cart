<?php

namespace block_sharing_cart\hook\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Dispatched inside the sharing cart restore task after the restore plan executed and the new sections were placed
 * after the target section.
 *
 * A course format that nests sections should attach the restored sections to its hierarchy here.
 */
#[\core\attribute\label('Dispatched after a sharing cart restore created nested sections; lets a course format attach them to its hierarchy')]
#[\core\attribute\tags('restore')]
final class after_sections_restored
{
    /**
     * @param object[] $restored_sections depth-first list of
     *                                    {old_section_id, new_section_id, new_parent_section_id, sort_order};
     *                                    new_parent_section_id is the target section for first level children.
     */
    public function __construct(
        public readonly int $course_id,
        public readonly int $target_section_id,
        public readonly array $restored_sections,
    ) {
    }
}
