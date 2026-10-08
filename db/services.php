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
 * External functions of mod_mavr.
 *
 * Browser statements do not have a MAVR function: they go through core_xapi_statement_post
 * with component mod_mavr, handled by \mod_mavr\xapi\handler.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_mavr_start_attempt' => [
        'classname' => \mod_mavr\external\start_attempt::class,
        'description' => 'Start or resume the current user\'s attempt and return its launch descriptor.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_get_preview' => [
        'classname' => \mod_mavr\external\get_preview::class,
        'description' => 'Return a preview launch descriptor for teachers; nothing is recorded.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/mavr:manage',
    ],
    'mod_mavr_get_attempt_state' => [
        'classname' => \mod_mavr\external\get_attempt_state::class,
        'description' => 'Return the server evaluation of the current user\'s attempt.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_get_question' => [
        'classname' => \mod_mavr\external\get_question::class,
        'description' => 'Render an unlocked question of the current user\'s open attempt.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_submit_question' => [
        'classname' => \mod_mavr\external\submit_question::class,
        'description' => 'Submit a response to a question of the current user\'s open attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_finish_attempt' => [
        'classname' => \mod_mavr\external\finish_attempt::class,
        'description' => 'Finish the current user\'s open attempt and update grade and completion.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_editor_load' => [
        'classname' => \mod_mavr\external\editor_load::class,
        'description' => 'Load the room editor document of an activity.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/mavr:manage',
    ],
    'mod_mavr_editor_save' => [
        'classname' => \mod_mavr\external\editor_save::class,
        'description' => 'Validate and save the room editor document of an activity.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:manage',
    ],
    'mod_mavr_request_hint' => [
        'classname' => \mod_mavr\external\request_hint::class,
        'description' => 'Reveal the next hint of an objective in the current user\'s open attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
    'mod_mavr_get_question_view' => [
        'classname' => \mod_mavr\external\get_question_view::class,
        'description' => 'Return a plain-text view of a true/false or multiple-choice question for in-world panels.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/mavr:attempt',
    ],
];
