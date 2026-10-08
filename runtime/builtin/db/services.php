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
 * Web services of the MAVR built-in viewer run mode (live room signalling).
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mavrruntime_builtin_live_join' => [
        'classname' => \mavrruntime_builtin\external\live_join::class,
        'description' => 'Join the live room of a MAVR activity (or of the user\'s group) and return the people in it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mavr:view',
    ],
    'mavrruntime_builtin_live_poll' => [
        'classname' => \mavrruntime_builtin\external\live_poll::class,
        'description' => 'Keep a live room presence alive and collect the connection messages addressed to it.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
        'capabilities' => 'mod/mavr:view',
    ],
    'mavrruntime_builtin_live_send' => [
        'classname' => \mavrruntime_builtin\external\live_send::class,
        'description' => 'Pass connection messages to other people in the same live room.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
        'capabilities' => 'mod/mavr:view',
    ],
    'mavrruntime_builtin_live_leave' => [
        'classname' => \mavrruntime_builtin\external\live_leave::class,
        'description' => 'Leave a live room.',
        'type' => 'write',
        'ajax' => true,
        'readonlysession' => true,
        'capabilities' => 'mod/mavr:view',
    ],
];
