@block @block_sharing_cart
@javascript

Feature: Copy Sharing Cart items into course sections by dragging
  As an editing teacher
  I want course sections to show where a Sharing Cart item can be dropped
  So I can copy an item into the course without using the clipboard targets

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
      | activity   | name         | course | idnumber    | section |
      | book       | Book 1       | C1     | book1       | 1       |
      | subsection | Subsection 2 | C1     | subsection2 | 2       |

  Scenario: Drag an activity from the Sharing Cart into a course section
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I back up course element ".course-content [data-for='cmitem']" into the Sharing Cart as "module"
    And I run all adhoc tasks
    And I reload the page
    And I wait until ".block_sharing_cart .sharing_cart_items > .sharing_cart_item [data-action='copy_to_course']" "css_element" exists
    And I wait until ".block_sharing_cart .sharing_cart_items[data-sortable-initialized='true']" "css_element" exists
    Then copyable Sharing Cart items showed a drag handle and grab cursor
    And I should see "Drag an item to a course section" in the ".block_sharing_cart .has-items" "css_element"
    When I start dragging Sharing Cart item ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" over course section "#section-2"
    Then the course section showed a highlighted Sharing Cart drop target
    When I drop the dragged Sharing Cart item into the course section
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I reload the page
    And I run all adhoc tasks
    And I wait "20" seconds
    And I reload the page
    Then "#section-2 [data-activityname='Book 1']" "css_element" should exist
    And ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" "css_element" should exist

  Scenario: Drag a section from the Sharing Cart into a course section
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I back up course element "#section-1" into the Sharing Cart as "section"
    And I run all adhoc tasks
    And I reload the page
    And I wait until ".block_sharing_cart .sharing_cart_items > .sharing_cart_item[data-type='section'] [data-action='copy_to_course']" "css_element" exists
    And I wait until ".block_sharing_cart .sharing_cart_items[data-sortable-initialized='true']" "css_element" exists
    Then copyable Sharing Cart items showed a drag handle and grab cursor
    When I start dragging Sharing Cart item ".block_sharing_cart .sharing_cart_items > .sharing_cart_item[data-type='section']" over course section "#section-2"
    Then the course section showed a highlighted Sharing Cart drop target
    When I drop the dragged Sharing Cart item into the course section
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I reload the page
    And I run all adhoc tasks
    And I wait "20" seconds
    And I reload the page
    Then "#section-2 [data-activityname='Book 1']" "css_element" should exist
    And ".block_sharing_cart .sharing_cart_items > .sharing_cart_item[data-type='section']" "css_element" should exist

  Scenario: Dropping a cart item outside a course section does not start a copy
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I back up course element ".course-content [data-for='cmitem']" into the Sharing Cart as "module"
    And I run all adhoc tasks
    And I reload the page
    And I wait until ".block_sharing_cart .sharing_cart_items > .sharing_cart_item [data-action='copy_to_course']" "css_element" exists
    And I wait until ".block_sharing_cart .sharing_cart_items[data-sortable-initialized='true']" "css_element" exists
    When I start dragging Sharing Cart item ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" over course section ".course-content"
    Then the invalid course drop target was not highlighted
    When I drop the dragged Sharing Cart item into the course section
    Then no Sharing Cart copy confirmation was shown
    And ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" "css_element" should exist

  Scenario: Cancelling confirmation after a valid cart drop does not copy the item
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I back up course element ".course-content [data-for='cmitem']" into the Sharing Cart as "module"
    And I run all adhoc tasks
    And I reload the page
    And I wait until ".block_sharing_cart .sharing_cart_items > .sharing_cart_item [data-action='copy_to_course']" "css_element" exists
    And I wait until ".block_sharing_cart .sharing_cart_items[data-sortable-initialized='true']" "css_element" exists
    When I start dragging Sharing Cart item ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" over course section "#section-2"
    Then the course section showed a highlighted Sharing Cart drop target
    When I drop the dragged Sharing Cart item into the course section
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='cancel']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='cancel']" "xpath_element"
    Then "#section-2 [data-activityname='Book 1']" "css_element" should not exist
    And ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" "css_element" should exist

  Scenario: Dropping an activity onto a subsection copies it into that subsection
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I back up course element "#section-1 [data-for='cmitem']" into the Sharing Cart as "module"
    And I run all adhoc tasks
    And I reload the page
    And I wait until ".block_sharing_cart .sharing_cart_items > .sharing_cart_item [data-action='copy_to_course']" "css_element" exists
    And I wait until ".block_sharing_cart .sharing_cart_items[data-sortable-initialized='true']" "css_element" exists
    When I start dragging Sharing Cart item ".block_sharing_cart .sharing_cart_items > .sharing_cart_item" over course section "#section-2 [data-activityname='Subsection 2'] [data-for='section_title']"
    Then the course section showed a highlighted Sharing Cart drop target
    When I drop the dragged Sharing Cart item into the course section
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I reload the page
    And I run all adhoc tasks
    And I wait "20" seconds
    And I reload the page
    Then "#section-2 [data-activityname='Subsection 2'] [data-activityname='Book 1']" "css_element" should exist
