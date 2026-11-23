@block @block_sharing_cart @javascript
Feature: Sharing Cart reports a failed background restore

  Scenario: A failed restore shows a dismissible notice instead of the success warning
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections | initsections |
      | Source course | SRC       | topics | 1           | 1            |
      | Target course | TARGET    | topics | 1           | 1            |
    And the following "activities" exist:
      | activity | name        | course | idnumber | section |
      | page     | Source page | SRC    | page     | 1       |
    And the following "blocks" exist:
      | blockname    | contextlevel | reference | pagetypepattern | defaultregion |
      | sharing_cart | Course       | TARGET    | course-view-*   | side-pre      |
    And I log in as "admin"
    And I queue section "1" from course "SRC" in the Sharing Cart
    And I run all adhoc tasks
    And I am on "Target course" course homepage with editing mode on
    And I wait until "[data-action=copy_to_course]" "css_element" exists
    And I click on "[data-action=copy_to_course]" "css_element"
    And I click on "#section-1 .clipboard_target" "css_element"
    And I click on ".modal.show [data-action=save]" "css_element"
    And I wait until ".queue-item" "css_element" exists
    And the backup file for the queued Sharing Cart restore is missing
    When I run all adhoc tasks
    And I wait until "[data-region=restore-failure]" "css_element" exists
    Then I should see "contact your site administrator" in the "[data-region=restore-failure]" "css_element"
    And I should not see "You may need to reload"
    And "Source page" "activity" should not exist
    When I reload the page
    Then "[data-region=restore-failure]" "css_element" should be visible
    When I click on "[data-action=dismiss-restore-error]" "css_element"
    And I reload the page
    And I wait until "[data-region=no-restores]" "css_element" exists
    Then "[data-region=restore-failure]" "css_element" should not exist
