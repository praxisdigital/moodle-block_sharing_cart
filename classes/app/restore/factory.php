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

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Class app\restore\factory for the Sharing Cart block.
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

    public function extract_backup_file_to_tempdir(
        \stored_file $backupfile,
        string $backupdir
    ): string {
        $path = make_backup_temp_directory($backupdir);

        $fp = get_file_packer('application/vnd.moodle.backup');
        $result = $fp->extract_to_pathname($backupfile, $path);
        if ($result === false) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Failed to extract sharing cart backup file to temp directory: ' . $path
            );
        }

        return $path;
    }

    public function assert_backup_file_looks_valid(\stored_file $backupfile): void {
        $fp = get_file_packer('application/vnd.moodle.backup');
        $files = $fp->list_files($backupfile);
        if ($files === false || empty($files)) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Sharing cart backup file is not a readable MBZ (item file id: '
                . $backupfile->get_id() . ')'
            );
        }

        $hasmoodlebackupxml = false;
        foreach ($files as $fileinfo) {
            $name = is_object($fileinfo)
                ? ($fileinfo->pathname ?? $fileinfo->name ?? '')
                : (string)$fileinfo;
            $name = ltrim(str_replace('\\', '/', $name), './');
            if ($name === 'moodle_backup.xml' || str_ends_with($name, '/moodle_backup.xml')) {
                $hasmoodlebackupxml = true;
                break;
            }
        }

        if (!$hasmoodlebackupxml) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Sharing cart backup file is missing moodle_backup.xml (item file id: '
                . $backupfile->get_id() . ')'
            );
        }
    }

    public function restore_controller(\stored_file $backupfile, int $courseid, int $userid): \restore_controller {
        $backupdir = \restore_controller::get_tempdir_name($courseid, $userid);
        $this->extract_backup_file_to_tempdir($backupfile, $backupdir);

        return new \restore_controller(
            $backupdir,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_ASYNC,
            $userid,
            \backup::TARGET_EXISTING_ADDING,
            releasesession: \backup::RELEASESESSION_YES
        );
    }

    public function handler(): handler {
        return new handler($this->basefactory);
    }
}
