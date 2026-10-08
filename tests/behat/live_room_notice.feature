@mod @mod_vrroom @vrroomruntime_builtin @javascript
Feature: A student sees the live room of a VR room activity
  In order to meet the others working on the activity, knowing what is shared
  As a student
  I need to see what joining a live room discloses, and join and leave a room

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles  | livemode |
      | vrroom     | Museum walk | C1     | vrroom1    | Vase\|Portrait | 1        |
      | vrroom     | Quiet walk  | C1     | vrroom2    | Vase\|Portrait | 0        |

  Scenario: The notice names the IP address disclosure, and the student joins and leaves a room
    Given I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then I should see "Live room" in the "[data-region='vrroom-liveroom']" "css_element"
    And I should see "IP address" in the "[data-region='vrroom-live-notice']" "css_element"
    And "Join the live room" "button" should not exist
    And I should see "Room 1" in the "[data-region='vrroom-live-rooms']" "css_element"
    And I click on "[data-region='vrroom-live-room'][data-room='1'] [data-action='vrroom-live-joinroom']" "css_element"
    And I should see "Only you are here so far." in the "[data-region='vrroom-live-status']" "css_element"
    And I should see "You are in Room 1." in the "[data-region='vrroom-live-current']" "css_element"
    And "Leave the live room" "button" should be visible
    And "[data-region='vrroom-live-rooms']" "css_element" should not be visible
    And I press "Leave the live room"
    And "[data-region='vrroom-live-rooms']" "css_element" should be visible
    And "Leave the live room" "button" should not be visible

  Scenario: Without live mode there is no live room
    Given I am on the "Quiet walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then "Join the live room" "button" should not exist
    And "[data-region='vrroom-live-rooms']" "css_element" should not exist
    And I should not see "Live room"
