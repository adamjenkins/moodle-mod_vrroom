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
 * Activity page: the learner's attempts and the room's launcher.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/mavr/lib.php');

use mod_mavr\local\grading\grader;
use mod_mavr\local\runtime\registry as runtime_registry;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'mavr');

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mavr:view', $context);

$mavr = $DB->get_record('mavr', ['id' => $cm->instance], '*', MUST_EXIST);

mavr_view($mavr, $course, $cm, $context);

$PAGE->set_url('/mod/mavr/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($mavr->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));

// Resolve the run mode before any output, so a broken one shows a clear message.
$runtime = null;
$runtimeerror = '';
try {
    $runtime = runtime_registry::get($mavr->runtime);
    $ready = $runtime->readiness();
    if (!$ready->ready) {
        $runtimeerror = get_string('runtimenotready', 'mavr', s($ready->message));
        $runtime = null;
    }
} catch (\moodle_exception $e) {
    $runtimeerror = get_string('errorunknownruntime', 'mavr', s($mavr->runtime));
    $runtime = null;
}

// The user's attempts.
$canattempt = has_capability('mod/mavr:attempt', $context);
$canpreview = has_capability('mod/mavr:manage', $context);
$attempts = $DB->get_records('mavr_attempts', ['mavrid' => $mavr->id, 'userid' => $USER->id], 'attempt ASC');
$hasinprogress = false;
$attemptrows = [];
foreach ($attempts as $attempt) {
    if ($attempt->state === 'inprogress') {
        $hasinprogress = true;
    }
    $hasscore = $attempt->score !== null && $attempt->state === 'finished';
    $attemptrows[] = [
        'attempt' => (int)$attempt->attempt,
        'state' => get_string('state_' . $attempt->state, 'mavr'),
        'score' => $hasscore ? get_string('percents', 'moodle', format_float((float)$attempt->score * 100, 2)) : '-',
        'grade' => ($hasscore && (int)$mavr->grade > 0) ? grader::format_grade($mavr, (float)$attempt->score) : '-',
        'timefinish' => $attempt->timefinish ? userdate($attempt->timefinish) : '-',
    ];
}
$attemptcount = count($attempts);
$maxattempts = (int)$mavr->maxattempts;
$canstart = $canattempt && ($hasinprogress || $maxattempts === 0 || $attemptcount < $maxattempts);
$attemptsleft = $maxattempts === 0 ? -1 : max(0, $maxattempts - $attemptcount);

$finalgrade = '';
if ((int)$mavr->grade > 0 && $attempts) {
    $score = grader::calculate_user_score($mavr, (int)$USER->id);
    if ($score !== null) {
        $finalgrade = get_string('yourfinalgrade', 'mavr',
            grader::format_grade($mavr, $score) . ' / ' . format_float((float)$mavr->grade, 2));
    }
}

$message = '';
if ($canattempt) {
    if (!$canstart) {
        $message = get_string('nomoreattempts', 'mavr');
    } else if ($maxattempts > 0 && !$hasinprogress) {
        $message = get_string('attemptsleft', 'mavr', $attemptsleft);
    }
} else if (!$canpreview) {
    $message = get_string('cannotattempt', 'mavr');
}

$rootid = 'mavr-root-' . $cm->id;
$templatecontext = [
    'rootid' => $rootid,
    'cmid' => (int)$cm->id,
    'hasattempts' => !empty($attemptrows),
    'attempts' => $attemptrows,
    'finalgrade' => $finalgrade,
    'message' => $message,
    'hasroot' => $runtime !== null,
    'reporturl' => has_capability('mod/mavr:viewreports', $context)
        ? (new moodle_url('/mod/mavr/report.php', ['id' => $cm->id]))->out(false) : '',
];

if ($runtime !== null) {
    $boot = [
        'cmid' => (int)$cm->id,
        'rootid' => $rootid,
        'canattempt' => $canattempt,
        'canpreview' => $canpreview,
        'hasinprogress' => $hasinprogress,
        'canstart' => $canstart,
        'attemptsleft' => $attemptsleft,
    ];
    $entry = $runtime->client_entry();
    $PAGE->requires->js_call_amd($entry['module'], $entry['function'], [$boot]);
}

echo $OUTPUT->header();
if ($runtimeerror !== '') {
    // The message is a lang string whose only variable part went through s() above.
    echo $OUTPUT->notification($runtimeerror, \core\output\notification::NOTIFY_ERROR);
}
echo $OUTPUT->render_from_template('mod_mavr/view_page', $templatecontext);
echo $OUTPUT->footer();
