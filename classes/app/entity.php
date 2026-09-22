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


/**
 * Class app\entity for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class entity extends \stdClass implements \ArrayAccess, \JsonSerializable {
    protected array $record;

    public function __construct(array $record = []) {
        $this->record = $record;
    }

    public function get_id(): int {
        return $this->record['id'] ?? 0;
    }

    public function set_id(int $value): self {
        $this->record['id'] = $value;
        return $this;
    }

    public function jsonSerialize(): array {
        return $this->to_array();
    }

    abstract public function to_array(): array;

    public function __get($name): mixed {
        return $this->record[$name] ?? null;
    }

    public function __set($name, $value): void {
        $this->record[$name] = $value;
    }

    public function __isset($name): bool {
        return isset($this->record[$name]);
    }

    public function __unset($name): void {
        if (isset($this->record[$name])) {
            $this->record[$name] = null;
        }
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if (is_null($offset)) {
            $this->record[] = $value;
        } else {
            $this->record[$offset] = $value;
        }
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->record[$offset]);
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->record[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->record[$offset] ?? null;
    }
}
