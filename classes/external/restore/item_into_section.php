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

namespace block_sharing_cart\external\restore;

use block_sharing_cart\app\factory;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * item_into_section external API.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_into_section extends external_api
{
    /**
     * execute_parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'sectionid' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'coursemodulestoinclude' => new external_multiple_structure(
                new external_value(PARAM_INT, '', VALUE_REQUIRED)
            ),
            'beforecmid' => new external_value(
                PARAM_INT,
                'Place the restored activities before this course module in the section (0 = end of the section)',
                VALUE_DEFAULT,
                0
            ),
            'asnewsection' => new external_value(
                PARAM_BOOL,
                'Restore a section item into a new section created after sectionid',
                VALUE_DEFAULT,
                false
            ),
            'sectionstoinclude' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Original ids of nested sections to restore; empty means all', VALUE_REQUIRED),
                '',
                VALUE_DEFAULT,
                []
            ),
            'insertasnewsection' => new external_value(
                PARAM_BOOL,
                'Create the copied section as a new section under sectionid (0 = top level of courseid)' .
                    ' instead of merging into it',
                VALUE_DEFAULT,
                false
            ),
            'courseid' => new external_value(PARAM_INT, 'Required when sectionid is 0', VALUE_DEFAULT, 0),
            'replacesectiondetails' => new external_value(
                PARAM_BOOL,
                'When merging into sectionid, replace its title and description with the copied section\'s',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * execute.
     * @param int $itemid
     * @param int $sectionid
     * @param array $coursemodulestoinclude
     * @param int $beforecmid
     * @param bool $asnewsection whether to create a section after the target for drag/drop
     * @param array $sectionstoinclude
     * @param bool $insertasnewsection whether the copied section itself becomes a new section
     * @param int $courseid
     * @param bool $replacesectiondetails
     */
    public static function execute(
        int $itemid,
        int $sectionid,
        array $coursemodulestoinclude,
        int $beforecmid = 0,
        bool $asnewsection = false,
        array $sectionstoinclude = [],
        bool $insertasnewsection = false,
        int $courseid = 0,
        bool $replacesectiondetails = false
    ): bool {
        global $USER, $DB;

        $basefactory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'itemid' => $itemid,
            'sectionid' => $sectionid,
            'coursemodulestoinclude' => $coursemodulestoinclude,
            'beforecmid' => $beforecmid,
            'asnewsection' => $asnewsection,
            'sectionstoinclude' => $sectionstoinclude,
            'insertasnewsection' => $insertasnewsection,
            'courseid' => $courseid,
            'replacesectiondetails' => $replacesectiondetails,
        ]);

        if ($params['asnewsection'] && $params['insertasnewsection']) {
            throw new \invalid_parameter_exception('The two new-section restore options cannot be combined');
        }

        if ($params['sectionid'] > 0) {
            $courseid = (int)$DB->get_field('course_sections', 'course', ['id' => $params['sectionid']], MUST_EXIST);
        } else {
            // Top level of the course: only meaningful when inserting the copied section as a new section.
            if (!$params['insertasnewsection'] || $params['courseid'] <= 0) {
                return false;
            }
            $courseid = (int)$DB->get_field('course', 'id', ['id' => $params['courseid']], MUST_EXIST);
        }
        $context = \core\context\course::instance($courseid);

        self::validate_context($context);
        require_capability('moodle/restore:restoreactivity', $context);
        if ($params['asnewsection'] || $params['insertasnewsection']) {
            // Same capability core requires for adding a section.
            require_capability('moodle/course:update', $context);
        }

        if (
            $params['beforecmid'] && $params['sectionid'] > 0 && !$params['asnewsection'] &&
            !$DB->record_exists('course_modules', ['id' => $params['beforecmid'], 'section' => $params['sectionid']])
        ) {
            throw new \invalid_parameter_exception('beforecmid is not a course module in the target section');
        }

        $item = $basefactory->item()->repository()->get_by_id($params['itemid']);
        if (!$item) {
            return false;
        }

        if ($item->get_user_id() !== (int)$USER->id) {
            return false;
        }

        $settings = [
            'insert_as_new_section' => $params['insertasnewsection'],
            'replace_section_details' => $params['replacesectiondetails'],
        ];

        // Only pass include/exclude list when the user can configure restore in the target course context.
        if (has_capability('moodle/restore:configure', $context)) {
            $settings['course_modules_to_include'] = $params['coursemodulestoinclude'] ?? [];
            $settings['sections_to_include'] = $params['sectionstoinclude'] ?? [];
        }

        $result = $basefactory->restore()->handler()->restore_item_into_section(
            $item,
            $params['sectionid'],
            $params['itemid'],
            $settings,
            $params['beforecmid'],
            $params['asnewsection'],
            $courseid
        );

        return $result !== null;
    }

    /**
     * execute_returns.
     */
    public static function execute_returns(): external_description {
        return new external_value(PARAM_BOOL, '', VALUE_REQUIRED);
    }
}
