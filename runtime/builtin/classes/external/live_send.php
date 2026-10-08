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
 * Pass connection messages to other people in the same live room.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_send extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Own peer id from live_join'),
            'signals' => new external_multiple_structure(new external_single_structure([
                'to' => new external_value(PARAM_ALPHANUM, 'Recipient peer id'),
                'type' => new external_value(PARAM_ALPHA, 'description or candidates'),
                'payload' => new external_value(PARAM_RAW, 'JSON text'),
            ]), 'Messages, at most ' . room_manager::MAX_SIGNALS_PER_CALL),
        ]);
    }

    /**
     * Send.
     *
     * @param string $peerid Own peer id.
     * @param array $signals Messages.
     * @return array
     */
    public static function execute(string $peerid, array $signals): array {
        global $USER;
        if (count($signals) > room_manager::MAX_SIGNALS_PER_CALL) {
            throw new \invalid_parameter_exception('At most ' . room_manager::MAX_SIGNALS_PER_CALL . ' signals per call');
        }
        $params = self::validate_parameters(self::execute_parameters(), ['peerid' => $peerid, 'signals' => $signals]);

        $peer = room_manager::get_own_peer((string)$params['peerid'], (int)$USER->id);
        if (!$peer) {
            return ['status' => 'gone', 'results' => []];
        }
        try {
            [$cm, $context, $mavr] = access::load_by_mavrid((int)$peer->mavrid);
        } catch (\dml_missing_record_exception $e) {
            room_manager::remove_peer($peer);
            return ['status' => 'closed', 'results' => []];
        }
        try {
            self::validate_context($context);
            require_capability('mod/mavr:view', $context);
        } catch (\moodle_exception $e) {
            // The caller lost access to the activity: the presence goes at once, not after it is stale.
            room_manager::remove_peer($peer);
            throw $e;
        }

        return room_manager::send($peer, $cm, $context, $mavr, $params['signals']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, gone (no such presence) or closed (removed from the room)'),
            'results' => new external_multiple_structure(new external_single_structure([
                'to' => new external_value(PARAM_ALPHANUM, 'Recipient peer id'),
                'accepted' => new external_value(PARAM_BOOL, 'Whether the message was stored'),
                'error' => new external_value(
                    PARAM_ALPHA,
                    'Empty, unknownpeer, invalid, toolarge, ratelimited or mailboxfull'
                ),
            ]), 'One result per message, in the order sent'),
        ]);
    }
}
