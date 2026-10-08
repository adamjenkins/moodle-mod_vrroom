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

namespace mod_vrroom\event;

/**
 * A VR room attempt was deleted.
 *
 * @property-read array $other {
 *      - int vrroomid: the VR room instance id.
 * }
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_deleted extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'vrroom_attempts';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptdeleted', 'mod_vrroom');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' deleted the attempt with id '$this->objectid' of the user with id " .
            "'$this->relateduserid' at the VR room activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/vrroom/report.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['vrroomid'])) {
            throw new \coding_exception('The \'vrroomid\' value must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'vrroom_attempts', 'restore' => 'vrroom_attempt'];
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['vrroomid' => ['db' => 'vrroom', 'restore' => 'vrroom']];
    }
}
