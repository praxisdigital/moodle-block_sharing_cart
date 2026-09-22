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

/**
 * entity.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\app\item;

/**
 * entity class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entity extends \block_sharing_cart\app\entity
{
    /** STATUS_AWAITING_BACKUP constant. */
    public const STATUS_AWAITING_BACKUP = 0;
    /** STATUS_BACKEDUP constant. */
    public const STATUS_BACKEDUP = 1;
    /** STATUS_BACKUP_FAILED constant. */
    public const STATUS_BACKUP_FAILED = 2;

    /** TYPE_SECTION constant. */
    public const TYPE_SECTION = 'section';
    /** TYPE_MOD_SUBSECTION constant. */
    public const TYPE_MOD_SUBSECTION = 'mod_subsection';

    /** CURRENT_BACKUP_VERSION constant. */
    public const CURRENT_BACKUP_VERSION = 3;

    /**
     * get_user_id
     *
     * @return int
     */
    public function get_user_id(): int {
        return $this->record['user_id'] ?? 0;
    }

    /**
     * set_user_id
     *
     * @param int $value
     * @return self
     */
    public function set_user_id(int $value): self {
        $this->record['user_id'] = $value;
        return $this;
    }

    /**
     * get_file_id
     *
     * @return ?int
     */
    public function get_file_id(): ?int {
        return $this->record['file_id'] ?? null;
    }

    /**
     * set_file_id
     *
     * @param ?int $value
     * @return self
     */
    public function set_file_id(?int $value): self {
        $this->record['file_id'] = $value;
        return $this;
    }

    /**
     * get_parent_item_id
     *
     * @return ?int
     */
    public function get_parent_item_id(): ?int {
        return $this->record['parent_item_id'] ?? null;
    }

    /**
     * set_parent_item_id
     *
     * @param ?int $value
     * @return self
     */
    public function set_parent_item_id(?int $value): self {
        $this->record['parent_item_id'] = $value;
        return $this;
    }

    /**
     * get_old_instance_id
     *
     * @return ?int
     */
    public function get_old_instance_id(): ?int {
        return $this->record['old_instance_id'] ?? null;
    }

    /**
     * set_old_instance_id
     *
     * @param ?int $value
     * @return self
     */
    public function set_old_instance_id(?int $value): self {
        $this->record['old_instance_id'] = $value;
        return $this;
    }

    /**
     * get_type
     *
     * @return string
     */
    public function get_type(): string {
        return $this->record['type'] ?? self::TYPE_SECTION;
    }

    /**
     * set_type
     *
     * @param string $value
     * @return self
     */
    public function set_type(string $value): self {
        $this->record['type'] = $value;
        return $this;
    }

    /**
     * get_name
     *
     * @return string
     */
    public function get_name(): string {
        return $this->record['name'] ?? '';
    }

    /**
     * set_name
     *
     * @param string $value
     * @return self
     */
    public function set_name(string $value): self {
        $this->record['name'] = $value;
        return $this;
    }

    /**
     * get_status
     *
     * @return int
     */
    public function get_status(): int {
        return $this->record['status'] ?? self::STATUS_AWAITING_BACKUP;
    }

    /**
     * set_status
     *
     * @param int $value
     * @return self
     */
    public function set_status(int $value): self {
        $this->record['status'] = $value;
        return $this;
    }

    /**
     * get_sortorder
     *
     * @return ?int
     */
    public function get_sortorder(): ?int {
        return $this->record['sortorder'] ?? null;
    }

    /**
     * set_sortorder
     *
     * @param ?int $value
     * @return self
     */
    public function set_sortorder(?int $value): self {
        $this->record['sortorder'] = $value;
        return $this;
    }

    /**
     * get_version
     *
     * @return int
     */
    public function get_version(): int {
        return $this->record['version'] ?? self::CURRENT_BACKUP_VERSION;
    }

    /**
     * get_timecreated
     *
     * @return int
     */
    public function get_timecreated(): int {
        return $this->record['timecreated'] ?? 0;
    }

    /**
     * set_timecreated
     *
     * @param int $value
     * @return self
     */
    public function set_timecreated(int $value): self {
        $this->record['timecreated'] = $value;
        return $this;
    }

    /**
     * get_timemodified
     *
     * @return int
     */
    public function get_timemodified(): int {
        return $this->record['timemodified'] ?? 0;
    }

    /**
     * set_timemodified
     *
     * @param int $value
     * @return self
     */
    public function set_timemodified(int $value): self {
        $this->record['timemodified'] = $value;
        return $this;
    }

    /**
     * is_section
     *
     * @return bool
     */
    public function is_section(): bool {
        return $this->get_type() === self::TYPE_SECTION;
    }

    /**
     * is_subsection
     *
     * @return bool
     */
    public function is_subsection(): bool {
        return $this->get_type() === self::TYPE_MOD_SUBSECTION;
    }

    /**
     * is_module
     *
     * @return bool
     */
    public function is_module(): bool {
        return !$this->is_section();
    }

    /**
     * get_original_course_fullname
     *
     * @return ?string
     */
    public function get_original_course_fullname(): ?string {
        return $this->record['original_course_fullname'] ?? null;
    }

    /**
     * set_original_course_fullname
     *
     * @param ?string $value
     * @return self
     */
    public function set_original_course_fullname(?string $value): self {
        $this->record['original_course_fullname'] = $value;
        return $this;
    }

    /**
     * to_array
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'id' => $this->get_id(),
            'user_id' => $this->get_user_id(),
            'file_id' => $this->get_file_id(),
            'parent_item_id' => $this->get_parent_item_id(),
            'old_instance_id' => $this->get_old_instance_id(),
            'type' => $this->get_type(),
            'name' => format_string($this->get_name()),
            'status' => $this->get_status(),
            'sortorder' => $this->get_sortorder(),
            'original_course_fullname' => $this->get_original_course_fullname(),
            'version' => $this->get_version(),
            'timecreated' => $this->get_timecreated(),
            'timemodified' => $this->get_timemodified(),
        ];
    }
}
