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

namespace block_sharing_cart\privacy;

// @codeCoverageIgnoreStart
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\item\entity;
use core\context\user as user_context;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\subsystem_link;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests discovery, export and deletion of Sharing Cart personal data.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var string Plugin component. */
    private const COMPONENT = 'block_sharing_cart';

    /** @var string Cart item table. */
    private const TABLE = 'block_sharing_cart_items';

    /** @var string User backup file area. */
    private const FILEAREA = 'backup';

    /**
     * Prepare isolated database and file storage fixtures.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The privacy metadata identifies the item's owner and declares file storage.
     */
    public function test_get_metadata_declares_item_owner_and_file_storage(): void {
        $metadata = provider::get_metadata(new collection(self::COMPONENT))->get_collection();
        $tables = array_values(array_filter(
            $metadata,
            static fn($type): bool => $type instanceof database_table && $type->get_name() === self::TABLE,
        ));

        self::assertCount(1, $tables);
        self::assertArrayHasKey('user_id', $tables[0]->get_privacy_fields());

        $filesubsystems = array_filter(
            $metadata,
            static fn($type): bool => $type instanceof subsystem_link && $type->get_name() === 'core_files',
        );
        self::assertCount(1, $filesubsystems);
    }

    /**
     * Either a cart row or a backup is enough, but only in the owner's context.
     */
    public function test_get_contexts_for_userid_requires_owned_cart_data(): void {
        $generator = self::getDataGenerator();
        $empty = user_context::instance($generator->create_user()->id);
        $rowonly = user_context::instance($generator->create_user()->id);
        $fileonly = user_context::instance($generator->create_user()->id);
        $this->create_item($rowonly->instanceid, 'Row only');
        $this->create_backup($fileonly, 0, 'file-only.mbz');

        foreach ([$empty, $rowonly, $fileonly] as $context) {
            $hasdata = $context !== $empty;
            $contexts = provider::get_contexts_for_userid($context->instanceid);
            self::assertEquals($hasdata ? [$context->id] : [], $contexts->get_contextids());

            $users = new userlist($context, self::COMPONENT);
            provider::get_users_in_context($users);
            self::assertEquals($hasdata ? [$context->instanceid] : [], $users->get_userids());
        }

        $users = new userlist(\context_system::instance(), self::COMPONENT);
        provider::get_users_in_context($users);
        self::assertSame([], $users->get_userids());
    }

    /**
     * A backup without a cart row can still be exported.
     */
    public function test_export_user_data_includes_file_without_cart_row(): void {
        $owner = self::getDataGenerator()->create_user();
        $context = user_context::instance($owner->id);
        $file = $this->create_backup($context, 0, 'unlinked.mbz');

        provider::export_user_data(new approved_contextlist($owner, self::COMPONENT, [$context->id]));

        $export = writer::with_context($context);
        $path = [get_string('pluginname', self::COMPONENT), '0'];
        self::assertEmpty($export->get_data($path));
        $files = $export->get_files($path);
        self::assertSame(['unlinked.mbz'], array_keys($files));
        self::assertSame((int)$file->get_id(), (int)$files['unlinked.mbz']->get_id());
    }

    /**
     * Export puts each owned item and its file together at a separate path.
     */
    public function test_export_user_data_uses_unique_paths_for_item_metadata_and_files(): void {
        global $DB;

        $generator = self::getDataGenerator();
        $owner = $generator->create_user();
        $context = user_context::instance($owner->id);
        $othercontext = user_context::instance($generator->create_user()->id);
        $otheritem = $this->create_item($othercontext->instanceid, 'Other cart item');
        $this->create_backup($othercontext, $otheritem, 'other.mbz');

        // Use the same item name so it cannot serve as the per-item export key.
        $firstid = $this->create_item($owner->id, 'Cart item');
        $secondid = $this->create_item($owner->id, 'Cart item');
        $firstfile = $this->create_backup($context, $firstid, 'first.mbz');
        $secondfile = $this->create_backup($context, $secondid, 'second.mbz');
        $DB->set_field(self::TABLE, 'file_id', $firstfile->get_id(), ['id' => $firstid]);
        $DB->set_field(self::TABLE, 'file_id', $secondfile->get_id(), ['id' => $secondid]);

        provider::export_user_data(new approved_contextlist($owner, self::COMPONENT, [$context->id, $othercontext->id]));

        $export = writer::with_context($context);
        $pluginname = get_string('pluginname', self::COMPONENT);
        foreach ([$firstid => $firstfile, $secondid => $secondfile] as $itemid => $file) {
            $path = [$pluginname, (string)$itemid];
            $data = $export->get_data($path);
            self::assertInstanceOf(\stdClass::class, $data);
            self::assertEquals($owner->id, $data->user_id);
            self::assertSame('Cart item', $data->name);
            self::assertEquals($file->get_id(), $data->file_id);

            $files = $export->get_files($path);
            self::assertSame([$file->get_filename()], array_keys($files));
            self::assertSame((int)$file->get_id(), (int)$files[$file->get_filename()]->get_id());
        }
        self::assertFalse($export->has_any_data([$pluginname, (string)$otheritem]));
        self::assertFalse(writer::with_context($othercontext)->has_any_data());
    }

    /**
     * Export requires explicit approval of the requesting user's own context.
     */
    public function test_export_user_data_rejects_empty_and_unrelated_approvals(): void {
        $generator = self::getDataGenerator();
        $owner = $generator->create_user();
        $context = user_context::instance($owner->id);
        $othercontext = user_context::instance($generator->create_user()->id);
        $itemid = $this->create_item($owner->id, 'Cart item');
        $this->create_backup($context, $itemid, 'item.mbz');

        foreach ([[], [\context_system::instance()->id, $othercontext->id]] as $approvedids) {
            provider::export_user_data(new approved_contextlist($owner, self::COMPONENT, $approvedids));
            self::assertFalse(writer::with_context($context)->has_any_data_in_any_context());
        }
    }

    /**
     * All deletion APIs remove both records and the entire owned backup area.
     *
     * @param string $operation Deletion API to exercise.
     */
    #[DataProvider('deletion_operations')]
    public function test_deletion_removes_only_the_selected_users_cart_data(string $operation): void {
        global $DB;

        $generator = self::getDataGenerator();
        $owner = $generator->create_user();
        $context = user_context::instance($owner->id);
        $otheruser = $generator->create_user();
        $othercontext = user_context::instance($otheruser->id);
        $itemid = $this->create_item($owner->id, 'Owned cart item');
        $this->create_backup($context, $itemid, 'owned.mbz');
        $this->create_backup($context, 0, 'unlinked.mbz');
        $otheritemid = $this->create_item($otheruser->id, 'Other cart item');
        $otherfile = $this->create_backup($othercontext, $otheritemid, 'other.mbz');

        switch ($operation) {
            case 'context':
                provider::delete_data_for_all_users_in_context($context);
                break;
            case 'user':
                provider::delete_data_for_user(new approved_contextlist(
                    $owner,
                    self::COMPONENT,
                    [$context->id, $othercontext->id],
                ));
                break;
            case 'userlist':
                provider::delete_data_for_users(new approved_userlist(
                    $context,
                    self::COMPONENT,
                    [$owner->id, $otheruser->id],
                ));
                break;
        }

        $fs = get_file_storage();
        self::assertFalse($DB->record_exists(self::TABLE, ['user_id' => $owner->id]));
        self::assertEmpty($fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA));
        self::assertTrue($DB->record_exists(self::TABLE, ['id' => $otheritemid, 'user_id' => $otheruser->id]));
        self::assertNotFalse($fs->get_file_by_id($otherfile->get_id()));
    }

    /**
     * Provide the privacy deletion APIs to exercise.
     *
     * @return array<string, array{string}> Named deletion operations.
     */
    public static function deletion_operations(): array {
        return [
            'all users in context' => ['context'],
            'approved contexts for a user' => ['user'],
            'approved users in context' => ['userlist'],
        ];
    }

    /**
     * Unrelated contexts or users cannot delete an existing cart.
     */
    public function test_deletion_rejects_unrelated_contexts_and_unapproved_users(): void {
        global $DB;

        $generator = self::getDataGenerator();
        $owner = $generator->create_user();
        $context = user_context::instance($owner->id);
        $otheruser = $generator->create_user();
        $othercontext = user_context::instance($otheruser->id);
        $systemcontext = \context_system::instance();
        $itemid = $this->create_item($owner->id, 'Owned cart item');
        $file = $this->create_backup($context, $itemid, 'owned.mbz');
        $otheritemid = $this->create_item($otheruser->id, 'Other cart item');
        $otherfile = $this->create_backup($othercontext, $otheritemid, 'other.mbz');

        provider::delete_data_for_all_users_in_context($systemcontext);
        provider::delete_data_for_user(new approved_contextlist(
            $owner,
            self::COMPONENT,
            [$systemcontext->id, $othercontext->id],
        ));
        provider::delete_data_for_users(new approved_userlist($systemcontext, self::COMPONENT, [$owner->id]));
        provider::delete_data_for_users(new approved_userlist($context, self::COMPONENT, [$otheruser->id]));

        $fs = get_file_storage();
        self::assertTrue($DB->record_exists(self::TABLE, ['id' => $itemid]));
        self::assertTrue($DB->record_exists(self::TABLE, ['id' => $otheritemid]));
        self::assertNotFalse($fs->get_file_by_id($file->get_id()));
        self::assertNotFalse($fs->get_file_by_id($otherfile->get_id()));
    }

    /**
     * Insert a cart row without running the backup task.
     *
     * @param int $userid Owner of the cart item.
     * @param string $name Item name.
     * @return int Item ID.
     */
    private function create_item(int $userid, string $name): int {
        global $DB;

        return $DB->insert_record(self::TABLE, [
            'user_id' => $userid,
            'old_instance_id' => 1,
            'type' => 'mod_page',
            'name' => $name,
            'status' => entity::STATUS_BACKEDUP,
            'original_course_fullname' => 'Source course',
            'version' => entity::CURRENT_BACKUP_VERSION,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);
    }

    /**
     * Store harmless fixture content in the cart backup file area.
     *
     * @param user_context $context Owner's context.
     * @param int $itemid Cart item ID.
     * @param string $filename Backup filename.
     * @return \stored_file Stored fixture.
     */
    private function create_backup(user_context $context, int $itemid, string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $context->instanceid,
        ], 'Sharing Cart privacy fixture: ' . $filename);
    }
}
