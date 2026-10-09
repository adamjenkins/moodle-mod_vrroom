@mod @mod_vrroom @vrroomruntime_builtin @javascript
Feature: A student chooses a live room of a VR room activity
  In order to meet others in a room that has space
  As a student
  I need to see the live rooms with how full they are, join one, or start a new one

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |

  Scenario: With fixed rooms a full room has no Join button and the student joins another room
    Given the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles  | livemode | liverooms | livemaxusers |
      | vrroom   | Museum walk | C1     | vrroom1  | Vase\|Portrait | 1        | 2         | 2            |
    And the following "vrroomruntime_builtin > presences" exist:
      | activity | user     | room |
      | vrroom1  | student2 | 1    |
      | vrroom1  | student3 | 1    |
    And I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then I should see "Room 1" in the "[data-region='vrroom-live-room'][data-room='1']" "css_element"
    And I should see "2 of 2" in the "[data-region='vrroom-live-room'][data-room='1']" "css_element"
    And I should see "(full)" in the "[data-region='vrroom-live-room'][data-room='1']" "css_element"
    And "[data-region='vrroom-live-room'][data-room='1'] [data-action='vrroom-live-joinroom']" "css_element" should not exist
    And I should see "Room 2" in the "[data-region='vrroom-live-room'][data-room='2']" "css_element"
    And I should see "0 of 2" in the "[data-region='vrroom-live-room'][data-room='2']" "css_element"
    And "[data-action='vrroom-live-start']" "css_element" should not exist
    And I click on "[data-region='vrroom-live-room'][data-room='2'] [data-action='vrroom-live-joinroom']" "css_element"
    And I should see "Only you are here so far." in the "[data-region='vrroom-live-status']" "css_element"
    And I should see "You are in Room 2." in the "[data-region='vrroom-live-current']" "css_element"

  Scenario: When students open rooms, no new room can be started at the maximum, but a room with space can be joined
    Given the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles  | livemode | liverooms | livemaxusers |
      | vrroom   | Museum walk | C1     | vrroom1  | Vase\|Portrait | 2        | 1         | 2            |
    And the following "vrroomruntime_builtin > presences" exist:
      | activity | user     | room |
      | vrroom1  | student2 | 1    |
    And I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then I should see "1 of 2" in the "[data-region='vrroom-live-room'][data-room='1']" "css_element"
    And the "disabled" attribute of "Start a new room" "button" should be set
    And I should see "No more rooms can be opened (the most allowed at once: 1)." in the "[data-region='vrroom-live-startreason']" "css_element"
    And I click on "[data-region='vrroom-live-room'][data-room='1'] [data-action='vrroom-live-joinroom']" "css_element"
    And I should see "You are in Room 1." in the "[data-region='vrroom-live-current']" "css_element"

  Scenario: When students open rooms without a limit, a student starts a room that closes when they leave
    Given the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles  | livemode | liverooms | livemaxusers |
      | vrroom   | Museum walk | C1     | vrroom1  | Vase\|Portrait | 2        | 0         | 2            |
    And I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then I should see "No room is open yet. Start a new room." in the "[data-region='vrroom-live-noroom']" "css_element"
    And I press "Start a new room"
    And I should see "Only you are here so far." in the "[data-region='vrroom-live-status']" "css_element"
    And I should see "You are in Room 1." in the "[data-region='vrroom-live-current']" "css_element"
    And I press "Leave the live room"
    And I should see "No room is open yet. Start a new room." in the "[data-region='vrroom-live-noroom']" "css_element"

  Scenario: Without live mode there is no list of rooms
    Given the following "activities" exist:
      | activity | name       | course | idnumber | stationtitles  | livemode |
      | vrroom   | Quiet walk | C1     | vrroom2  | Vase\|Portrait | 0        |
    And I am on the "Quiet walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then "[data-region='vrroom-live-rooms']" "css_element" should not exist
    And "Start a new room" "button" should not exist
