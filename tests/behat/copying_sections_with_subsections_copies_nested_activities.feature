@blocks @blocks_sharing_cart
@javascript

Feature: As an editing teacher, copying a section with a subsection, should automatically
  include any nested activities inside the subsection when copying the section to a different section.

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
    | admin | C1        | editingteacher |
    And the following "activities" exist:
    | activity   | name        | course | idnumber    | section |
    | subsection | Subsection1 | C1     | Subsection1 | 1       |
    | book       | Subactivity | C1     | book1       | 3       |

Scenario: A course has a section with a subsection containing an activity.
The editing teacher copies the section and inserts it into a different section.

  Given I log in as "admin"
  And I am on "Course 1" course homepage with editing mode on
  And I click on "//a[@data-key='addblock']" "xpath_element"
  And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
  And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"

  #Region Start: All the steps to copy the section that has a subsection with an activity inside it
  And I wait until "//li[@id='section-1']//*[@class='fa fa-shopping-basket add_to_sharing_cart']" "xpath_element" exists
  And I click on "//li[@id='section-1']//*[@class='fa fa-shopping-basket add_to_sharing_cart']" "xpath_element"

  And I wait "5" seconds
  And I wait until "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element" exists
  And I click on "//div[@class='modal-footer']/button[@data-action='save']" "xpath_element"

  And I wait "15" seconds
  And I run all adhoc tasks

  And I wait "10" seconds
  And I wait until "(//div[@class='sharing_cart_item' and @data-type='section']//i[@class='fa fa-clone'])[1]" "xpath_element" exists
  And I click on "(//div[@class='sharing_cart_item' and @data-type='section']//i[@class='fa fa-clone'])[1]" "xpath_element"

  And I click on "//li[@id='section-2']//*[@class='clipboard_target']" "xpath_element"

  And I wait "5" seconds
  And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
  And I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
  
  And I wait "20" seconds
  And I reload the page
  And I run all adhoc tasks
  #Region End

  When I run all adhoc tasks
  And I wait "5" seconds
  And I reload the page
    #Subsection should be visible
  Then "//li[@id='section-2']//div[@data-activityname='Subsection1']" "xpath_element" should be visible
    #Nested activity should be visible
  And "//li[@id='section-2']//div[@data-activityname='Subactivity']" "xpath_element" should be visible
  