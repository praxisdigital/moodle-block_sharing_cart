@block @block_sharing_cart
@javascript

Feature: As an editing teacher, I can drag and drop an activity onto the sharing cart block
  so that a backup of the activity is queued into my sharing cart.

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

  Scenario: The editing teacher drags an activity onto the sharing cart block
    When I drag the "Book 1" activity to the sharing cart
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait "15" seconds
    And I run all adhoc tasks
    And I reload the page
    Then "//div[@class='sharing_cart_items']//span[@class='name'][contains(text(), 'Book 1')]" "xpath_element" should be visible
