<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class factory
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    public function extract_backup_file_to_tempdir(
        \stored_file $backup_file,
        string $backupdir
    ): string {
        $path = make_backup_temp_directory($backupdir);

        $fp = get_file_packer('application/vnd.moodle.backup');
        $result = $fp->extract_to_pathname($backup_file, $path);
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

    public function is_backup_tempdir_complete(string $backupdir): bool
    {
        $path = get_backup_temp_directory($backupdir);
        if ($path === false || !is_dir($path)) {
            return false;
        }

        return is_readable($path . '/moodle_backup.xml')
            && is_readable($path . '/roles.xml');
    }

    public function ensure_backup_extracted_to_controller_tempdir(
        \stored_file $backup_file,
        string $backupdir
    ): bool {
        if ($this->is_backup_tempdir_complete($backupdir)) {
            return false;
        }

        $path = get_backup_temp_directory($backupdir);
        if ($path !== false && is_dir($path)) {
            fulldelete($path);
        }

        $this->extract_backup_file_to_tempdir($backup_file, $backupdir);

        if (!$this->is_backup_tempdir_complete($backupdir)) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Sharing cart backup temp directory incomplete after extract: ' . $backupdir
            );
        }

        return true;
    }

    public function assert_backup_file_looks_valid(\stored_file $backup_file): void
    {
        $fp = get_file_packer('application/vnd.moodle.backup');
        $files = $fp->list_files($backup_file);
        if ($files === false || empty($files)) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Sharing cart backup file is not a readable MBZ (item file id: '
                . $backup_file->get_id() . ')'
            );
        }

        $has_moodle_backup_xml = false;
        foreach ($files as $fileinfo) {
            $name = is_object($fileinfo)
                ? ($fileinfo->pathname ?? $fileinfo->name ?? '')
                : (string)$fileinfo;
            $name = ltrim(str_replace('\\', '/', $name), './');
            if ($name === 'moodle_backup.xml' || str_ends_with($name, '/moodle_backup.xml')) {
                $has_moodle_backup_xml = true;
                break;
            }
        }

        if (!$has_moodle_backup_xml) {
            throw new \moodle_exception(
                'error',
                'error',
                '',
                null,
                'Sharing cart backup file is missing moodle_backup.xml (item file id: '
                . $backup_file->get_id() . ')'
            );
        }
    }

    public function restore_controller(\stored_file $backup_file, int $course_id, int $user_id): \restore_controller
    {
        $backupdir = \restore_controller::get_tempdir_name($course_id, $user_id);
        $this->extract_backup_file_to_tempdir($backup_file, $backupdir);

        return new \restore_controller(
            $backupdir,
            $course_id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_ASYNC,
            $user_id,
            \backup::TARGET_EXISTING_ADDING,
            releasesession: \backup::RELEASESESSION_YES
        );
    }

    public function handler(): handler
    {
        return new handler($this->base_factory);
    }
}
