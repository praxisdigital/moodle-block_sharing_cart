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
 * restored_section_test.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\integration\event;

use advanced_testcase;
use block_sharing_cart\event\restored_section;
use core\event\base;
use Exception;

/**
 * restored_section_test class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restored_section_test extends advanced_testcase
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
     * get_triggered_event
     *
     * @param base $event
     * @return restored_section
     */
    private function get_triggered_event(base $event): restored_section {
        $eventredirect = $this->redirectEvents();

        $event->trigger();

        $eventredirect->close();

        $events = $eventredirect->get_events();

        $actual = $events[array_key_first($events)];
        if (!$actual instanceof restored_section) {
            throw new Exception('Expected event to be of type restored_section');
        }
        return $actual;
    }

    /**
     * Test the event creation and triggering.
     * See if the event is triggered correctly and the data is set as expected.
     * @return void
     * @throws Exception
     * @covers \block_sharing_cart\event\restored_section
     */
    public function test_trigger_event(): void {
        global $DB;
        self::setAdminUser();

        global $USER;

        $generator = self::getDataGenerator();
        $course = $generator->create_course();

        $sectionnumber = $DB->count_records_select('course_sections', "course = ?", [$course->id]);
        $section = $generator->create_course_section([
            'course' => $course->id,
            'section' => $sectionnumber + 1,
        ]);

        $starttime = time();
        $finishtime = time() + 10;

        $event = restored_section::create_by_section(
            $course->id,
            $section->id,
            $USER->id,
            $starttime,
            $finishtime
        );

        $actual = $this->get_triggered_event($event);

        self::assertEquals(
            $event,
            $actual,
            'The event should be the same as the one created.'
        );
    }
}
