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
 * English strings for mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['area_environment'] = 'Environment model';
$string['area_media'] = 'Images';
$string['area_models'] = '3D models';
$string['attemptfinished'] = 'Finished';
$string['attemptgrade'] = 'Grade';
$string['attemptnumber'] = 'Attempt';
$string['attemptsandgrading'] = 'Attempts and grading';
$string['attemptscore'] = 'Score';
$string['attemptsdeleted'] = 'MAVR attempts deleted';
$string['attemptsleft'] = 'Attempts left: {$a}';
$string['attemptstate'] = 'State';
$string['cannotattempt'] = 'You can look at this activity, but you cannot attempt it.';
$string['completiondetail:objectives'] = 'Complete all required objectives';
$string['completionobjectives'] = 'Complete all required objectives';
$string['completionobjectives_help'] = 'The activity is complete when the student finishes an attempt in which every required objective was completed.';
$string['errorattemptclosed'] = 'This attempt is no longer in progress.';
$string['errorinvalidslot'] = 'There is no such question in this activity.';
$string['errornomoreattempts'] = 'You have no attempts left for this activity.';
$string['errornopreview'] = 'Preview is not available for this run mode.';
$string['errornotyourattempt'] = 'This attempt does not belong to you.';
$string['errorquestionanswered'] = 'This question has already been answered.';
$string['errorquestionduplicate'] = 'The same question cannot be used twice in one activity.';
$string['errorquestionlocked'] = 'This question is still locked.';
$string['errorquestionnotallowed'] = 'You are not allowed to use the selected question.';
$string['errorquestionnotfound'] = 'The selected question no longer exists.';
$string['errorquestiontype'] = 'Questions of this type cannot be used in MAVR.';
$string['errorscalenotsupported'] = 'Scales are not supported. Choose a point grade.';
$string['errorunknownruntime'] = 'The run mode "{$a}" used by this activity is not installed.';
$string['errorunknowntemplate'] = 'The template "{$a}" used by this activity is not installed.';
$string['eventattemptdeleted'] = 'Attempt deleted';
$string['eventattemptfinished'] = 'Attempt finished';
$string['eventattemptstarted'] = 'Attempt started';
$string['eventstatementrecorded'] = 'Statement recorded';
$string['gradeaverage'] = 'Average of attempts';
$string['gradehighest'] = 'Highest attempt';
$string['gradelast'] = 'Last attempt';
$string['grademethod'] = 'Grading method';
$string['grademethod_help'] = 'When several attempts are allowed, this decides which attempt\'s score goes to the gradebook.';
$string['invalidgraph'] = 'The tasks for this room could not be built: {$a}';
$string['invalidscene'] = 'The room could not be built: {$a}';
$string['mavr:addinstance'] = 'Add a new MAVR activity';
$string['mavr:attempt'] = 'Attempt a MAVR activity';
$string['mavr:deleteattempts'] = 'Delete MAVR attempts';
$string['mavr:manage'] = 'Manage MAVR scenes and content';
$string['mavr:view'] = 'View a MAVR activity';
$string['mavr:viewreports'] = 'View MAVR reports';
$string['mavrsettings'] = 'Room settings';
$string['maxattemptevents'] = 'Statements per attempt';
$string['maxattemptevents_desc'] = 'The most statements stored for one attempt. Further statements are refused.';
$string['maxattempts'] = 'Attempts allowed';
$string['maxbatch'] = 'Statements per request';
$string['maxbatch_desc'] = 'The most xAPI statements a browser may send in one request. Larger requests are refused.';
$string['maxmodelsize'] = 'Maximum model file size (MB)';
$string['maxmodelsize_desc'] = 'The largest 3D model or environment file that teachers can upload, in megabytes.';
$string['modulename'] = 'MAVR';
$string['modulename_help'] = 'MAVR activities are 3D rooms that students explore in the browser, on a computer or a phone. Students visit stations, look at models, images and texts, and answer questions from the question bank. Their actions are recorded as xAPI statements, and grades and completion are calculated from them. Every activity also has a list view that works without 3D, with a keyboard and with a screen reader.';
$string['modulenameplural'] = 'MAVR activities';
$string['noattempts'] = 'There are no attempts yet.';
$string['nomoreattempts'] = 'No more attempts are allowed.';
$string['objecttype_activity'] = 'activity';
$string['objecttype_object'] = 'object';
$string['objecttype_objective'] = 'objective';
$string['objecttype_question'] = 'question';
$string['objecttype_scene'] = 'room';
$string['objecttype_zone'] = 'station';
$string['pluginadministration'] = 'MAVR administration';
$string['pluginname'] = 'MAVR';
$string['preset_courtyard'] = 'Courtyard';
$string['preset_galleryhall'] = 'Gallery hall';
$string['privacy:attemptpath'] = 'Attempt {$a}';
$string['privacy:metadata:core_question'] = 'MAVR stores the answers to its questions with the question engine.';
$string['privacy:metadata:core_xapi'] = 'MAVR stores the user\'s last position in a room as an xAPI state.';
$string['privacy:metadata:mavr_attempts'] = 'Each attempt a user makes at a MAVR activity.';
$string['privacy:metadata:mavr_attempts:attempt'] = 'The attempt number.';
$string['privacy:metadata:mavr_attempts:score'] = 'The score calculated for the attempt.';
$string['privacy:metadata:mavr_attempts:state'] = 'Whether the attempt is in progress or finished.';
$string['privacy:metadata:mavr_attempts:timefinish'] = 'When the attempt was finished.';
$string['privacy:metadata:mavr_attempts:timemodified'] = 'When the attempt was last changed.';
$string['privacy:metadata:mavr_attempts:timestart'] = 'When the attempt was started.';
$string['privacy:metadata:mavr_attempts:userid'] = 'The user who made the attempt.';
$string['privacy:metadata:mavr_events'] = 'The actions recorded during an attempt, as xAPI statements.';
$string['privacy:metadata:mavr_events:clientasserted'] = 'Whether the browser or the server reported the action.';
$string['privacy:metadata:mavr_events:objectid'] = 'Which thing the action was about.';
$string['privacy:metadata:mavr_events:objecttype'] = 'What kind of thing the action was about.';
$string['privacy:metadata:mavr_events:resultjson'] = 'The result the server calculated for the action.';
$string['privacy:metadata:mavr_events:timecreated'] = 'When the server recorded the action.';
$string['privacy:metadata:mavr_events:timestamp'] = 'When the browser says the action happened.';
$string['privacy:metadata:mavr_events:verb'] = 'What the user did.';
$string['questionchoice'] = '{$a->bank}: {$a->category}: {$a->name}';
$string['questionmissing'] = 'This question is no longer available.';
$string['reportactions'] = 'Actions';
$string['reportattempt'] = 'Attempt';
$string['reportattemptdeleted'] = 'The attempt has been deleted.';
$string['reportattemptdetails'] = 'Attempt {$a->attempt} by {$a->user}';
$string['reportclientasserted'] = 'Browser';
$string['reportclientasserted_help'] = 'The browser reported this action. Browser reports can be faked, so actions that award points should be checked by questions.';
$string['reportdeleteattempt'] = 'Delete attempt';
$string['reportdeleteconfirm'] = 'Delete attempt {$a->attempt} by {$a->user}? The grade will be recalculated.';
$string['reportgrade'] = 'Grade';
$string['reportlink'] = 'View reports';
$string['reportobject'] = 'Object';
$string['reportobjective'] = 'Objective';
$string['reportobjectives'] = 'Objectives';
$string['reportobjectivestatus'] = 'Status';
$string['reportoverview'] = 'Attempts';
$string['reportpoints'] = 'Points';
$string['reportresult'] = 'Result';
$string['reports'] = 'Reports';
$string['reportscore'] = 'Score';
$string['reportserverasserted'] = 'Server';
$string['reportsource'] = 'Recorded by';
$string['reportstate'] = 'State';
$string['reportstatements'] = 'Statements';
$string['reporttime'] = 'Time';
$string['reporttimefinish'] = 'Finished';
$string['reporttimestart'] = 'Started';
$string['reportuser'] = 'Student';
$string['reportverb'] = 'Action';
$string['reportviewattempt'] = 'View details';
$string['resetattempts'] = 'Delete all MAVR attempts';
$string['runtime'] = 'Run mode';
$string['runtime_help'] = 'How the room is delivered to students. The built-in viewer runs inside Moodle and needs nothing else.';
$string['runtimenotready'] = 'This activity cannot be opened at the moment: {$a}';
$string['state_abandoned'] = 'Abandoned';
$string['state_finished'] = 'Finished';
$string['state_inprogress'] = 'In progress';
$string['status_available'] = 'Available';
$string['status_completed'] = 'Completed';
$string['status_locked'] = 'Locked';
$string['subplugintype_mavrruntime'] = 'Run mode';
$string['subplugintype_mavrruntime_plural'] = 'Run modes';
$string['subplugintype_mavrtemplate'] = 'Template';
$string['subplugintype_mavrtemplate_plural'] = 'Templates';
$string['template'] = 'Template';
$string['template_help'] = 'The template builds the room and its tasks from the fields below.';
$string['templatelocked'] = 'The template cannot be changed after the activity has been created.';
$string['tokenttl'] = 'Launch token lifetime';
$string['tokenttl_desc'] = 'How long a launch token for external run modes stays valid.';
$string['verb_answered'] = 'answered';
$string['verb_completed'] = 'completed';
$string['verb_entered'] = 'entered';
$string['verb_exited'] = 'left';
$string['verb_failed'] = 'failed';
$string['verb_initialized'] = 'opened';
$string['verb_interacted'] = 'interacted with';
$string['verb_launched'] = 'launched';
$string['verb_passed'] = 'passed';
$string['verb_terminated'] = 'closed';
$string['yourattempts'] = 'Your attempts';
$string['yourfinalgrade'] = 'Your final grade: {$a}';
