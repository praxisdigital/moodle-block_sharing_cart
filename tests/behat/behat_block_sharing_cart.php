<?php

namespace behat;

use behat_base;
use behat_hooks;

class behat_block_sharing_cart extends behat_base
{

    /**
     * @behat_hooks\BeforeScenario @setup_sharing_cart
     */
    public function before_sharing_cart_feature() {
/*        global $DB;

        $course_id = $DB->get_field("course", "id", ["shortname" => "C1"]);

        //Insert Section 1
        $section_1_id = $DB->insert_record("course_sections",[
            'course' => $course_id,
            'section' => 1,
            'name' => 'Section 1'
        ],true);

        $subsection_11_hidden_section_id = $DB->insert_record("course_sections",[
            'course' => $course_id,
            'section' => 2,
            'name' => 'Subsection 1.1',
            'component' => 'mod_subsection'
        ],true);

        //Insert Subsection 1.1 module into Section 1
        $DB->insert_record("course_modules",[
            'course' => $course_id,
            'section' => $section_1_id,
            'name' => 'Subsection 1.1',
            'module' => '20'
        ]);

        //Insert Book Activity into Subsection 1.1
        $DB->insert_record("course_modules",[
            'course' => $course_id,
            'section' => $section_1_id,
            'name' => 'Subsection 1.1'
        ]);*/

        //Insert New Section empty


    }


}