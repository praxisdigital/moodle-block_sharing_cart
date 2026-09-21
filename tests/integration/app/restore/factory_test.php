<?php

namespace block_sharing_cart\integration\app\restore;

use block_sharing_cart\app\factory;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

class factory_test extends \advanced_testcase
{
    private factory $factory;

    protected function setUp(): void
    {
        $this->resetAfterTest();
        $this->factory = factory::make();
    }

    private function create_mbz_stored_file(array $files, string $filename = 'backup.mbz'): \stored_file
    {
        global $USER;

        self::setAdminUser();

        $packer = get_file_packer('application/vnd.moodle.backup');
        $tmpdir = make_request_directory();
        $archivepath = $tmpdir . '/' . $filename;

        $filemap = [];
        foreach ($files as $pathname => $content) {
            $full = $tmpdir . '/src/' . $pathname;
            $dir = dirname($full);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($full, $content);
            $filemap[$pathname] = $full;
        }

        $packer->archive_to_pathname($filemap, $archivepath);

        $fs = get_file_storage();
        return $fs->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ], $archivepath);
    }

    public function test_extract_backup_file_to_tempdir(): void
    {
        $backup_file = $this->create_mbz_stored_file([
            'moodle_backup.xml' => '<?xml version="1.0"?><moodle_backup/>',
            'roles.xml' => '<?xml version="1.0"?><roles/>',
        ]);

        $backupdir = 'sc_test_' . md5(uniqid((string)mt_rand(), true));
        $path = $this->factory->restore()->extract_backup_file_to_tempdir($backup_file, $backupdir);

        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/moodle_backup.xml');
        self::assertFileExists($path . '/roles.xml');

        fulldelete($path);
    }

    public function test_assert_backup_file_looks_valid_accepts_mbz_with_moodle_backup_xml(): void
    {
        $backup_file = $this->create_mbz_stored_file([
            'moodle_backup.xml' => '<?xml version="1.0"?><moodle_backup/>',
            'roles.xml' => '<?xml version="1.0"?><roles/>',
        ]);

        $this->factory->restore()->assert_backup_file_looks_valid($backup_file);
        $this->addToAssertionCount(1);
    }

    public function test_assert_backup_file_looks_valid_rejects_mbz_without_moodle_backup_xml(): void
    {
        $backup_file = $this->create_mbz_stored_file([
            'roles.xml' => '<?xml version="1.0"?><roles/>',
            'readme.txt' => 'not a backup',
        ]);

        $this->expectException(\moodle_exception::class);
        $this->factory->restore()->assert_backup_file_looks_valid($backup_file);
    }
}
