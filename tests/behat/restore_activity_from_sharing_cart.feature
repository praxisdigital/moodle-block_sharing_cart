@block @block_sharing_cart
@javascript

Feature: As an editing teacher, I can restore an activity from my sharing cart into a course
  section.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | numsections | initsections |
      | Course 1 | C1        | 0        | 2           | 1            |
    And the following "activities" exist:
      | activity | name    | course | idnumber | section |
      | book     | Book 1  | C1     | book1    | 1       |
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I enable the sharing cart plugin

    # Backup the activity into the sharing cart first.
    And I drag the "Book 1" activity to the sharing cart
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I reload the page

  Scenario: The editing teacher restores an activity from the sharing cart into a different section
    Given I wait until "//div[@data-type='mod_book']//i[@data-action='copy_to_course']" "xpath_element" exists
    When I click on "//div[@data-type='mod_book']//i[@data-action='copy_to_course']" "xpath_element"
    And I wait until "//li[@id='section-2']//*[@class='clipboard_target']" "xpath_element" exists
    And I click on "//li[@id='section-2']//*[@class='clipboard_target']" "xpath_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I reload the page
    Then "//li[@id='section-2']//span[contains(@class,'instancename')][contains(text(),'Book 1')]" "xpath_element" should be visible
