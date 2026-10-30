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
                promptFillsBlock: Math.abs(active.width - prompt.width) <= 4 && Math.abs(active.height - prompt.height) <= 4,
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

    /**
     * Start dragging a Sharing Cart item over a course section.
     *
     * @When /^I start dragging Sharing Cart item "(?P<source_selector>[^"]*)" over course section "(?P<target_selector>[^"]*)"$/
     * @param string $sourceselector CSS selector for a Sharing Cart item.
     * @param string $targetselector CSS selector for a course section.
     * @return void
     */
    public function i_start_dragging_sharing_cart_item_over_course_section(string $sourceselector, string $targetselector): void {
        $sourceselector = json_encode($sourceselector, JSON_THROW_ON_ERROR);
        $targetselector = json_encode($targetselector, JSON_THROW_ON_ERROR);
        $this->getSession()->executeScript(
            <<<JS
            const source = document.querySelector($sourceselector);
            const target = document.querySelector($targetselector);
            if (!source || !target) {
                throw new Error('Could not find the Sharing Cart item or course section.');
            }

            const makeDragEvent = (type, bubbles = true) => {
                const event = new DragEvent(type, {bubbles, cancelable: true});
                Object.defineProperty(event, 'dataTransfer', {
                    value: {
                        effectAllowed: 'copyMove',
                        dropEffect: 'none',
                        setData: () => {},
                        getData: () => '',
                        types: [],
                    },
                });
                return event;
            };

            window.sharingCartDraggedItem = source;
            window.sharingCartCourseDropTarget = target;
            window.sharingCartMakeItemDragEvent = makeDragEvent;
            window.sharingCartDocumentReceivedDragOver = false;
            document.addEventListener('dragover', () => {
                window.sharingCartDocumentReceivedDragOver = true;
            }, {once: true});
            source.dispatchEvent(makeDragEvent('dragstart', false));
            target.dispatchEvent(makeDragEvent('dragover'));
            JS
        );
        $this->wait_for_pending_js();
    }

    /**
     * Assert that a course section is highlighted as a valid Sharing Cart target.
     *
     * @Then /^the course section showed a highlighted Sharing Cart drop target$/
     * @return void
     */
    public function the_course_section_showed_a_highlighted_sharing_cart_drop_target(): void {
        $valid = $this->getSession()->evaluateScript(
            'return (() => {'
                . 'const section = window.sharingCartCourseDropTarget.closest("[data-for=section]");'
                . 'return Boolean(section && section.classList.contains("sharing_cart_drop_target") '
                . '&& section.classList.contains("sharing_cart_drop_target_active") '
                . '&& section.querySelector(".sharing_cart_drop_prompt") '
                . '&& window.sharingCartDocumentReceivedDragOver);'
                . '})();'
        );

        if (!$valid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The course section was not highlighted as a valid Sharing Cart drop target.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that dropping outside a course section does not highlight a destination.
     *
     * @Then /^the invalid course drop target was not highlighted$/
     * @return void
     */
    public function the_invalid_course_drop_target_was_not_highlighted(): void {
        $valid = $this->getSession()->evaluateScript(
            'return !document.querySelector(".course-section.sharing_cart_drop_target_active");'
        );

        if (!$valid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'An invalid course drop target was highlighted.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that no copy confirmation was opened after an invalid drop.
     *
     * @Then /^no Sharing Cart copy confirmation was shown$/
     * @return void
     */
    public function no_sharing_cart_copy_confirmation_was_shown(): void {
        $visible = $this->getSession()->evaluateScript(
            'return Boolean(document.querySelector(".modal.show .modal-footer [data-action=save]"));'
        );

        if ($visible) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'A copy confirmation was opened after dropping outside a course section.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that copyable cart items communicate their drag affordance.
     *
     * @Then /^copyable Sharing Cart items showed a drag handle and grab cursor$/
     * @return void
     */
    public function copyable_sharing_cart_items_showed_a_drag_handle_and_grab_cursor(): void {
        $valid = $this->getSession()->evaluateScript(
            'return (() => {'
                . 'const item = document.querySelector(".sharing_cart_item[data-can-drag-to-course=\'1\']");'
                . 'return Boolean(item && item.querySelector(".sharing_cart_item_drag_handle") '
                . '&& window.getComputedStyle(item).cursor === "grab");'
                . '})();'
        );

        if (!$valid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Copyable Sharing Cart items did not show a drag handle and grab cursor.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that nested saved-cart items do not advertise drag-to-course when only roots can be dragged.
     *
     * @Then /^nested Sharing Cart items did not show drag handles$/
     * @return void
     */
    public function nested_sharing_cart_items_did_not_show_drag_handles(): void {
        $valid = $this->getSession()->evaluateScript(
            'return !document.querySelector(".sharing_cart_items > .sharing_cart_item '
                . '.sharing_cart_item_children .sharing_cart_item_drag_handle");'
        );

        if (!$valid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Nested Sharing Cart items showed drag handles.',
                $this->getSession()
            );
        }
    }

    /**
     * Drop the active Sharing Cart item into its selected course section.
     *
     * @When /^I drop the dragged Sharing Cart item into the course section$/
     * @return void
     */
    public function i_drop_the_dragged_sharing_cart_item_into_the_course_section(): void {
        $this->getSession()->executeScript(<<<JS
            const source = window.sharingCartDraggedItem;
            const target = window.sharingCartCourseDropTarget;
            const makeDragEvent = window.sharingCartMakeItemDragEvent;
            if (!source || !target || !makeDragEvent) {
                throw new Error('No active Sharing Cart item drag was found.');
            }

            target.dispatchEvent(makeDragEvent('drop'));
            source.dispatchEvent(makeDragEvent('dragend', false));
            delete window.sharingCartDraggedItem;
            delete window.sharingCartCourseDropTarget;
            delete window.sharingCartMakeItemDragEvent;
        JS);
        $this->wait_for_pending_js();
    }

    /**
     * Create a test cart backup through the same Moodle AJAX endpoint used by the course-to-cart controls.
     *
     * @When /^I back up course element "(?P<source_selector>[^"]*)" into the Sharing Cart as "(?P<type>module|section)"$/
     * @param string $sourceselector CSS selector for a course module or section.
     * @param string $type The course element type to back up.
     * @return void
     */
    public function i_back_up_course_element_into_the_sharing_cart(string $sourceselector, string $type): void {
        $sourceselector = json_encode($sourceselector, JSON_THROW_ON_ERROR);
        $type = json_encode($type, JSON_THROW_ON_ERROR);
        $this->getSession()->executeScript(
            <<<JS
            const source = document.querySelector($sourceselector);
            if (!source || !source.dataset.id) {
                throw new Error('Could not find the course element to back up.');
            }

            const type = $type;
            const pendingKey = 'sharing-cart-backup-' + Date.now();
            const methodName = type === 'module' ?
                'block_sharing_cart_backup_course_module_into_sharing_cart' :
                'block_sharing_cart_backup_section_into_sharing_cart';
            const args = type === 'module' ? {
                coursemoduleid: Number.parseInt(source.dataset.id),
                settings: {users: false, anonymize: false},
            } : {
                sectionid: Number.parseInt(source.dataset.id),
                settings: {users: false, anonymize: false},
            };

            window.sharingCartBackupError = null;
            M.util.js_pending(pendingKey);
            require(['core/ajax'], Ajax => {
                Ajax.call([{
                    methodname: methodName,
                    args,
                    done: () => M.util.js_complete(pendingKey),
                    fail: error => {
                        window.sharingCartBackupError = error.message || 'The Sharing Cart backup failed.';
                        M.util.js_complete(pendingKey);
                    },
                }]);
            });
            JS
        );
        $this->wait_for_pending_js();

        $error = $this->getSession()->evaluateScript('return window.sharingCartBackupError;');
        if ($error) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Could not create the Sharing Cart backup: ' . $error,
                $this->getSession()
            );
        }
    }
}
