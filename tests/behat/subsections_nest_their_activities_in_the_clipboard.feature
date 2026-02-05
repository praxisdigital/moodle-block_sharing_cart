@blocks @blocks_sharing_cart
@javascript

Feature: As an editing teacher that has successfully copied a section with a subsection containing an activity,
  in the sharing cart clipboard, the subsection icon should on click unfold the nested activity in the clipboard with padding on the left.

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
    And the following "activities" exist:
      | activity   | name        | course | idnumber    | section |
      | subsection | Subsection1 | C1     | Subsection1 | 1       |
      | book       | Subactivity | C1     | book1       | 3       |
    And the following "block_sharing_cart > sharing_cart_items" exist:
      | user_id | parent_item_name |   type         | name                     |
      | 2       |                  | section        | Section 1                |
      | 2       |  Section 1       | mod_subsection | Subsection 1             |
      | 2       |  Subsection 1    | mod_book       | Subsection 1 Book 1      |

  Scenario: The editing teacher is on the course page and has enabled editing mode, aswell as enabled the sharing cart plugin.
  The editing teacher clicks the folder icon in the items clipboard of the sharing card, and clicks the subsection icon.

    Given I log in as "admin"

    And I am on "Course 1" course homepage with editing mode on

    #Enable sharing cart plugin
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"

    And I wait "5" seconds

    #Click on section icon
    When I click on "//div[@data-type='section']//i[@class='fa fa-folder-o']" "xpath_element"

    Then I should see "Subsection 1 Book 1" in the "//div[@data-type='mod_subsection']//div[@class='sharing_cart_item_children']//div[@data-type='mod_book']//span" "xpath_element"

