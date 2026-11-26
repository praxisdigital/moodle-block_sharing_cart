@block @block_sharing_cart @block_sharing_cart_empty_subsection @javascript
Feature: Subsection copies cannot restore unselected content from a shared backup
  As an editing teacher
  I want an empty subsection selection to be rejected
  So that no unrelated activities are copied into my course

  Scenario Outline: An empty selection stays open and can be corrected without copying siblings
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections | initsections |
      | Source course | SRC       | topics | 2           | 1            |
      | Target course | TARGET    | topics | 2           | 1            |
    And the following "activities" exist:
      | activity   | name                | course | idnumber       | section |
      | page       | Unrelated root page | SRC    | rootpage       | 1       |
      | subsection | Chosen subsection   | SRC    | rootsubsection | 1       |
      | page       | Subsection page     | SRC    | subsectionpage | 3       |
    And the following "blocks" exist:
      | blockname    | contextlevel | reference | pagetypepattern | defaultregion |
      | sharing_cart | Course       | TARGET    | course-view-*   | side-pre      |
    And I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue section "1" from course "SRC" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET > Section 1" "course > section" page
    And I switch editing mode on
    And I wait until ".sharing_cart_item[data-type='section'] .info" "css_element" exists
    And I click on ".sharing_cart_item[data-type='section'] > .item-body .info" "css_element"
    And I copy Sharing Cart subsection "Chosen subsection" into section "1" using "<method>"
    And I wait until ".import_form input[data-type='coursemodule']" "css_element" exists
    And I set the field with xpath "//div[contains(@class, 'import_form')]//input[@data-type='coursemodule']" to "0"
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    Then "[data-region='restore-selection-error']:not(.d-none)" "css_element" should be visible
    And ".import_form" "css_element" should be visible
    And no Sharing Cart restore is queued
    When I set the field with xpath "//div[contains(@class, 'import_form')]//input[@data-type='coursemodule']" to "1"
    And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until ".queue-item" "css_element" exists
    And I run all adhoc tasks
    And I wait until "//*[@data-activityname='Chosen subsection']//*[@data-activityname='Subsection page']" "xpath_element" exists
    Then "Subsection page" "activity" should exist
    And "Unrelated root page" "activity" should not exist
    And "Chosen subsection" "activity" should exist
    And "//*[@data-activityname='Chosen subsection']//*[@data-activityname='Subsection page']" "xpath_element" should exist

    Examples:
      | method    |
      | clipboard |
      | drag      |

  Scenario: An empty subsection can be copied using the keyboard
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections | initsections |
      | Source course | SRC       | topics | 2           | 1            |
      | Target course | TARGET    | topics | 2           | 1            |
    And the following "activities" exist:
      | activity   | name                | course | idnumber       | section |
      | subsection | Empty subsection    | SRC    | emptysub       | 1       |
    And the following "blocks" exist:
      | blockname    | contextlevel | reference | pagetypepattern | defaultregion |
      | sharing_cart | Course       | TARGET    | course-view-*   | side-pre      |
    And I log in as "admin"
    And I queue section "1" from course "SRC" in the Sharing Cart
    And I run all adhoc tasks
    And I am on "Target course" course homepage with editing mode on
    And I wait until ".sharing_cart_item[data-type=section] button.info" "css_element" exists
    When I start keyboard navigation from the Sharing Cart test control ".sharing_cart_item[data-type=section] button.info"
    And I press the enter key
    Then ".sharing_cart_item[data-type=section] button.info[aria-expanded=true]" "css_element" should be visible
    When I press the tab key
    Then the focused element is ".sharing_cart_item[data-type=section] > .item-body [data-action=copy_to_course]" "css_element"
    When I press the tab key
    Then the focused element is ".sharing_cart_item[data-type=mod_subsection] [data-action=copy_to_course]" "css_element"
    When I press the enter key
    And I click on "#section-1 .clipboard_target" "css_element"
    And I wait until ".modal.show [data-action=save]" "css_element" exists
    And I start keyboard navigation from the Sharing Cart test control ".modal.show [data-action=save]"
    And I press the enter key
    And I wait until ".queue-item" "css_element" exists
    And I run all adhoc tasks
    Then "#section-1 [data-activityname='Empty subsection']" "css_element" should be visible
