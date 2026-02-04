@blocks @blocks_sharing_cart

Feature: As an editing teacher, copying a section with a subsection, should automatically
  include any nested activities inside the subsection when copying the section to a different section.

  @setup_sharing_cart
  Background:
    Given the following "users" exist:
    | username | firstname | lastname | email                |
    | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
    | fullname | shortname | category | numsections | initsections |
    | Course 1 | C1        | 0        | 2           | 1            |
    And the following "activities" exist:
    | activity   | name        | course | idnumber    | section |
    | subsection | Subsection1 | C1     | Subsection1 | 1       |
    | book       | Subactivity | C1     | book1       | 3       |

  @javascript
Scenario: A course has a section with a subsection containing an activity.
The editing teacher copies the section and inserts it into a different section.

  Given I log in as "teacher1"
  And I am on "Course 1" course homepage with editing mode on
  And I enable "Sharing Cart" "block" plugin

  #Region Start: All the steps to copy the section that has a subsection with an activity inside it
  And I click on "//li[@data-sectionname='Section 1']//*[@class='fa fa-shopping-basket add_to_sharing_cart']" "xpath_element"

  And I wait until "//span[@class='sharing_cart_item_actions']//*button[@data-action='run_now']" "xpath_element" exists
  And I click on "//span[@class='sharing_cart_item_actions']//*button[@data-action='run_now']" "xpath_element"

  And I wait until "//i[@data-action='copy_to_course']" "xpath_element" exists
  And I click on "//i[@data-action='copy_to_course']" "xpath_element"

  And I click on "//li[@data-sectionname='New Section']//*[@class='clipboard_target']" "xpath_element"

  And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
  And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
  
  And I wait "5" seconds
  And I reload the page
  #Region End

  #Running the copy/restore process
  When I click on "//div[@class='sharing_cart_queue']//button" "xpath_element"
    And I wait "5" seconds
    #Subsection should be visible
    Then "//li[@id='section-2']//div[@data-activityname='Subsection1']" "xpath_element" should be visible
    #Nested activity should be visible
    And "//li[@id='section-2']//div[@data-activityname='Subactivity']" "xpath_element" should be visible
  