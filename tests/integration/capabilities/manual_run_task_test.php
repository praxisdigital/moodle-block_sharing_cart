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

namespace block_sharing_cart\integration\capabilities;

use core\context\course;
use core\context\system;

/**
 * capabilities tests for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_sharing_cart\external\task\run_now
 */
final class manual_run_task_test extends \advanced_testcase
{
    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * db
     *
     * @return \moodle_database
     */
    private function db(): \moodle_database {
        global $DB;
        return $DB;
    }

    /**
     * test_user_expected_not_allowed
     *
     * @return void
     */
    public function test_user_expected_not_allowed(): void {
        $generator = $this->getDataGenerator();
        $USER = $generator->create_user();
        $course = $generator->create_course();
        $coursecontext = course::instance($course->id);
        $systemcontext = system::instance();

        self::assertFalse(
            has_capability('block/sharing_cart:manual_run_task', $coursecontext, $USER),
        );
        self::assertFalse(
            has_capability('block/sharing_cart:manual_run_task', $systemcontext, $USER),
        );
    }

    /**
     * test_user_with_manager_role_expected_not_allowed
     *
     * @return void
     */
    public function test_user_with_manager_role_expected_not_allowed(): void {
        $generator = $this->getDataGenerator();
        $USER = $generator->create_user();
        $course = $generator->create_course();

        $generator->enrol_user($USER->id, $course->id, 'manager');
        $context = course::instance($course->id);

        self::assertFalse(
            has_capability('block/sharing_cart:manual_run_task', $context, $USER),
        );
    }

    /**
     * test_user_enrolled_in_course_with_capable_role_expected_allowed
     *
     * @return void
     */
    public function test_user_enrolled_in_course_with_capable_role_expected_allowed(): void {
        $generator = $this->getDataGenerator();
        $USER = $generator->create_user();
        $course = $generator->create_course();

        $context = course::instance($course->id);
        $role = $this->db()->get_record(
            'role',
            ['id' => $generator->create_role(['shortname' => 'testrole'])]
        );

        $generator->create_role_capability(
            $role->id,
            ['block/sharing_cart:manual_run_task' => 'allow'],
            $context
        );
        $generator->enrol_user(
            $USER->id,
            $course->id,
            $role->shortname
        );

        self::assertTrue(
            has_capability('block/sharing_cart:manual_run_task', $context, $USER),
        );
    }

    /**
     * test_user_assign_to_capable_role_expected_allowed
     *
     * @return void
     */
    public function test_user_assign_to_capable_role_expected_allowed(): void {
        $generator = $this->getDataGenerator();
        $USER = $generator->create_user();

        $systemcontext = system::instance();
        $role = $this->db()->get_record(
            'role',
            ['id' => $generator->create_role(['shortname' => 'testrole'])]
        );

        $generator->create_role_capability(
            $role->id,
            ['block/sharing_cart:manual_run_task' => 'allow'],
            $systemcontext
        );

        $generator->role_assign(
            $role->id,
            $USER->id,
            $systemcontext->id
        );

        self::assertTrue(
            has_capability('block/sharing_cart:manual_run_task', $systemcontext, $USER),
        );
    }

    /**
     * test_user_with_site_admin_expected_allowed
     *
     * @return void
     */
    public function test_user_with_site_admin_expected_allowed(): void {
        global $USER;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        self::setAdminUser();

        $generator->enrol_user($USER->id, $course->id, 'editingteacher');
        $context = course::instance($course->id);

        self::assertTrue(
            has_capability('block/sharing_cart:manual_run_task', $context, $USER),
        );
    }
}
