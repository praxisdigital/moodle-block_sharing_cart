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
use block_sharing_cart\app\restore\section_planner;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Root subsection identities must support historical IDs without guessing across ID-table collisions.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(section_planner::class)]
final class subsection_identity_test extends \advanced_testcase {
    /**
     * Exercise identity resolution using archived task metadata, not any live source course.
     *
     * @param int $oldid stored cart identity
     * @param int|null $parentid cart parent (null for a root)
     * @param array $sections [section ID, delegated owner CM ID] descriptors
     * @param int[] $moduleids selected children
     * @param array<int, int> $modulesections archived child CM ID to original section ID
     * @param int|null $expected resolved delegated section ID, or null for rejection
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('identity_provider')]
    public function test_subsection_identity(
        int $oldid,
        ?int $parentid,
        array $sections,
        array $moduleids,
        array $modulesections,
        ?int $expected
    ): void {
        global $DB;

        $this->resetAfterTest();
        $course = self::getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 1]);
        $targetid = (int)$DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1], MUST_EXIST);
        $tasks = [];
        foreach ($sections as [$sectionid, $ownerid]) {
            $task = $this->createMock(\restore_section_task::class);
            $task->method('get_taskbasepath')->willReturn('/backup/sections/section_' . $sectionid);
            $task->method('get_delegated_cm')->willReturn($ownerid);
            $tasks[] = $task;
        }
        $restoreplan = $this->createMock(\restore_plan::class);
        $restoreplan->method('get_tasks')->willReturn($tasks);
        $activities = [];
        foreach ($modulesections as $moduleid => $sectionid) {
            $activities[] = (object)['moduleid' => $moduleid, 'sectionid' => $sectionid];
        }
        $controller = $this->createMock(\restore_controller::class);
        $controller->method('get_courseid')->willReturn((int)$course->id);
        $controller->method('get_plan')->willReturn($restoreplan);
        $controller->method('get_info')->willReturn((object)['activities' => $activities]);
        $item = factory::make()->item()->entity((object)[
            'id' => 123, 'type' => 'mod_subsection', 'old_instance_id' => $oldid, 'parent_item_id' => $parentid,
        ]);
        if ($expected === null) {
            $this->expectException(\coding_exception::class);
        }
        $plan = factory::make()->restore()->section_planner()->plan(
            $controller,
            $item,
            $targetid,
            false,
            [],
            $moduleids,
            true
        );
        if ($expected !== null) {
            $owners = array_column($sections, 1, 0);
            $this->assertSame($expected, $plan->rootoldsectionid);
            $this->assertSame([...$moduleids, $owners[$expected]], $plan->coursemodulestoinclude);
        }
    }

    /**
     * Valid and rejected archive identity combinations.
     *
     * @return array<string, array>
     */
    public static function identity_provider(): array {
        $collision = [[100, 200], [300, 100]];
        $children = [1000 => 100, 3000 => 300];
        return [
            'modern root section ID' => [100, null, [[100, 200]], [1000], [1000 => 100], 100],
            'legacy root CM ID' => [200, null, [[100, 200]], [1000], [1000 => 100], 100],
            'nested CM ID is not a section ID' => [200, 1, [[200, 300], [100, 200]], [1000], [1000 => 100], 100],
            'regular section collision is ignored' => [200, null, [[200, null], [100, 200]], [1000], [1000 => 100], 100],
            'colliding modern ID' => [100, null, $collision, [1000], $children, 100],
            'colliding legacy ID' => [100, null, $collision, [3000], $children, 300],
            'colliding legacy ID with reversed task order' => [100, null, array_reverse($collision), [3000], $children, 300],
            'ambiguous without children' => [100, null, $collision, [], $children, null],
            'ambiguous with unknown children' => [100, null, $collision, [9999], $children, null],
            'children span conflicting candidates' => [100, null, $collision, [1000, 3000], $children, null],
            'no matching subsection' => [999, null, [[100, 200]], [1000], $children, null],
        ];
    }
}
