@block @block_sharing_cart @format_pxgrid @javascript

Feature: Sharing Cart basket icons on pxgrid subsections
  As an editing teacher
  I want subsection basket icons to reflect their content and sit beside the title
  So I can find and copy subsections from the course page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | numsections | initsections |
      | Course 1 | C1        | pxgrid | 0           | 1            |
    And the following "format_pxgrid > sections" exist:
      | id | course | parent_section_id | name      |
      | 1  | C1     | 0                 | Section 1 |
      | 2  | C1     | 0                 | Section 2 |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | admin    | C1     | editingteacher |
    And the following config values are set as admin:
      | show_sharing_cart_basket | 1 | block_sharing_cart |

  Scenario: A populated subsection has an enabled basket beside its title
    Given the following "activities" exist:
      | activity   | name       | course | idnumber   | section |
      | subsection | FilledSub  | C1     | filledsub  | 1       |
      | book       | SubBook    | C1     | subbook    | 3       |
      | choice     | ChoiceSub  | C1     | choicesub  | 3       |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I click on ".course-content .section-items li[data-for='section']:nth-of-type(2) a" "css_element"
    And I should see "FilledSub"
    And I wait until "//li[contains(@class, 'modtype_subsection')]//i[contains(@class, 'add_to_sharing_cart')]" "xpath_element" exists
    Then "//li[contains(@class, 'modtype_subsection')]//div[contains(@class, 'activitytitle')]//i[contains(@class, 'add_to_sharing_cart') and not(contains(@class, 'disabled'))]" "xpath_element" should exist
    And I wait until ".modtype_choice[data-for='cmitem'] .activity-name-area .activityname .add_to_sharing_cart" "css_element" exists
    And there are exactly 1 Sharing Cart baskets matching ".modtype_choice[data-for='cmitem'] .activity-name-area .activityname > .add_to_sharing_cart"
    And ".modtype_choice .cm_action_menu .add_to_sharing_cart" "css_element" should not exist
    When I replace course module ".course-content li[data-for='cmitem'].modtype_choice" without reloading
    And I wait until ".modtype_choice[data-for='cmitem'] .activity-name-area .activityname .add_to_sharing_cart" "css_element" exists
    Then there are exactly 1 Sharing Cart baskets matching ".modtype_choice[data-for='cmitem'] .activity-name-area .activityname > .add_to_sharing_cart"
    And I wait until ".course-content [data-for='section_title'] .add_to_sharing_cart" "css_element" exists
    And the Sharing Cart basket matching ".course-content [data-for='section_title'] .add_to_sharing_cart" is displayed inline
