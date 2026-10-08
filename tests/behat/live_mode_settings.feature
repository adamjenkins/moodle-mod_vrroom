@mod @mod_vrroom @vrroomruntime_builtin @javascript
Feature: A teacher turns on live mode for a VR room activity
  In order to let students meet in the room while they work
  As a teacher
  I need to turn on live mode and choose how many people a live room holds

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
      | activity | name        | course | idnumber | stationtitles  |
      | vrroom     | Museum walk | C1     | vrroom1    | Vase\|Portrait |

  Scenario: Live mode and the room size are saved
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And the field "Live mode" matches value "Off"
    When I set the following fields to these values:
      | Live mode            | On |
      | Maximum participants | 4  |
    And I press "Save and display"
    And I am on the "Museum walk" "vrroom activity editing" page
    And I expand all fieldsets
    Then the field "Live mode" matches value "On"
    And the field "Maximum participants" matches value "4"

  Scenario: Live mode cannot be chosen when it is turned off on the site
    Given the following config values are set as admin:
      | liveenabled | 0 | vrroomruntime_builtin |
    When I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then I should see "Live mode is turned off on this site."
    And "Maximum participants" "field" should not exist
