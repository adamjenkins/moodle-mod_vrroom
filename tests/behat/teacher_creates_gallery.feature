@mod @mod_mavr @javascript
Feature: A teacher builds a gallery walk with the form
  In order to give students a room to explore
  As a teacher
  I need to create a MAVR gallery walk from the activity form

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
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
      | questioncategory | qtype     | name | questiontext               |
      | Test questions   | truefalse | Q1   | The vase was made in 1720. |

  Scenario: Create a gallery walk with two stations and one question
    Given I log in as "teacher1"
    When I add a MAVR activity using the "gallery" template to course "Course 1" section "1" and I fill the form with:
      | Name                | Museum walk |
      | gallery_title[0]    | Vase        |
      | gallery_title[1]    | Portrait    |
      | gallery_question[0] | Q1          |
    Then I should see "Museum walk"
    And I am on the "Museum walk" "mavr activity" page
    And I should see "Preview the list view"
    And I press "Preview the list view"
    And the MAVR room is ready
    And I should see "Vase" in the "[data-region='mavr-listview']" "css_element"
    And I should see "Portrait" in the "[data-region='mavr-listview']" "css_element"

  Scenario: Station titles are required
    Given I log in as "teacher1"
    When I add a MAVR activity using the "gallery" template to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name | Empty walk |
    And I press "Save and return to course"
    Then I should see "Add at least one station."
