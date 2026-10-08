<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat steps for mod_vrroom.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Steps that drive the VR room room through the test hooks the built-in viewer exposes, open the
 * activity form with a chosen template, and wait for the room editor.
 *
 * When a room has started, its root element carries data-vrroom-ready ("3d" or "list") and, in the
 * 3D view, a vrroomTest object with enterZone(name) and clickObject(name). The room editor's root
 * carries data-vrroom-editor-ready once its document has loaded.
 */
class behat_mod_vrroom extends behat_base {
    /** @var string CSS selector of a started room. */
    private const READY_SELECTOR = '[data-region="vrroom-root"][data-vrroom-ready]';

    /** @var string CSS selector of a loaded room editor. */
    private const EDITOR_READY_SELECTOR = '[data-region="vrroom-editor"][data-vrroom-editor-ready]';

    /**
     * Resolve VR room page types for "I am on the ... page".
     *
     * Recognised page types: "Report" and "Room editor" (identifier: the activity name).
     *
     * @param string $type page type
     * @param string $identifier activity name
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'report':
                $cm = $this->get_cm_by_activity_name('vrroom', $identifier);
                return new moodle_url('/mod/vrroom/report.php', ['id' => $cm->id]);
            case 'room editor':
                $cm = $this->get_cm_by_activity_name('vrroom', $identifier);
                return new moodle_url('/mod/vrroom/edit.php', ['id' => $cm->id]);
            default:
                throw new Exception('Unrecognised mod_vrroom page type "' . $type . '."');
        }
    }

    /**
     * Open the form that adds a VR room activity built by one template, as its activity chooser
     * entry does.
     *
     * @Given I add a VR room activity using the :template template to course :coursefullname section :sectionnum
     * @param string $template template short name, e.g. gallery or escape
     * @param string $coursefullname course full name
     * @param string $sectionnum section number
     */
    public function i_add_a_vrroom_activity_using_the_template(
        string $template,
        string $coursefullname,
        string $sectionnum
    ): void {
        $courseid = $this->get_course_id($coursefullname);
        if (!$courseid) {
            throw new ExpectationException('There is no course "' . $coursefullname . '".', $this->getSession());
        }
        $addurl = new moodle_url('/course/modedit.php', [
            'add' => 'vrroom',
            'course' => $courseid,
            'section' => intval($sectionnum),
            'template' => $template,
        ]);
        $this->execute('behat_general::i_visit', [$addurl]);
    }

    // phpcs:disable moodle.Files.LineLength.TooLong -- a Behat step definition cannot be wrapped.

    /**
     * Add a VR room activity built by one template, fill the form and save it.
     *
     * @Given I add a VR room activity using the :template template to course :coursefullname section :sectionnum and I fill the form with:
     * @param string $template template short name
     * @param string $coursefullname course full name
     * @param string $sectionnum section number
     * @param TableNode $data the form fields and values
     */
    public function i_add_a_vrroom_activity_using_the_template_and_fill_the_form(
        string $template,
        string $coursefullname,
        string $sectionnum,
        TableNode $data
    ): void {
        $this->i_add_a_vrroom_activity_using_the_template($template, $coursefullname, $sectionnum);
        $this->execute('behat_general::wait_until_the_page_is_ready');
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $data);
        $this->execute('behat_forms::press_button', get_string('savechangesandreturntocourse'));
    }

    // phpcs:enable

    /**
     * Wait until the VR room room editor has loaded its document.
     *
     * @Given /^the VR room room editor is ready$/
     */
    public function the_vrroom_room_editor_is_ready(): void {
        $this->ensure_element_exists(self::EDITOR_READY_SELECTOR, 'css_element');
    }

    /**
     * Wait until the VR room room (3D or list view) has started.
     *
     * @Given /^the VR room room is ready$/
     */
    public function the_vrroom_room_is_ready(): void {
        $this->ensure_element_exists(self::READY_SELECTOR, 'css_element');
    }

    /**
     * Move the player into a zone of the 3D room, by zone name.
     *
     * @When /^I enter the VR room zone "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name zone name
     */
    public function i_enter_the_vrroom_zone(string $name): void {
        $this->call_test_hook('enterZone', $name);
    }

    /**
     * Click an object of the 3D room, by name.
     *
     * @When /^I click the VR room object "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name interactable name
     */
    public function i_click_the_vrroom_object(string $name): void {
        $this->call_test_hook('clickObject', $name);
    }

    /**
     * Call one of the 3D room's test hooks and fail when it reports false.
     *
     * @param string $method enterZone or clickObject
     * @param string $name zone or object name
     * @return void
     */
    private function call_test_hook(string $method, string $name): void {
        $this->the_vrroom_room_is_ready();
        $selector = json_encode(self::READY_SELECTOR);
        $arg = json_encode($name);
        $script = "(function() {
            var root = document.querySelector({$selector});
            if (!root || !root.vrroomTest || typeof root.vrroomTest.{$method} !== 'function') {
                return 'nohook';
            }
            return root.vrroomTest.{$method}({$arg}) ? 'ok' : 'notfound';
        })()";
        $result = $this->evaluate_script($script);
        if ($result === 'nohook') {
            throw new ExpectationException('The VR room 3D room has no test hooks (is it the 3D view?).', $this->getSession());
        }
        if ($result !== 'ok') {
            throw new ExpectationException('"' . $name . '" was not found in the VR room room.', $this->getSession());
        }
        $this->wait_for_pending_js();
    }
}
