@block @block_sharing_cart @javascript

Feature: Empty subsections show a disabled Sharing Cart basket
  As an editing teacher
  I want an empty subsection's basket to be disabled
  So I know it cannot be copied yet

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
      | admin    | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name     | course | idnumber | section |
      | subsection | EmptySub | C1     | emptysub | 1       |

  Scenario: An empty subsection has a greyed-out, non-actionable basket
    Given the following config values are set as admin:
      | show_sharing_cart_basket | 1 | block_sharing_cart |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "//li[@id='section-1']//li[contains(@class, 'modtype_subsection')]//i[contains(@class, 'add_to_sharing_cart')]" "xpath_element" exists
    Then "//li[@id='section-1']//li[contains(@class, 'modtype_subsection')]//i[contains(@class, 'add_to_sharing_cart') and contains(@class, 'disabled')]" "xpath_element" should exist
    And there are exactly 1 Sharing Cart baskets matching ".course-content li[data-for='cmitem'].modtype_subsection .add_to_sharing_cart"
    When I replace course module ".course-content li[data-for='cmitem'].modtype_subsection" without reloading
    And I wait until "//li[@id='section-1']//li[contains(@class, 'modtype_subsection')]//i[contains(@class, 'add_to_sharing_cart')]" "xpath_element" exists
    And there are exactly 1 Sharing Cart baskets matching ".course-content li[data-for='cmitem'].modtype_subsection .add_to_sharing_cart"
    Then "//li[@id='section-1']//li[contains(@class, 'modtype_subsection')]//i[contains(@class, 'add_to_sharing_cart') and contains(@class, 'disabled')]" "xpath_element" should exist
