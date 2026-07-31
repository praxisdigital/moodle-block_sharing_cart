@block @block_sharing_cart
@javascript

Feature: As an editing teacher, I can use the "Copy section" control inside the sharing cart
  block to queue a backup of a section into my sharing cart.

  Background:
    Given the following config values are set as admin:
      | show_copy_section_in_block | 1 | block_sharing_cart |
    And the following "courses" exist:
      | fullname | shortname | category | numsections | initsections |
      | Course 1 | C1        | 0        | 2           | 1            |
    And the following "activities" exist:
      | activity | name    | course | idnumber | section |
      | book     | Book 1  | C1     | book1    | 1       |
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I enable the sharing cart plugin

  Scenario: The editing teacher copies a section using the block's "Copy section" control
    When I set the field with xpath "//div[@id='copy_section_container']//select" to "Section 1"
    And I click on "//div[@id='copy_section_container']//button" "xpath_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I reload the page
    Then "//div[@class='sharing_cart_items']//span[@class='name'][contains(text(), 'Section 1')]" "xpath_element" should be visible
