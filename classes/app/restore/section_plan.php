<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/**
 * Which sections of a cart backup are restored, and the section number each of them gets.
 *
 * The root section (the restored item's own) either merges into the target section or, with insert_as_new_section,
 * is created as a new section under it (target 0 = top level of the course). Descendant sections always become new
 * sections. Everything else in the backup is excluded.
 */
final class section_plan
{
    /** Original id of the restored item's own section; 0 for legacy (non section) items. */
    public int $root_old_section_id = 0;

    /** Section chosen by the user; 0 = top level of the course. */
    public int $target_section_id;

    public int $target_section_number;

    public bool $insert_as_new_section = false;

    /** True when the restored item is a section item and other sections in the backup must be excluded. */
    public bool $selective = false;

    /**
     * Sections to create, keyed by old section id, in depth-first order:
     * {old_section_id, old_parent_section_id, sort_order, new_section_number}.
     *
     * @var object[]
     */
    public array $sections = [];

    public function __construct(int $target_section_id, int $target_section_number)
    {
        $this->target_section_id = $target_section_id;
        $this->target_section_number = $target_section_number;
    }

    public function add_section(
        int $old_section_id,
        int $old_parent_section_id,
        int $sort_order,
        int $new_section_number
    ): void {
        $this->sections[$old_section_id] = (object)[
            'old_section_id' => $old_section_id,
            'old_parent_section_id' => $old_parent_section_id,
            'sort_order' => $sort_order,
            'new_section_number' => $new_section_number,
        ];
    }

    public function has_sections(): bool
    {
        return !empty($this->sections);
    }

    /**
     * Old ids of every section that is restored: the root plus the planned ones.
     *
     * @return int[]
     */
    public function included_section_ids(): array
    {
        return array_merge([$this->root_old_section_id], array_keys($this->sections));
    }

    /** The root merges into an existing section, so its title and description can be replaced. */
    public function merges_into_target(): bool
    {
        return $this->selective && !$this->insert_as_new_section && $this->target_section_id > 0;
    }
}
