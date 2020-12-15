<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *  Sharing Cart
 *
 * @package    block_sharing_cart
 * @copyright  2017 (C) VERSION2, INC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_sharing_cart\files;

defined('MOODLE_INTERNAL') || die();


class file
{
    /** @var object|\stored_file */
    private $file;

    public function __construct($file){
        $this->file = $file;
    }

    /**
     * @return int
     */
    public function get_id() {
        return (int)$this->file->id;
    }

    /**
     * @return int
     */
    public function get_user_id(): int {
        return (int)$this->file->userid;
    }

    /**
     * @return string
     */
    public function get_area() {
        return $this->file->filearea ?? '';
    }

    /**
     * @return string
     */
    public function get_name() {
        return $this->file->filename ?? '';
    }

    /**
     * @return bool
     */
    public function is_backup_file() {
        return $this->get_area() === 'backup';
    }
}
