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

use block_sharing_cart\app\factory as base_factory;

/**
 * Class app\item\factory for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class factory {
    private base_factory $basefactory;

    public function __construct(base_factory $basefactory) {
        $this->basefactory = $basefactory;
    }

    public function repository(): repository {
        return new repository($this->basefactory);
    }

    public function entity(object $record): entity {
        return new entity((array)$record);
    }
}
