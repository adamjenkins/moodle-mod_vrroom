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

namespace mod_mavr\local;

use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\grading\grader;
use mod_mavr\local\runtime\cleanup_scope;
use mod_mavr\local\runtime\registry as runtime_registry;

/**
 * Course reset for MAVR activities (the lib.php reset callbacks delegate here).
 *
 * MAVR stores no dates that a reset would shift, so only user data is handled: attempts with
 * their question usages, statements and xAPI resume states, plus the gradebook.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reset {

    /** @var string Name of the reset form checkbox. */
    public const FIELD_ATTEMPTS = 'reset_mavr_attempts';

    /**
     * Add the MAVR elements to the course reset form.
     *
     * @param \MoodleQuickForm $mform the course reset form
     * @return void
     */
    public static function course_form_definition(\MoodleQuickForm $mform): void {
        $mform->addElement('header', 'mavrheader', get_string('modulenameplural', 'mod_mavr'));
        $mform->addElement('advcheckbox', self::FIELD_ATTEMPTS, get_string('resetattempts', 'mod_mavr'));
    }

    /**
     * Defaults for the MAVR elements of the course reset form.
     *
     * @param \stdClass $course the course being reset
     * @return array element name => default
     */
    public static function course_form_defaults(\stdClass $course): array {
        return [self::FIELD_ATTEMPTS => 1];
    }

    /**
     * Reset the gradebook items of every MAVR activity in a course.
     *
     * @param int $courseid course id
     * @return void
     */
    public static function reset_gradebook(int $courseid): void {
        foreach (self::get_instances($courseid) as $mavr) {
            grader::grade_item_update($mavr, 'reset');
        }
    }

    /**
     * Delete user data on course reset.
     *
     * @param \stdClass $data the reset form data (courseid, reset_mavr_attempts, reset_gradebook_grades)
     * @return array status rows ['component' => ..., 'item' => ..., 'error' => bool]
     */
    public static function reset_userdata(\stdClass $data): array {
        $status = [];
        if (empty($data->{self::FIELD_ATTEMPTS})) {
            return $status;
        }

        $courseid = (int)$data->courseid;
        foreach (self::get_instances($courseid) as $mavr) {
            // Deletes attempts, their usages, statements and xAPI states, and resets the grade item.
            attempt_manager::delete_all_for_instance($mavr);
            self::cleanup_runtime($mavr, $courseid);
        }

        // Core resets every grade itself when the gradebook grades are being reset.
        if (empty($data->reset_gradebook_grades)) {
            self::reset_gradebook($courseid);
        }

        $status[] = [
            'component' => get_string('modulenameplural', 'mod_mavr'),
            'item' => get_string('attemptsdeleted', 'mod_mavr'),
            'error' => false,
        ];
        return $status;
    }

    /**
     * The MAVR activities of a course.
     *
     * @param int $courseid course id
     * @return \stdClass[] mavr rows (with cmidnumber, as grade_item_update expects)
     */
    private static function get_instances(int $courseid): array {
        global $DB;
        $sql = "SELECT m.*, cm.idnumber AS cmidnumber
                  FROM {mavr} m
                  JOIN {course_modules} cm ON cm.instance = m.id
                  JOIN {modules} md ON md.id = cm.module AND md.name = :modname
                 WHERE m.course = :courseid";
        return $DB->get_records_sql($sql, ['modname' => 'mavr', 'courseid' => $courseid]);
    }

    /**
     * Let the activity's run mode remove what it keeps outside Moodle for this course.
     *
     * @param \stdClass $mavr mavr row
     * @param int $courseid course id
     * @return void
     */
    private static function cleanup_runtime(\stdClass $mavr, int $courseid): void {
        try {
            $runtime = runtime_registry::get((string)$mavr->runtime);
        } catch (\moodle_exception $e) {
            // The run mode is not installed, so it cannot hold anything to clean up.
            return;
        }
        $runtime->cleanup(new cleanup_scope(cleanup_scope::COURSE_RESET, $courseid, (int)$mavr->id));
    }
}
