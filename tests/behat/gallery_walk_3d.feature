@mod @mod_mavr @mod_mavr_webgl @javascript
Feature: A student completes a gallery walk in the 3D room
  In order to explore a room like a visitor
  As a student
  I need to walk to the stations in 3D, answer the question there and finish

  # Needs a browser with WebGL (headless Chrome with software rendering, e.g. SwiftShader).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | qbank    | Bank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name           |
      | Activity module | qbank1    | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext               |
      | Test questions   | truefalse | Q1   | The vase was made in 1720. |
    And the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles   | stationquestions | completion | completionobjectives |
      | mavr     | Museum walk | C1     | mavr1    | Vase\|Portrait | Q1\|             | 2          | 1                    |

  Scenario: Walk to both stations, answer in the room and finish
    Given I am on the "Museum walk" "mavr activity" page logged in as "student1"
    When I press "Enter the 3D room"
    And the MAVR room is ready
    And I enter the MAVR zone "Vase"
    And I should see "The vase was made in 1720."
    And I set the field "True" to "1"
    And I press "Submit answer"
    Then I should see "Correct" in the "[data-region='mavr-overlay']" "css_element"
    And I enter the MAVR zone "Portrait"
    And I should see "You have completed everything that is required."
    And I click on "[data-action='mavr-toggle-progress']" "css_element"
    And I click on "Finish attempt" "button" in the "[data-region='mavr-progress']" "css_element"
    And I click on "[data-action='save']" "css_element" in the ".modal-dialog" "css_element"
    And I should see "Your attempts"
    And I should see "100.00"
    And the "Complete all required objectives" completion condition of "Museum walk" is displayed as "done"

  Scenario: Clicking a station object opens its panel
    Given I am on the "Museum walk" "mavr activity" page logged in as "student1"
    When I press "Enter the 3D room"
    And the MAVR room is ready
    And I click the MAVR object "Vase"
    Then I should see "Vase" in the "[data-region='mavr-panel']" "css_element"
