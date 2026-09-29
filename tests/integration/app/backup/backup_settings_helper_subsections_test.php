<?php
// phpcs:disable moodle.Files.LineLength.TooLong
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
 * backup_settings_helper_subsections_test.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_sharing_cart;

use block_sharing_cart\app\factory as basefactory;
use block_sharing_cart\app\backup\backup_settings_helper;

/**
 * backup_settings_helper_subsections_test class.
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \block_sharing_cart\app\backup\backup_settings_helper
 */
final class backup_settings_helper_subsections_test extends \advanced_testcase
{
    /** @var backup_settings_helper $helper */
    protected backup_settings_helper $helper;

    /** @var basefactory $basefactory */
    protected basefactory $basefactory;

    /** @var object $customdata1 */
    protected object $customdata1;
    /** @var object $course2 */
    protected object $course2;
    /** @var object $course3 */
    protected object $course3;
    /** @var object $section1course2 */
    protected object $section1course2;
    /** @var object $subsection1course2 */
    protected object $subsection1course2;
    /** @var object $section1course3 */
    protected object $section1course3;
    /** @var object $forum1course2 */
    protected object $forum1course2;
    /** @var object $subsectionmodule1course2 */
    private object $subsectionmodule1course2;
    /** @var object $book1undersubsection1course2 */
    private object $book1undersubsection1course2;
    /** @var object $subsectionparent1course3 */
    private object $subsectionparent1course3;
    /** @var object $subsectionmodule1course3 */
    private object $subsectionmodule1course3;
    /** @var object $book1undersubsection1course3 */
    private object $book1undersubsection1course3;
    /** @var object $quiz1undersubsection1course3 */
    private object $quiz1undersubsection1course3;
    /** @var object $forum1undersubsection1course3 */
    private object $forum1undersubsection1course3;
    /** @var object $subsection1hiddensectioncourse3 */
    private object $subsection1hiddensectioncourse3;
    /** @var object $forum1course3 */
    private object $forum1course3;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;

        if (!$this->is_plugin_installed("block_sharing_cart") || $CFG->version < 2024100700) {
            $this->markTestSkipped("Skipping tests. Subsections are unsupported.");
        }

        $this->resetAfterTest();
        $this->basefactory = basefactory::make();
        $this->helper = $this->basefactory->backup()->settings_helper();

