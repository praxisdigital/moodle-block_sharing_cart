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

// @codeCoverageIgnoreEnd


/**
 * Sharing Cart block class.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_sharing_cart extends block_base {
    public function init(): void {
        $this->title = get_string('pluginname', 'block_sharing_cart');
    }

    public function applicable_formats(): array {
        return [
            'all' => false,
            'course' => true,
            'site' => true
        ];
    }

    public function has_config(): bool {
        return true;
    }

    public function get_content(): object|string {
        global $OUTPUT, $USER, $COURSE;

        $basefactory = \block_sharing_cart\app\factory::make();

        if ($this->page->user_is_editing()) {
            $this->page->requires->css('/blocks/sharing_cart/style/style.css');
            $this->page->requires->strings_for_js([
                'copy_item',
                'confirm_copy_item',
                'confirm_copy_item_form_text',
                'into_section',
                'delete_item',
                'delete_items',
                'confirm_delete_item',
                'confirm_delete_items',
                'backup_without_user_data',
                'copy',
                'backup_item',
                'into_sharing_cart',
                'copy_user_data',
                'anonymize_user_data',
                'no_items',
                'run_now',
                'atleast_one_course_module_must_be_included',
                'no_course_modules_in_section',
                'no_course_modules_in_section_description',
                'select_all',
                'deselect_all',
            ], 'block_sharing_cart');
            $this->page->requires->strings_for_js([
                'delete',
                'cancel',
            ], 'core');
        }

        if ($this->content !== null) {
            return $this->content;
        }

        if (!$this->page->user_is_editing()) {
            return $this->content = '';
        }

        if (!has_capability(
                'moodle/backup:backupactivity',
                \context_course::instance($COURSE->id)
            ) &&
            !has_capability(
                'moodle/restore:restoreactivity',
                \context_course::instance($COURSE->id)
        )) {
            return $this->content = (object)[
                'text' => get_string('nopermissions', 'block_sharing_cart')
            ];
        }

        $template = new \block_sharing_cart\output\block\content($basefactory, $USER->id, $COURSE->id);

        return $this->content = (object)[
            'text' => $OUTPUT->render($template)
        ];
    }
}
