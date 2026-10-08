@mod @mod_vrroom @javascript
Feature: The activity settings show a picture of the chosen room
  In order to know what a room will look like before saving
  As a teacher
  I need to see a preview of the room chosen in the activity settings

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

  Scenario: The preview follows the chosen room before saving
    Given I log in as "teacher1"
    When I add a VR room activity using the "gallery" template to course "Course 1" section "1"
    And I expand all fieldsets
    Then "img[alt='Gallery hall']" "css_element" should exist in the "[data-region='vrroom-envpreview']" "css_element"
    And I should see "Gallery hall" in the "[data-region='vrroom-envpreview'] [data-region='name']" "css_element"
    And I should see "Places for 10 stations" in the "[data-region='vrroom-envpreview']" "css_element"
    When I set the field "gallery_environment" to "Courtyard"
    Then I should see "Courtyard" in the "[data-region='vrroom-envpreview'] [data-region='name']" "css_element"
    And "img[alt='Courtyard']" "css_element" should exist in the "[data-region='vrroom-envpreview']" "css_element"
    When I set the field "gallery_environment" to "Upload my own model (GLB)"
    Then I should see "No preview: the room is your own 3D model." in the "[data-region='vrroom-envpreview']" "css_element"
