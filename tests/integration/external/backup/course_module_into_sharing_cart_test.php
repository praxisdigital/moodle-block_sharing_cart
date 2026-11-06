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

namespace block_sharing_cart\integration\external\backup;

use block_sharing_cart\external\backup\course_module_into_sharing_cart;

/**
 * Tests for backing up course modules into the Sharing Cart.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_module_into_sharing_cart_test extends \advanced_testcase {
    /**
     * A subsection copied from its module action uses the delegated section as its item identity.
     *
     * @covers \block_sharing_cart\external\backup\course_module_into_sharing_cart
     * @return void
     */
    public function test_subsection_course_module_is_backed_up_as_its_delegated_section(): void {
        global $DB;

        $this->resetAfterTest();
        self::setAdminUser();

        $course = self::getDataGenerator()->create_course();
        $subsection = self::getDataGenerator()->create_module('subsection', [
            'course' => $course->id,
            'section' => 1,
        ]);
        $subsectioncm = $DB->get_record('course_modules', ['id' => $subsection->cmid], 'id, instance', MUST_EXIST);
        $delegatedsection = $DB->get_record(
            'course_sections',
            ['course' => $course->id, 'component' => 'mod_subsection', 'itemid' => $subsectioncm->instance],
            'id, course, section, itemid',
            MUST_EXIST
        );
        self::getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'section' => $delegatedsection->section,
        ]);

        $result = course_module_into_sharing_cart::execute($subsection->cmid, [
            'users' => false,
            'anonymize' => false,
        ]);

        $item = $DB->get_record('block_sharing_cart_items', ['id' => $result->id], '*', MUST_EXIST);
        self::assertSame('mod_subsection', $item->type);
        self::assertSame((int)$delegatedsection->id, (int)$item->old_instance_id);
        self::assertGreaterThan(0, $result->taskid);
    }
}
