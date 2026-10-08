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

namespace mod_mavr\local\questions;

/**
 * Serves files embedded in questions shown inside a MAVR attempt.
 *
 * Core's question_pluginfile() looks up the usage's owning component and calls
 * mavr_question_pluginfile(), which delegates here. The checks follow
 * quiz_question_pluginfile(): the usage must belong to a MAVR attempt, the user
 * must be allowed into the activity, the attempt owner may fetch files while
 * the attempt is open, anyone else (or a closed attempt) needs the report
 * capability, and the question engine decides whether the file is visible under
 * the display options in force.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class files {
    /**
     * Send a question file, or a "not found" response. Never returns normally when a file is sent.
     *
     * @param \stdClass $course The course core resolved from the file's context (re-derived from the attempt).
     * @param \context $context The context the file lives in (the question's bank context).
     * @param string $component The component that owns the file area (e.g. 'question', 'qtype_x').
     * @param string $filearea The file area.
     * @param int $qubaid The question usage id.
     * @param int $slot The usage slot number.
     * @param array $args The remaining path components (itemid..., filename).
     * @param bool $forcedownload Whether to force download.
     * @param array $options Additional file serving options.
     */
    public static function serve_question_file($course, $context, $component, $filearea, $qubaid, $slot, $args,
            $forcedownload, array $options = []): void {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $attempt = $DB->get_record('mavr_attempts', ['uniqueid' => (int)$qubaid]);
        if (!$attempt) {
            send_file_not_found();
        }
        $cm = get_coursemodule_from_instance('mavr', $attempt->mavrid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            send_file_not_found();
        }
        $attemptcourse = get_course($cm->course);
        require_login($attemptcourse, false, $cm);
        $modulecontext = \context_module::instance($cm->id);

        $isown = (int)$attempt->userid === (int)$USER->id;
        $isopen = $attempt->state === 'inprogress';
        if ($isown && $isopen) {
            require_capability('mod/mavr:attempt', $modulecontext);
            $reviewing = false;
        } else {
            require_capability('mod/mavr:viewreports', $modulecontext);
            $reviewing = true;
        }

        $quba = \question_engine::load_questions_usage_by_activity((int)$qubaid);
        if ($quba->get_owning_component() !== 'mod_mavr'
                || (int)$quba->get_owning_context()->id !== (int)$modulecontext->id) {
            send_file_not_found();
        }
        $slot = (int)$slot;
        if (!in_array($slot, $quba->get_slots(), false)) {
            send_file_not_found();
        }

        // Show what the panel shows: feedback once the question is answered, or to a reviewer.
        $feedback = $reviewing || $quba->get_question_state($slot)->is_finished();
        $displayoptions = usage::display_options($feedback);
        $displayoptions->context = $modulecontext;
        if (!$quba->check_file_access($slot, $displayoptions, $component, $filearea, $args, $forcedownload)) {
            send_file_not_found();
        }

        $fs = get_file_storage();
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/{$component}/{$filearea}/{$relativepath}";
        $file = $fs->get_file_by_hash(sha1($fullpath));
        if (!$file || $file->is_directory()) {
            send_file_not_found();
        }

        send_stored_file($file, 0, 0, $forcedownload, $options);
    }
}
