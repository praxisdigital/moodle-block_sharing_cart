<?php

namespace block_sharing_cart\integration\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\backup\backup_settings_helper;
use block_sharing_cart\app\item\entity;


class backup_settings_helper_test extends \advanced_testcase
{
    protected backup_settings_helper $helper;

    protected base_factory $base_factory;

    protected object $custom_data_1;

    protected object $course1;
    protected object $course2;
    protected object $course3;

    protected object $section1;
    protected object $section2;
    protected object $section3;
    protected object $section4;
    protected object $module1;
    protected object $module2;
    protected object $module3;
    protected object $module4;

    protected function setUp(): void
    {
        $this->resetAfterTest();
        $this->base_factory = base_factory::make();
        $this->helper = $this->base_factory->backup()->settings_helper();

        $this->generate_courses();
        $this->generate_custom_datas();
    }

    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_not_include_users_when_users_are_set_to_false(): void
    {
        $this->custom_data_1->backup_settings["users"] = false;
        $this->custom_data_1->item["old_instance_id"] = $this->section1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section1)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->module1)]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->module1)]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->module2)]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->module2)]);

        $item->set_old_instance_id($this->section2->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section2)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section2)]);

    }
    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_include_users_when_users_are_set_to_true_and_user_has_capability(): void
    {
        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section1->id;
        $this->custom_data_1->item["type"] = "section";

        $this->setAdminUser();

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section1)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->module1)]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->module1)]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->module2)]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->module2)]);

        $item->set_old_instance_id($this->section2->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section2)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section2)]);

    }

    public function test_construct_backup_plan_settings_includes_all_nested_child_modules_of_subsections_of_section(){

    }

    public function test_construct_backup_plan_settings_includes_only_specified_section_and_all_children_and_nested_child_modules_of_subsections(){

    }

    public function test_get_course_settings_by_item_using_section_item_with_users_true(): void
    {
        $item = $this->base_factory->item()->entity((object) []);
        $item->set_type('section');
        $item->set_old_instance_id($this->section1->id);

        $output = $this->helper->get_course_settings_by_item($item, true);

        // Section asserts
        $this->assertTrue($output[$this->get_section_include($this->section1)]);
        $this->assertTrue($output[$this->get_section_userinfo($this->section1)]);
        $this->assertFalse($output[$this->get_section_include($this->section2)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertTrue($output[$this->get_module_include($this->module1)]);
        $this->assertTrue($output[$this->get_module_userinfo($this->module1)]);
        $this->assertTrue($output[$this->get_module_include($this->module2)]);
        $this->assertTrue($output[$this->get_module_userinfo($this->module2)]);
        $this->assertFalse($output[$this->get_module_include($this->module3)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module3)]);


        $item->set_old_instance_id($this->section2->id);
        $output = $this->helper->get_course_settings_by_item($item, true);

        // Section asserts
        $this->assertFalse($output[$this->get_section_include($this->section1)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section1)]);
        $this->assertTrue($output[$this->get_section_include($this->section2)]);
        $this->assertTrue($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertFalse($output[$this->get_module_include($this->module1)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module1)]);
        $this->assertFalse($output[$this->get_module_include($this->module2)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module2)]);
        $this->assertTrue($output[$this->get_module_include($this->module3)]);
        $this->assertTrue($output[$this->get_module_userinfo($this->module3)]);
    }

    public function test_get_course_settings_by_item_using_activity_item_with_users_false(): void
    {
        $item = $this->base_factory->item()->entity((object) []);
        $item->set_type('page');
        $item->set_old_instance_id($this->module1->cmid);

        $output = $this->helper->get_course_settings_by_item($item, false);

        // Section asserts
        $this->assertTrue($output[$this->get_section_include($this->section1)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section1)]);
        $this->assertFalse($output[$this->get_section_include($this->section2)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertTrue($output[$this->get_module_include($this->module1)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module1)]);
        $this->assertFalse($output[$this->get_module_include($this->module2)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module2)]);
        $this->assertFalse($output[$this->get_module_include($this->module3)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module3)]);


        $item->set_old_instance_id($this->module2->cmid);
        $output = $this->helper->get_course_settings_by_item($item, false);

        // Section asserts
        $this->assertTrue($output[$this->get_section_include($this->section1)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section1)]);
        $this->assertFalse($output[$this->get_section_include($this->section2)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertFalse($output[$this->get_module_include($this->module1)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module1)]);
        $this->assertTrue($output[$this->get_module_include($this->module2)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module2)]);
        $this->assertFalse($output[$this->get_module_include($this->module3)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module3)]);
    }

    public function test_get_course_settings_by_item_using_activity_item_with_users_true(): void
    {
        $item = $this->base_factory->item()->entity((object) []);
        $item->set_type('page');
        $item->set_old_instance_id($this->module1->cmid);

        $output = $this->helper->get_course_settings_by_item($item, true);

        // Section asserts
        $this->assertTrue($output[$this->get_section_include($this->section1)]);
        $this->assertTrue($output[$this->get_section_userinfo($this->section1)]);
        $this->assertFalse($output[$this->get_section_include($this->section2)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertTrue($output[$this->get_module_include($this->module1)]);
        $this->assertTrue($output[$this->get_module_userinfo($this->module1)]);
        $this->assertFalse($output[$this->get_module_include($this->module2)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module2)]);
        $this->assertFalse($output[$this->get_module_include($this->module3)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module3)]);


        $item->set_old_instance_id($this->module2->cmid);
        $output = $this->helper->get_course_settings_by_item($item, true);

        // Section asserts
        $this->assertTrue($output[$this->get_section_include($this->section1)]);
        $this->assertTrue($output[$this->get_section_userinfo($this->section1)]);
        $this->assertFalse($output[$this->get_section_include($this->section2)]);
        $this->assertFalse($output[$this->get_section_userinfo($this->section2)]);

        // Module asserts
        $this->assertFalse($output[$this->get_module_include($this->module1)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module1)]);
        $this->assertTrue($output[$this->get_module_include($this->module2)]);
        $this->assertTrue($output[$this->get_module_userinfo($this->module2)]);
        $this->assertFalse($output[$this->get_module_include($this->module3)]);
        $this->assertFalse($output[$this->get_module_userinfo($this->module3)]);
    }


    protected function generate_custom_datas(){

        $this->custom_data_1 = (object)[
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
                'timemodified' => 1769697663
            ],
            'backup_settings' => [
                'users' => false,
                'anonymize' => false
            ]
        ];


    }

    protected function generate_courses(): void
    {
        $db = $this->base_factory->moodle()->db();

        //Course1
        $this->course1 = self::getDataGenerator()->create_course();

        $this->section1 = $db->get_record('course_sections',['course' => $this->course1->id,'section' => 0]);
        $this->module1 = self::getDataGenerator()->create_module('page',['course'=> $this->course1->id,'section' => $this->section1->section]);
        $this->module2 = self::getDataGenerator()->create_module('page',['course'=> $this->course1->id,'section' => $this->section1->section]);

        $this->section2 = $db->get_record('course_sections',['course' => $this->course1->id,'section' => 1]);
        $this->module3 = self::getDataGenerator()->create_module('page',['course'=> $this->course1->id,'section' => $this->section2->section]);

        // Course2
        $this->course2 = self::getDataGenerator()->create_course();

        $this->section3 = $db->get_record('course_sections',['course' => $this->course2->id,'section' => 0]);
        $this->module4 = self::getDataGenerator()->create_module('page',['course'=> $this->course2->id,'section' => $this->section3->section]);

        // Course3
        $this->course3 = self::getDataGenerator()->create_course();

        $this->section4 = $db->get_record('course_sections',['course' => $this->course3->id,'section' => 0]);
    }

    protected function get_module_include(object $module): string
    {
        return 'page_' . $module->cmid . '_included';
    }

    private function get_module_userinfo(object $module): string
    {
        return 'page_' . $module->cmid . '_userinfo';
    }

    protected function get_section_include(object $section): string
    {
        return 'section_' . $section->id . '_included';
    }

    private function get_section_userinfo(object $section): string
    {
        return 'section_' . $section->id . '_userinfo';
    }
}

