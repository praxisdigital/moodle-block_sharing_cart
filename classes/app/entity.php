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
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\app;

/**
 * entity class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class entity extends \stdClass implements \ArrayAccess, \JsonSerializable
{
    /** @var array $record */
    protected array $record;

    /**
     * __construct
     *
     * @param array $record
     */
    public function __construct(array $record = []) {
        $this->record = $record;
    }

    /**
     * get_id
     *
     * @return int
     */
    public function get_id(): int {
        return $this->record['id'] ?? 0;
    }

    /**
     * set_id
     *
     * @param int $value
     * @return self
     */
    public function set_id(int $value): self {
        $this->record['id'] = $value;
        return $this;
    }

    /**
     * jsonSerialize
     *
     * @return array
     */
    public function jsonSerialize(): array {
        return $this->to_array();
    }

        /**
         * Convert entity to array.
         *
         * @return array
         */
    abstract public function to_array(): array;

    /**
     * __get
     *
     * @param mixed $name
     * @return mixed
     */
    public function __get($name): mixed {
        return $this->record[$name] ?? null;
    }

    /**
     * __set
     *
     * @param mixed $name
     * @param mixed $value
     * @return void
     */
    public function __set($name, $value): void {
        $this->record[$name] = $value;
    }

    /**
     * __isset
     *
     * @param mixed $name
     * @return bool
     */
    public function __isset($name): bool {
        return isset($this->record[$name]);
    }

    /**
     * __unset
     *
     * @param mixed $name
     * @return void
     */
    public function __unset($name): void {
        if (isset($this->record[$name])) {
            $this->record[$name] = null;
        }
    }

    /**
     * offsetSet
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void {
        if (is_null($offset)) {
            $this->record[] = $value;
        } else {
            $this->record[$offset] = $value;
        }
    }

    /**
     * offsetExists
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool {
        return isset($this->record[$offset]);
    }

    /**
     * offsetUnset
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void {
        unset($this->record[$offset]);
    }

    /**
     * offsetGet
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed {
        return $this->record[$offset] ?? null;
    }
}
