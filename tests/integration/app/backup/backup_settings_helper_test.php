<?php

namespace block_sharing_cart\integration\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\backup\backup_settings_helper;
use block_sharing_cart\app\item\entity;
use core\exception\required_capability_exception as core_required_capability_exception;
use \section_info as section_info;

class backup_settings_helper_test extends \advanced_testcase
{
    protected backup_settings_helper $helper;

    protected base_factory $base_factory;

    protected object $custom_data_1;

    protected object $course_1;


    protected object $section_1_course_1;
    protected object $section_2_course_1;
    protected object $page_1_course_1;
    protected object $book_1_course_1;
    protected object $page_2_course_1;

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
        $this->custom_data_1->item["old_instance_id"] = $this->section_1_course_1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section_1_course_1)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section_1_course_1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->page_1_course_1,'page')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->page_1_course_1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book_1_course_1,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book_1_course_1,'book')]);

        $item->set_old_instance_id($this->section_2_course_1->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section_2_course_1)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section_2_course_1)]);

    }

    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_include_users_when_users_are_set_to_true_and_user_has_capability(): void
    {
        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section_1_course_1->id;
        $this->custom_data_1->item["type"] = "section";

        $this->setAdminUser();

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section_1_course_1)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section_1_course_1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->page_1_course_1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->page_1_course_1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book_1_course_1,'book')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->book_1_course_1, 'book')]);

        $item->set_old_instance_id($this->section_2_course_1->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section_2_course_1)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section_2_course_1)]);

    }

    public function test_construct_backup_plan_settings_terminates_with_error_when_users_are_set_to_true_and_lacks_capability(){

        if(!class_exists('core\exception\required_capability_exception')){
            $this->markTestSkipped("Skipping test. Required class core\exception\required_capability_exception does not exist in this version of moodle");
        }

        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section_1_course_1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_1->id);

        $this->expectException(core_required_capability_exception::class);

        $_ = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

    }

    public function test_construct_backup_plan_settings_terminates_with_error_when_anonymize_are_set_to_true_and_lacks_capability(){

        if(!class_exists('core\exception\required_capability_exception')){
            $this->markTestSkipped("Skipping test. Required class core\exception\required_capability_exception does not exist in this version of moodle");
        }

        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->backup_settings["anonymize"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section_1_course_1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_1->id);

        $this->expectException(core_required_capability_exception::class);

        $_ = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

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
        $this->course_1 = self::getDataGenerator()->create_course();
        $this->section_1_course_1 = $db->get_record('course_sections',['course' => $this->course_1->id,'section' => 0]);
        $this->page_1_course_1 = self::getDataGenerator()->create_module('page',['course'=> $this->course_1->id,'section' => $this->section_1_course_1->section]);
        $this->book_1_course_1 = self::getDataGenerator()->create_module('book',['course'=> $this->course_1->id, 'section' => $this->section_1_course_1->section]);
        $this->section_2_course_1 = $db->get_record('course_sections',['course' => $this->course_1->id,'section' => 1]);
        $this->page_2_course_1 = self::getDataGenerator()->create_module('page',['course'=> $this->course_1->id,'section' => $this->section_2_course_1->section]);

    }

    protected function get_module_include(object $module, string $module_name): string
    {
        return $module_name.'_'. $module->cmid . '_included';
    }

    private function get_module_userinfo(object $module, string $module_name): string
    {
        return  $module_name.'_'.$module->cmid . '_userinfo';
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

