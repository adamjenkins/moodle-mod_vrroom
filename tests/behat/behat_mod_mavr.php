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
 * Behat steps for mod_mavr.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps that drive the MAVR room through the test hooks the built-in viewer exposes.
 *
 * When a room has started, its root element carries data-mavr-ready ("3d" or "list") and, in the
 * 3D view, a mavrTest object with enterZone(name) and clickObject(name).
 */
class behat_mod_mavr extends behat_base {

    /** @var string CSS selector of a started room. */
    private const READY_SELECTOR = '[data-region="mavr-root"][data-mavr-ready]';

    /**
     * Resolve MAVR page types for "I am on the ... page".
     *
     * Recognised page types: "Report" (identifier: the activity name).
     *
     * @param string $type page type
     * @param string $identifier activity name
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'report':
                $cm = $this->get_cm_by_activity_name('mavr', $identifier);
                return new moodle_url('/mod/mavr/report.php', ['id' => $cm->id]);
            default:
                throw new Exception('Unrecognised mod_mavr page type "' . $type . '."');
        }
    }

    /**
     * Wait until the MAVR room (3D or list view) has started.
     *
     * @Given /^the MAVR room is ready$/
     */
    public function the_mavr_room_is_ready(): void {
        $this->ensure_element_exists(self::READY_SELECTOR, 'css_element');
    }

    /**
     * Move the player into a zone of the 3D room, by zone name.
     *
     * @When /^I enter the MAVR zone "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name zone name
     */
    public function i_enter_the_mavr_zone(string $name): void {
        $this->call_test_hook('enterZone', $name);
    }

    /**
     * Click an object of the 3D room, by name.
     *
     * @When /^I click the MAVR object "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name interactable name
     */
    public function i_click_the_mavr_object(string $name): void {
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
        $this->the_mavr_room_is_ready();
        $selector = json_encode(self::READY_SELECTOR);
        $arg = json_encode($name);
        $script = "(function() {
            var root = document.querySelector({$selector});
            if (!root || !root.mavrTest || typeof root.mavrTest.{$method} !== 'function') {
                return 'nohook';
            }
            return root.mavrTest.{$method}({$arg}) ? 'ok' : 'notfound';
        })()";
        $result = $this->evaluate_script($script);
        if ($result === 'nohook') {
            throw new ExpectationException('The MAVR 3D room has no test hooks (is it the 3D view?).', $this->getSession());
        }
        if ($result !== 'ok') {
            throw new ExpectationException('"' . $name . '" was not found in the MAVR room.', $this->getSession());
        }
        $this->wait_for_pending_js();
    }
}
