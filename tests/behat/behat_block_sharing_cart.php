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
}
