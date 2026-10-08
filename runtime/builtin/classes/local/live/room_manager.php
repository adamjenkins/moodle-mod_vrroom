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

namespace vrroomruntime_builtin\local\live;

/**
 * Live rooms: presences refreshed by polling, and the connection messages between their browsers.
 *
 * A presence (peer) is one row of vrroomruntime_builtin_peers with a random peer id made here. It
 * stays while its browser polls; after STALE_AFTER seconds without a poll it is gone. Signals are
 * only ever stored for a fresh peer of the same activity and the same group room, rebuilt by
 * signal_validator, and deleted when delivered or after SIGNAL_TTL seconds. Every limit is
 * enforced here, on the server.
 *
 * The heartbeat that refreshes a presence row and returns the others active in the last few
 * seconds is adapted from mod_gear's sync_session service.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class room_manager {
    /** @var int Hard cap of people in one room (each browser connects to every other). */
    public const MAX_PEERS = 6;
    /** @var int Seconds without a poll after which a presence is gone. */
    public const STALE_AFTER = 20;
    /** @var int Seconds a signal is kept at most. */
    public const SIGNAL_TTL = 60;
    /** @var int Seconds after a join or a signal during which the poll interval stays short. */
    public const ACTIVE_WINDOW = 15;
    /** @var int Poll interval in ms while connections are being set up. */
    public const INTERVAL_ACTIVE = 1500;
    /** @var int Poll interval in ms otherwise. */
    public const INTERVAL_IDLE = 4000;
    /** @var int Poll interval in ms after a throttled poll. */
    public const INTERVAL_THROTTLED = 3000;
    /** @var int Length of a rate-limit window in seconds. */
    public const RATE_WINDOW = 10;
    /** @var int Polls allowed per peer and window. */
    public const MAX_POLLS_PER_WINDOW = 20;
    /** @var int Signals allowed per peer and window. */
    public const MAX_SIGNALS_PER_WINDOW = 60;
    /** @var int Signals allowed in one send call. */
    public const MAX_SIGNALS_PER_CALL = 10;
    /** @var int Largest canonical signal payload in bytes. */
    public const MAX_PAYLOAD = 16384;
    /** @var int Undelivered signals allowed per recipient. */
    public const MAX_PENDING = 100;
    /** @var int Signals delivered by one poll. */
    public const MAX_DELIVER = 50;
    /** @var int Seconds to wait for the room lock. */
    public const LOCK_TIMEOUT = 5;

    /** @var string Presence table. */
    public const TABLE_PEERS = 'vrroomruntime_builtin_peers';
    /** @var string Signal table. */
    public const TABLE_SIGNALS = 'vrroomruntime_builtin_signals';

    /** @var string Lock type of the room locks. */
    private const LOCK_TYPE = 'vrroomruntime_builtin_live';

    /** @var int Ids per IN () list when deleting in bulk. */
    private const CHUNK = 500;

    /**
     * A new random peer id.
     *
     * @return string 32 lowercase hex characters
     */
    public static function new_peerid(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether a value has the shape of a peer id.
     *
     * @param string $value
     * @return bool
     */
    public static function is_peerid(string $value): bool {
        return (bool)preg_match('/^[0-9a-f]{32}$/', $value);
    }

    /**
     * Join a room: replace the user's earlier presence in the activity and check the room size.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param int $userid The user (never from the client).
     * @param string $kind access::KIND_ATTEMPT or access::KIND_PREVIEW.
     * @param int $attemptid The attempt in progress, 0 for a preview.
     * @param int $groupid The room's group, 0 without groups.
     * @param int|null $now Current time.
     * @return array{peer: \stdClass, peers: array, interval: int}
     * @throws \moodle_exception errorlivebusy, errorliveroomfull
     */
    public static function join(
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        int $userid,
        string $kind,
        int $attemptid,
        int $groupid,
        ?int $now = null
    ): array {
        global $DB;
        $now = $now ?? time();
        $vrroomid = (int)$vrroom->id;

        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock("room_{$vrroomid}_{$groupid}", self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('errorlivebusy', 'vrroomruntime_builtin');
        }
        try {
            self::purge_room($vrroomid, $groupid, $now);

            // One presence per user per activity: a new join (another tab, another group) replaces it.
            $existing = $DB->get_record(self::TABLE_PEERS, ['vrroomid' => $vrroomid, 'userid' => $userid]);
            if ($existing) {
                self::remove_peer($existing);
            }

            $max = access::max_peers($vrroom);
            $count = $DB->count_records_select(
                self::TABLE_PEERS,
                'vrroomid = :vrroomid AND groupid = :groupid AND timeseen >= :fresh',
                ['vrroomid' => $vrroomid, 'groupid' => $groupid, 'fresh' => $now - self::STALE_AFTER]
            );
            if ($count >= $max) {
                throw new \moodle_exception('errorliveroomfull', 'vrroomruntime_builtin', '', $max);
            }

            $peer = (object)[
                'vrroomid' => $vrroomid,
                'groupid' => $groupid,
                'userid' => $userid,
                'peerid' => self::new_peerid(),
                'kind' => $kind,
                'attemptid' => $attemptid,
                'timejoined' => $now,
                'timeseen' => $now,
                'lastsignal' => $now,
                'ratewindow' => $now,
                'pollcount' => 0,
                'signalcount' => 0,
            ];
            try {
                $peer->id = (int)$DB->insert_record(self::TABLE_PEERS, $peer);
            } catch (\dml_write_exception $e) {
                // A simultaneous join of the same user into another group's room won the unique index.
                throw new \moodle_exception('errorlivebusy', 'vrroomruntime_builtin');
            }
        } finally {
            $lock->release();
        }

        return [
            'peer' => $peer,
            'peers' => self::roster($peer, $context, $now),
            'interval' => self::INTERVAL_ACTIVE,
        ];
    }

    /**
     * The caller's own fresh presence, or null (a foreign, malformed or stale id is never touched).
     *
     * @param string $peerid Peer id from the client.
     * @param int $userid The logged-in user.
     * @param int|null $now Current time.
     * @return \stdClass|null
     */
    public static function get_own_peer(string $peerid, int $userid, ?int $now = null): ?\stdClass {
        global $DB;
        $now = $now ?? time();
        if (!self::is_peerid($peerid)) {
            return null;
        }
        $peer = $DB->get_record(self::TABLE_PEERS, ['peerid' => $peerid]);
        if (!$peer || (int)$peer->userid !== $userid || (int)$peer->timeseen < $now - self::STALE_AFTER) {
            return null;
        }
        return $peer;
    }

    /**
     * A poll: keep the presence alive and deliver the signals addressed to it.
     *
     * @param \stdClass $peer The caller's own presence (get_own_peer()).
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param bool $refreshice Whether to return new ICE servers.
     * @param int|null $now Current time.
     * @return array{status: string, interval: int, peers: array, signals: array, iceservers: array, iceexpires: int}
     */
    public static function poll(
        \stdClass $peer,
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        bool $refreshice,
        ?int $now = null
    ): array {
        global $DB;
        $now = $now ?? time();
        $result = [
            'status' => 'ok',
            'interval' => self::INTERVAL_IDLE,
            'peers' => [],
            'signals' => [],
            'iceservers' => [],
            'iceexpires' => 0,
        ];

        if (!access::still_allowed($peer, $cm, $context, $vrroom)) {
            self::remove_peer($peer);
            $result['status'] = 'closed';
            return $result;
        }

        $update = (object)['id' => (int)$peer->id];
        if ($now - (int)$peer->ratewindow >= self::RATE_WINDOW) {
            $peer->ratewindow = $update->ratewindow = $now;
            $peer->pollcount = $update->pollcount = 0;
            $peer->signalcount = $update->signalcount = 0;
            self::purge_room((int)$peer->vrroomid, (int)$peer->groupid, $now);
        }
        $peer->pollcount = $update->pollcount = (int)$peer->pollcount + 1;
        $peer->timeseen = $update->timeseen = $now;

        if ((int)$peer->pollcount > self::MAX_POLLS_PER_WINDOW) {
            $DB->update_record(self::TABLE_PEERS, $update);
            $result['peers'] = self::roster($peer, $context, $now);
            $result['interval'] = self::INTERVAL_THROTTLED;
            return $result;
        }

        $fresh = $now - self::SIGNAL_TTL;
        $rows = $DB->get_records_select(
            self::TABLE_SIGNALS,
            'topeer = :topeer AND timecreated >= :fresh',
            ['topeer' => $peer->peerid, 'fresh' => $fresh],
            'id ASC',
            'id, frompeer, type, payload',
            0,
            self::MAX_DELIVER
        );
        $delivered = (bool)$rows;
        if ($delivered) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'sig');
            $DB->delete_records_select(self::TABLE_SIGNALS, "id {$insql}", $inparams);
            $peer->lastsignal = $update->lastsignal = $now;
            foreach ($rows as $row) {
                $result['signals'][] = [
                    'from' => (string)$row->frompeer,
                    'type' => (string)$row->type,
                    'payload' => (string)$row->payload,
                ];
            }
        }
        $DB->update_record(self::TABLE_PEERS, $update);

        $morepending = $DB->record_exists_select(
            self::TABLE_SIGNALS,
            'topeer = :topeer AND timecreated >= :fresh',
            ['topeer' => $peer->peerid, 'fresh' => $fresh]
        );

        $result['peers'] = self::roster($peer, $context, $now);
        $result['interval'] = self::interval_for($peer, $now, $delivered, $morepending);
        if ($refreshice) {
            $ice = config::ice_servers((string)$peer->peerid, $now);
            $result['iceservers'] = $ice['servers'];
            $result['iceexpires'] = $ice['expires'];
        }
        return $result;
    }

    /**
     * Pass signals to other fresh peers of the same room.
     *
     * Every signal of a call costs one unit of the sender's window allowance, accepted or not
     * (an empty call costs one too), so refused signals cannot be sent without limit.
     *
     * @param \stdClass $peer The caller's own presence (get_own_peer()).
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param array $signals List of ['to' => string, 'type' => string, 'payload' => string].
     * @param int|null $now Current time.
     * @return array{status: string, results: array}
     */
    public static function send(
        \stdClass $peer,
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        array $signals,
        ?int $now = null
    ): array {
        global $DB;
        $now = $now ?? time();

        if (!access::still_allowed($peer, $cm, $context, $vrroom)) {
            self::remove_peer($peer);
            return ['status' => 'closed', 'results' => []];
        }

        $update = (object)['id' => (int)$peer->id];
        if ($now - (int)$peer->ratewindow >= self::RATE_WINDOW) {
            $peer->ratewindow = $update->ratewindow = $now;
            $peer->pollcount = $update->pollcount = 0;
            $peer->signalcount = $update->signalcount = 0;
            self::purge_room((int)$peer->vrroomid, (int)$peer->groupid, $now);
        }

        $signalcount = (int)$peer->signalcount;
        $fresh = $now - self::STALE_AFTER;
        $targets = [];
        $results = [];
        $accepted = 0;
        foreach (array_values($signals) as $signal) {
            $to = (string)($signal['to'] ?? '');
            $entry = ['to' => self::is_peerid($to) ? $to : '', 'accepted' => false, 'error' => ''];

            if ($signalcount + 1 > self::MAX_SIGNALS_PER_WINDOW) {
                $entry['error'] = 'ratelimited';
                $results[] = $entry;
                continue;
            }
            $signalcount++;

            if (!self::is_peerid($to) || $to === $peer->peerid) {
                $entry['error'] = 'unknownpeer';
                $results[] = $entry;
                continue;
            }
            if (!array_key_exists($to, $targets)) {
                $targets[$to] = $DB->record_exists_select(
                    self::TABLE_PEERS,
                    'peerid = :peerid AND vrroomid = :vrroomid AND groupid = :groupid AND timeseen >= :fresh AND id <> :self',
                    ['peerid' => $to, 'vrroomid' => (int)$peer->vrroomid, 'groupid' => (int)$peer->groupid,
                    'fresh' => $fresh,
                    'self' => (int)$peer->id]
                );
            }
            if (!$targets[$to]) {
                $entry['error'] = 'unknownpeer';
                $results[] = $entry;
                continue;
            }

            $type = (string)($signal['type'] ?? '');
            [$canonical, $error] = signal_validator::canonical($type, (string)($signal['payload'] ?? ''));
            if ($canonical === null) {
                $entry['error'] = $error;
                $results[] = $entry;
                continue;
            }

            $pending = $DB->count_records_select(
                self::TABLE_SIGNALS,
                'topeer = :topeer AND timecreated >= :fresh',
                ['topeer' => $to, 'fresh' => $now - self::SIGNAL_TTL]
            );
            if ($pending >= self::MAX_PENDING) {
                $entry['error'] = 'mailboxfull';
                $results[] = $entry;
                continue;
            }

            $DB->insert_record(self::TABLE_SIGNALS, (object)[
                'vrroomid' => (int)$peer->vrroomid,
                'frompeer' => (string)$peer->peerid,
                'topeer' => $to,
                'type' => $type,
                'payload' => $canonical,
                'timecreated' => $now,
            ]);
            $accepted++;
            $entry['accepted'] = true;
            $results[] = $entry;
        }
        if (!$signals) {
            $signalcount++;
        }

        $peer->signalcount = $update->signalcount = min($signalcount, self::MAX_SIGNALS_PER_WINDOW + 1);
        $peer->timeseen = $update->timeseen = $now;
        if ($accepted > 0) {
            $peer->lastsignal = $update->lastsignal = $now;
        }
        $DB->update_record(self::TABLE_PEERS, $update);

        return ['status' => 'ok', 'results' => $results];
    }

    /**
     * Leave: remove the caller's own presence (fresh or stale). Never throws for a foreign id.
     *
     * @param string $peerid Peer id from the client.
     * @param int $userid The logged-in user.
     * @return void
     */
    public static function leave(string $peerid, int $userid): void {
        global $DB;
        if (!self::is_peerid($peerid)) {
            return;
        }
        $peer = $DB->get_record(self::TABLE_PEERS, ['peerid' => $peerid, 'userid' => $userid]);
        if ($peer) {
            self::remove_peer($peer);
        }
    }

    /**
     * The other fresh peers of a peer's room, with names made on the server.
     *
     * @param \stdClass $peer The peer whose room it is.
     * @param \context_module $context Module context.
     * @param int $now Current time.
     * @return array list of ['peerid' => string, 'name' => string, 'kind' => string]
     */
    public static function roster(\stdClass $peer, \context_module $context, int $now): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u', true, '', '', true);
        $sql = "SELECT p.id, p.peerid, p.kind, p.userid {$userfields->selects}
                  FROM {" . self::TABLE_PEERS . "} p
                  JOIN {user} u ON u.id = p.userid {$userfields->joins}
                 WHERE p.vrroomid = :vrroomid
                   AND p.groupid = :groupid
                   AND p.id <> :self
                   AND p.timeseen >= :fresh
              ORDER BY p.timejoined ASC, p.id ASC";
        $rows = $DB->get_records_sql($sql, array_merge($userfields->params, [
            'vrroomid' => (int)$peer->vrroomid,
            'groupid' => (int)$peer->groupid,
            'self' => (int)($peer->id ?? 0),
            'fresh' => $now - self::STALE_AFTER,
        ]));
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context, (int)$peer->userid);
        $roster = [];
        foreach ($rows as $row) {
            $roster[] = [
                'peerid' => (string)$row->peerid,
                'name' => fullname($row, $viewfullnames),
                'kind' => (string)$row->kind,
            ];
        }
        return $roster;
    }

    /**
     * Remove a presence and every signal from or to it.
     *
     * @param \stdClass $peer vrroomruntime_builtin_peers row.
     * @return void
     */
    public static function remove_peer(\stdClass $peer): void {
        global $DB;
        $DB->delete_records_select(
            self::TABLE_SIGNALS,
            'frompeer = :frompeer OR topeer = :topeer',
            ['frompeer' => (string)$peer->peerid, 'topeer' => (string)$peer->peerid]
        );
        $DB->delete_records(self::TABLE_PEERS, ['id' => (int)$peer->id]);
    }

    /**
     * Remove the stale presences of one room and the expired signals of its activity.
     *
     * @param int $vrroomid Activity.
     * @param int $groupid Room's group.
     * @param int $now Current time.
     * @return void
     */
    public static function purge_room(int $vrroomid, int $groupid, int $now): void {
        global $DB;
        $stale = $DB->get_fieldset_select(
            self::TABLE_PEERS,
            'peerid',
            'vrroomid = :vrroomid AND groupid = :groupid AND timeseen < :fresh',
            ['vrroomid' => $vrroomid, 'groupid' => $groupid, 'fresh' => $now - self::STALE_AFTER]
        );
        if ($stale) {
            self::delete_peers_by_peerid($stale);
        }
        $DB->delete_records_select(
            self::TABLE_SIGNALS,
            'vrroomid = :vrroomid AND timecreated < :expired',
            ['vrroomid' => $vrroomid, 'expired' => $now - self::SIGNAL_TTL]
        );
    }

    /**
     * Remove every stale presence (with its signals) and every expired signal of the site.
     *
     * @param int|null $now Current time.
     * @return array{peers: int, signals: int} How many rows were removed.
     */
    public static function purge_expired(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $stale = $DB->get_fieldset_select(
            self::TABLE_PEERS,
            'peerid',
            'timeseen < :fresh',
            ['fresh' => $now - self::STALE_AFTER]
        );
        $signals = 0;
        if ($stale) {
            $signals += self::delete_peers_by_peerid($stale);
        }
        $select = 'timecreated < :expired';
        $params = ['expired' => $now - self::SIGNAL_TTL];
        $signals += $DB->count_records_select(self::TABLE_SIGNALS, $select, $params);
        $DB->delete_records_select(self::TABLE_SIGNALS, $select, $params);
        return ['peers' => count($stale), 'signals' => $signals];
    }

    /**
     * Remove every presence and signal of an activity.
     *
     * @param int $vrroomid Activity.
     * @return void
     */
    public static function purge_activity(int $vrroomid): void {
        global $DB;
        $DB->delete_records(self::TABLE_SIGNALS, ['vrroomid' => $vrroomid]);
        $DB->delete_records(self::TABLE_PEERS, ['vrroomid' => $vrroomid]);
    }

    /**
     * Remove every presence and signal of the activities of a course.
     *
     * @param int $courseid Course.
     * @return void
     */
    public static function purge_course(int $courseid): void {
        global $DB;
        $select = 'vrroomid IN (SELECT m.id FROM {vrroom} m WHERE m.course = :courseid)';
        $DB->delete_records_select(self::TABLE_SIGNALS, $select, ['courseid' => $courseid]);
        $DB->delete_records_select(self::TABLE_PEERS, $select, ['courseid' => $courseid]);
    }

    /**
     * Remove a user's presences (in one activity, or everywhere) with every signal from or to them.
     *
     * @param int $userid User.
     * @param int $vrroomid Activity, 0 for all.
     * @return void
     */
    public static function purge_user(int $userid, int $vrroomid = 0): void {
        global $DB;
        $conditions = ['userid' => $userid];
        if ($vrroomid > 0) {
            $conditions['vrroomid'] = $vrroomid;
        }
        foreach ($DB->get_records(self::TABLE_PEERS, $conditions) as $peer) {
            self::remove_peer($peer);
        }
    }

    /**
     * The poll interval for a peer (PHASE3-CONTRACT §3.3).
     *
     * @param \stdClass $peer The peer, with timejoined and lastsignal up to date.
     * @param int $now Current time.
     * @param bool $delivered Whether this poll delivered signals.
     * @param bool $morepending Whether more signals wait for the peer.
     * @return int Milliseconds.
     */
    public static function interval_for(\stdClass $peer, int $now, bool $delivered, bool $morepending): int {
        global $DB;
        if (
            $delivered || $morepending
                || $now - (int)$peer->timejoined < self::ACTIVE_WINDOW
                || $now - (int)$peer->lastsignal < self::ACTIVE_WINDOW
        ) {
            return self::INTERVAL_ACTIVE;
        }
        $newcomer = $DB->record_exists_select(
            self::TABLE_PEERS,
            'vrroomid = :vrroomid AND groupid = :groupid AND id <> :self AND timejoined > :recent AND timeseen >= :fresh',
            ['vrroomid' => (int)$peer->vrroomid, 'groupid' => (int)$peer->groupid, 'self' => (int)($peer->id ?? 0),
            'recent' => $now - self::ACTIVE_WINDOW,
            'fresh' => $now - self::STALE_AFTER]
        );
        return $newcomer ? self::INTERVAL_ACTIVE : self::INTERVAL_IDLE;
    }

    /**
     * Delete presences and every signal from or to them, chunked.
     *
     * @param string[] $peerids Peer ids.
     * @return int Number of signals removed.
     */
    private static function delete_peers_by_peerid(array $peerids): int {
        global $DB;
        $signals = 0;
        foreach (array_chunk(array_values(array_unique($peerids)), self::CHUNK) as $chunk) {
            [$fromsql, $fromparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pf');
            [$tosql, $toparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pt');
            $select = "frompeer {$fromsql} OR topeer {$tosql}";
            $params = $fromparams + $toparams;
            $signals += $DB->count_records_select(self::TABLE_SIGNALS, $select, $params);
            $DB->delete_records_select(self::TABLE_SIGNALS, $select, $params);
            [$peersql, $peerparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pp');
            $DB->delete_records_select(self::TABLE_PEERS, "peerid {$peersql}", $peerparams);
        }
        return $signals;
    }
}
