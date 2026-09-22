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

namespace block_sharing_cart\integration\app\backup;

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\backup\backup_settings_helper;
use block_sharing_cart\app\item\entity;
use core\exception\required_capability_exception as core_required_capability_exception;
use section_info;

/**
 * backup settings helper test for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_sharing_cart\app\backup\backup_settings_helper
 */
final class backup_settings_helper_test extends \advanced_testcase
{
    /** @var backup_settings_helper $helper */
    protected backup_settings_helper $helper;

    /** @var basefactory $basefactory */
    protected basefactory $basefactory;

    /** @var object $customdata1 */
    protected object $customdata1;

    /** @var object $course1 */
    protected object $course1;

    /** @var object $section1course1 */
    protected object $section1course1;
    /** @var object $section2course1 */
    protected object $section2course1;
    /** @var object $page1course1 */
    protected object $page1course1;
    /** @var object $book1course1 */
    protected object $book1course1;
    /** @var object $page2course1 */
    protected object $page2course1;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->basefactory = basefactory::make();
        $this->helper = $this->basefactory->backup()->settings_helper();

        $this->generate_courses();
        $this->generate_custom_datas();
    }

    /** test_construct_backup_plan_settings_sets_all_sections_and_modules_to_not_include_users_when_users_are_set_to_false
     *
     * @return void
     */
    // phpcs:ignore moodle.Files.LineLength.TooLong
    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_not_include_users_when_users_are_set_to_false(): void {
        $this->customdata1->backupsettings["users"] = false;
        $this->customdata1->item["old_instance_id"] = $this->section1Course1->id;
        $this->customdata1->item["type"] = "section";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course1->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        // Section asserts.
        $this->assertTrue($backupplansettings[$this->get_section_include($this->section1Course1)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->section1Course1)]);

        // Module asserts.
        $this->assertTrue($backupplansettings[$this->get_module_include($this->page1Course1, 'page')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->page1Course1, 'page')]);
        $this->assertTrue($backupplansettings[$this->get_module_include($this->book1Course1, 'book')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->book1Course1, 'book')]);

        $item->set_old_instance_id($this->section2Course1->id);
        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        // Section asserts.
        $this->assertTrue($backupplansettings[$this->get_section_include($this->section2Course1)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->section2Course1)]);
    }

    /**
     * Includes users when capability allows.
     *
     * @return void
     */
    // phpcs:ignore moodle.Files.LineLength.TooLong
    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_include_users_when_users_are_set_to_true_and_user_has_capability(): void {
        $this->customdata1->backupsettings["users"] = true;
        $this->customdata1->item["old_instance_id"] = $this->section1Course1->id;
        $this->customdata1->item["type"] = "section";

        $this->setAdminUser();

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course1->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        // Section asserts.
        $this->assertTrue($backupplansettings[$this->get_section_include($this->section1Course1)]);
        $this->assertTrue($backupplansettings[$this->get_section_userinfo($this->section1Course1)]);

        // Module asserts.
        $this->assertTrue($backupplansettings[$this->get_module_include($this->page1Course1, 'page')]);
        $this->assertTrue($backupplansettings[$this->get_module_userinfo($this->page1Course1, 'page')]);
        $this->assertTrue($backupplansettings[$this->get_module_include($this->book1Course1, 'book')]);
        $this->assertTrue($backupplansettings[$this->get_module_userinfo($this->book1Course1, 'book')]);

        $item->set_old_instance_id($this->section2Course1->id);
        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        // Section asserts.
        $this->assertTrue($backupplansettings[$this->get_section_include($this->section2Course1)]);
        $this->assertTrue($backupplansettings[$this->get_section_userinfo($this->section2Course1)]);
    }

    /**
     * test_construct_backup_plan_settings_terminates_with_error_when_users_are_set_to_true_and_lacks_capability.
     */
    // phpcs:ignore moodle.Files.LineLength.TooLong
    public function test_construct_backup_plan_settings_terminates_with_error_when_users_are_set_to_true_and_lacks_capability(): void {
        if (!class_exists('core\exception\required_capability_exception')) {
            $this->markTestSkipped(
                'Skipping test. Required class core\exception\required_capability_exception ' .
                'does not exist in this version of moodle'
            );
        }

        $this->customdata1->backupsettings["users"] = true;
        $this->customdata1->item["old_instance_id"] = $this->section1Course1->id;
        $this->customdata1->item["type"] = "section";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course1->id);

        $this->expectException(core_required_capability_exception::class);

        $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);
    }

    /**
     * test_construct_backup_plan_settings_terminates_with_error_when_anonymize_are_set_to_true_and_lacks_capability.
     */
    // phpcs:ignore moodle.Files.LineLength.TooLong
    public function test_construct_backup_plan_settings_terminates_with_error_when_anonymize_are_set_to_true_and_lacks_capability(): void {
        if (!class_exists('core\exception\required_capability_exception')) {
            $this->markTestSkipped(
                'Skipping test. Required class core\exception\required_capability_exception ' .
                'does not exist in this version of moodle'
            );
        }

        $this->customdata1->backupsettings["users"] = true;
        $this->customdata1->backupsettings["anonymize"] = true;
        $this->customdata1->item["old_instance_id"] = $this->section1Course1->id;
        $this->customdata1->item["type"] = "section";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course1->id);

        $this->expectException(core_required_capability_exception::class);

        $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);
    }

    /**
     * generate_custom_datas.
     */
    protected function generate_custom_datas() {
        $this->customdata1 = (object)[
            'backupid' => '12cee540508d23de30d78bdf906611f4',
            'item' => [
                'id' => 0,
                'user_id' => 2,
                'file_id' => null,
                'parent_item_id' => null,
                'old_instance_id' => 0,
                'type' => '',
                'name' => 'B1',
                'status' => 0,
                'sortorder' => null,
                'original_course_fullname' => null,
                'version' => 3,
                'timecreated' => 1769697663,
                'timemodified' => 1769697663,
            ],
            'backup_settings' => [
                'users' => false,
                'anonymize' => false,
            ],
        ];
    }

    /**
     * Generate course fixtures for the tests.
     *
     * @return void
     */
    protected function generate_courses(): void {
        $db = $this->basefactory->moodle()->db();

        // Course1.
        $this->course1 = self::getDataGenerator()->create_course();
        $this->section1Course1 = $db->get_record('course_sections', ['course' => $this->course1->id, 'section' => 0]);
        $this->page1Course1 = self::getDataGenerator()->create_module('page', [
            'course' => $this->course1->id,
            'section' => $this->section1Course1->section,
        ]);
        $this->book1Course1 = self::getDataGenerator()->create_module('book', [
            'course' => $this->course1->id,
            'section' => $this->section1Course1->section,
        ]);
        $this->section2Course1 = $db->get_record('course_sections', ['course' => $this->course1->id, 'section' => 1]);
        $this->page2Course1 = self::getDataGenerator()->create_module('page', [
            'course' => $this->course1->id,
            'section' => $this->section2Course1->section,
        ]);
    }

    /**
     * get_module_include
     *
     * @param object $module
     * @param string $modulename
     * @return string
     */
    protected function get_module_include(object $module, string $modulename): string {
        return $modulename . '_' . $module->cmid . '_included';
    }

    /**
     * get_module_userinfo
     *
     * @param object $module
     * @param string $modulename
     * @return string
     */
    private function get_module_userinfo(object $module, string $modulename): string {
        return  $modulename . '_' . $module->cmid . '_userinfo';
    }

    /**
     * get_section_include
     *
     * @param object $section
     * @return string
     */
    protected function get_section_include(object $section): string {
        return 'section_' . $section->id . '_included';
    }

    /**
     * get_section_userinfo
     *
     * @param object $section
     * @return string
     */
    private function get_section_userinfo(object $section): string {
        return 'section_' . $section->id . '_userinfo';
    }
}
