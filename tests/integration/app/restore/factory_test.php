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
 * factory_test.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\integration\app\restore;

use block_sharing_cart\app\factory;

/**
 * factory_test class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class factory_test extends \advanced_testcase
{
    /** @var factory $factory */
    private factory $factory;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->factory = factory::make();
    }

    /**
     * create_mbz_stored_file
     *
     * @param array $files
     * @param string $filename
     * @return \stored_file
     */
    private function create_mbz_stored_file(array $files, string $filename = 'backup.mbz'): \stored_file {
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

    /**
     * test_extract_backup_file_to_tempdir
     *
     * @return void
     * @covers \block_sharing_cart\app\factory
     */
    public function test_extract_backup_file_to_tempdir(): void {
        $backupfile = $this->create_mbz_stored_file([
            'moodle_backup.xml' => '<?xml version="1.0"?><moodle_backup/>',
            'roles.xml' => '<?xml version="1.0"?><roles/>',
        ]);

        $backupdir = 'sc_test_' . md5(uniqid((string)mt_rand(), true));
        $path = $this->factory->restore()->extract_backup_file_to_tempdir($backupfile, $backupdir);

        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/moodle_backup.xml');
        self::assertFileExists($path . '/roles.xml');

        fulldelete($path);
    }

    /**
     * test_assert_backup_file_looks_valid_accepts_mbz_with_moodle_backup_xml
     *
     * @return void
     * @covers \block_sharing_cart\app\factory
     */
    public function test_assert_backup_file_looks_valid_accepts_mbz_with_moodle_backup_xml(): void {
        $backupfile = $this->create_mbz_stored_file([
            'moodle_backup.xml' => '<?xml version="1.0"?><moodle_backup/>',
            'roles.xml' => '<?xml version="1.0"?><roles/>',
        ]);

        $this->factory->restore()->assert_backup_file_looks_valid($backupfile);
        $this->addToAssertionCount(1);
    }

    /**
     * test_assert_backup_file_looks_valid_rejects_mbz_without_moodle_backup_xml
     *
     * @return void
     * @covers \block_sharing_cart\app\factory
     */
    public function test_assert_backup_file_looks_valid_rejects_mbz_without_moodle_backup_xml(): void {
        $backupfile = $this->create_mbz_stored_file([
            'roles.xml' => '<?xml version="1.0"?><roles/>',
            'readme.txt' => 'not a backup',
        ]);

        $this->expectException(\moodle_exception::class);
        $this->factory->restore()->assert_backup_file_looks_valid($backupfile);
    }
}
