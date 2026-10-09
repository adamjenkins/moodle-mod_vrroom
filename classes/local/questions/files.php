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

namespace mod_vrroom\local\questions;

/**
 * Serves files embedded in questions shown inside a VR room attempt.
 *
 * Core's question_pluginfile() looks up the usage's owning component and calls
 * vrroom_question_pluginfile(), which delegates here. The checks follow
 * quiz_question_pluginfile(): the usage must belong to a VR room attempt, the user
 * must be allowed into the activity, the attempt owner may fetch files while
 * the attempt is open, anyone else (or a closed attempt) needs the report
 * capability (and, in separate groups, a group shared with the attempt's owner),
 * and the question engine decides whether the file is visible under
 * the display options in force.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class files {
    /** @var string The attempt owner fetching files of their own open attempt. */
    public const ACCESS_OWN = 'own';

    /** @var string A reviewer (or the owner of a closed attempt) fetching files with feedback shown. */
    public const ACCESS_REVIEW = 'review';

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
    public static function serve_question_file(
        $course,
        $context,
        $component,
        $filearea,
        $qubaid,
        $slot,
        $args,
        $forcedownload,
        array $options = []
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $attempt = $DB->get_record('vrroom_attempts', ['uniqueid' => (int)$qubaid]);
        if (!$attempt) {
            send_file_not_found();
        }
        $cm = get_coursemodule_from_instance('vrroom', $attempt->vrroomid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            send_file_not_found();
        }
        $attemptcourse = get_course($cm->course);
        require_login($attemptcourse, false, $cm);
        $modulecontext = \context_module::instance($cm->id);

        $access = self::get_access($attempt, $cm, $attemptcourse);
        if ($access === null) {
            send_file_not_found();
        }
        $reviewing = $access === self::ACCESS_REVIEW;

        $quba = \question_engine::load_questions_usage_by_activity((int)$qubaid);
        if (
            $quba->get_owning_component() !== 'mod_vrroom'
                || (int)$quba->get_owning_context()->id !== (int)$modulecontext->id
        ) {
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

    /**
     * Decide how the current user may reach the question files of an attempt.
     *
     * The attempt owner may fetch files while the attempt is open (needs mod/vrroom:attempt). Anyone
     * else, or the owner of a closed attempt, needs mod/vrroom:viewreports and, in separate groups
     * without "access all groups", a group shared with the attempt's owner (as report.php).
     * The caller must already have called require_login() for the attempt's course and module.
     *
     * @param \stdClass $attempt The vrroom_attempts row.
     * @param \stdClass|\cm_info $cm The attempt's course module (with groupmode).
     * @param \stdClass $course The attempt's course.
     * @return string|null self::ACCESS_OWN, self::ACCESS_REVIEW, or null when the group rule refuses.
     * @throws \required_capability_exception When the user lacks the capability the access needs.
     */
    public static function get_access(\stdClass $attempt, \stdClass|\cm_info $cm, \stdClass $course): ?string {
        global $USER;

        $modulecontext = \context_module::instance($cm->id);
        $isown = (int)$attempt->userid === (int)$USER->id;
        $isopen = $attempt->state === 'inprogress';
        if ($isown && $isopen) {
            require_capability('mod/vrroom:attempt', $modulecontext);
            return self::ACCESS_OWN;
        }
        require_capability('mod/vrroom:viewreports', $modulecontext);
        // Separate groups: a reviewer sees only attempts of people in their groups (as report.php).
        if (!$isown && !groups_user_groups_visible($course, (int)$attempt->userid, $cm)) {
            return null;
        }
        return self::ACCESS_REVIEW;
    }
}
