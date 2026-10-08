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
 * The room editor: rooms, objects and tasks of one activity.
 *
 * The page only renders the editor's frame; mod_vrroom/editor_app loads the document through
 * mod_vrroom_editor_load and saves it through mod_vrroom_editor_save.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'vrroom');

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/vrroom:manage', $context);

$vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);
$name = format_string($vrroom->name, true, ['context' => $context]);

$PAGE->set_url('/mod/vrroom/edit.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('editorheading', 'vrroom', $name));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->add_body_class('vrroom-editor-page');

// The editor draws rooms with the built-in run mode's renderer.
if (!\core_component::get_component_directory('vrroomruntime_builtin')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('erroreditorbuiltin', 'vrroom'), \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    die();
}

$rootid = 'vrroom-editor-' . $cm->id;
$PAGE->requires->js_call_amd('mod_vrroom/editor_app', 'init', [['cmid' => (int)$cm->id, 'rootid' => $rootid]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editorheading', 'vrroom', $name), 2);
echo $OUTPUT->render_from_template('mod_vrroom/editor_page', [
    'rootid' => $rootid,
    'cmid' => (int)$cm->id,
    'viewurl' => (new moodle_url('/mod/vrroom/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
