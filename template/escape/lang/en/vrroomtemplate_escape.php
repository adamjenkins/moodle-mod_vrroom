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
 * English strings for the Escape chain template.
 *
 * @package    vrroomtemplate_escape
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addpuzzles'] = 'Add {no} more puzzles';
$string['chooserhelp'] = 'Students solve a chain of puzzles to get out of a room: find clues, take keys, open locks with questions, and ask for hints if they are stuck. A time limit is optional.';
$string['clue'] = 'Clue';
$string['clueimage'] = 'Image file';
$string['cluemedia'] = 'Clue exhibit';
$string['cluemedia_image'] = 'Image';
$string['cluemedia_model'] = '3D model (GLB)';
$string['cluemedia_none'] = 'Text only';
$string['cluemodel'] = 'Model file';
$string['environment'] = 'Room';
$string['environment_help'] = 'The room the puzzles are placed in: a simple room or a room from an installed room pack. Each room has a fixed number of places for puzzles. The picture under the list shows the room from its starting point.';
$string['errorhintpenalty'] = 'The points taken off for a hint must be zero or more.';
$string['errorhintpenaltyrange'] = 'The points taken off for a hint must be 0, or a number from 0.0000001 to {$a}.';
$string['errorimagerequired'] = 'Upload an image, or choose a different clue exhibit.';
$string['errormodelrequired'] = 'Upload a model, or choose a different clue exhibit.';
$string['errornopuzzles'] = 'Add at least one puzzle.';
$string['errorpoints'] = 'Points must be zero or more.';
$string['errorpointsrange'] = 'Points must be 0, or a number from 0.0000001 to {$a}.';
$string['errorquestionrequired'] = 'Choose a question for this lock, or choose a different lock.';
$string['errortimelimit'] = 'The time limit must be between zero and seven days.';
$string['errortitlerequired'] = 'Give the puzzle a title.';
$string['errortoomanypuzzles'] = 'The chosen room has space for {$a} puzzles.';
$string['escapeheading'] = 'Escape chain';
$string['exitdoor'] = 'Exit door';
$string['exittext'] = 'The door is open. You have escaped!';
$string['hint1'] = 'First hint';
$string['hint1_help'] = 'Optional hints the student can ask for, one after the other. Each hint costs the points set for the activity.';
$string['hint2'] = 'Second hint';
$string['hintpenalty'] = 'Points taken off for each hint';
$string['hintpenalty_help'] = 'When a student asks for a hint, the puzzle\'s points are reduced by this much. Points never go below zero.';
$string['keyname'] = 'Name of the key';
$string['keyname_default'] = 'Key';
$string['locktype'] = 'Lock';
$string['locktype_help'] = 'What the student must do to solve this puzzle after finding its clue. The next puzzle opens only when this one is solved.';
$string['locktype_key'] = 'Find and take a key';
$string['locktype_none'] = 'Finding the clue is enough';
$string['locktype_question'] = 'Unlock with a question (for example a code)';
$string['noquestion'] = 'No question';
$string['objectivecode'] = 'Open the lock at {$a}';
$string['objectiveexit'] = 'Leave through the exit door';
$string['objectivefind'] = 'Search {$a}';
$string['objectivekey'] = 'Take the {$a}';
$string['pluginname'] = 'Escape chain';
$string['points'] = 'Points';
$string['points_help'] = 'Points for solving this puzzle.';
$string['previewplaces'] = 'Places for {$a} puzzles';
$string['privacy:metadata'] = 'The Escape chain template stores no personal data.';
$string['puzzle'] = 'Puzzle {no}';
$string['puzzletitle'] = 'Title';
$string['question'] = 'Question';
$string['question_help'] = 'A question from the course question banks. A wrong answer does not open the lock; the student can try again.';
$string['scenename'] = 'Escape room';
$string['story'] = 'Story';
$string['story_help'] = 'A short text shown on a board near the start, for example why the students are locked in.';
$string['storyname'] = 'Story';
$string['timelimit'] = 'Time limit';
$string['timelimit_help'] = 'When enabled, the attempt is finished automatically when the time is up.';
