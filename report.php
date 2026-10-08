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
 * Teacher report of a MAVR activity: attempts, one attempt's objectives and statements, and
 * attempt deletion.
 *
 * Adapted from mod_gear's analytics report over tracked events.
 *
 * @package    mod_mavr
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\output\report_attempt;
use mod_mavr\output\report_overview;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/mavr/lib.php');

$id = required_param('id', PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);
$deleteid = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'mavr');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mavr:viewreports', $context);
$mavr = $DB->get_record('mavr', ['id' => $cm->instance], '*', MUST_EXIST);

$baseurl = new moodle_url('/mod/mavr/report.php', ['id' => $cm->id]);
$pageurl = new moodle_url($baseurl);
if ($attemptid) {
    $pageurl->param('attemptid', $attemptid);
}
if ($deleteid) {
    $pageurl->param('delete', $deleteid);
}
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($mavr->name) . ': ' . get_string('reports', 'mod_mavr'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

// Delete an attempt: confirmation page, then the deletion itself (POST with sesskey).
if ($deleteid) {
    require_capability('mod/mavr:deleteattempts', $context);
    // The id comes from the request: it must name an attempt of THIS activity.
    $attempt = $DB->get_record('mavr_attempts', ['id' => $deleteid, 'mavrid' => $mavr->id], '*', MUST_EXIST);

    if ($confirm) {
        require_sesskey();
        attempt_manager::delete_attempt($attempt);
        redirect($baseurl, get_string('reportattemptdeleted', 'mod_mavr'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    $user = core_user::get_user((int)$attempt->userid);
    $a = (object)[
        'attempt' => (int)$attempt->attempt,
        'user' => $user ? fullname($user) : '',
    ];
    $continue = new single_button(
        new moodle_url($baseurl, ['delete' => $attempt->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        get_string('reportdeleteattempt', 'mod_mavr'),
        'post',
        single_button::BUTTON_DANGER
    );

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($mavr->name));
    // The confirmation message is rendered as HTML, so the user's name is escaped here.
    echo $OUTPUT->confirm(s(get_string('reportdeleteconfirm', 'mod_mavr', $a)), $continue, $baseurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($mavr->name) . ': ' . get_string('reports', 'mod_mavr'));

if ($attemptid) {
    // The id comes from the request: it must name an attempt of THIS activity.
    $attempt = $DB->get_record('mavr_attempts', ['id' => $attemptid, 'mavrid' => $mavr->id], '*', MUST_EXIST);
    echo $OUTPUT->render_from_template('mod_mavr/report_attempt',
        (new report_attempt($mavr, $cm, $context, $attempt))->export_for_template($OUTPUT));
} else {
    echo $OUTPUT->render_from_template('mod_mavr/report_overview',
        (new report_overview($mavr, $cm, $context))->export_for_template($OUTPUT));
}

echo $OUTPUT->footer();
