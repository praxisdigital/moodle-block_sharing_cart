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
 * factory.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\app\backup;

use block_sharing_cart\app\factory as basefactory;

/**
 * factory class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * backup_controller
     *
     * @param string $type
     * @param int $instanceid
     * @param int $userid
     * @return \backup_controller
     */
    public function backup_controller(string $type, int $instanceid, int $userid): \backup_controller {
        return new \backup_controller(
            $type,
            $instanceid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_ASYNC,
            $userid,
            \backup::RELEASESESSION_YES
        );
    }

    /**
     * handler
     *
     * @return handler
     */
    public function handler(): handler {
        return new handler($this->basefactory);
    }

    /**
     * settings_helper
     *
     * @return backup_settings_helper
     */
    public function settings_helper(): backup_settings_helper {
        return new backup_settings_helper($this->basefactory);
    }

    /**
     * settings_repository
     *
     * @return backup_settings_queries
     */
    public function settings_repository(): backup_settings_queries {
        return new backup_settings_queries($this->basefactory);
    }
}
