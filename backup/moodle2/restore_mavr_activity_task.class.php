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
 * Restore task for mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/mavr/backup/moodle2/restore_mavr_stepslib.php');

/**
 * Provides the steps to restore one MAVR activity.
 */
class restore_mavr_activity_task extends restore_activity_task {

    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_mavr_activity_structure_step('mavr_structure', 'mavr.xml'));
    }

    /**
     * Content that the link decoder processes.
     *
     * Backup encodes links in every text it writes, so the JSON columns that can hold teacher
     * text (station texts in the template config, hotspot texts in scenes) are decoded too.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('mavr', ['intro', 'templateconfig', 'graphjson'], 'mavr'),
            new restore_decode_content('mavr_scenes', ['scenejson'], 'mavr_scene'),
            new restore_decode_content('mavr_graphs', ['graphjson'], 'mavr_graph'),
        ];
    }

    /**
     * Link decoding rules.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('MAVRVIEWBYID', '/mod/mavr/view.php?id=$1', 'course_module'),
            new restore_decode_rule('MAVRINDEX', '/mod/mavr/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Restore log rules for the activity (legacy log entries).
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('mavr', 'add', 'view.php?id={course_module}', '{mavr}'),
            new restore_log_rule('mavr', 'update', 'view.php?id={course_module}', '{mavr}'),
            new restore_log_rule('mavr', 'view', 'view.php?id={course_module}', '{mavr}'),
        ];
    }

    /**
     * Restore log rules for the course (legacy log entries not linked to an instance).
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('mavr', 'view all', 'index.php?id={course}', null),
        ];
    }
}
