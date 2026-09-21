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
 * restored_course_module_test.php
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart\integration\event;

use advanced_testcase;
use block_sharing_cart\event\restored_course_module;
use core\event\base;

/**
 * restored_course_module_test class.
 *
 * @package    block_sharing_cart
 * @copyright  2024 Praxis Digital A/S
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restored_course_module_test extends advanced_testcase
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
     * @return restored_course_module
     */
    private function get_triggered_event(base $event): restored_course_module {
        $eventredirect = $this->redirectEvents();

        $event->trigger();

        $eventredirect->close();

        $events = $eventredirect->get_events();

        $actual = $events[array_key_first($events)];
        if (!$actual instanceof restored_course_module) {
            throw new \Exception('Expected event to be of type restored_course_module');
        }
        return $actual;
    }

    /**
     * Test the event creation and triggering.
     * See if the event is triggered correctly and the data is set as expected.
     * @return void
     * @throws \Exception
     * @covers \block_sharing_cart\event\restored_course_module
     */
    public function test_trigger_event(): void {
        self::setAdminUser();

        global $USER;

        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $coursemodule = $generator->create_module('assign', [
            'course' => $course->id,
        ]);
        $coursemodule->modname ??= 'assign';

        $starttime = time();
        $finishtime = time() + 10;

        $event = restored_course_module::create_by_course_module(
            $course->id,
            $coursemodule->cmid,
            $coursemodule->modname,
            $USER->id,
            $starttime,
            $finishtime
        );

        $actual = $this->get_triggered_event($event);

        self::assertEquals(
            $event,
            $actual
        );
    }
}
