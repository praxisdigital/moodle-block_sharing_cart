@block @block_sharing_cart @javascript @sdgsdfgdfg
Feature: Drag a copied section to a new course position
  As an editing teacher
  I want to drag a copied section between course sections
  So it is restored at the position where I drop it

  Background:
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections | initsections |
      | Source course | Source    | topics | 2           | 1            |
      | Target course | Target    | topics | 2           | 1            |
    And the following "activities" exist:
      | activity | name            | course | idnumber | section |
      | page     | Copied activity | Source | copied   | 1       |
      | page     | Before activity | Target | before   | 1       |
      | page     | After activity  | Target | after    | 2       |

  Scenario: A copied section is inserted after the section where it is dropped
    Given I log in as "admin"
    And I am on the "Source course" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "//li[@id='section-1']//*[@class='fa fa-shopping-basket add_to_sharing_cart']" "xpath_element" exists
    And I click on "//li[@id='section-1']//*[@class='fa fa-shopping-basket add_to_sharing_cart']" "xpath_element"
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
    When I drag Sharing Cart section "Section 1" after course section "1"
    And I wait until "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[contains(@class, 'modal-footer')]//button[@data-action='save']" "xpath_element"
    And I run all adhoc tasks
    And I reload the page
    Then "//li[@id='section-1']//div[@data-activityname='Before activity']" "xpath_element" should be visible
    And "//li[@id='section-2']//div[@data-activityname='Copied activity']" "xpath_element" should be visible
    And "//li[@id='section-3']//div[@data-activityname='After activity']" "xpath_element" should be visible
    And "//li[@id='section-4']" "xpath_element" should not exist
