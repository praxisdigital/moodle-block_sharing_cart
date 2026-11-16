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

namespace block_sharing_cart\integration\output\block;

use block_sharing_cart\app\factory;
use block_sharing_cart\output\block\content;

/**
 * Tests for the Sharing Cart block content output.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(content::class)]
final class content_test extends \advanced_testcase {
    /**
     * An unset drop-zone setting uses its declared default, while an explicit zero disables it.
     *
     * @return void
     */
    public function test_drop_zones_default_to_enabled_when_setting_is_unset(): void {
        global $USER;

        $this->resetAfterTest();
        self::setAdminUser();
        $course = self::getDataGenerator()->create_course();
        $renderer = $this->createMock(\renderer_base::class);
        $content = new content(factory::make(), $USER->id, $course->id);

        unset_config('enable_drop_zones', 'block_sharing_cart');
        $data = $content->export_for_template($renderer);
        self::assertTrue($data['dropZonesEnabled']);

        set_config('enable_drop_zones', 0, 'block_sharing_cart');
        $data = $content->export_for_template($renderer);
        self::assertFalse($data['dropZonesEnabled']);
    }
}