        $this->generate_courses();
        $this->generate_custom_datas();
    }

    // Test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_section_is_specified.
    /**
     * test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_section_is_specified.
     *
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     */
    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_section_is_specified(): void {

        $this->customdata1->item["old_instance_id"] = $this->forum1course2->cmid;
        $this->customdata1->item["type"] = "mod_forum";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course2->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->forum1course2, 'forum')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->forum1course2, 'forum')]);
    }

    // Test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_subsection_is_specified.
    /**
     * test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_subsection_is_specified.
     *
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     */
    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_subsection_is_specified(): void {

        $this->customdata1->item["old_instance_id"] = $this->book1undersubsection1course2->cmid;
        $this->customdata1->item["type"] = "mod_book";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course2->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->book1undersubsection1course2, 'book')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->book1undersubsection1course2, 'book')]);

        $this->assertFalse($backupplansettings[$this->get_module_include($this->subsectionmodule1course2, 'subsection')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->subsectionmodule1course2, 'subsection')]);
    }

    // Subsections have corresponding "hidden" sections that must be included, aswell as the "real" parent section.
    // Test_construct_backup_plan_settings_includes_parent_section_when_a_subsection_is_specified_and_the_subsection_section_and_its_child_modules.
    /**
     * test_construct_backup_plan_settings_includes_parent_section_when_a_subsection_is_specified_and_the_subsection_section_and_its_child_modules.
     *
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     */

    public function test_construct_backup_plan_settings_includes_parent_section_when_a_subsection_is_specified_and_the_subsection_section_and_its_child_modules(): void {

        $this->customdata1->item["old_instance_id"] = $this->subsection1hiddensectioncourse3->id; // Must point to subsection section id, not the parent.
        $this->customdata1->item["type"] = "mod_subsection";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course3->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        $this->assertFalse($backupplansettings[$this->get_section_include($this->section1course3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->section1course3)]);

        $this->assertFalse($backupplansettings[$this->get_module_include($this->forum1course3, 'forum')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->forum1course3, 'forum')]);

        $this->assertTrue($backupplansettings[$this->get_section_include($this->subsectionparent1course3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->subsectionparent1course3)]);

        $this->assertTrue($backupplansettings[$this->get_section_include($this->subsection1hiddensectioncourse3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->subsection1hiddensectioncourse3)]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->subsectionmodule1course3, 'subsection')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->subsectionmodule1course3, 'subsection')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->book1undersubsection1course3, 'book')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->book1undersubsection1course3, 'book')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->quiz1undersubsection1course3, 'quiz')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->quiz1undersubsection1course3, 'quiz')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->forum1undersubsection1course3, 'forum')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->forum1undersubsection1course3, 'forum')]);
    }

    // In a course with multiple sections, only the specified section should be included (including it's child modules and modules nested in subsections) and the others excluded.
    // Test_construct_backup_plan_settings_includes_only_the_specified_section_and_its_children_modules_and_nested_child_modules_of_subsections.
    /**
     * test_construct_backup_plan_settings_includes_only_the_specified_section_and_its_children_modules_and_nested_child_modules_of_subsections.
     *
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     * @covers \block_sharing_cart\app\backup\backup_settings_helper
     */

    public function test_construct_backup_plan_settings_includes_only_the_specified_section_and_its_children_modules_and_nested_child_modules_of_subsections(): void {

        $this->customdata1->item["old_instance_id"] = $this->subsectionparent1course3->id;
        $this->customdata1->item["type"] = "section";

        $item = $this->basefactory->item()->entity((object)$this->customdata1->item);
        $backupcontrollercontext = \core\context\course::instance($this->course3->id);

        $backupplansettings = $this->helper->construct_backup_plan_settings($this->customdata1, $backupcontrollercontext, $item);

        $this->assertFalse($backupplansettings[$this->get_section_include($this->section1course3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->section1course3)]);

        $this->assertFalse($backupplansettings[$this->get_module_include($this->forum1course3, 'forum')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->forum1course3, 'forum')]);

        $this->assertTrue($backupplansettings[$this->get_section_include($this->subsectionparent1course3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->subsectionparent1course3)]);

        $this->assertTrue($backupplansettings[$this->get_section_include($this->subsection1hiddensectioncourse3)]);
        $this->assertFalse($backupplansettings[$this->get_section_userinfo($this->subsection1hiddensectioncourse3)]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->subsectionmodule1course3, 'subsection')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->subsectionmodule1course3, 'subsection')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->book1undersubsection1course3, 'book')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->book1undersubsection1course3, 'book')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->quiz1undersubsection1course3, 'quiz')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->quiz1undersubsection1course3, 'quiz')]);

        $this->assertTrue($backupplansettings[$this->get_module_include($this->forum1undersubsection1course3, 'forum')]);
        $this->assertFalse($backupplansettings[$this->get_module_userinfo($this->forum1undersubsection1course3, 'forum')]);
    }
    // Generate_custom_datas.
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
     * generate_courses
     *
     * @return void
     */
    protected function generate_courses(): void {
        $db = $this->basefactory->moodle()->db();

        // Course2.
        $this->course2 = self::getDataGenerator()->create_course();
        $this->section1course2 = $db->get_record('course_sections', ['course' => $this->course2->id, 'section' => 0]);
        $this->subsection1course2 = $db->get_record('course_sections', ['course' => $this->course2->id, 'section' => 1]);

        $this->subsectionmodule1course2 = self::getDataGenerator()->create_module(
            'subsection',
            ['course' => $this->course2->id, 'section' => $this->section1course2->section]
        );
        $this->forum1course2 = self::getDataGenerator()->create_module(
            'forum',
            ['course' => $this->course2->id, 'section' => $this->section1course2->section]
        );
        $this->book1undersubsection1course2 = self::getDataGenerator()->create_module(
            'book',
            ['course' => $this->course2->id, 'section' => $this->subsection1course2->section]
        );

        // Course3.
        $this->course3 = self::getDataGenerator()->create_course();
        $this->section1course3 = $db->get_record('course_sections', ['course' => $this->course3->id, 'section' => 0]);
        $this->subsectionparent1course3 = $db->get_record('course_sections', ['course' => $this->course3->id, 'section' => 1]);

        $this->subsectionmodule1course3 = self::getDataGenerator()->create_module(
            'subsection',
            ['course' => $this->course3->id, 'section' => $this->subsectionparent1course3->section]
        );
        $subsectionmodule31instance = $db->get_record('course_modules', ['id' => $this->subsectionmodule1course3->cmid]);
        $this->subsection1hiddensectioncourse3 = $db->get_record('course_sections', ['itemid' => $subsectionmodule31instance->instance]);

        $this->book1undersubsection1course3 = self::getDataGenerator()->create_module(
            'book',
            ['course' => $this->course3->id, 'section' => $this->subsection1hiddensectioncourse3->section]
        );
        $this->quiz1undersubsection1course3 = self::getDataGenerator()->create_module(
            'quiz',
            ['course' => $this->course3->id, 'section' => $this->subsection1hiddensectioncourse3->section]
        );
        $this->forum1undersubsection1course3 = self::getDataGenerator()->create_module(
            'forum',
            ['course' => $this->course3->id, 'section' => $this->subsection1hiddensectioncourse3->section]
        );

        $this->forum1course3 = self::getDataGenerator()->create_module(
            'forum',
            ['course' => $this->course3->id, 'section' => $this->section1course3->section]
        );
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

    /**
     * is_plugin_installed
     *
     * @param string $component
     * @return bool
     */
    public function is_plugin_installed(string $component): bool {
        return \core_plugin_manager::instance()->get_plugin_info($component) !== null;
    }
}
