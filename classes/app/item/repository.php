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

namespace block_sharing_cart\app\item;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\collection;

global $CFG;
require_once($CFG->dirroot . '/course/format/lib.php');

/**
 * Class app\item\repository for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class repository extends \block_sharing_cart\app\repository {
    public function get_table(): string {
        return 'block_sharing_cart_items';
    }

    public function map_record_to_entity(object $record): entity {
        return $this->basefactory->item()->entity($record);
    }

    public function get_by_id(int $id): false|entity {
        return parent::get_by_id($id);
    }

    public function get_by_user_id(int $userid): collection {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table(), ['user_id' => $userid], 'sortorder ASC, id DESC')
        );
    }

    public function get_by_file_id(int $fileid): ?entity {
        $record = $this->db->get_record($this->get_table(), ['file_id' => $fileid]);

        return $record ? $this->map_record_to_entity(
            $record
        ) : null;
    }

    public function get_by_parent_item_id(?int $parentitemid): collection {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table(), ['parent_item_id' => $parentitemid])
        );
    }

    public function delete_by_id(int $id): bool {
        $fs = get_file_storage();
        if (!$fs) {
            return false;
        }

        $childitems = $this->get_by_parent_item_id($id);
        foreach ($childitems as $childitem) {
            if (!$this->delete_by_id($childitem->get_id())) {
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

    public function insert_activity(int $coursemoduleid, int $userid, ?int $parentitemid, int $status): entity {
        $courseid = $this->db->get_field('course_modules', 'course', ['id' => $coursemoduleid], MUST_EXIST);

        /**
         * @var \cm_info $cminfo
         */
        $cminfo = \cm_info::create((object)['id' => $coursemoduleid, 'course' => $courseid], $userid);

        $time = time();
        $itemid = $this->insert(
            $entity = $this->basefactory->item()->entity(
                (object)[
                    'user_id' => $userid,
                    'file_id' => null,
                    'parent_item_id' => $parentitemid,
                    'old_instance_id' => $cminfo->id,
                    'type' => "mod_{$cminfo->modname}",
                    'name' => $cminfo->get_formatted_name(),
                    'status' => $status,
                    'version' => entity::CURRENT_BACKUP_VERSION,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]
            )
        );

        $entity->set_id($itemid);

        return $entity;
    }

    public function insert_section(object $section, int $userid, ?int $parentitemid, int $status): entity {
        $courseformat = course_get_format($section->course);

        $entitytype = isset($section->itemid) ? entity::TYPE_MOD_SUBSECTION : entity::TYPE_SECTION;

        $time = time();
        $itemid = $this->insert(
            $entity = $this->basefactory->item()->entity(
                (object)[
                    'user_id' => $userid,
                    'file_id' => null,
                    'parent_item_id' => $parentitemid,
                    'old_instance_id' => $section->id,
                    'type' => $entitytype,
                    'name' => $courseformat->get_section_name($section),
                    'status' => $status,
                    'version' => entity::CURRENT_BACKUP_VERSION,
                    'timecreated' => $time,
                    'timemodified' => $time
                ]
            )
        );

        $entity->set_id($itemid);

        return $entity;
    }

    private function insert_activities(array $activities, entity $rootitem): void {
        // Handle a single subsection (with possible nested activities).
        if ($rootitem->get_type() === "mod_subsection") {
            foreach ($activities[array_key_first($activities)]->subsection_activities as $subsectionactivity) {
                $this->insert_activity(
                    $subsectionactivity->moduleid,
                    $rootitem->get_user_id(),
                    $rootitem->get_id(),
                    entity::STATUS_BACKEDUP
                );
            }
            return;
        }

        // Handle multiple activities.
        foreach ($activities as $activity) {
                if ($activity->modulename === "subsection") {
                    $subsectionentity = $this->insert_activity(
                        $activity->moduleid,
                        $rootitem->get_user_id(),
                        $rootitem->get_id(),
                        entity::STATUS_BACKEDUP
                    );
                    foreach ($activity->subsection_activities as $subsectionactivity) {
                        $this->insert_activity(
                            $subsectionactivity->moduleid,
                            $rootitem->get_user_id(),
                            $subsectionentity->get_id(),
                            entity::STATUS_BACKEDUP
                        );
                    }

                    continue;
                }

                $this->insert_activity(
                    $activity->moduleid,
                    $rootitem->get_user_id(),
                    $rootitem->get_id(),
                    entity::STATUS_BACKEDUP
                );
            }
    }

    public function update_sharing_cart_item_with_backup_file(entity $rootitem, \stored_file $file): void {
        $this->db->delete_records($this->get_table(), ['parent_item_id' => $rootitem->get_id()]);

        $rootitem->set_status(entity::STATUS_BACKEDUP);
        $rootitem->set_file_id($file->get_id());
        $rootitem->set_timemodified(time());

        $courseinfo = $this->basefactory->backup()->handler()->get_backup_course_info($file);
        $rootitem->set_original_course_fullname($courseinfo['fullname'] ?? null);

        $this->update($rootitem);

        $section = $this->basefactory->backup()->handler()->get_backup_item_tree($file);

        if (isset($section['lone_activity'])) return;
        if (empty($section)) {
            throw new \Exception("Backup file was empty.");
        }

        $this->insert_activities($section[array_key_first($section)]->activities, $rootitem);
    }

    public function get_recursively_by_parent_id(int $itemid, ?collection $items = null): collection {
        if (!$items) {
            $items = $this->basefactory->collection();

            $rootitem = $this->get_by_id($itemid);
            if (!$rootitem) {
                return $items;
            }

            $items->add($rootitem);
        }

        $children = $this->get_by_parent_item_id($itemid);
        foreach ($children as $child) {
            $items->add($child);
        }

        foreach ($children as $child) {
            $this->get_recursively_by_parent_id($child->get_id(), $items);
        }

        return $items;
    }

    public function get_parent_item_recursively_by_item(entity $item): entity {
        if ($item->get_parent_item_id()) {
            return $this->get_parent_item_recursively_by_item(
                $this->get_by_id(
                    $item->get_parent_item_id()
                )
            );
        }

        return $item;
    }

    public function get_stored_file_by_item(entity $item): ?\stored_file {
        /**
         * @var \file_storage $fs
         */
        $fs = get_file_storage();

        if ($itemfile = array_values(
            $fs->get_area_files(
                \core\context\user::instance($item->get_user_id())->id,
                'block_sharing_cart',
                'backup',
                $item->get_id(),
                includedirs: false,
                limitnum: 1
            )
        )[0] ?? null) {
            return $itemfile;
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

    public function get_count(): int {
        return $this->db->count_records($this->get_table());
    }
}
