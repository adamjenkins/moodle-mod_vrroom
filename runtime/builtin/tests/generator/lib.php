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
 * Data generator for the MAVR built-in run mode.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates live room presences and connection messages for tests.
 *
 * Rows are inserted directly, without the room checks of the live room classes, so tests can
 * build any state (stale peers, expired messages, peers of other activities).
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mavrruntime_builtin_generator extends component_generator_base {

    /**
     * Create a presence in a live room.
     *
     * Required: mavrid, userid. Defaults: groupid 0, a new random peerid, kind 'attempt',
     * attemptid 0, timejoined/timeseen/lastsignal/ratewindow = time(), counters 0.
     *
     * @param array $record the row values
     * @return stdClass the inserted row with id
     * @throws coding_exception when a required value is missing
     */
    public function create_peer(array $record): stdClass {
        global $DB;
        foreach (['mavrid', 'userid'] as $required) {
            if (empty($record[$required])) {
                throw new coding_exception("create_peer() needs '{$required}'.");
            }
        }
        $now = time();
        $timeseen = (int)($record['timeseen'] ?? $now);
        $row = (object)[
            'mavrid' => (int)$record['mavrid'],
            'groupid' => (int)($record['groupid'] ?? 0),
            'userid' => (int)$record['userid'],
            'peerid' => (string)($record['peerid'] ?? bin2hex(random_bytes(16))),
            'kind' => (string)($record['kind'] ?? 'attempt'),
            'attemptid' => (int)($record['attemptid'] ?? 0),
            'timejoined' => (int)($record['timejoined'] ?? $timeseen),
            'timeseen' => $timeseen,
            'lastsignal' => (int)($record['lastsignal'] ?? $timeseen),
            'ratewindow' => (int)($record['ratewindow'] ?? $timeseen),
            'pollcount' => (int)($record['pollcount'] ?? 0),
            'signalcount' => (int)($record['signalcount'] ?? 0),
        ];
        $row->id = $DB->insert_record('mavrruntime_builtin_peers', $row);
        return $row;
    }

    /**
     * Create a connection message between two presences.
     *
     * Required: mavrid, frompeer, topeer (peer ids). Defaults: type 'candidates', payload
     * '{"gen":0,"candidates":[]}', timecreated time().
     *
     * @param array $record the row values
     * @return stdClass the inserted row with id
     * @throws coding_exception when a required value is missing
     */
    public function create_signal(array $record): stdClass {
        global $DB;
        foreach (['mavrid', 'frompeer', 'topeer'] as $required) {
            if (empty($record[$required])) {
                throw new coding_exception("create_signal() needs '{$required}'.");
            }
        }
        $row = (object)[
            'mavrid' => (int)$record['mavrid'],
            'frompeer' => (string)$record['frompeer'],
            'topeer' => (string)$record['topeer'],
            'type' => (string)($record['type'] ?? 'candidates'),
            'payload' => (string)($record['payload'] ?? '{"gen":0,"candidates":[]}'),
            'timecreated' => (int)($record['timecreated'] ?? time()),
        ];
        $row->id = $DB->insert_record('mavrruntime_builtin_signals', $row);
        return $row;
    }
}
