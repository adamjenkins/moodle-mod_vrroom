@mod @mod_vrroom @javascript
Feature: A teacher moves an object with the keyboard in the room editor
  In order to arrange a room without a mouse or a 3D view
  As a teacher
  I need to choose an object in the room contents list and change its fields

  # The room editor works without WebGL: the list and the fields are enough for these steps.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles   |
      | vrroom     | Museum walk | C1     | vrroom1    | Vase\|Portrait |

  Scenario: Move a zone by typing its centre and save
    Given I am on the "Museum walk" "mod_vrroom > Room editor" page logged in as "teacher1"
    And the VR room room editor is ready
    When I click on "Vase" "button" in the "[data-region='vrroom-editor-outline'] [data-group='zone']" "css_element"
    And I set the field "Centre X" to "2.5"
    And I press the tab key
    And I click on "[data-action='vrroom-editor-save']" "css_element"
    Then I should see "All changes saved" in the "[data-region='vrroom-editor-status']" "css_element"
    And I reload the page
    And the VR room room editor is ready
    And I click on "Vase" "button" in the "[data-region='vrroom-editor-outline'] [data-group='zone']" "css_element"
    And the field "Centre X" matches value "2.5"

  Scenario: Undo brings the old value back
    Given I am on the "Museum walk" "mod_vrroom > Room editor" page logged in as "teacher1"
    And the VR room room editor is ready
    When I click on "Vase" "button" in the "[data-region='vrroom-editor-outline'] [data-group='zone']" "css_element"
    And I set the field "Name" to "Tall vase"
    And I press the tab key
    And I should see "Tall vase" in the "[data-region='vrroom-editor-outline']" "css_element"
    And I click on "[data-action='vrroom-editor-undo']" "css_element"
    Then the field "Name" matches value "Vase"
    And I should see "Vase" in the "[data-region='vrroom-editor-outline'] [data-group='zone']" "css_element"
    And I should not see "Tall vase" in the "[data-region='vrroom-editor-outline']" "css_element"
