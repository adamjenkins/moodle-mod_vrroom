@mod @mod_vrroom @vrroomruntime_builtin @javascript
Feature: A teacher turns on live mode for a VR room activity
  In order to let students meet in the room while they work
  As a teacher
  I need to turn on live mode and choose how many rooms there are and how many people a room holds

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

  Scenario: Live mode with fixed rooms, the number of rooms and the room size are saved
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And the field "Live mode" matches value "Off"
    When I set the following fields to these values:
      | Live mode            | On, fixed rooms |
      | Number of rooms      | 3               |
      | Maximum participants | 4               |
    And I press "Save and display"
    And I am on the "Museum walk" "vrroom activity editing" page
    And I expand all fieldsets
    Then the field "Live mode" matches value "On, fixed rooms"
    And the field "Number of rooms" matches value "3"
    And the field "Maximum participants" matches value "4"

  Scenario: The number of rooms and the room size are shown only when live mode is on
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I set the field "Live mode" to "Off"
    Then "Number of rooms" "field" should not be visible
    And "Maximum participants" "field" should not be visible
    And I set the field "Live mode" to "On, students open rooms"
    And "Number of rooms" "field" should be visible
    And "Maximum participants" "field" should be visible

  Scenario: Webcams in live rooms are on by default, shown only with live mode on, and can be turned off
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I set the field "Live mode" to "Off"
    Then "Webcams in live rooms" "field" should not be visible
    And I set the field "Live mode" to "On, fixed rooms"
    And "Webcams in live rooms" "field" should be visible
    And the field "Webcams in live rooms" matches value "1"
    And I set the field "Webcams in live rooms" to "0"
    And I press "Save and display"
    And I am on the "Museum walk" "vrroom activity editing" page
    And I expand all fieldsets
    And the field "Webcams in live rooms" matches value "0"

  Scenario: Students open rooms without a limit on the number of rooms
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I set the following fields to these values:
      | Live mode            | On, students open rooms |
      | Number of rooms      | No limit                |
      | Maximum participants | 2                       |
    And I press "Save and display"
    And I am on the "Museum walk" "vrroom activity editing" page
    And I expand all fieldsets
    Then the field "Live mode" matches value "On, students open rooms"
    And the field "Number of rooms" matches value "No limit"
    And the field "Maximum participants" matches value "2"

  Scenario: Fixed rooms need a number of rooms
    Given I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I set the following fields to these values:
      | Live mode       | On, fixed rooms |
      | Number of rooms | No limit        |
    And I press "Save and display"
    Then I should see "With fixed rooms, choose from 1 to 20 rooms."

  Scenario: Live mode cannot be chosen when it is turned off on the site
    Given the following config values are set as admin:
      | liveenabled | 0 | vrroomruntime_builtin |
    When I am on the "Museum walk" "vrroom activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then I should see "Live mode is turned off on this site."
    And "Maximum participants" "field" should not exist
    And "Number of rooms" "field" should not exist
