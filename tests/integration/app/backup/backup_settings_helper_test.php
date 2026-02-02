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

    protected object $course1;
    protected object $course2;
    protected object $course3;

    protected object $section1Course1;
    protected object $section2Course1;
    protected object $section1Course2;
    protected object $subsection1Course2;
    protected object $section1Course3;
    protected object $page1Course1;
    protected object $book1Course1;
    protected object $page2Course1;
    protected object $forum1Course2;
    private \stdClass $subsectionModule1Course2;
    private \stdClass $book1UnderSubsection1Course2;
    private \stdClass $subsectionParent1Course3;
    private \stdClass $subsectionModule1Course3;
    private \stdClass $book1UnderSubsection1Course3;
    private \stdClass $quiz1UnderSubsection1Course3;
    private \stdClass $forum1UnderSubsection1Course3;
    private mixed $subsection1HiddenSectionCourse3;
    private \stdClass $forum1Course3;

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
        $this->custom_data_1->item["old_instance_id"] = $this->section1Course1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section1Course1)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section1Course1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->page1Course1,'page')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->page1Course1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book1Course1,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book1Course1,'book')]);

        $item->set_old_instance_id($this->section2Course1->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section2Course1)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section2Course1)]);

    }

    public function test_construct_backup_plan_settings_sets_all_sections_and_modules_to_include_users_when_users_are_set_to_true_and_user_has_capability(): void
    {
        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section1Course1->id;
        $this->custom_data_1->item["type"] = "section";

        $this->setAdminUser();

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section1Course1)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section1Course1)]);

        // Module asserts
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->page1Course1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->page1Course1,'page')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book1Course1,'book')]);
        $this->assertTrue($backup_plan_settings[$this->get_module_userinfo($this->book1Course1, 'book')]);

        $item->set_old_instance_id($this->section2Course1->id);
        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        // Section asserts
        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->section2Course1)]);
        $this->assertTrue($backup_plan_settings[$this->get_section_userinfo($this->section2Course1)]);

    }

    public function test_construct_backup_plan_settings_terminates_with_error_when_users_are_set_to_true_and_lacks_capability(){

        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section1Course1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $this->expectException(core_required_capability_exception::class);

        $_ = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

    }

    public function test_construct_backup_plan_settings_terminates_with_error_when_anonymize_are_set_to_true_and_lacks_capability(){

        $this->custom_data_1->backup_settings["users"] = true;
        $this->custom_data_1->backup_settings["anonymize"] = true;
        $this->custom_data_1->item["old_instance_id"] = $this->section1Course1->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course1->id);

        $this->expectException(core_required_capability_exception::class);

        $_ = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

    }

    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_section_is_specified(){

        $this->custom_data_1->item["old_instance_id"] = $this->forum1Course2->cmid;
        $this->custom_data_1->item["type"] = "mod_forum";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course2->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum1Course2,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum1Course2,'forum')]);
    }

    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_subsection_is_specified(){

        $this->custom_data_1->item["old_instance_id"] = $this->book1UnderSubsection1Course2->cmid;
        $this->custom_data_1->item["type"] = "mod_book";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course2->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book1UnderSubsection1Course2,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book1UnderSubsection1Course2,'book')]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->subsectionModule1Course2,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsectionModule1Course2,'subsection')]);

    }

    //Subsections have corresponding "hidden" sections that must be included, aswell as the "real" parent section.
    public function test_construct_backup_plan_settings_includes_parent_section_when_a_subsection_is_specified_and_the_subsection_section_and_its_child_modules(){

        $this->custom_data_1->item["old_instance_id"] = $this->subsection1HiddenSectionCourse3->id; //must point to subsection section id, not the parent
        $this->custom_data_1->item["type"] = "mod_subsection";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course3->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertFalse($backup_plan_settings[$this->get_section_include($this->section1Course3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section1Course3)]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->forum1Course3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum1Course3,'forum')]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsectionParent1Course3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsectionParent1Course3)]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection1HiddenSectionCourse3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection1HiddenSectionCourse3)]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->subsectionModule1Course3,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsectionModule1Course3,'subsection')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book1UnderSubsection1Course3,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book1UnderSubsection1Course3,'book')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->quiz1UnderSubsection1Course3,'quiz')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->quiz1UnderSubsection1Course3,'quiz')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum1UnderSubsection1Course3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum1UnderSubsection1Course3,'forum')]);

    }

    //In a course with multiple sections, only the specified section should be included (including it's child modules and modules nested in subsections) and the others excluded.
    public function test_construct_backup_plan_settings_includes_only_the_specified_section_and_its_children_modules_and_nested_child_modules_of_subsections(){

        $this->custom_data_1->item["old_instance_id"] = $this->subsectionParent1Course3->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course3->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertFalse($backup_plan_settings[$this->get_section_include($this->section1Course3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section1Course3)]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->forum1Course3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum1Course3,'forum')]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsectionParent1Course3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsectionParent1Course3)]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection1HiddenSectionCourse3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection1HiddenSectionCourse3)]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->subsectionModule1Course3,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsectionModule1Course3,'subsection')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book1UnderSubsection1Course3,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book1UnderSubsection1Course3,'book')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->quiz1UnderSubsection1Course3,'quiz')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->quiz1UnderSubsection1Course3,'quiz')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum1UnderSubsection1Course3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum1UnderSubsection1Course3,'forum')]);

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
        $this->section1Course1 = $db->get_record('course_sections',['course' => $this->course1->id,'section' => 0]);
        $this->page1Course1 = self::getDataGenerator()->create_module('page',['course'=> $this->course1->id,'section' => $this->section1Course1->section]);
        $this->book1Course1 = self::getDataGenerator()->create_module('book',['course'=> $this->course1->id, 'section' => $this->section1Course1->section]);
        $this->section2Course1 = $db->get_record('course_sections',['course' => $this->course1->id,'section' => 1]);
        $this->page2Course1 = self::getDataGenerator()->create_module('page',['course'=> $this->course1->id,'section' => $this->section2Course1->section]);


        // Course2
        $this->course2 = self::getDataGenerator()->create_course();
        $this->section1Course2 = $db->get_record('course_sections',['course' => $this->course2->id,'section' => 0]);
        $this->subsection1Course2 = $db->get_record('course_sections',['course' => $this->course2->id,'section' => 1]);

        $this->subsectionModule1Course2 = self::getDataGenerator()->create_module('subsection',['course'=> $this->course2->id,'section' => $this->section1Course2->section]);
        $this->forum1Course2 = self::getDataGenerator()->create_module('forum',['course'=> $this->course2->id,'section' => $this->section1Course2->section]);
        $this->book1UnderSubsection1Course2 = self::getDataGenerator()->create_module('book',['course'=> $this->course2->id,'section' => $this->subsection1Course2->section]);


        // Course3
        $this->course3 = self::getDataGenerator()->create_course();
        $this->section1Course3 = $db->get_record('course_sections',['course' => $this->course3->id,'section' => 0]);
        $this->subsectionParent1Course3 = $db->get_record('course_sections',['course' => $this->course3->id,'section' => 1]);

        $this->subsectionModule1Course3 = self::getDataGenerator()->create_module('subsection',['course'=> $this->course3->id,'section' => $this->subsectionParent1Course3->section]);
        $subsection_module_3_1_instance = $db->get_record('course_modules', ['id' => $this->subsectionModule1Course3->cmid]);
        $this->subsection1HiddenSectionCourse3 = $db->get_record('course_sections',['itemid' =>$subsection_module_3_1_instance->instance]);

        $this->book1UnderSubsection1Course3 = self::getDataGenerator()->create_module('book',['course'=> $this->course3->id,'section' => $this->subsection1HiddenSectionCourse3->section]);
        $this->quiz1UnderSubsection1Course3 = self::getDataGenerator()->create_module('quiz',['course'=> $this->course3->id,'section' => $this->subsection1HiddenSectionCourse3->section]);
        $this->forum1UnderSubsection1Course3 = self::getDataGenerator()->create_module('forum',['course'=> $this->course3->id,'section' => $this->subsection1HiddenSectionCourse3->section]);

        $this->forum1Course3 = self::getDataGenerator()->create_module('forum',['course'=> $this->course3->id,'section' => $this->section1Course3->section]);
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

