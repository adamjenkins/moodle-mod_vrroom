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
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/vrroom/lib.php');

use mod_vrroom\local\grading\grader;
use mod_vrroom\local\runtime\registry as runtime_registry;
use mod_vrroom\local\scene\environment_registry;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'vrroom');

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/vrroom:view', $context);

$vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);

vrroom_view($vrroom, $course, $cm, $context);

$PAGE->set_url('/mod/vrroom/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($vrroom->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));

// Resolve the run mode before any output, so a broken one shows a clear message.
$runtime = null;
$runtimeerror = '';
try {
    $runtime = runtime_registry::get($vrroom->runtime);
    $ready = $runtime->readiness();
    if (!$ready->ready) {
        $runtimeerror = get_string('runtimenotready', 'vrroom', s($ready->message));
        $runtime = null;
    }
} catch (\moodle_exception $e) {
    $runtimeerror = get_string('errorunknownruntime', 'vrroom', s($vrroom->runtime));
    $runtime = null;
}

// The user's attempts.
$canattempt = has_capability('mod/vrroom:attempt', $context);
$canpreview = has_capability('mod/vrroom:manage', $context);
$attempts = $DB->get_records('vrroom_attempts', ['vrroomid' => $vrroom->id, 'userid' => $USER->id], 'attempt ASC');
$hasinprogress = false;
$attemptrows = [];
foreach ($attempts as $attempt) {
    if ($attempt->state === 'inprogress') {
        $hasinprogress = true;
    }
    $hasscore = $attempt->score !== null && $attempt->state === 'finished';
    $attemptrows[] = [
        'attempt' => (int)$attempt->attempt,
        'state' => get_string('state_' . $attempt->state, 'vrroom'),
        'score' => $hasscore ? get_string('percents', 'moodle', format_float((float)$attempt->score * 100, 2)) : '-',
        'grade' => ($hasscore && (int)$vrroom->grade > 0) ? grader::format_grade($vrroom, (float)$attempt->score) : '-',
        'timefinish' => $attempt->timefinish ? userdate($attempt->timefinish) : '-',
    ];
}
$attemptcount = count($attempts);
$maxattempts = (int)$vrroom->maxattempts;
$canstart = $canattempt && ($hasinprogress || $maxattempts === 0 || $attemptcount < $maxattempts);
$attemptsleft = $maxattempts === 0 ? -1 : max(0, $maxattempts - $attemptcount);

$finalgrade = '';
if ((int)$vrroom->grade > 0 && $attempts) {
    $score = grader::calculate_user_score($vrroom, (int)$USER->id);
    if ($score !== null) {
        $finalgrade = get_string(
            'yourfinalgrade',
            'vrroom',
            grader::format_grade($vrroom, $score) . ' / ' . format_float((float)$vrroom->grade, 2)
        );
    }
}

$message = '';
if ($canattempt) {
    if (!$canstart) {
        $message = get_string('nomoreattempts', 'vrroom');
    } else if ($maxattempts > 0 && !$hasinprogress) {
        $message = get_string('attemptsleft', 'vrroom', $attemptsleft);
    }
} else if (!$canpreview) {
    $message = get_string('cannotattempt', 'vrroom');
}

// Rooms from room packs: credits for everyone, a warning to teachers when a pack room is not installed.
$scenes = [];
foreach ($DB->get_fieldset_select('vrroom_scenes', 'scenejson', 'vrroomid = ?', [$vrroom->id]) as $scenejson) {
    $scene = json_decode((string)$scenejson, true);
    if (is_array($scene)) {
        $scenes[] = $scene;
    }
}
$environments = environment_registry::describe_scenes($scenes);

$rootid = 'vrroom-root-' . $cm->id;
$templatecontext = [
    'rootid' => $rootid,
    'cmid' => (int)$cm->id,
    'hasattempts' => !empty($attemptrows),
    'attempts' => $attemptrows,
    'finalgrade' => $finalgrade,
    'message' => $message,
    'hasroot' => $runtime !== null,
    'reporturl' => has_capability('mod/vrroom:viewreports', $context)
        ? (new moodle_url('/mod/vrroom/report.php', ['id' => $cm->id]))->out(false) : '',
    'editurl' => $canpreview ? (new moodle_url('/mod/vrroom/edit.php', ['id' => $cm->id]))->out(false) : '',
    'hascredits' => !empty($environments['credits']),
    'credits' => $environments['credits'],
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
        // The page already explains why the room cannot be opened (no attempts left, or no
        // permission), so the client does not add a second, vaguer notice of its own.
        'hasmessage' => $message !== '' && !$canstart && !$canpreview,
    ];
    $entry = $runtime->client_entry();
    $PAGE->requires->js_call_amd($entry['module'], $entry['function'], [$boot]);
}

echo $OUTPUT->header();
if ($runtimeerror !== '') {
    // The message is a lang string whose only variable part went through s() above.
    echo $OUTPUT->notification($runtimeerror, \core\output\notification::NOTIFY_ERROR);
}
if ($environments['missing'] && $canpreview) {
    echo $OUTPUT->notification(
        get_string('envmissing', 'vrroom', s(implode(', ', $environments['missing']))),
        \core\output\notification::NOTIFY_WARNING
    );
}
echo $OUTPUT->render_from_template('mod_vrroom/view_page', $templatecontext);
echo $OUTPUT->footer();
