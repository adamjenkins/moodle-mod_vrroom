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
 * Backup task for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/vrroom/backup/moodle2/backup_vrroom_stepslib.php');

/**
 * Provides the steps to back up one VR room activity.
 *
 * As mod_quiz, the activity uses question-bank questions, so the question categories step and
 * the temporary-questions cleanup step follow the structure step.
 */
class backup_vrroom_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * The structure step, then the question bank steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_vrroom_activity_structure_step('vrroom_structure', 'vrroom.xml'));
        // Work out which question categories the annotated questions need.
        $this->add_step(new backup_calculate_question_categories('activity_question_categories'));
        // Remove the questions annotated in the temporary table; they are no longer needed.
        $this->add_step(new backup_delete_temp_questions('clean_temp_questions'));
    }

    /**
     * Encode links to index.php and view.php.
     *
     * @param string $content some HTML text that may contain URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of VR room activities.
        $search = '/(' . $base . '\/mod\/vrroom\/index.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@VR roomINDEX*$2@$', $content);

        // Link to a VR room activity by course module id.
        $search = '/(' . $base . '\/mod\/vrroom\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@VR roomVIEWBYID*$2@$', $content);

        return $content;
    }
}
