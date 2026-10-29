@block @block_sharing_cart
@javascript

Feature: Explain the Sharing Cart basket icon migration
  As an editing teacher who used the basket icons
  I want to know how to add items after the icons are disabled
  So I can continue using the Sharing Cart

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

  Scenario: Dismiss the migration notice for the current user
    Given the following config values are set as admin:
      | show_sharing_cart_basket    | 0 | block_sharing_cart |
      | basket_icons_notice_pending | 1 | block_sharing_cart |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    Then I should see "Starting with Moodle 6.0, the Sharing Cart basket icons will be deprecated." in the ".block.block_sharing_cart" "css_element"
    And I should see "Drag and drop activities or sections into the Sharing Cart to add them." in the ".block.block_sharing_cart .no-items" "css_element"
    When I click on ".block.block_sharing_cart .sharing-cart-basket-notice button" "css_element"
    And I wait until ".block.block_sharing_cart .sharing-cart-basket-notice" "css_element" does not exist
    And I reload the page
    Then ".block.block_sharing_cart .sharing-cart-basket-notice" "css_element" should not exist

  Scenario: Show the notice when basket icons are disabled without a migration pending
    Given the following config values are set as admin:
      | show_sharing_cart_basket | 0 | block_sharing_cart |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    Then I should see "Starting with Moodle 6.0, the Sharing Cart basket icons will be deprecated." in the ".block.block_sharing_cart" "css_element"
