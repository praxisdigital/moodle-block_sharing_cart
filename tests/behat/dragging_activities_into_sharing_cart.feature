@block @block_sharing_cart
@javascript

Feature: Copy course activities into the Sharing Cart by dragging them
  As an editing teacher
  I want the Sharing Cart to become a clear drop target while I drag an activity
  So I can copy course content without losing sight of the destination

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | numsections | initsections |
      | Course 1 | C1        | 0        | 2           | 1            |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name   | course | idnumber | section |
      | book     | Book 1 | C1     | book1    | 1       |

  Scenario: Dragging an activity into the Sharing Cart copies it and preserves the source
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until ".block.block_sharing_cart" "css_element" exists
    And I wait until ".block.block_sharing_cart .drop_here_text" "css_element" exists
    When I start dragging course element ".course-content [data-for='cmitem']" over the Sharing Cart
    Then the Sharing Cart showed a full-card drop target without resizing
    And I wait until ".block.block_sharing_cart .drop_here_text.dragging_item_over" "css_element" exists
    When I drop the dragged course element into the Sharing Cart
    And I wait until "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I wait "2" seconds
    Then I should see "Book 1" in the ".block.block_sharing_cart .sharing_cart_items" "css_element"
    And I should see "Book 1" in the "#section-1" "css_element"

  Scenario: Dragging a section into the Sharing Cart copies it and preserves its activities
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until ".block.block_sharing_cart .drop_here_text" "css_element" exists
    When I start dragging course element "#section-1 [data-for='section_title']" over the Sharing Cart
    Then the Sharing Cart showed a full-card drop target without resizing
    And I wait until ".block.block_sharing_cart .drop_here_text.dragging_item_over" "css_element" exists
    When I drop the dragged course element into the Sharing Cart
    And I wait until "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I wait "2" seconds
    Then ".block.block_sharing_cart .sharing_cart_item[data-type='section']" "css_element" should be visible
    And I should see "Book 1" in the "#section-1" "css_element"
