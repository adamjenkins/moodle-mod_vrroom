@mod @mod_mavr @javascript
Feature: A teacher builds an escape chain
  In order to give students a chain of puzzles to solve
  As a teacher
  I need to create a MAVR escape chain from its activity chooser entry and its form

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
      | activity | name   | course | idnumber |
      | qbank    | Bank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name           |
      | Activity module | qbank1    | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext            |
      | Test questions   | truefalse | Q1   | The safe code is 1234. |

  Scenario: Create an escape chain with a key puzzle and a question lock
    Given I log in as "teacher1"
    When I add a MAVR activity using the "escape" template to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name               | Lab escape                                   |
      | escape_title[0]    | Desk                                         |
      | escape_locktype[0] | Find and take a key                          |
      | escape_hint1[0]    | Look on the desk.                            |
      | escape_title[1]    | Safe                                         |
      | escape_locktype[1] | Unlock with a question (for example a code) |
    # The question picker is an autocomplete: its named select is hidden, so set it by label.
    And I set the field "Question" in the "#fitem_id_escape_question_1" "css_element" to "Q1"
    And I press "Save and return to course"
    Then I should see "Lab escape"
    And I am on the "Lab escape" "mavr activity" page
    And I press "Preview the list view"
    And the MAVR room is ready
    And I should see "Desk" in the "[data-region='mavr-listview']" "css_element"
    And I should see "Safe" in the "[data-region='mavr-listview']" "css_element"

  Scenario: The activity chooser entry opens the escape chain form only
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add a "MAVR: Escape chain" to section "1" using the activity chooser
    Then I should see "Escape chain"
    And "escape_title[0]" "field" should exist
    And "gallery_title[0]" "field" should not exist
