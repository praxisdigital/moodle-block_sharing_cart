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

namespace block_sharing_cart\app;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;

/**
 * Class app\repository for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

abstract class repository {
    protected base_factory $basefactory;
    protected \moodle_database $db;

    public function __construct(base_factory $basefactory) {
        $this->basefactory = $basefactory;
        $this->db = $this->basefactory->moodle()->db();
    }

    abstract public function get_table(): string;

    public function get_all(): collection {
        return $this->map_records_to_collection_of_entities(
            $this->db->get_records($this->get_table())
        );
    }

    public function get_by_id(int $id): false|entity {
        $record = $this->db->get_record($this->get_table(), ['id' => $id]);
        if (!$record) {
            return false;
        }
        return $this->map_record_to_entity($record);
    }

    public function insert(entity $entity): int {
        return $this->db->insert_record($this->get_table(), (object)$entity->to_array());
    }

    public function update(entity $entity): void {
        $this->db->update_record($this->get_table(), (object)$entity->to_array());
    }

    public function delete_by_id(int $id): bool {
        return $this->db->delete_records($this->get_table(), ['id' => $id]);
    }

    abstract public function map_record_to_entity(object $record): entity;

    public function map_records_to_collection_of_entities(array|collection $records): collection {
        return $this->basefactory->collection(
            array_map(fn($record) => $this->map_record_to_entity($record), $records)
        );
    }
}
