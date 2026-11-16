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
     * Checks native container inclusion independently of the accessible descendant-selection summary.
     *
     * @Then /^the restore container "([^"]+)" is "([01])" with "(\d+)" of "(\d+)" descendants selected$/
     * @param string $title
     * @param string $included
     * @param int $selected
     * @param int $total
     */
    public function assert_restore_container_selection(string $title, string $included, int $selected, int $total): void {
        $titlejson = json_encode($title);
        $state = $this->getSession()->evaluateScript(<<<JS
            (() => {
                const checkbox = [...document.querySelectorAll('.import_form input[data-container="true"]')]
                    .find(input => input.dataset.title === $titlejson);
                if (!checkbox) {
                    return null;
                }
                const summary = checkbox.closest('.form-check')
                    .querySelector(':scope > [data-region="restore-descendant-selection"]');
                return {
                    checked: checkbox.checked,
                    indeterminate: checkbox.indeterminate,
                    nativeState: !checkbox.hasAttribute('aria-checked') ||
                        checkbox.getAttribute('aria-checked') === String(checkbox.checked),
                    described: Boolean(summary && summary.id &&
                        (checkbox.getAttribute('aria-describedby') || '').split(/\s+/).includes(summary.id) &&
                        document.getElementById(summary.id) === summary),
                    visible: Boolean(summary && summary.getClientRects().length),
                    live: summary?.getAttribute('aria-live'),
                    count: summary?.querySelector('[data-region="restore-descendant-count"]')?.textContent,
                };
            })()
            JS);
        $expected = [
            'checked' => $included === '1',
            'indeterminate' => false,
            'nativeState' => true,
            'described' => true,
            'visible' => true,
            'live' => 'polite',
            'count' => "$selected / $total",
        ];
        foreach ($expected as $key => $value) {
            if ($state === null || !array_key_exists($key, $state) || $state[$key] !== $value) {
                throw new \Behat\Mink\Exception\ExpectationException(
                    "Unexpected $key for restore container '$title': " . json_encode($state),
                    $this->getSession()
                );
            }
        }
    }

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
     * Simulates dragging a cart section to the lower edge of a top-level course section.
     *
     * @When /^I drag Sharing Cart section "(?P<itemname>[^\"]+)" after course section "(?P<sectionnumber>\d+)"$/
     * @param string $itemname
     * @param string $sectionnumber
     */
    public function drag_sharing_cart_section_after_course_section(string $itemname, string $sectionnumber): void {
        $script = '(function() {'
            . ' const itemname = ' . json_encode($itemname) . ';'
            . ' const sectionnumber = ' . json_encode($sectionnumber) . ';'
            . ' const item = Array.from(document.querySelectorAll(' .
                '"#block_sharing_cart .sharing_cart_items > .sharing_cart_item[data-type=section]"' .
            ')).find((candidate) => candidate.querySelector(":scope > .item-body .name")?.textContent.trim() === itemname);'
            . ' const target = document.querySelector(' .
                '"[data-for=course_sectionlist] > [data-for=section][data-number=\"" + sectionnumber + "\"]"' .
            ');'
            . ' if (!item || !target) { throw new Error("Could not find the Sharing Cart item or target section"); }'
            . ' const rect = target.getBoundingClientRect();'
            . ' const y = rect.bottom - 1;'
            . ' const transfer = {'
            . '   effectAllowed: "move", dropEffect: "none", types: [], files: [], items: [],'
            . '   setData: () => {}, getData: () => "", clearData: () => {}, setDragImage: () => {},'
            . ' };'
            . ' const dispatch = (type, element) => {'
            . '   const event = new DragEvent(type, {'
            . '     bubbles: true, cancelable: true, clientX: rect.left + rect.width / 2, clientY: y,'
            . '   });'
            . '   Object.defineProperty(event, "dataTransfer", {value: transfer});'
            . '   element.dispatchEvent(event);'
            . ' };'
            . ' dispatch("dragstart", item);'
            . ' dispatch("dragover", target);'
            . ' dispatch("drop", target);'
            . ' dispatch("dragend", item);'
            . '})()';

        $this->getSession()->executeScript($script);
    }

    /**
     * Queues a section backup with a test-only nested custom-section tree. The browser flow under test starts at the
     * import dialog, so no course-format plugin is required to provide the custom hierarchy.
     *
     * @When /^I queue custom section "(?P<sectionnumber>\d+)" with child "(?P<childnumber>\d+)" in the Sharing Cart$/
     * @param int $sectionnumber root section number
     * @param int $childnumber custom child section number
     * @param bool $unnamedroot whether the copied root has no custom title
     * @return void
     */
    public function queue_section_with_custom_child_in_sharing_cart(
        int $sectionnumber,
        int $childnumber,
        bool $unnamedroot = false
    ): void {
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
        $rootsection->name = $unnamedroot ? null : 'Root section';
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

    /**
     * Queues an unnamed root whose generated cart label must not become a restored custom title.
     *
     * @When /^I queue unnamed custom section "(\d+)" with child "(\d+)" in the Sharing Cart$/
     * @param int $sectionnumber
     * @param int $childnumber
     */
    public function queue_unnamed_section_with_custom_child_in_sharing_cart(int $sectionnumber, int $childnumber): void {
        $this->queue_section_with_custom_child_in_sharing_cart($sectionnumber, $childnumber, true);
    }

    /**
     * Supplies the course-format integration target without requiring a third-party nesting format in this test.
     *
     * @Given /^I add a top-level Sharing Cart restore target for course "([^"]+)"$/
     * @param string $shortname
     */
    public function add_top_level_restore_target(string $shortname): void {
        global $DB;

        $courseid = (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $this->getSession()->executeScript(<<<JS
            const target = document.createElement('div');
            target.dataset.region = 'sharing-cart-course-target';
            target.dataset.parentSectionId = '0';
            target.dataset.courseId = '$courseid';
            document.querySelector('.course-content').prepend(target);
            JS);
    }
}
