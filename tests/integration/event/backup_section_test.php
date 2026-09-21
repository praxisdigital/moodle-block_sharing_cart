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
 * backup_section_test.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\integration\event;

/**
 * backup_section_test class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_section_test extends \advanced_testcase
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
     * @param \core\event\base $event
     * @return \block_sharing_cart\event\backup_section
     */
    private function get_triggered_event(\core\event\base $event): \block_sharing_cart\event\backup_section {
        $eventredirect = $this->redirectEvents();

        $event->trigger();

        $eventredirect->close();

        $events = $eventredirect->get_events();

        $actual = $events[array_key_first($events)];
        if (!$actual instanceof \block_sharing_cart\event\backup_section) {
            throw new \Exception('Expected event to be of type backup_section');
        }
        return $actual;
    }

    /**
     * test_trigger_event
     *
     * @return void
     * @covers \block_sharing_cart\app\factory
     */
    public function test_trigger_event(): void {
        global $USER;

        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $section = $generator->create_course_section([
            'course' => $course->id,
            'section' => 1,
        ]);

        $event = \block_sharing_cart\event\backup_section::create_by_section(
            $course->id,
            $section->id,
            $USER->id,
        );

        $actual = $this->get_triggered_event($event);

        // Check the event data.
        self::assertEquals($course->id, $actual->get_course_id());
        self::assertEquals($section->id, $actual->get_section_id());
    }
}
