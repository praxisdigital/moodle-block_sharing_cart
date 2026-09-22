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
 * repository.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\app;

use block_sharing_cart\app\factory as basefactory;

/**
 * repository class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class repository
{
    /** @var basefactory $basefactory */
    protected basefactory $basefactory;
    /** @var \moodle_database $db */
    protected \moodle_database $db;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     */
    public function __construct(basefactory $basefactory) {
        $this->basefactory = $basefactory;
        $this->db = $this->basefactory->moodle()->db();
    }

    /**
     * Get database table name.
     *
     * @return string
     */
    abstract public function get_table(): string;

    /**
     * get_all
     *
     * @return collection
     */
    public function get_all(): collection {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table())
        );
    }

    /**
     * get_by_id
     *
     * @param int $id
     * @return false|entity
     */
    public function get_by_id(int $id): false|entity {
        $record = $this->db->get_record($this->get_table(), ['id' => $id]);
        if (!$record) {
            return false;
        }
        return $this->map_record_to_entity($record);
    }

    /**
     * insert
     *
     * @param entity $entity
     * @return int
     */
    public function insert(entity $entity): int {
        return $this->db->insert_record($this->get_table(), (object)$entity->to_array());
    }

    /**
     * update
     *
     * @param entity $entity
     * @return void
     */
    public function update(entity $entity): void {
        $this->db->update_record($this->get_table(), (object)$entity->to_array());
    }

    /**
     * delete_by_id
     *
     * @param int $id
     * @return bool
     */
    public function delete_by_id(int $id): bool {
        return $this->db->delete_records($this->get_table(), ['id' => $id]);
    }

    /**
     * Map DB record to entity.
     *
     * @param object $record
     * @return entity
     */
    abstract public function map_record_to_entity(object $record): entity;

    /**
     * map_records_to_collection_of_entities
     *
     * @param array|collection $records
     * @return collection
     */
    public function map_records_to_collection_of_entities(array|collection $records): collection {
        return $this->basefactory->collection(
            array_map(fn($record) => $this->map_record_to_entity($record), $records)
        );
    }
}
