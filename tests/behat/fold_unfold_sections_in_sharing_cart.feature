@block @block_sharing_cart
@javascript

Feature: As an editing teacher, I can fold and unfold sections (and subsections) inside the
  sharing cart so I can inspect which activities are contained within them.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | numsections | initsections |
      | Course 1 | C1        | 0        | 2           | 1            |
    And the following "block_sharing_cart > sharing_cart_items" exist:
      | user_id | parent_item_name | type      | name       |
      | 2       |                  | section   | Section 1  |
      | 2       | Section 1        | mod_book  | Book 1     |
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I enable the sharing cart plugin

  Scenario: The editing teacher unfolds and then folds a section item in the sharing cart
    Given "//div[@data-type='section']//div[@class='sharing_cart_item_children']//div[@data-type='mod_book']" "xpath_element" should not be visible
    And "//div[@data-type='section']//div[@class='info']//i[contains(@class,'fa-folder-o')]" "xpath_element" should be visible

    When I click on "//div[@data-type='section']//div[@class='info']" "xpath_element"
    Then "//div[@data-type='section']//div[@class='sharing_cart_item_children']//div[@data-type='mod_book']" "xpath_element" should be visible
    And "//div[@data-type='section']//div[@class='info']//i[contains(@class,'fa-folder-open-o')]" "xpath_element" should be visible

    When I click on "//div[@data-type='section']//div[@class='info']" "xpath_element"
    Then "//div[@data-type='section']//div[@class='sharing_cart_item_children']//div[@data-type='mod_book']" "xpath_element" should not be visible
    And "//div[@data-type='section']//div[@class='info']//i[contains(@class,'fa-folder-o')]" "xpath_element" should be visible
