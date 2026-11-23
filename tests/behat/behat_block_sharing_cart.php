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
     * Place focus on a starting control without clicking or activating it.
     *
     * @Given /^I start keyboard navigation from the Sharing Cart test control "([^"]+)"$/
     * @param string $selector CSS selector for the starting control
     */
    public function focus_keyboard_starting_control(string $selector): void {
        $this->get_selected_node('css_element', $selector)->focus();
    }

    /**
     * Queue a real section backup, allowing the installed course format to declare its own hierarchy.
     *
     * @Given /^I queue section "(\d+)" from course "([^"]+)" in the Sharing Cart$/
     * @param int $number section number
     * @param string $shortname course shortname
     */
    public function queue_course_section(int $number, string $shortname): void {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $number], MUST_EXIST);
        \block_sharing_cart\external\backup\section_into_sharing_cart::execute(
            (int)$sectionid,
            ['users' => false, 'anonymize' => false]
        );
    }

    /**
     * Assert invalid UI selections have not queued a restore as a side effect.
     *
     * @Then /^no Sharing Cart restore is queued$/
     */
    public function assert_no_restore_queued(): void {
        if (\core\task\manager::get_adhoc_tasks(\block_sharing_cart\task\asynchronous_restore_task::class)) {
            throw new \Behat\Mink\Exception\ExpectationException('An invalid restore was queued.', $this->getSession());
        }
    }

    /**
     * Simulate a backup becoming unavailable between queueing and running the restore.
     *
     * @Given /^the backup file for the queued Sharing Cart restore is missing$/
     */
    public function remove_queued_restore_backup(): void {
        $tasks = \core\task\manager::get_adhoc_tasks(\block_sharing_cart\task\asynchronous_restore_task::class);
        $task = count($tasks) === 1 ? reset($tasks) : null;
        $file = $task ? get_file_storage()->get_file_by_id((int)$task->get_custom_data()->item->file_id) : null;
        if (!$file || !$file->delete()) {
            throw new \Behat\Mink\Exception\ExpectationException('Could not remove the queued backup file.', $this->getSession());
        }
    }

    /**
     * Delay the actual string loader to prove an obsolete validation response cannot change a newer form state.
     *
     * @Then /^delayed Sharing Cart validation cannot overwrite a newer selection$/
     */
    public function assert_delayed_validation_is_discarded(): void {
        $this->getSession()->executeScript(<<<'JS'
            (() => {
                M.util.js_pending('sharing_cart_delayed_validation');
                require(['block_sharing_cart/app/block/restore_selection', 'core/str'], (module, strings) => {
                    (async () => {
                        const Selection = module.default ?? module;
                        const original = strings.get_string;
                        let form;
                        try {
                            for (const action of ['select', 'change-back', 'close']) {
                                form = document.createElement('div');
                                form.hidden = true;
                                const contents = document.createElement('div');
                                contents.className = 'import_form';
                                const input = document.createElement('input');
                                input.type = 'checkbox';
                                input.dataset.type = 'coursemodule';
                                input.dataset.id = '42';
                                const error = document.createElement('p');
                                error.dataset.region = 'restore-selection-error';
                                error.className = 'd-none';
                                contents.append(input, error);
                                form.append(contents);
                                document.body.append(form);
                                Selection.bind(form);
                                let release;
                                strings.get_string = (key, ...args) => key === 'atleast_one_course_module_must_be_included'
                                    ? new Promise(resolve => { release = resolve; }) : original(key, ...args);
                                const pending = Selection.reportEmpty(form);
                                if (!release) {
                                    throw new Error('The delayed string loader was not used');
                                }
                                if (action === 'close') {
                                    form.remove();
                                } else {
                                    input.checked = true;
                                    input.dispatchEvent(new Event('change', {bubbles: true}));
                                    if (action === 'change-back') {
                                        input.checked = false;
                                        input.dispatchEvent(new Event('change', {bubbles: true}));
                                    }
                                }
                                release('Delayed validation message');
                                await pending;
                                if (!error.classList.contains('d-none') || input.validationMessage !== '') {
                                    throw new Error('Stale validation changed the form after: ' + action);
                                }
                                form.remove();
                            }
                            window.sharingCartDelayedValidationResult = true;
                        } catch (error) {
                            window.sharingCartDelayedValidationResult = error.stack || error.message;
                        } finally {
                            strings.get_string = original;
                            form?.remove();
                            M.util.js_complete('sharing_cart_delayed_validation');
                        }
                    })();
                });
            })();
            JS);
        $this->wait_for_pending_js();
        $result = $this->getSession()->evaluateScript('window.sharingCartDelayedValidationResult');
        if ($result !== true) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Delayed restore validation was not discarded: ' . json_encode($result),
                $this->getSession()
            );
        }
    }

    /**
     * Exercise both supported ways of opening the subsection-copy dialog.
     *
     * @When /^I copy Sharing Cart subsection "([^"]+)" into section "(\d+)" using "(clipboard|drag)"$/
     * @param string $name subsection name
     * @param int $number destination section number
     * @param string $method clipboard or drag
     */
    public function copy_subsection_into_section(string $name, int $number, string $method): void {
        if ($method === 'clipboard') {
            $literal = behat_context_helper::escape($name);
            $this->get_selected_node(
                'xpath_element',
                "//div[@data-type='mod_subsection'][.//span[@class='name' and normalize-space(.)=$literal]]" .
                "//*[@data-action='copy_to_course']"
            )->click();
            $this->get_selected_node('css_element', ".single-section [data-number='$number'] .clipboard_target")->click();
            return;
        }

        $namejson = json_encode($name);
        $this->getSession()->executeScript(<<<JS
            (() => {
                const item = [...document.querySelectorAll('.sharing_cart_item[data-type="mod_subsection"]')]
                    .find(node => node.querySelector(':scope > .item-body .name')?.textContent.trim() === $namejson);
                const target = document.querySelector('.single-section [data-for="section"][data-number="$number"]');
                if (!item || !target) {
                    throw new Error('The cart subsection or course section is missing');
                }
                const rect = target.getBoundingClientRect();
                const transfer = new DataTransfer();
                const dispatch = (type, node) => node.dispatchEvent(new DragEvent(type, {
                    bubbles: true, cancelable: true, dataTransfer: transfer,
                    clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2,
                }));
                dispatch('dragstart', item);
                dispatch('dragover', target);
                dispatch('drop', target);
                dispatch('dragend', item);
            })();
            JS);
    }

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
