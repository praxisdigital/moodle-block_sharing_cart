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

namespace block_sharing_cart\integration\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\restore\section_details_replacement;

/**
 * Replacement preflight must retain the original destination until it is safe to blank it.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(section_details_replacement::class)]
final class section_details_replacement_test extends \advanced_testcase {
    /**
     * Snapshot preparation is read-only for the target; blanking can still be rolled back with its files.
     */
    public function test_prepare_blank_and_rollback_preserve_the_original_details(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = self::getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 1]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        course_update_section($course, $section, ['name' => 'Keep title', 'summary' => '<p>Keep description</p>']);
        $record = [
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'course', 'filearea' => 'section', 'itemid' => $section->id,
            'filepath' => '/', 'filename' => 'keep.txt',
        ];
        get_file_storage()->create_file_from_string($record, 'Keep file');
        $replacement = factory::make()->restore()->section_details_replacement();

        $replacement->snapshot((int)$course->id, (int)$section->id);
        $this->assertTrue($replacement->has_snapshot((int)$course->id, (int)$section->id));
        $this->assertSame('Keep title', $DB->get_field('course_sections', 'name', ['id' => $section->id]));
        $this->assertSame('<p>Keep description</p>', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));
        $this->assertNotFalse(get_file_storage()->get_file(...array_values($record)));

        $replacement->blank((int)$course->id, (int)$section->id);
        $this->assertNull($DB->get_field('course_sections', 'name', ['id' => $section->id]));
        $this->assertSame('', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));
        $this->assertFalse(get_file_storage()->get_file(...array_values($record)));

        $this->assertTrue($replacement->rollback((int)$course->id, (int)$section->id));
        $this->assertSame('Keep title', $DB->get_field('course_sections', 'name', ['id' => $section->id]));
        $this->assertSame('<p>Keep description</p>', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));
        $this->assertSame('Keep file', get_file_storage()->get_file(...array_values($record))->get_content());
        $this->assertFalse($replacement->has_snapshot((int)$course->id, (int)$section->id));
    }

    /**
     * Callers cannot clear a section without first creating a rollback snapshot.
     */
    public function test_blank_requires_a_snapshot(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = self::getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 1]);
        $sectionid = (int)$DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1], MUST_EXIST);
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Section details cannot be cleared without a replacement snapshot.');
        factory::make()->restore()->section_details_replacement()->blank((int)$course->id, $sectionid);
    }
}
