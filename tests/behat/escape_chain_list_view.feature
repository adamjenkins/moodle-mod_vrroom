@mod @mod_vrroom @javascript
Feature: A student escapes in the list view
  In order to solve an escape chain without 3D
  As a student
  I need to find clues, ask for a hint, take a key, open a lock with a question and leave

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
      | activity | name   | course | idnumber |
      | qbank    | Bank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name           |
      | Activity module | qbank1    | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext            |
      | Test questions   | truefalse | Q1   | The safe code is 1234. |
    And the following "activities" exist:
      | activity | name       | course | idnumber | template | puzzletitles | puzzlelocks    | puzzlequestions | puzzlehints          | hintpenalty |
      | vrroom     | Lab escape | C1     | vrroom1    | escape   | Desk\|Safe   | key\|question | \|Q1            | Look on the desk.\| | 0.5         |

  Scenario: Ask for a hint, take the key, retry the lock and leave through the exit door
    # Points: Desk's key 1 point less the 0.5 hint penalty = 0.5; Safe's lock 1 point (the wrong
    # first answer only asks for a retry); finding a clue and the exit door are worth 0.
    # Score (0.5 + 1) / 2 = 0.75, so the grade out of 100 is 75.00.
    Given I am on the "Lab escape" "vrroom activity" page logged in as "student1"
    When I press "Open the list view"
    And the VR room room is ready
    And I click on "Open station" "button" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Desk']]" "xpath_element"
    And I click on "Get a hint" "button"
    Then I should see "Look on the desk." in the "[data-region='vrroom-hints']" "css_element"
    And I click on "Take Key" "button"
    And I click on "Open station" "button" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Safe']]" "xpath_element"
    And I should see "The safe code is 1234."
    And I set the field "False" to "1"
    And I press "Submit answer"
    And I should see "That is not right yet. You can try again."
    And I click on "Try again" "button"
    And I set the field "True" to "1"
    And I press "Submit answer"
    And I should see "Correct" in the "//section[@data-region='vrroom-station'][.//h3[normalize-space()='Safe']]" "xpath_element"
    And I click on "Use Exit door" "button"
    And I should see "You have completed everything that is required."
    And I click on "Finish attempt" "button" in the "[data-region='vrroom-list-progress']" "css_element"
    And I click on "[data-action='save']" "css_element" in the ".modal-dialog" "css_element"
    And I should see "Your attempts"
    And I should see "75.00"
    And I am on "Course 1" course homepage
    And I navigate to "Grades" in current page administration
    And I should see "75.00" in the "Lab escape" "table_row"
