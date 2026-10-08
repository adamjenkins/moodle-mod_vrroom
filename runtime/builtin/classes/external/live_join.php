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
use vrroomruntime_builtin\local\live\config;
use vrroomruntime_builtin\local\live\room_manager;

/**
 * Join the live room of an activity (or of one of the user's groups).
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_join extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'attemptid' => new external_value(PARAM_INT, 'The attempt in progress, 0 for a teacher preview', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'The group whose room to join, 0 for the default room', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Join the room.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id, 0 for a preview.
     * @param int $groupid Group id, 0 for the default room.
     * @return array
     */
    public static function execute(int $cmid, int $attemptid = 0, int $groupid = 0): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'attemptid' => $attemptid, 'groupid' => $groupid]
        );

        [$cm, $context, $vrroom] = access::load_by_cmid((int)$params['cmid']);
        self::validate_context($context);
        require_capability('mod/vrroom:view', $context);
        if (!access::is_available($vrroom)) {
            throw new \moodle_exception('errorlivedisabled', 'vrroomruntime_builtin');
        }

        $userid = (int)$USER->id;
        $kind = access::resolve_kind($cm, $context, $vrroom, (int)$params['attemptid'], $userid);
        $room = access::resolve_group($cm, $context, $userid, (int)$params['groupid']);
        $joined = room_manager::join($cm, $context, $vrroom, $userid, $kind['kind'], $kind['attemptid'], $room);

        $peer = $joined['peer'];
        $ice = config::ice_servers((string)$peer->peerid);
        return [
            'peerid' => (string)$peer->peerid,
            'groupid' => $room,
            'roomname' => access::room_name($cm, $context, $vrroom, $room),
            'maxpeers' => access::max_peers($vrroom),
            'interval' => (int)$joined['interval'],
            'peers' => $joined['peers'],
            'iceservers' => self::export_ice($ice['servers']),
            'iceexpires' => (int)$ice['expires'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Own peer id for poll, send and leave'),
            'groupid' => new external_value(PARAM_INT, 'The room\'s group, 0 without groups'),
            'roomname' => new external_value(PARAM_TEXT, 'Plain name of the room'),
            'maxpeers' => new external_value(PARAM_INT, 'Most people in the room'),
            'interval' => new external_value(PARAM_INT, 'Milliseconds until the first poll'),
            'peers' => new external_multiple_structure(self::peer_structure(), 'The others in the room'),
            'iceservers' => new external_multiple_structure(self::iceserver_structure(), 'STUN and TURN servers'),
            'iceexpires' => new external_value(PARAM_INT, 'When the TURN credentials expire, 0 without TURN'),
        ]);
    }

    /**
     * A person in the room.
     *
     * @return external_single_structure
     */
    public static function peer_structure(): external_single_structure {
        return new external_single_structure([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Peer id'),
            'name' => new external_value(PARAM_TEXT, 'Plain full name, made by the server'),
            'kind' => new external_value(PARAM_ALPHA, 'attempt or preview'),
        ]);
    }

    /**
     * An ICE server.
     *
     * @return external_single_structure
     */
    public static function iceserver_structure(): external_single_structure {
        return new external_single_structure([
            'urls' => new external_multiple_structure(new external_value(PARAM_RAW, 'STUN or TURN URL')),
            'username' => new external_value(PARAM_RAW, 'TURN username', VALUE_DEFAULT, ''),
            'credential' => new external_value(PARAM_RAW, 'Short-lived TURN credential', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * A connection message for the caller.
     *
     * @return external_single_structure
     */
    public static function signal_structure(): external_single_structure {
        return new external_single_structure([
            'from' => new external_value(PARAM_ALPHANUM, 'Sender peer id'),
            'type' => new external_value(PARAM_ALPHA, 'description or candidates'),
            'payload' => new external_value(PARAM_RAW, 'Canonical JSON built by the server'),
        ]);
    }

    /**
     * ICE servers of config::ice_servers() with every key of iceserver_structure() set.
     *
     * @param array $servers Servers.
     * @return array
     */
    public static function export_ice(array $servers): array {
        $out = [];
        foreach ($servers as $server) {
            $out[] = [
                'urls' => array_values($server['urls']),
                'username' => (string)($server['username'] ?? ''),
                'credential' => (string)($server['credential'] ?? ''),
            ];
        }
        return $out;
    }
}
