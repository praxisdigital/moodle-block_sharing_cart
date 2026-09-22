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

namespace block_sharing_cart\integration;

// @codeCoverageIgnoreEnd

use advanced_testcase;
use block_sharing_cart\app\factory;

/**
 * Unit/integration tests for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \block_sharing_cart\event\user_deleted
 */
final class observers_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_deleted_user_expect_sharing_cart_record_to_be_remove(): void {
        $USER = self::getDataGenerator()->create_user();
        $this->create_sharing_cart_item($USER->id);

        self::assertTrue(
            $this->has_sharing_cart_item($USER->id)
        );

        delete_user($USER);

        self::assertFalse(
            $this->has_sharing_cart_item($USER->id)
        );
    }

    private function has_sharing_cart_item(int $userid): bool {
        $basefactory = factory::make();

        return $basefactory->item()->repository()->get_by_user_id($userid)->not_empty();
    }

    private function create_sharing_cart_item(int $userid): object {
        $basefactory = factory::make();

        $entity = $basefactory->item()->entity((object)[]);
        $entity->set_user_id($userid);
        $entity->set_file_id(0);
        $entity->set_parent_item_id(0);
        $entity->set_old_instance_id(0);
        $entity->set_type('section');
        $entity->set_name('Some section name');
        $entity->set_status($entity::STATUS_AWAITING_BACKUP);
        $entity->set_sortorder(0);
        $entity->set_timecreated(time());
        $entity->set_timemodified(time());

        $id = $basefactory->item()->repository()->insert($entity);

        $entity->set_id($id);

        return $entity;
    }
}
