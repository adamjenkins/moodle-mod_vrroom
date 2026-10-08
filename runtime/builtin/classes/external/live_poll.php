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

namespace mavrruntime_builtin\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mavrruntime_builtin\local\live\access;
use mavrruntime_builtin\local\live\room_manager;

/**
 * Keep a live room presence alive and collect the connection messages addressed to it.
 *
 * The heartbeat that also returns the others in the room is adapted from mod_gear's
 * sync_session service. An unknown, foreign or stale peer id only ever gets the answer 'gone'.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_poll extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Own peer id from live_join'),
            'refreshice' => new external_value(PARAM_BOOL, 'Whether to return new ICE servers', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Poll.
     *
     * @param string $peerid Own peer id.
     * @param bool $refreshice Whether to return new ICE servers.
     * @return array
     */
    public static function execute(string $peerid, bool $refreshice = false): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(),
            ['peerid' => $peerid, 'refreshice' => $refreshice]);

        $peer = room_manager::get_own_peer((string)$params['peerid'], (int)$USER->id);
        if (!$peer) {
            return self::empty_result('gone');
        }
        try {
            [$cm, $context, $mavr] = access::load_by_mavrid((int)$peer->mavrid);
        } catch (\dml_missing_record_exception $e) {
            room_manager::remove_peer($peer);
            return self::empty_result('closed');
        }
        try {
            self::validate_context($context);
            require_capability('mod/mavr:view', $context);
        } catch (\moodle_exception $e) {
            // The caller lost access to the activity: the presence goes at once, not after it is stale.
            room_manager::remove_peer($peer);
            throw $e;
        }

        $result = room_manager::poll($peer, $cm, $context, $mavr, (bool)$params['refreshice']);
        $result['iceservers'] = live_join::export_ice($result['iceservers']);
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, gone (no such presence) or closed (removed from the room)'),
            'interval' => new external_value(PARAM_INT, 'Milliseconds until the next poll'),
            'peers' => new external_multiple_structure(live_join::peer_structure(), 'The others in the room'),
            'signals' => new external_multiple_structure(live_join::signal_structure(), 'Messages for the caller'),
            'iceservers' => new external_multiple_structure(live_join::iceserver_structure(),
                'New STUN and TURN servers, only when asked for'),
            'iceexpires' => new external_value(PARAM_INT, 'When the new TURN credentials expire, 0 without TURN'),
        ]);
    }

    /**
     * A result without peers or signals.
     *
     * @param string $status 'gone' or 'closed'.
     * @return array
     */
    private static function empty_result(string $status): array {
        return [
            'status' => $status,
            'interval' => room_manager::INTERVAL_IDLE,
            'peers' => [],
            'signals' => [],
            'iceservers' => [],
            'iceexpires' => 0,
        ];
    }
}
