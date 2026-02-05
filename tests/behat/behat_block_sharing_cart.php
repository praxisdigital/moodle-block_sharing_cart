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
        //$this->ensure_element_exists("//a[@data-blockname='sharing_cart']", "xpath_element");
        $this->get_selected_node("xpath_element", "//a[@data-blockname='sharing_cart']")->click();
    }



}