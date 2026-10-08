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

namespace vrroomruntime_builtin\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use vrroomruntime_builtin\local\live\access;
use vrroomruntime_builtin\local\live\room_manager;

/**
 * List the live rooms of an activity (or of one of the user's groups) with their occupancy.
 *
 * Read only: a snapshot without locks. Counts only, never names, and only to people who could join
 * these rooms anyway.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_rooms extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'groupid' => new external_value(
                PARAM_INT,
                'The group whose rooms to list, 0 for the default group',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * List the rooms.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id, 0 for the default group.
     * @return array
     */
    public static function execute(int $cmid, int $groupid = 0): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'groupid' => $groupid]
        );

        [$cm, $context, $vrroom] = access::load_by_cmid((int)$params['cmid']);
        self::validate_context($context);
        require_capability('mod/vrroom:view', $context);
        if (!access::is_available($vrroom)) {
            throw new \moodle_exception('errorlivedisabled', 'vrroomruntime_builtin');
        }
        if (!has_any_capability(['mod/vrroom:attempt', 'mod/vrroom:manage'], $context)) {
            throw new \moodle_exception('errorlivenotallowed', 'vrroomruntime_builtin');
        }

        $userid = (int)$USER->id;
        $resolved = access::resolve_group($cm, $context, $userid, (int)$params['groupid']);
        $list = room_manager::list_rooms($vrroom, $resolved, $userid);
        $list['interval'] = room_manager::ROOMS_INTERVAL;
        return $list;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'groupid' => new external_value(PARAM_INT, 'The group whose rooms these are, 0 without groups'),
            'roommode' => new external_value(PARAM_ALPHA, 'fixed or dynamic'),
            'maxrooms' => new external_value(PARAM_INT, 'fixed: number of rooms; dynamic: most rooms at once, 0 = no limit'),
            'capacity' => new external_value(PARAM_INT, 'Most people in one room'),
            'rooms' => new external_multiple_structure(self::room_structure(), 'The rooms, in number order'),
            'canstart' => new external_value(PARAM_BOOL, 'Whether the user can start a new room now'),
            'startreason' => new external_value(PARAM_ALPHA, 'Empty when a room can be started, else fixed or limit'),
            'interval' => new external_value(PARAM_INT, 'Milliseconds until the client should ask again'),
        ]);
    }

    /**
     * One room.
     *
     * @return external_single_structure
     */
    public static function room_structure(): external_single_structure {
        return new external_single_structure([
            'number' => new external_value(PARAM_INT, 'Room number, 1 upwards'),
            'name' => new external_value(PARAM_TEXT, 'Plain name of the room, made by the server'),
            'count' => new external_value(PARAM_INT, 'People in the room now, the user included'),
            'capacity' => new external_value(PARAM_INT, 'Most people in the room'),
            'joinable' => new external_value(PARAM_BOOL, 'Whether the user can join the room now'),
        ]);
    }
}
