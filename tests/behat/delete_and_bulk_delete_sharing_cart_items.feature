@block @block_sharing_cart
@javascript

Feature: As an editing teacher, I can delete individual items and bulk delete multiple items
  from my sharing cart.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | numsections | initsections |
      | Course 1 | C1        | 0        | 2           | 1            |
    And the following "block_sharing_cart > sharing_cart_items" exist:
      | user_id | parent_item_name | type     | name       |
      | 2       |                  | mod_book | Book 1     |
      | 2       |                  | mod_book | Book 2     |
    Given I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    And I enable the sharing cart plugin

  Scenario: The editing teacher deletes a single item from the sharing cart
    When I click on "//div[@data-type='mod_book'][.//span[@class='name'][contains(text(),'Book 1')]]//i[@data-action='delete']" "xpath_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='delete']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='delete']" "xpath_element"
    And I wait "2" seconds
    Then "//div[@data-type='mod_book'][.//span[@class='name'][contains(text(),'Book 1')]]" "xpath_element" should not exist
    And I should see "Book 2"

  Scenario: The editing teacher bulk deletes multiple items from the sharing cart
    Given I click on "#block_sharing_cart_bulk_delete" "css_element"
    And I click on "//div[@data-type='mod_book'][.//span[@class='name'][contains(text(),'Book 1')]]//input[@data-action='bulk_select']" "xpath_element"
    And I click on "//div[@data-type='mod_book'][.//span[@class='name'][contains(text(),'Book 2')]]//input[@data-action='bulk_select']" "xpath_element"

    When I click on "#block_sharing_cart_bulk_delete_confirm" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='delete']" "xpath_element" exists
    And I click on "//div[@class='modal-footer']//button[@data-action='delete']" "xpath_element"
    And I wait "2" seconds
    Then I should not see "Book 1"
    And I should not see "Book 2"
    And I should see "No items"
