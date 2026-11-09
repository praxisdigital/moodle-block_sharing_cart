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
     * Simulate the pxgrid body class for Sharing Cart layout tests.
     * @Given /^I add the pxgrid body class for Sharing Cart$/
     */
    public function add_pxgrid_body_class_for_sharing_cart(): void {
        $this->getSession()->executeScript("document.body.classList.add('format-pxgrid');");
    }

    /**
     * Simulate a course-format DOM replacement without reloading the page.
     * @When /^I replace course module "(?P<selector>[^"]*)" without reloading$/
     * @param string $selector CSS selector for the course module to replace.
     */
    public function replace_course_module_without_reloading(string $selector): void {
        $this->replace_course_content_node($selector, true);
    }

    /**
     * Simulate a course-format DOM replacement while retaining its basket markup.
     *
     * @When /^I replace course module "(?P<selector>[^"]*)" preserving its Sharing Cart basket without reloading$/
     * @param string $selector CSS selector for the course module to replace.
     */
    public function replace_course_module_preserving_basket_without_reloading(string $selector): void {
        $this->replace_course_content_node($selector, false);
    }

    /**
     * Simulate a course-section DOM replacement while retaining its basket markup.
     *
     * @When /^I replace section "(?P<selector>[^"]*)" preserving its Sharing Cart basket without reloading$/
     * @param string $selector CSS selector for the course section.
     */
    public function replace_section_preserving_basket_without_reloading(string $selector): void {
        $this->replace_course_content_node($selector, false);
    }

    /**
     * Simulate a delegated section's content state changing without reloading.
     *
     * @When /^I set delegated section contents for subsection "(?P<selector>[^"]*)" to "(?P<state>empty|non-empty)"$/
     * @param string $selector CSS selector for the subsection course module.
     * @param string $state Whether the delegated section should be empty.
     */
    public function set_delegated_section_contents(string $selector, string $state): void {
        $selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $hascontent = $state === 'non-empty' ? 'true' : 'false';
        $script = str_replace(
            ['SELECTOR_PLACEHOLDER', 'HAS_CONTENT_PLACEHOLDER'],
            [$selector, $hascontent],
            <<<'JS'
            const subsection = document.querySelector(SELECTOR_PLACEHOLDER);
            if (!subsection) {
                throw new Error('Could not find the subsection course module.');
            }
            const delegatedSection = [...subsection.querySelectorAll('[data-for="section"]')].find((section) =>
                section.closest('[data-for="cmitem"]') === subsection
            );
            if (!delegatedSection) {
                throw new Error('Could not find the subsection delegated section.');
            }

            const courseModuleId = subsection.dataset.id;
            const sectionId = delegatedSection.dataset.id;
            const courseEditor = require('core_courseformat/courseeditor').getCurrentCourseEditor();
            const courseModule = courseEditor.state.cm.get(courseModuleId);
            if (!courseModule) {
                throw new Error('Could not find the subsection course-module state.');
            }

            if (courseModule.delegatesectionid !== null) {
                courseEditor.stateManager.processUpdates([{
                    name: 'cm',
                    action: 'update',
                    fields: {id: courseModuleId, delegatesectionid: null},
                }]);
            }
            courseEditor.stateManager.processUpdates([{
                name: 'section',
                action: 'update',
                fields: {
                    id: sectionId,
                    cmlist: HAS_CONTENT_PLACEHOLDER ? [courseModuleId] : [],
                },
            }]);
        JS
        );
        $this->getSession()->executeScript($script);
    }

    /**
     * Clone and replace a course-content node, optionally removing its baskets.
     *
     * @param string $selector CSS selector for the node to replace.
     * @param bool $removebaskets Whether to remove basket icons from the clone.
     */
    private function replace_course_content_node(string $selector, bool $removebaskets): void
    {
        $selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $script = str_replace(
            ['SELECTOR_PLACEHOLDER', 'REMOVE_BASKETS_PLACEHOLDER'],
            [$selector, $removebaskets ? 'true' : 'false'],
            <<<'JS'
            const courseContentNode = document.querySelector(SELECTOR_PLACEHOLDER);
            if (!courseContentNode) {
                throw new Error('Could not find the course-content node to replace.');
            }
            const replacement = courseContentNode.cloneNode(true);
            if (REMOVE_BASKETS_PLACEHOLDER) {
                replacement.querySelectorAll('.add_to_sharing_cart').forEach((button) => button.remove());
            }
            courseContentNode.replaceWith(replacement);
        JS
        );
        $this->getSession()->executeScript($script);
        $this->wait_for_pending_js();
    }

    /**
     * Assert an exact number of basket icons matched by a CSS selector.
     * @Then /^there are exactly (?P<count>\d+) Sharing Cart baskets matching "(?P<selector>[^"]*)"$/
     * @param int $count Expected number of baskets.
     * @param string $selector CSS selector for the baskets.
     */
    public function there_are_exactly_sharing_cart_baskets(int $count, string $selector): void {
        $selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $actual = (int)$this->getSession()->evaluateScript(
            'return document.querySelectorAll(' . $selector . ').length;'
        );

        if ($actual !== $count) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Found ' . $actual . ' Sharing Cart baskets; expected ' . $count . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Click the single enabled basket directly owned by a course module beside its title.
     *
     * @When /^I click the single actionable Sharing Cart basket owned by course module "(?P<selector>[^"]*)" in its title$/
     * @param string $selector CSS selector for the course module.
     */
    public function click_single_actionable_basket_owned_by_course_module(string $selector): void {
        $selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $script = str_replace('SELECTOR_PLACEHOLDER', $selector, <<<'JS'
            const courseModule = document.querySelector(SELECTOR_PLACEHOLDER);
            if (!courseModule) {
                throw new Error('Could not find the course module that owns the Sharing Cart basket.');
            }

            const baskets = [...courseModule.querySelectorAll('.add_to_sharing_cart')].filter((basket) =>
                basket.closest('[data-for="cmitem"]') === courseModule
            );
            if (baskets.length !== 1) {
                throw new Error(
                    `Expected one basket owned by the course module, found ${baskets.length}`
                    + ` (id=${courseModule.dataset.id}).`
                );
            }

            const basket = baskets[0];
            if (basket.parentElement?.matches('.activityname, .sectionname') !== true) {
                throw new Error('The course-module basket is not placed beside its title.');
            }
            if (basket.classList.contains('disabled') || basket.getAttribute('aria-disabled') === 'true') {
                throw new Error('The course-module basket is disabled.');
            }
            if (basket.dataset.cmid !== courseModule.dataset.id) {
                throw new Error('The course-module basket is not associated with its owning course module.');
            }

            basket.click();
        JS);
        $this->getSession()->executeScript($script);
        $this->wait_for_pending_js();
    }

    /**
     * Assert a basket is aligned on the same line as its editable title.
     * @Then /^the Sharing Cart basket matching "(?P<selector>[^"]*)" is inline with its title$/
     * @param string $selector CSS selector for the basket.
     */
    public function sharing_cart_basket_is_inline_with_title(string $selector): void {
        $selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $aligned = $this->getSession()->evaluateScript(
            'return (() => {'
            . 'const basket = document.querySelector(' . $selector . ');'
            . 'const container = basket?.closest(".activity-name-area")
            || basket?.closest("[data-for=section_title]") || basket?.parentElement;'
            . 'const title = container?.querySelector(".inplaceeditable");'
            . 'if (!basket || !title) { return false; }'
            . 'const basketRect = basket.getBoundingClientRect();'
            . 'const titleRect = title.getBoundingClientRect();'
            . 'return Math.abs((basketRect.top + basketRect.height / 2) - (titleRect.top + titleRect.height / 2)) <= 8;'
            . '})();'
        );

        if (!$aligned) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The Sharing Cart basket is not aligned with its editable title.',
                $this->getSession()
            );
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
     * Start dragging a course element over the Sharing Cart and capture the active drop target layout.
     *
     * @When /^I start dragging course element "(?P<source_selector>[^"]*)" over the Sharing Cart$/
     * @param string $sourceselector CSS selector for a draggable course element.
     * @return void
     */
    public function i_start_dragging_course_element_over_the_sharing_cart(string $sourceselector): void {
        $sourceselector = json_encode($sourceselector, JSON_THROW_ON_ERROR);
        $this->getSession()->executeScript(
            <<<JS
            const source = document.querySelector($sourceselector)
            const target = document.querySelector('.block.block_sharing_cart');
            if (!source || !target) {
                throw new Error('Could not find the draggable source or Sharing Cart block.');
            }

            const makeDragEvent = (type) => {
                const event = new DragEvent(type, {bubbles: true, cancelable: true});
                Object.defineProperty(event, 'dataTransfer', {
                    value: {
                        setDragImage: () => {},
                        types: {includes: () => false},
                    },
                });
                return event;
            };

            window.sharingCartDraggedSource = source;
            window.sharingCartDropTarget = target;
            window.sharingCartMakeDragEvent = makeDragEvent;
            const before = target.getBoundingClientRect();
            source.dispatchEvent(makeDragEvent('dragstart'));

            const dropPrompt = target.querySelector('.drop_here_text');
            const blockContent = target.querySelector('#block_sharing_cart .block-content');
            const active = target.getBoundingClientRect();
            const prompt = dropPrompt.getBoundingClientRect();
            window.sharingCartDropTargetMetrics = {
                promptVisible: window.getComputedStyle(dropPrompt).display !== 'none',
                contentHidden: window.getComputedStyle(blockContent).visibility === 'hidden',
                blockStable: Math.abs(before.width - active.width) < 1 && Math.abs(before.height - active.height) < 1,
                promptFillsBlock: Math.abs(active.width - prompt.width)
                <= 4 && Math.abs(active.height - prompt.height) <= 4,
            };

            target.dispatchEvent(makeDragEvent('dragenter'));
            target.dispatchEvent(makeDragEvent('dragover'));
        JS
        );
        $this->wait_for_pending_js();
    }

    /**
     * Complete the course element drag started by i_start_dragging_course_element_over_the_sharing_cart.
     *
     * @When /^I drop the dragged course element into the Sharing Cart$/
     * @return void
     */
    public function i_drop_the_dragged_course_element_into_the_sharing_cart(): void {
        $this->getSession()->executeScript(<<<JS
            const source = window.sharingCartDraggedSource;
            const target = window.sharingCartDropTarget;
            const makeDragEvent = window.sharingCartMakeDragEvent;
            if (!source || !target || !makeDragEvent) {
                throw new Error('No active Sharing Cart drag was found.');
            }

            target.dispatchEvent(makeDragEvent('drop'));
            source.dispatchEvent(makeDragEvent('dragend'));
            delete window.sharingCartDraggedSource;
            delete window.sharingCartDropTarget;
            delete window.sharingCartMakeDragEvent;
        JS);
        $this->wait_for_pending_js();
    }

    /**
     * Assert that the target replaced the cart content without changing the block dimensions.
     *
     * @Then /^the Sharing Cart showed a full-card drop target without resizing$/
     * @return void
     */
    public function the_sharing_cart_showed_a_full_card_drop_target_without_resizing(): void {
        $valid = $this->getSession()->evaluateScript(
            'return window.sharingCartDropTargetMetrics && '
                . 'window.sharingCartDropTargetMetrics.promptVisible && '
                . 'window.sharingCartDropTargetMetrics.contentHidden && '
                . 'window.sharingCartDropTargetMetrics.blockStable && '
                . 'window.sharingCartDropTargetMetrics.promptFillsBlock;'
        );

        if (!$valid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The Sharing Cart drop target was not visible at the original block dimensions.',
                $this->getSession()
            );
        }
    }
}
