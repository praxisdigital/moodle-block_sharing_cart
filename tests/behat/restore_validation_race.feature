@block @block_sharing_cart @block_sharing_cart_validation_race @javascript
Feature: Delayed copy validation cannot overwrite a newer selection
  As an editing teacher
  I want obsolete copy errors to be discarded
  So that correcting my selection does not show an error from an earlier attempt

  Scenario: Delayed validation is discarded after changing the selection or closing the dialog
    Given I log in as "admin"
    Then delayed Sharing Cart validation cannot overwrite a newer selection
