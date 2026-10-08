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

namespace mod_mavr\event;

/**
 * A MAVR attempt was finished.
 *
 * @property-read array $other {
 *      - int mavrid: the MAVR instance id.
 *      - float score: the attempt's score fraction (0..1).
 * }
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_finished extends \core\event\base {

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'mavr_attempts';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptfinished', 'mod_mavr');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' finished the attempt with id '$this->objectid' at the " .
            "MAVR activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/mavr/report.php', ['id' => $this->contextinstanceid, 'attemptid' => $this->objectid]);
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
        if (!isset($this->other['mavrid'])) {
            throw new \coding_exception('The \'mavrid\' value must be set in other.');
        }
        if (!array_key_exists('score', $this->other)) {
            throw new \coding_exception('The \'score\' value must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'mavr_attempts', 'restore' => 'mavr_attempt'];
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['mavrid' => ['db' => 'mavr', 'restore' => 'mavr']];
    }
}
