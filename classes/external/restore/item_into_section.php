<?php

namespace block_sharing_cart\external\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class item_into_section extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'item_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'section_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'course_modules_to_include' => new external_multiple_structure(
                new external_value(PARAM_INT, '', VALUE_REQUIRED)
            ),
            'sections_to_include' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Original ids of nested sections to restore; empty means all', VALUE_REQUIRED),
                '',
                VALUE_DEFAULT,
                []
            ),
            'insert_as_new_section' => new external_value(
                PARAM_BOOL,
                'Create the copied section as a new section under section_id (0 = top level of course_id) instead of merging into it',
                VALUE_DEFAULT,
                false
            ),
            'course_id' => new external_value(PARAM_INT, 'Required when section_id is 0', VALUE_DEFAULT, 0),
            'replace_section_details' => new external_value(
                PARAM_BOOL,
                'When merging into section_id, replace its title and description with the copied section\'s',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    public static function execute(
        int $item_id,
        int $section_id,
        array $course_modules_to_include,
        array $sections_to_include = [],
        bool $insert_as_new_section = false,
        int $course_id = 0,
        bool $replace_section_details = false
    ): bool {
        global $USER, $DB;

        $base_factory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'item_id' => $item_id,
            'section_id' => $section_id,
            'course_modules_to_include' => $course_modules_to_include,
            'sections_to_include' => $sections_to_include,
            'insert_as_new_section' => $insert_as_new_section,
            'course_id' => $course_id,
            'replace_section_details' => $replace_section_details,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        $item = $base_factory->item()->repository()->get_by_id($params['item_id']);
        if (!$item) {
            return false;
        }

        if ($item->get_user_id() !== (int)$USER->id) {
            return false;
        }

        if ($params['section_id'] > 0) {
            $course_id = (int)$DB->get_field('course_sections', 'course', ['id' => $params['section_id']], MUST_EXIST);
        } else {
            // Top level of the course: only meaningful when creating a new section.
            if (!$params['insert_as_new_section'] || $params['course_id'] <= 0) {
                return false;
            }
            $course_id = (int)$DB->get_field('course', 'id', ['id' => $params['course_id']], MUST_EXIST);
        }
        $context = \core\context\course::instance($course_id);

        $settings = [
            'insert_as_new_section' => $params['insert_as_new_section'],
            'replace_section_details' => $params['replace_section_details'],
        ];

        // Only pass include/exclude list when the user can configure restore in the target course context.
        if (has_capability('moodle/restore:configure', $context)) {
            $settings['course_modules_to_include'] = $params['course_modules_to_include'] ?? [];
            $settings['sections_to_include'] = $params['sections_to_include'] ?? [];
        }

        $result = $base_factory->restore()->handler()->restore_item_into_section(
            $item,
            $params['section_id'],
            $params['item_id'],
            $settings,
            $course_id
        );

        return $result !== null;
    }

    public static function execute_returns(): external_description
    {
        return new external_value(PARAM_BOOL, '', VALUE_REQUIRED);
    }
}
