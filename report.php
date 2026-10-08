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
 * Teacher report of a VR room activity: attempts, one attempt's objectives and statements, and
 * attempt deletion.
 *
 * Adapted from mod_gear's analytics report over tracked events.
 *
 * @package    mod_vrroom
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\output\report_attempt;
use mod_vrroom\output\report_overview;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/vrroom/lib.php');

$id = required_param('id', PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);
$deleteid = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'vrroom');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/vrroom:viewreports', $context);
$vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);

// Group mode: the overview is filtered by the chosen group, and an attempt of someone outside the
// teacher's groups (separate groups without "access all groups") can be neither opened nor deleted.
$groupmode = groups_get_activity_groupmode($cm);
$groupid = 0;
if ($groupmode != NOGROUPS) {
    $groupid = (int)groups_get_activity_group($cm, true);
}

$baseurl = new moodle_url('/mod/vrroom/report.php', ['id' => $cm->id]);
$pageurl = new moodle_url($baseurl);
if ($attemptid) {
    $pageurl->param('attemptid', $attemptid);
}
if ($deleteid) {
    $pageurl->param('delete', $deleteid);
}
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($vrroom->name) . ': ' . get_string('reports', 'mod_vrroom'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

// Delete an attempt: confirmation page, then the deletion itself (POST with sesskey).
if ($deleteid) {
    require_capability('mod/vrroom:deleteattempts', $context);
    // The id comes from the request: it must name an attempt of THIS activity.
    $attempt = $DB->get_record('vrroom_attempts', ['id' => $deleteid, 'vrroomid' => $vrroom->id], '*', MUST_EXIST);
    report_overview::require_attempt_in_groups($course, $cm, $attempt);

    if ($confirm) {
        require_sesskey();
        attempt_manager::delete_attempt($attempt);
        redirect(
            $baseurl,
            get_string('reportattemptdeleted', 'mod_vrroom'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $user = core_user::get_user((int)$attempt->userid);
    $a = (object)[
        'attempt' => (int)$attempt->attempt,
        'user' => $user ? fullname($user) : '',
    ];
    $continue = new single_button(
        new moodle_url($baseurl, ['delete' => $attempt->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        get_string('reportdeleteattempt', 'mod_vrroom'),
        'post',
        single_button::BUTTON_DANGER
    );

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($vrroom->name));
    // The confirmation message is rendered as HTML, so the user's name is escaped here.
    echo $OUTPUT->confirm(s(get_string('reportdeleteconfirm', 'mod_vrroom', $a)), $continue, $baseurl);
    echo $OUTPUT->footer();
    exit;
}

$attempt = null;
if ($attemptid) {
    // The id comes from the request: it must name an attempt of THIS activity, and in separate
    // groups one of a person the teacher shares a group with. Checked before any output.
    $attempt = $DB->get_record('vrroom_attempts', ['id' => $attemptid, 'vrroomid' => $vrroom->id], '*', MUST_EXIST);
    report_overview::require_attempt_in_groups($course, $cm, $attempt);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($vrroom->name) . ': ' . get_string('reports', 'mod_vrroom'));

if ($attempt) {
    echo $OUTPUT->render_from_template(
        'mod_vrroom/report_attempt',
        (new report_attempt($vrroom, $cm, $context, $attempt))->export_for_template($OUTPUT)
    );
} else {
    if ($groupmode != NOGROUPS) {
        echo groups_print_activity_menu($cm, $baseurl, true);
    }
    echo $OUTPUT->render_from_template(
        'mod_vrroom/report_overview',
        (new report_overview($vrroom, $cm, $context, $groupid))->export_for_template($OUTPUT)
    );
}

echo $OUTPUT->footer();
