@block @block_sharing_cart @javascript

Feature: Restore a selected activity from an unchecked custom section
  As an editing teacher
  I want to restore an activity from a custom section without restoring that section
  So that the activity is placed in the target section

  Background:
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections | initsections |
      | Source course | SRC       | topics | 2           | 1            |
      | Target course | TARGET    | topics  | 2           | 1            |
    And the following "activities" exist:
      | activity | name                  | course | idnumber       | section |
      | page     | Root activity         | SRC    | rootactivity   | 1       |
      | page     | Custom child activity | SRC    | childactivity  | 2       |
    And the following "course enrolments" exist:
      | user  | course | role           |
      | admin | SRC    | editingteacher |
      | admin | TARGET | editingteacher |

  Scenario: Reselecting a child activity restores it flat in the target section
    Given I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue custom section "1" with child "2" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element" exists
    And I click on "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element"
    And I wait until "#section-2 .clipboard_target" "css_element" exists
    And I click on "#section-2 .clipboard_target" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I set the field with xpath "//input[@data-container='true' and @data-title='Custom child']" to "0"
    And the field with xpath "//input[@data-container='true' and @data-title='Custom child']/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' form-check ')][1]//div[contains(concat(' ', normalize-space(@class), ' '), ' item-children ')]//input[@data-type='coursemodule']" matches value "0"
    And I set the field with xpath "//input[@data-container='true' and @data-title='Custom child']/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' form-check ')][1]//div[contains(concat(' ', normalize-space(@class), ' '), ' item-children ')]//input[@data-type='coursemodule']" to "1"
    Then the field with xpath "//input[@data-container='true' and @data-title='Custom child']" matches value "0"
    And the field with xpath "//input[@data-container='true' and @data-title='Custom child']/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' form-check ')][1]//div[contains(concat(' ', normalize-space(@class), ' '), ' item-children ')]//input[@data-type='coursemodule']" matches value "1"
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until "//div[contains(concat(' ', normalize-space(@class), ' '), ' queue-item ')]" "xpath_element" exists
    And I run all adhoc tasks
    Then "//li[@id='section-2']//div[@data-activityname='Root activity']" "xpath_element" should be visible
    And "//li[@id='section-2']//div[@data-activityname='Custom child activity']" "xpath_element" should be visible
    And "//li[@data-for='section'][@data-sectionname='Custom child']" "xpath_element" should not exist

  Scenario: Keeping only a custom section checked restores it without any activities or subsections
    Given the following "activities" exist:
      | activity   | name                | course | idnumber       | section |
      | subsection | Root subsection     | SRC    | rootsubsection | 1       |
      | page       | Subsection activity | SRC    | subsectionpage | 3       |
    And I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue custom section "1" with child "2" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element" exists
    And I click on "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element"
    And I wait until "#section-2 .clipboard_target" "css_element" exists
    And I click on "#section-2 .clipboard_target" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Root activity')]/input" to "0"
    And I set the field with xpath "//input[@data-container='true' and @data-title='Root subsection']" to "0"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Subsection activity')]/input" to "0"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Custom child activity')]/input" to "0"
    Then the field with xpath "//input[@data-container='true' and @data-title='Custom child']" matches value "1"
    And the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Root activity')]/input" matches value "0"
    And the field with xpath "//input[@data-container='true' and @data-title='Root subsection']" matches value "0"
    And the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Subsection activity')]/input" matches value "0"
    And the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Custom child activity')]/input" matches value "0"
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until "//div[contains(concat(' ', normalize-space(@class), ' '), ' queue-item ')]" "xpath_element" exists
    And I run all adhoc tasks
    Then "//li[@data-for='section'][@data-sectionname='Custom child']" "xpath_element" should exist
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='Root activity']" "xpath_element" should not exist
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='Root subsection']" "xpath_element" should not exist
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='Subsection activity']" "xpath_element" should not exist
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='Custom child activity']" "xpath_element" should not exist

  Scenario Outline: Container inclusion stays distinct from partial descendant selection
    Given the following "activities" exist:
      | activity   | name                      | course | idnumber       | section |
      | page       | Other custom activity     | SRC    | othercustom    | 2       |
      | subsection | Root subsection           | SRC    | rootsubsection | 1       |
      | page       | Subsection activity       | SRC    | subsectionpage | 3       |
      | page       | Other subsection activity | SRC    | othersubpage   | 3       |
    And I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue custom section "1" with child "2" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element" exists
    And I click on "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element"
    And I wait until "#section-2 .clipboard_target" "css_element" exists
    And I click on "#section-2 .clipboard_target" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    Then the restore container "<container>" is "1" with "2" of "2" descendants selected
    When I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., '<firstchild>')]/input" to "0"
    Then the restore container "<container>" is "1" with "1" of "2" descendants selected
    When I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., '<secondchild>')]/input" to "0"
    Then the restore container "<container>" is "1" with "0" of "2" descendants selected
    When I set the field with xpath "//input[@data-container='true' and @data-title='<container>']" to "0"
    Then the restore container "<container>" is "0" with "0" of "2" descendants selected
    When I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., '<secondchild>')]/input" to "1"
    Then the restore container "<container>" is "0" with "1" of "2" descendants selected
    When I set the field with xpath "//input[@data-container='true' and @data-title='<container>']" to "<included>"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., '<firstchild>')]/input" to "0"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., '<secondchild>')]/input" to "1"
    Then the restore container "<container>" is "<included>" with "1" of "2" descendants selected
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until "//div[contains(concat(' ', normalize-space(@class), ' '), ' queue-item ')]" "xpath_element" exists
    And I run all adhoc tasks
    Then "//div[contains(@class, 'course-content')]//*[@data-activityname='<secondchild>']" "xpath_element" should exist
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='<firstchild>']" "xpath_element" should not exist
    And "//div[contains(@class, 'course-content')]//*[@data-sectionname='<container>' or @data-activityname='<container>']" "xpath_element" should <presence>

    Examples:
      | container       | firstchild            | secondchild               | included | presence  |
      | Custom child    | Custom child activity | Other custom activity     | 1        | exist     |
      | Custom child    | Custom child activity | Other custom activity     | 0        | not exist |
      | Root subsection | Subsection activity   | Other subsection activity | 1        | exist     |
      | Root subsection | Subsection activity   | Other subsection activity | 0        | not exist |

  Scenario Outline: Placement hints use the backed-up custom title rather than the generated cart label
    Given I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue <kind> section "1" with child "2" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I wait until "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element" exists
    And I click on "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element"
    And I wait until "#section-0 .clipboard_target" "css_element" exists
    And I click on "#section-0 .clipboard_target" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I set the field with xpath "//input[@data-container='true' and @data-title='Custom child']" to "0"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Custom child activity')]/input" to "1"
    Then "//small[@data-region='restore-placement-hint']//strong[normalize-space(.)='<destination>']" "xpath_element" should be visible
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until "//div[contains(concat(' ', normalize-space(@class), ' '), ' queue-item ')]" "xpath_element" exists
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    Then "//li[@id='section-0'][@data-sectionname='<destination>']" "xpath_element" should exist
    And "//li[@id='section-0']//*[@data-activityname='Custom child activity']" "xpath_element" should be visible
    And "//li[@data-for='section'][@data-sectionname='Custom child']" "xpath_element" should not exist

    Examples:
      | kind           | destination  |
      | unnamed custom | General      |
      | custom         | Root section |

  Scenario Outline: Selected activities show their new root destination when their subsection is unchecked
    Given the following "activities" exist:
      | activity   | name                | course | idnumber       | section |
      | subsection | Root subsection     | SRC    | rootsubsection | 1       |
      | page       | Subsection activity | SRC    | subsectionpage | 3       |
    And I log in as "admin"
    And I am on the "SRC" "Course" page
    And I switch editing mode on
    And I queue <kind> section "1" with child "2" in the Sharing Cart
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    And I switch editing mode on
    And I click on "//a[@data-key='addblock']" "xpath_element"
    And I wait until "//a[@data-blockname='sharing_cart']" "xpath_element" exists
    And I click on "//a[@data-blockname='sharing_cart']" "xpath_element"
    And I add a top-level Sharing Cart restore target for course "TARGET"
    And I wait until "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element" exists
    And I click on "#block_sharing_cart [data-type=section] [data-action=copy_to_course]" "css_element"
    And I wait until "[data-region=sharing-cart-course-target] .clipboard_target" "css_element" exists
    And I click on "[data-region=sharing-cart-course-target] .clipboard_target" "css_element"
    And I wait until "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element" exists
    And I set the field with xpath "//input[@data-container='true' and @data-title='Root subsection']" to "0"
    And I set the field with xpath "//div[contains(@class, 'import_form')]//label[contains(., 'Subsection activity')]/input" to "1"
    Then "//small[@data-region='restore-placement-hint']//strong[normalize-space(.)='<destination>']" "xpath_element" should be visible
    When I click on "//div[@class='modal-footer']//button[@data-action='save']" "xpath_element"
    And I wait until "//div[contains(concat(' ', normalize-space(@class), ' '), ' queue-item ')]" "xpath_element" exists
    And I run all adhoc tasks
    And I am on the "TARGET" "Course" page
    Then "//li[@id='section-3'][@data-sectionname='<destination>']//*[@data-activityname='Subsection activity']" "xpath_element" should be visible
    And "//div[contains(@class, 'course-content')]//*[@data-activityname='Root subsection']" "xpath_element" should not exist

    Examples:
      | kind           | destination  |
      | unnamed custom | New section  |
      | custom         | Root section |
