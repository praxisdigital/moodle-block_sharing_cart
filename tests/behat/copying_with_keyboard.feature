@block @block_sharing_cart @javascript
Feature: Copy course content with the keyboard

  Scenario Outline: An editing user copies a section into the cart and back into the course using the keyboard
    Given the following config values are set as admin:
      | enable_drop_zones          | 1 | block_sharing_cart |
      | show_sharing_cart_basket   | 1 | block_sharing_cart |
      | show_copy_section_in_block | 1 | block_sharing_cart |
    And the following "courses" exist:
      | fullname        | shortname | format | numsections | initsections |
      | Keyboard course | KEYBOARD  | topics | 2           | 1            |
    And the following "activities" exist:
      | activity | name          | course   | idnumber  | section |
      | page     | Keyboard page | KEYBOARD | keypage   | 1       |
    And I log in as "admin"
    And I am on "Keyboard course" course homepage with editing mode on
    And I add the "Sharing Cart" block
    And I wait until "#section-1 .add_to_sharing_cart" "css_element" exists
    When I start keyboard navigation from the Sharing Cart test control "#section-1 [data-for=section_title] [data-inplaceeditablelink]"
    And I press the tab key
    Then the focused element is "#section-1 .add_to_sharing_cart" "css_element"
    When I press the enter key
    And I wait until ".modal.show [data-action=save]" "css_element" exists
    And I start keyboard navigation from the Sharing Cart test control ".modal.show [data-action=cancel]"
    And I press the tab key
    And I press the space key
    And I wait until "#block_sharing_cart .sharing_cart_item" "css_element" exists
    And I run all adhoc tasks
    And I wait until "#block_sharing_cart [data-action=copy_to_course]" "css_element" exists
    And I start keyboard navigation from the Sharing Cart test control "#copy_section_container select"
    And I press the shift tab key
    Then the focused element is "#block_sharing_cart [data-action=copy_to_course]" "css_element"
    When I press the space key
    And I wait until "#section-0 .clipboard_target" "css_element" exists
    Then the focused element is "#section-0 .clipboard_target" "css_element"
    When I press the escape key
    Then ".clipboard_target" "css_element" should not exist
    And the focused element is "#block_sharing_cart [data-action=copy_to_course]" "css_element"
    And no Sharing Cart restore is queued
    When I press the enter key
    And I wait until "#section-0 .clipboard_target" "css_element" exists
    Then the focused element is "#section-0 .clipboard_target" "css_element"
    When I press the space key
    And I wait until ".modal.show [data-action=save]" "css_element" exists
    And I press the escape key
    Then the focused element is "#section-0 .clipboard_target" "css_element"
    When I press the enter key
    And I wait until ".modal.show [data-action=save]" "css_element" exists
    And I control Sharing Cart queue polling for a restore "<timing>"
    And I start keyboard navigation from the Sharing Cart test control ".modal.show [data-action=cancel]"
    And I press the tab key
    And I press the enter key
    Then the focused element is "#block_sharing_cart [data-action=copy_to_course]" "css_element"
    When the Sharing Cart restore is ready to finish "<timing>"
    And I run all adhoc tasks
    And I resume Sharing Cart queue polling for a restore "<timing>"
    Then "#section-0 [data-activityname='Keyboard page']" "css_element" should be visible

    Examples:
      | timing                       |
      | before the first queue fetch |
      | without a cached outcome     |
