@mod @mod_mavr @javascript
Feature: A teacher reviews and deletes attempts
  In order to follow what students did in a room
  As a teacher
  I need to see their attempts, objectives and statements, and delete attempts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name        | course | idnumber | stationtitles   |
      | mavr     | Museum walk | C1     | mavr1    | Vase\|Portrait |
    And I am on the "Museum walk" "mavr activity" page logged in as "student1"
    And I press "Open the list view"
    And the MAVR room is ready
    And I click on "Open station" "button" in the "//section[@data-region='mavr-station'][.//h3[normalize-space()='Vase']]" "xpath_element"
    And I click on "Finish attempt" "button" in the "[data-region='mavr-list-progress']" "css_element"
    And I click on "[data-action='save']" "css_element" in the ".modal-dialog" "css_element"
    And I should see "Your attempts"
    And I log out

  Scenario: Review an attempt and its statements
    Given I am on the "Museum walk" "mod_mavr > Report" page logged in as "teacher1"
    Then I should see "Student One" in the "[data-region='mavr-report-attempts']" "css_element"
    And I should see "Finished" in the "Student One" "table_row"
    And I click on "View details" "link" in the "Student One" "table_row"
    And I should see "Attempt 1 by Student One"
    And I should see "Completed" in the "[data-region='mavr-report-objectives']" "css_element"
    And I should see "Available" in the "[data-region='mavr-report-objectives']" "css_element"
    And I should see "Browser" in the "[data-region='mavr-report-statements']" "css_element"
    And I should see "Server" in the "[data-region='mavr-report-statements']" "css_element"
    And I should see "Vase" in the "[data-region='mavr-report-statements']" "css_element"

  Scenario: The report is reached from the activity's settings
    Given I am on the "Museum walk" "mavr activity" page logged in as "teacher1"
    When I navigate to "Reports" in current page administration
    Then I should see "Attempts"
    And I should see "Student One"

  Scenario: Delete an attempt
    Given I am on the "Museum walk" "mod_mavr > Report" page logged in as "teacher1"
    When I click on "Delete attempt" "link" in the "Student One" "table_row"
    And I should see "Delete attempt 1 by Student One? The grade will be recalculated."
    And I press "Delete attempt"
    Then I should see "The attempt has been deleted."
    And I should see "There are no attempts yet."

  Scenario: Students cannot open the report
    Given I am on the "Museum walk" "mavr activity" page logged in as "student1"
    Then "Reports" "link" should not exist in current page administration
