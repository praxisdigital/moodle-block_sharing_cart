<?php

namespace block_sharing_cart\app\item;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\collection;

global $CFG;
require_once($CFG->dirroot . '/course/format/lib.php');

class repository extends \block_sharing_cart\app\repository
{
    public function get_table(): string
    {
        return 'block_sharing_cart_items';
    }

    public function map_record_to_entity(object $record): entity
    {
        return $this->base_factory->item()->entity($record);
    }

    public function get_by_id(int $id): false|entity
    {
        return parent::get_by_id($id);
    }

    public function get_by_user_id(int $user_id): collection
    {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table(), ['user_id' => $user_id], 'sortorder ASC, id DESC')
        );
    }

    public function get_by_file_id(int $file_id): ?entity
    {
        $record = $this->db->get_record($this->get_table(), ['file_id' => $file_id]);

        return $record ? $this->map_record_to_entity(
            $record
        ) : null;
    }

    public function get_by_parent_item_id(?int $parent_item_id): collection
    {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table(), ['parent_item_id' => $parent_item_id], 'sortorder ASC, id ASC')
        );
    }

    public function delete_by_id(int $id): bool
    {
        $fs = get_file_storage();
        if (!$fs) {
            return false;
        }

        $child_items = $this->get_by_parent_item_id($id);
        foreach ($child_items as $child_item) {
            if (!$this->delete_by_id($child_item->get_id())) {
                return false;
            }
        }

        $item = $this->get_by_id($id);
        if (!$item) {
            return true;
        }

        if ($item->get_file_id() && $file = $fs->get_file_by_id($item->get_file_id())) {
            $file->delete();
        }

        return parent::delete_by_id($id);
    }

    public function insert_activity(int $course_module_id, int $user_id, ?int $parent_item_id, int $status): entity
    {
        $course_id = $this->db->get_field('course_modules', 'course', ['id' => $course_module_id], MUST_EXIST);

        /**
         * @var \cm_info $cm_info
         */
        $cm_info = \cm_info::create((object)['id' => $course_module_id, 'course' => $course_id], $user_id);

        $time = time();
        $item_id = $this->insert(
            $entity = $this->base_factory->item()->entity(
                (object)[
                    'user_id' => $user_id,
                    'file_id' => null,
                    'parent_item_id' => $parent_item_id,
                    'old_instance_id' => $cm_info->id,
                    'type' => "mod_{$cm_info->modname}",
                    'name' => $cm_info->get_formatted_name(),
                    'status' => $status,
                    'version' => entity::CURRENT_BACKUP_VERSION,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]
            )
        );

        $entity->set_id($item_id);

        return $entity;
    }

    public function insert_section(object $section, int $user_id, ?int $parent_item_id, int $status): entity
    {

        $course_format = course_get_format($section->course);

        $entity_type = isset($section->itemid) ? entity::TYPE_MOD_SUBSECTION : entity::TYPE_SECTION;

        $time = time();
        $item_id = $this->insert(
            $entity = $this->base_factory->item()->entity(
                (object)[
                    'user_id' => $user_id,
                    'file_id' => null,
                    'parent_item_id' => $parent_item_id,
                    'old_instance_id' => $section->id,
                    'type' => $entity_type,
                    'name' => $course_format->get_section_name($section),
                    'status' => $status,
                    'version' => entity::CURRENT_BACKUP_VERSION,
                    'timecreated' => $time,
                    'timemodified' => $time
                ]
            )
        );

        $entity->set_id($item_id);

        return $entity;
    }

    /**
     * Insert a nested section item (a child section of a copied section, as declared by a nesting course format).
     * The item has no file of its own; the backup file lives on the root item.
     */
    public function insert_child_section(
        int $old_section_id,
        string $name,
        int $user_id,
        int $parent_item_id,
        int $sortorder
    ): entity {
        $time = time();
        $item_id = $this->insert(
            $entity = $this->base_factory->item()->entity(
                (object)[
                    'user_id' => $user_id,
                    'file_id' => null,
                    'parent_item_id' => $parent_item_id,
                    'old_instance_id' => $old_section_id,
                    'type' => entity::TYPE_SECTION,
                    'name' => $name,
                    'status' => entity::STATUS_BACKEDUP,
                    'sortorder' => $sortorder,
                    'version' => entity::CURRENT_BACKUP_VERSION,
                    'timecreated' => $time,
                    'timemodified' => $time
                ]
            )
        );

        $entity->set_id($item_id);

        return $entity;
    }

    private function insert_activities(array $activities, entity $parent_item): void
    {

        //Handle a single subsection (with possible nested activities)
        if($parent_item->get_type() === "mod_subsection"){
            if (empty($activities)) {
                return;
            }
            foreach($activities[array_key_first($activities)]->subsection_activities as $subsection_activity) {
                $this->insert_activity(
                    $subsection_activity->moduleid,
                    $parent_item->get_user_id(),
                    $parent_item->get_id(),
                    entity::STATUS_BACKEDUP
                );
            }
            return;
        }

        //Handle multiple activities
        foreach ($activities as $activity) {

                if($activity->modulename === "subsection") {
                    $subsection_entity = $this->insert_activity(
                        $activity->moduleid,
                        $parent_item->get_user_id(),
                        $parent_item->get_id(),
                        entity::STATUS_BACKEDUP
                    );
                    foreach($activity->subsection_activities as $subsection_activity) {
                        $this->insert_activity(
                            $subsection_activity->moduleid,
                            $parent_item->get_user_id(),
                            $subsection_entity->get_id(),
                            entity::STATUS_BACKEDUP
                        );
                    }

                    continue;
                }

                $this->insert_activity(
                    $activity->moduleid,
                    $parent_item->get_user_id(),
                    $parent_item->get_id(),
                    entity::STATUS_BACKEDUP
                );

            }


    }

    /**
     * Build the nested items for a copied section: its own activities under the root item, then one 'section' item
     * per descendant section (depth-first, as stored in $section_tree) with that section's activities beneath it.
     *
     * @param array $sections output of backup\handler::get_backup_item_tree()
     * @param entity $root_item
     * @param array $section_tree depth-first list of {section_id, parent_section_id, sort_order}
     */
    private function insert_section_tree(array $sections, entity $root_item, array $section_tree): void
    {
        $root_old_section_id = (int)$root_item->get_old_instance_id();
        $root_section = $sections[$root_old_section_id] ?? $sections[array_key_first($sections)];

        $this->insert_activities($root_section->activities, $root_item);

        $items_by_old_section_id = [$root_old_section_id => $root_item];

        foreach ($section_tree as $node) {
            $node = (object)$node;
            $old_section_id = (int)$node->section_id;
            $section = $sections[$old_section_id] ?? null;
            $parent_item = $items_by_old_section_id[(int)$node->parent_section_id] ?? null;

            // Not part of the backup (excluded) or its parent was not, so the branch is dropped.
            if ($section === null || $parent_item === null) {
                continue;
            }

            $section_item = $this->insert_child_section(
                $old_section_id,
                (string)(!empty($node->name) ? $node->name : $section->title),
                $root_item->get_user_id(),
                $parent_item->get_id(),
                (int)$node->sort_order
            );
            $items_by_old_section_id[$old_section_id] = $section_item;

            $this->insert_activities($section->activities, $section_item);
        }
    }

    public function update_sharing_cart_item_with_backup_file(
        entity $root_item,
        \stored_file $file,
        array $section_tree = []
    ): void {
        foreach ($this->get_by_parent_item_id($root_item->get_id()) as $child_item) {
            $this->delete_by_id($child_item->get_id());
        }

        $root_item->set_status(entity::STATUS_BACKEDUP);
        $root_item->set_file_id($file->get_id());
        $root_item->set_timemodified(time());

        $course_info = $this->base_factory->backup()->handler()->get_backup_course_info($file);
        $root_item->set_original_course_fullname($course_info['fullname'] ?? null);

        $this->update($root_item);

        $sections = $this->base_factory->backup()->handler()->get_backup_item_tree($file, $section_tree);

        if(isset($sections['lone_activity'])) return;
        if(empty($sections)){
            throw new \Exception("Backup file was empty.");
        }

        if ($root_item->is_subsection()) {
            $this->insert_activities($sections[array_key_first($sections)]->activities, $root_item);
            return;
        }

        $this->insert_section_tree($sections, $root_item, $section_tree);
    }

    public function get_recursively_by_parent_id(int $item_id, ?collection $items = null): collection
    {
        if (!$items) {
            $items = $this->base_factory->collection();

            $root_item = $this->get_by_id($item_id);
            if (!$root_item) {
                return $items;
            }

            $items->add($root_item);
        }

        $children = $this->get_by_parent_item_id($item_id);
        foreach ($children as $child) {
            $items->add($child);
        }

        foreach ($children as $child) {
            $this->get_recursively_by_parent_id($child->get_id(), $items);
        }

        return $items;
    }

    public function get_parent_item_recursively_by_item(entity $item): entity
    {
        if ($item->get_parent_item_id()) {
            return $this->get_parent_item_recursively_by_item(
                $this->get_by_id(
                    $item->get_parent_item_id()
                )
            );
        }

        return $item;
    }

    public function get_stored_file_by_item(entity $item): ?\stored_file
    {
        /**
         * @var \file_storage $fs
         */
        $fs = get_file_storage();

        if ($item_file = array_values(
            $fs->get_area_files(
                \core\context\user::instance($item->get_user_id())->id,
                'block_sharing_cart',
                'backup',
                $item->get_id(),
                includedirs: false,
                limitnum: 1
            )
        )[0] ?? null) {
            return $item_file;
        }

        return array_values(
            $fs->get_area_files(
                \core\context\user::instance($item->get_user_id())->id,
                'block_sharing_cart',
                'backup',
                $this->get_parent_item_recursively_by_item($item)->get_id(),
                includedirs: false,
                limitnum: 1
            )
        )[0] ?? null;
    }

    public function get_count(): int
    {
        return $this->db->count_records($this->get_table());
    }
}
