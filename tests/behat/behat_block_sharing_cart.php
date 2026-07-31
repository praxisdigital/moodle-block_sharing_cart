<?php

require_once __DIR__ . '/../../../../lib/behat/behat_base.php';

class behat_block_sharing_cart extends behat_base
{

    /**
     *
     * @Given /^I enable the sharing cart plugin$/
     */
    public function enable_sharing_cart_plugin()
    {
        $this->get_selected_node("xpath_element", "//a[@data-key='addblock']")->click();
        $this->execute('behat_general::wait_until_exists', ["//a[@data-blockname='sharing_cart']", "xpath_element"]);
        $this->get_selected_node("xpath_element", "//a[@data-blockname='sharing_cart']")->click();
    }

    /**
     * Simulates a native HTML5 drag and drop of a course module onto the sharing cart block.
     *
     * The course editor uses native HTML5 drag and drop (dragstart/dragend/drop) driven by
     * DataTransfer objects, which cannot be reliably reproduced through Selenium's mouse based
     * "I drag ... and I drop it in ..." step. Instead we dispatch the same native events the
     * browser would dispatch, which is picked up by the exact same JS listeners.
     *
     * @Given /^I drag the "(?P<cm_name_string>(?:[^"]|\\")*)" activity to the sharing cart$/
     * @param string $cmname
     */
    public function i_drag_the_activity_to_the_sharing_cart($cmname)
    {
        $cmname = $this->getSession()->getSelectorsHandler()->xpathLiteral($cmname);
        $this->drag_element_to_sharing_cart(
            "//li[@data-for='cmitem'][.//span[contains(@class,'instancename')][contains(normalize-space(.),{$cmname})]]"
        );
    }

    /**
     * Simulates a native HTML5 drag and drop of a section onto the sharing cart block.
     *
     * @Given /^I drag the "(?P<section_name_string>(?:[^"]|\\")*)" section to the sharing cart$/
     * @param string $sectionname
     */
    public function i_drag_the_section_to_the_sharing_cart($sectionname)
    {
        $sectionname = $this->getSession()->getSelectorsHandler()->xpathLiteral($sectionname);
        $this->drag_element_to_sharing_cart(
            "//li[@data-for='section'][@data-sectionname={$sectionname}]//*[@data-for='section_title']"
        );
    }

    /**
     * @param string $sourcexpath
     */
    protected function drag_element_to_sharing_cart($sourcexpath)
    {
        if (!$this->running_javascript()) {
            throw new \Behat\Mink\Exception\DriverException('This step requires javascript.');
        }

        $sourcenode = $this->find('xpath_element', $sourcexpath);
        $sourcexpathvalue = $sourcenode->getXpath();
        $encodedxpath = json_encode($sourcexpathvalue);

        $script = <<<JS
            (function() {
                var source = document.evaluate(
                    {$encodedxpath}, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null
                ).singleNodeValue;
                var target = document.getElementById('block_sharing_cart');
                if (!source || !target) {
                    return;
                }
                var dataTransfer = new DataTransfer();
                var dragStartEvent = new DragEvent('dragstart', {
                    bubbles: true, cancelable: true, dataTransfer: dataTransfer
                });
                source.dispatchEvent(dragStartEvent);

                var dropEvent = new DragEvent('drop', {
                    bubbles: true, cancelable: true, dataTransfer: dataTransfer
                });
                target.dispatchEvent(dropEvent);

                var dragEndEvent = new DragEvent('dragend', {
                    bubbles: true, cancelable: true, dataTransfer: dataTransfer
                });
                source.dispatchEvent(dragEndEvent);
            })();
        JS;

        $this->getSession()->executeScript($script);
    }
}