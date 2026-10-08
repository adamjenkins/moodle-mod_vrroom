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
 * An xAPI statement was accepted and stored for a VR room attempt.
 *
 * @property-read array $other {
 *      - int attemptid: the attempt id.
 *      - string verb: short verb.
 *      - string objecttype: object type.
 *      - string|null objectid: object id (null for the activity).
 * }
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statement_recorded extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'vrroom_events';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventstatementrecorded', 'mod_vrroom');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $verb = $this->other['verb'] ?? '';
        $type = $this->other['objecttype'] ?? '';
        $attemptid = $this->other['attemptid'] ?? 0;
        return "A statement '$verb' about a '$type' was recorded for the user with id '$this->relateduserid' in the " .
            "attempt with id '$attemptid' at the VR room activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/vrroom/report.php', [
            'id' => $this->contextinstanceid,
            'attemptid' => $this->other['attemptid'] ?? 0,
        ]);
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
        foreach (['attemptid', 'verb', 'objecttype'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
        if (!array_key_exists('objectid', $this->other)) {
            throw new \coding_exception('The \'objectid\' value must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'vrroom_events', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'attemptid' => ['db' => 'vrroom_attempts', 'restore' => 'vrroom_attempt'],
            'verb' => \core\event\base::NOT_MAPPED,
            'objecttype' => \core\event\base::NOT_MAPPED,
            'objectid' => \core\event\base::NOT_MAPPED,
        ];
    }
}
