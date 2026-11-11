@block @block_sharing_cart @format_pxgrid @javascript
Feature: Restore nested pxgrid sections into a flat course
  As an editing teacher
  I want empty ancestors omitted when restoring a nested section tree into a flat course
  So the course only gains sections that contain restored activities

  Background:
    Given the following config values are set as admin:
      | show_copy_section_in_block | 1 | block_sharing_cart |
    And the following "courses" exist:
      | fullname     | shortname | format | numsections | initsections |
      | Source grid  | PX        | pxgrid | 0           | 1            |
      | Target course | Target    | topics | 2           | 1            |
    And the following "format_pxgrid > sections" exist:
      | id | course | parent_section_id | name            |
      | 1  | PX     | 0                 | Structural root |
      | 2  | PX     | 1                 | Empty middle    |
      | 3  | PX     | 2                 | Populated leaf  |
    And the following "activities" exist:
      | activity | name               | course | idnumber  | section |
      | page     | Root-only activity | PX     | rootpage  | 1       |
      | page     | Leaf activity      | PX     | leafpage  | 3       |

  Scenario: Drag a nested section to a new section in a flat course
    Given I log in as "admin"
    And I am on the "Source grid" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "//div[@id='copy_section_container']//option[normalize-space(.)='Structural root']" "xpath_element" exists
    When I start copying section "Structural root" from the Sharing Cart block
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I run all adhoc tasks
    And I wait until "//div[@id='block_sharing_cart']//div[@data-type='section']//i[@data-action='copy_to_course']" "xpath_element" exists
    And I am on the "Target course" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "//div[@id='block_sharing_cart']//div[@data-type='section']//i[@data-action='copy_to_course']" "xpath_element" exists
    When I drag Sharing Cart section "Structural root" after course section "1"
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "Root-only activity" "checkbox"
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I wait "2" seconds
    And I reload the page
    Then "//li[@id='section-2']//div[@data-activityname='Leaf activity']" "xpath_element" should be visible
    And "//li[@id='section-2']//div[@data-activityname='Root-only activity']" "xpath_element" should not exist
    And "//li[@id='section-4']" "xpath_element" should not exist
    And I should not see "Empty middle" in the "div.course-content" "css_element"
