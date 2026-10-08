@mod @mod_vrroom @javascript
Feature: A student completes a gallery walk in the list view
  In order to take part without 3D, a keyboard or a screen reader being a problem
  As a student
  I need to visit every station, answer its question and finish in the list view

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
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
      | vrroom     | Museum walk | C1     | vrroom1    | Vase\|Portrait | Q1\|             | 2          | 1                    |

  Scenario: Visit both stations, answer the question and finish
    Given I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    And the "Complete all required objectives" completion condition of "Museum walk" is displayed as "todo"
    When I press "Open the list view"
    And the VR room room is ready
    And I click on "Open station" "button" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Vase']]" "xpath_element"
    And I set the field "True" to "1"
    And I press "Submit answer"
    Then I should see "Correct" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Vase']]" "xpath_element"
    And I click on "Open station" "button" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Portrait']]" "xpath_element"
    And I should see "You have completed everything that is required."
    And I click on "Finish attempt" "button" in the "[data-region='vrroom-list-progress']" "css_element"
    And I click on "[data-action='save']" "css_element" in the ".modal-dialog" "css_element"
    And I should see "Your attempts"
    And I should see "Finished"
    And I should see "100.00"
    And the "Complete all required objectives" completion condition of "Museum walk" is displayed as "done"
    And I am on "Course 1" course homepage
    And I navigate to "Grades" in current page administration
    And I should see "100.00" in the "Museum walk" "table_row"

  Scenario: A station's question stays locked until the station is visited
    Given I am on the "Museum walk" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    Then I should not see "The vase was made in 1720."
    And I click on "Open station" "button" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Vase']]" "xpath_element"
    And I should see "The vase was made in 1720."
