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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat tests for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_sharing_cart extends behat_base
{
    /**
     *
     * @Given /^I enable the sharing cart plugin$/
     */
    public function enable_sharing_cart_plugin() {
        $this->get_selected_node("xpath_element", "//a[@data-key='addblock']")->click();
        // Wait until the add block menu is ready.
        $this->get_selected_node("xpath_element", "//a[@data-blockname='sharing_cart']")->click();
    }

    /**
     * Queues a section backup with a test-only nested custom-section tree. The browser flow under test starts at the
     * import dialog, so no course-format plugin is required to provide the custom hierarchy.
     *
     * @When /^I queue section number "(?P<sectionnumber>\d+)" with custom child section "(?P<childnumber>\d+)" in the Sharing Cart$/
     * @param int $sectionnumber root section number
     * @param int $childnumber custom child section number
     * @return void
     */
    public function queue_section_with_custom_child_in_sharing_cart(int $sectionnumber, int $childnumber): void {
        global $DB, $USER;

        $rootsectionnode = $this->get_selected_node(
            'css_element',
            '.course-content li[data-for="section"][data-number="' . $sectionnumber . '"]'
        );
        $childsectionnode = $this->get_selected_node(
            'css_element',
            '.course-content li[data-for="section"][data-number="' . $childnumber . '"]'
        );
        $rootsectionid = (int)$rootsectionnode->getAttribute('data-id');
        $childsectionid = (int)$childsectionnode->getAttribute('data-id');
        $rootsection = $DB->get_record('course_sections', ['id' => $rootsectionid], '*', MUST_EXIST);
        $childsection = $DB->get_record('course_sections', ['id' => $childsectionid], '*', MUST_EXIST);
        $rootsection->name = 'Root section';
        $childsection->name = 'Custom child';
        $DB->set_field('course_sections', 'name', $rootsection->name, ['id' => $rootsectionid]);
        $DB->set_field('course_sections', 'name', $childsection->name, ['id' => $childsectionid]);

        $resolvecustomtree = new \block_sharing_cart\hook\backup\resolve_section_tree(
            (int)$rootsection->course,
            $rootsectionid
        );
        $resolvecustomtree->add_child($childsectionid, $rootsectionid, 1);
        $courseformat = course_get_format((int)$rootsection->course);
        $sectiontree = array_map(static function (object $node) use ($childsection, $courseformat): array {
            $name = (string)$childsection->name !== ''
                ? format_string($childsection->name)
                : $courseformat->get_section_name($childsection);

            return [
                'section_id' => (int)$node->section_id,
                'parent_section_id' => (int)$node->parent_section_id,
                'sort_order' => (int)$node->sort_order,
                'name' => $name,
            ];
        }, $resolvecustomtree->get_tree());

        $basefactory = \block_sharing_cart\app\factory::make();
        $item = $basefactory->item()->repository()->insert_section(
            $rootsection,
            (int)$USER->id,
            null,
            \block_sharing_cart\app\item\entity::STATUS_AWAITING_BACKUP
        );
        $basefactory->backup()->handler()->backup_section(
            $rootsection,
            $item,
            ['users' => false, 'anonymize' => false],
            $sectiontree
        );

    }
}
