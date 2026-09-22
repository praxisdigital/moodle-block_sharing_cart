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

namespace block_sharing_cart\app\moodle;

use block_sharing_cart\app\factory as basefactory;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Moodle factory for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory
{
    /** @var basefactory $basefactory */
    private basefactory $basefactory;

    /**
     * __construct
     *
     * @param basefactory $basefactory
     */
    public function __construct(basefactory $basefactory) {
        $this->basefactory = $basefactory;
    }

    /**
     * page
     *
     * @return \moodle_page
     */
    public function page(): \moodle_page {
        global $PAGE;
        return $PAGE;
    }

    /**
     * db
     *
     * @return \moodle_database
     */
    public function db(): \moodle_database {
        global $DB;
        return $DB;
    }

    /**
     * output
     *
     * @return mixed
     */
    public function output(): mixed {
        global $OUTPUT;
        return $OUTPUT;
    }

    /**
     * cfg
     *
     * @return object
     */
    public function cfg(): object {
        global $CFG;
        return $CFG;
    }

    /**
     * script
     *
     * @return string
     */
    public function script(): string {
        global $SCRIPT;
        return $SCRIPT;
    }

    /**
     * session
     *
     * @return object
     */
    public function session(): object {
        global $SESSION;
        return $SESSION;
    }

    /**
     * user
     *
     * @return object
     */
    public function user(): object {
        global $USER;
        return $USER;
    }

    /**
     * course
     *
     * @return object
     */
    public function course(): object {
        global $COURSE;
        return $COURSE;
    }
}
