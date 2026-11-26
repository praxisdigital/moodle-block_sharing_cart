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

// @codeCoverageIgnoreStart
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\item\entity;
use core\context\course as course_context;
use core\context\module as module_context;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the authorization checks on the restore confirmation fragment.
 *
 * @package block_sharing_cart
 * @copyright moxis
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('block_sharing_cart_output_fragment_item_restore_form')]
final class restore_form_authorization_test extends \advanced_testcase {
    /**
     * The fragment only confirms an owned item for a visible section in an authorized course context.
     *
     * @param string $scenario Context, capability or section condition to exercise.
     */
    #[DataProvider('authorization_scenarios')]
    public function test_restore_form_authorization(string $scenario): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/sharing_cart/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        self::setAdminUser();

        $generator = self::getDataGenerator();
        $course = $generator->create_course(['format' => 'topics', 'numsections' => 1]);
        $coursecontext = course_context::instance($course->id);
        $user = $generator->create_user();
        $role = $scenario === 'no-capability' ? 'student' : 'editingteacher';
        $generator->enrol_user($user->id, $course->id, $role);
        $targetcourse = $course;
        $fragmentcontext = $coursecontext;

        if ($scenario === 'other-course') {
            // Both courses grant restore permission; only course membership of the section differs.
            $targetcourse = $generator->create_course(['format' => 'topics', 'numsections' => 1]);
            $generator->enrol_user($user->id, $targetcourse->id, $role);
        } else if ($scenario === 'module-context') {
            $page = $generator->create_module('page', ['course' => $course->id]);
            $fragmentcontext = module_context::instance($page->cmid);
        }

        if ($scenario === 'hidden-section') {
            $section = get_fast_modinfo($course)->get_section_info(1, MUST_EXIST);
            course_update_section($course, $section, ['visible' => 0]);
            $roleid = $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
            assign_capability('moodle/course:viewhiddensections', CAP_PROHIBIT, $roleid, $coursecontext->id);
        }

        // A regular activity can render the confirmation without an archive or restore task.
        $itemid = $DB->insert_record('block_sharing_cart_items', [
            'user_id' => $user->id,
            'old_instance_id' => 1,
            'type' => 'mod_page',
            'name' => 'Cart activity',
            'status' => entity::STATUS_BACKEDUP,
            'version' => entity::CURRENT_BACKUP_VERSION,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);

        self::setUser($user);
        $target = get_fast_modinfo($targetcourse)->get_section_info(1, MUST_EXIST);
        self::assertSame($scenario !== 'hidden-section', $target->uservisible);
        self::assertSame($scenario !== 'no-capability', has_capability('moodle/restore:restoreactivity', $coursecontext));

        $args = ['itemid' => $itemid, 'clipboardtargetid' => $target->id];
        if ($scenario !== 'missing-context') {
            $args['context'] = $fragmentcontext;
        }

        $result = block_sharing_cart_output_fragment_item_restore_form($args);
        $expected = $scenario === 'authorized' ? get_string('confirm_copy_item', 'block_sharing_cart') : '';
        self::assertSame($expected, $result);
    }

    /**
     * Provide valid and invalid restore fragment authorization scenarios.
     *
     * @return array<string, array{string}> Named authorization scenarios.
     */
    public static function authorization_scenarios(): array {
        return [
            'authorized visible target in course' => ['authorized'],
            'target belongs to another course' => ['other-course'],
            'course context not supplied' => ['missing-context'],
            'module context rather than course context' => ['module-context'],
            'user lacks activity restore capability' => ['no-capability'],
            'target is not visible to user' => ['hidden-section'],
        ];
    }
}
