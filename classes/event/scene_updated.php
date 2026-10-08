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
 * The rooms of a MAVR activity were changed in the room editor.
 *
 * @property-read array $other {
 *      - int scenecount: the number of rooms after the change.
 * }
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scene_updated extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'mavr';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsceneupdated', 'mod_mavr');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $count = (int)($this->other['scenecount'] ?? 0);
        return "The user with id '$this->userid' changed the rooms of the MAVR activity with course module id " .
            "'$this->contextinstanceid' in the room editor; it now has $count room(s).";
    }

    /**
     * Relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/mavr/edit.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('The context must be a module context.');
        }
        if (!isset($this->other['scenecount'])) {
            throw new \coding_exception('The \'scenecount\' value must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'mavr', 'restore' => 'mavr'];
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
