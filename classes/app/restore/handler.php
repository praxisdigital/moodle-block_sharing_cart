<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\task\asynchronous_restore_task;

class handler
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    /**
     * @param entity $item
     * @param int $section_id target section; 0 means the top level of $course_id (only with insert_as_new_section)
     * @param int $item_id
     * @param array $settings course_modules_to_include, sections_to_include, insert_as_new_section
     * @param int $course_id required when $section_id is 0
     */
    public function restore_item_into_section(
        entity $item,
        int $section_id,
        int $item_id,
        array $settings = [],
        int $course_id = 0
    ): asynchronous_restore_task|null {
        global $USER, $DB;

        if ($section_id > 0) {
            $course_id = (int)$DB->get_field('course_sections', 'course', ['id' => $section_id], MUST_EXIST);
        }

        if ($course_id <= 0) {
            return null;
        }

        $settings['move_to_section_id'] = $section_id;
        $settings['move_to_course_id'] = $course_id;

        $backup_file = $this->base_factory->item()->repository()->get_stored_file_by_item($item);
        if (!$backup_file) {
            throw new \Exception('Backup file not found for item (id: ' . $item->get_id() . ')');
        }

        if (!$this->restore_is_valid($item_id, $section_id, !empty($settings['insert_as_new_section']))) {
            return null;
        }

        $restore_controller = $this->base_factory->restore()->restore_controller(
            $backup_file,
            $course_id,
            $USER->id
        );

        return $this->queue_async_restore($restore_controller, $item, $settings);
    }

    /**
     * Restores are valid and to be queued only if they are valid according to the conditions in the function body.
     * The UI presents the user with the options of where to restore the item copied from the clipboard.
     * This function is the backend check of that logic.
     */
    private function restore_is_valid(int $item_id, int $target_section_id, bool $insert_as_new_section = false) : bool{

        global $DB;

        $sql = "SELECT
                I1.type AS own_type
                ,I2.type AS parent_type
                FROM {block_sharing_cart_items} AS I1
                LEFT JOIN {block_sharing_cart_items} AS I2 ON I1.parent_item_id = I2.id
                WHERE I1.id = :item_id";
        $params = [
            'item_id' => $item_id,
        ];

        $subject_item = $DB->get_record_sql($sql,$params,MUST_EXIST);

        // Creating a new section (top level or under a regular section) only makes sense for section items.
        if ($insert_as_new_section && $subject_item->own_type !== 'section') {
            return false;
        }

        // Top level of the course.
        if ($target_section_id === 0) {
            return $insert_as_new_section;
        }

        $target_section = $DB->get_record('course_sections', ['id' => $target_section_id], MUST_EXIST);

        $is_target_a_section = empty($target_section->component) && empty($target_section->itemid);
        $is_target_a_subsection = !empty($target_section->component) && $target_section->component == 'mod_subsection';

        //Attempt to restore a section into a non-section?
        if(!$is_target_a_section){
            if($subject_item->own_type === 'section'){return false;}
        }

        //Attempt to restore a subsection into a subsection?
        if($is_target_a_subsection){
            if($subject_item->own_type === 'mod_subsection'){return false;}

            //Attempt to restore a subsections's child into a subsection?
            if($subject_item->parent_type === 'mod_subsection'){return false;}
        }

        return true;
    }

    private function queue_async_restore(
        \restore_controller $restore_controller,
        entity $item,
        array $settings = []
    ): asynchronous_restore_task {
        $asynctask = new asynchronous_restore_task();
        $asynctask->set_custom_data([
            'backupid' => $restore_controller->get_restoreid(),
            'item' => $item->to_array(),
            'course_id' => $restore_controller->get_courseid(),
            'backup_settings' => $settings
        ]);
        $asynctask->set_userid($restore_controller->get_userid());
        \core\task\manager::queue_adhoc_task($asynctask);

        return $asynctask;
    }
}
