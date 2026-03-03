<?php

namespace integration\app\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\backup\backup_settings_helper;

class backup_settings_helper_subsections_test extends \advanced_testcase
{

    protected backup_settings_helper $helper;

    protected base_factory $base_factory;

    protected object $custom_data_1;
    protected object $course_2;
    protected object $course_3;
    protected object $section_1_course_2;
    protected object $subsection_1_course_2;
    protected object $section_1_course_3;
    protected object $forum_1_course_2;
    private object $subsection_module_1_course_2;
    private object $book_1_under_subsection_1_course_2;
    private object $subsection_parent_1_course_3;
    private object $subsection_module_1_course_3;
    private object $book_1_under_subsection_1_course_3;
    private object $quiz_1_under_subsection_1_course_3;
    private object $forum_1_under_subsection_1_course_3;
    private object $subsection_1_hidden_section_course_3;
    private object $forum_1_course_3;

    protected function setUp(): void
    {
        global $CFG;

        if(!$this->is_plugin_installed("block_sharing_cart") || $CFG->version < 2024100700){
            $this->markTestSkipped("Skipping tests. Subsections are unsupported.");
        }

        $this->resetAfterTest();
        $this->base_factory = base_factory::make();
        $this->helper = $this->base_factory->backup()->settings_helper();

        $this->generate_courses();
        $this->generate_custom_datas();
    }

    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_section_is_specified(){

        $this->custom_data_1->item["old_instance_id"] = $this->forum_1_course_2->cmid;
        $this->custom_data_1->item["type"] = "mod_forum";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_2->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum_1_course_2,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum_1_course_2,'forum')]);
    }

    public function test_construct_backup_plan_settings_includes_activity_when_an_activity_that_lies_in_subsection_is_specified(){

        $this->custom_data_1->item["old_instance_id"] = $this->book_1_under_subsection_1_course_2->cmid;
        $this->custom_data_1->item["type"] = "mod_book";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_2->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book_1_under_subsection_1_course_2,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book_1_under_subsection_1_course_2,'book')]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->subsection_module_1_course_2,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsection_module_1_course_2,'subsection')]);

    }

    //Subsections have corresponding "hidden" sections that must be included, aswell as the "real" parent section.
    public function test_construct_backup_plan_settings_includes_parent_section_when_a_subsection_is_specified_and_the_subsection_section_and_its_child_modules(){

        $this->custom_data_1->item["old_instance_id"] = $this->subsection_1_hidden_section_course_3->id; //must point to subsection section id, not the parent
        $this->custom_data_1->item["type"] = "mod_subsection";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_3->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertFalse($backup_plan_settings[$this->get_section_include($this->section_1_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section_1_course_3)]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->forum_1_course_3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum_1_course_3,'forum')]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection_parent_1_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection_parent_1_course_3)]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection_1_hidden_section_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection_1_hidden_section_course_3)]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->subsection_module_1_course_3,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsection_module_1_course_3,'subsection')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book_1_under_subsection_1_course_3,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book_1_under_subsection_1_course_3,'book')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->quiz_1_under_subsection_1_course_3,'quiz')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->quiz_1_under_subsection_1_course_3,'quiz')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum_1_under_subsection_1_course_3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum_1_under_subsection_1_course_3,'forum')]);

    }

    //In a course with multiple sections, only the specified section should be included (including it's child modules and modules nested in subsections) and the others excluded.
    public function test_construct_backup_plan_settings_includes_only_the_specified_section_and_its_children_modules_and_nested_child_modules_of_subsections(){

        $this->custom_data_1->item["old_instance_id"] = $this->subsection_parent_1_course_3->id;
        $this->custom_data_1->item["type"] = "section";

        $item = $this->base_factory->item()->entity((object)$this->custom_data_1->item);
        $backup_controller_context = \core\context\course::instance($this->course_3->id);

        $backup_plan_settings = $this->helper->construct_backup_plan_settings($this->custom_data_1,$backup_controller_context,$item);

        $this->assertFalse($backup_plan_settings[$this->get_section_include($this->section_1_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->section_1_course_3)]);

        $this->assertFalse($backup_plan_settings[$this->get_module_include($this->forum_1_course_3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum_1_course_3,'forum')]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection_parent_1_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection_parent_1_course_3)]);

        $this->assertTrue($backup_plan_settings[$this->get_section_include($this->subsection_1_hidden_section_course_3)]);
        $this->assertFalse($backup_plan_settings[$this->get_section_userinfo($this->subsection_1_hidden_section_course_3)]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->subsection_module_1_course_3,'subsection')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->subsection_module_1_course_3,'subsection')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->book_1_under_subsection_1_course_3,'book')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->book_1_under_subsection_1_course_3,'book')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->quiz_1_under_subsection_1_course_3,'quiz')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->quiz_1_under_subsection_1_course_3,'quiz')]);

        $this->assertTrue($backup_plan_settings[$this->get_module_include($this->forum_1_under_subsection_1_course_3,'forum')]);
        $this->assertFalse($backup_plan_settings[$this->get_module_userinfo($this->forum_1_under_subsection_1_course_3,'forum')]);

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

        // Course2
        $this->course_2 = self::getDataGenerator()->create_course();
        $this->section_1_course_2 = $db->get_record('course_sections',['course' => $this->course_2->id,'section' => 0]);
        $this->subsection_1_course_2 = $db->get_record('course_sections',['course' => $this->course_2->id,'section' => 1]);

        $this->subsection_module_1_course_2 = self::getDataGenerator()->create_module('subsection',['course'=> $this->course_2->id,'section' => $this->section_1_course_2->section]);
        $this->forum_1_course_2 = self::getDataGenerator()->create_module('forum',['course'=> $this->course_2->id,'section' => $this->section_1_course_2->section]);
        $this->book_1_under_subsection_1_course_2 = self::getDataGenerator()->create_module('book',['course'=> $this->course_2->id,'section' => $this->subsection_1_course_2->section]);


        // Course3
        $this->course_3 = self::getDataGenerator()->create_course();
        $this->section_1_course_3 = $db->get_record('course_sections',['course' => $this->course_3->id,'section' => 0]);
        $this->subsection_parent_1_course_3 = $db->get_record('course_sections',['course' => $this->course_3->id,'section' => 1]);

        $this->subsection_module_1_course_3 = self::getDataGenerator()->create_module('subsection',['course'=> $this->course_3->id,'section' => $this->subsection_parent_1_course_3->section]);
        $subsection_module_3_1_instance = $db->get_record('course_modules', ['id' => $this->subsection_module_1_course_3->cmid]);
        $this->subsection_1_hidden_section_course_3 = $db->get_record('course_sections',['itemid' =>$subsection_module_3_1_instance->instance]);

        $this->book_1_under_subsection_1_course_3 = self::getDataGenerator()->create_module('book',['course'=> $this->course_3->id,'section' => $this->subsection_1_hidden_section_course_3->section]);
        $this->quiz_1_under_subsection_1_course_3 = self::getDataGenerator()->create_module('quiz',['course'=> $this->course_3->id,'section' => $this->subsection_1_hidden_section_course_3->section]);
        $this->forum_1_under_subsection_1_course_3 = self::getDataGenerator()->create_module('forum',['course'=> $this->course_3->id,'section' => $this->subsection_1_hidden_section_course_3->section]);

        $this->forum_1_course_3 = self::getDataGenerator()->create_module('forum',['course'=> $this->course_3->id,'section' => $this->section_1_course_3->section]);
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

    public function is_plugin_installed(string $component): bool
    {
        return \core_plugin_manager::instance()->get_plugin_info($component) !== null;
    }

}