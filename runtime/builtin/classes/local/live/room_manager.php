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
 * stays while its browser polls; after STALE_AFTER seconds without a poll it is gone. A room is
 * (activity, group, room number): fixed rooms 1..N always exist; rooms students open exist while
 * they have a fresh presence and vanish with the last one. Signals are only ever stored for a fresh
 * peer of the same activity, group and room, rebuilt by signal_validator, and deleted when
 * delivered or after SIGNAL_TTL seconds. Every limit is enforced here, on the server.
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
    /** @var int Milliseconds until the client asks for the room list again. */
    public const ROOMS_INTERVAL = 5000;
    /** @var int Highest room number (the column is int(4)). */
    public const MAX_ROOM_NUMBER = 9999;
    /** @var int Most rooms listed at once (rooms students open). */
    public const MAX_LISTED_ROOMS = 200;

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
     * Join a room: check the room exists and has space, replace the user's earlier presence in the activity.
     *
     * Fixed rooms (live mode 1) are numbered 1..access::max_rooms(). With rooms students open (live
     * mode 2), $room 0 starts a new room with the lowest free number, under an activity-level lock,
     * refused at the maximum; any other number must be an open room (one with a fresh presence).
     * Every check runs here, under the locks; nothing the client sends is trusted.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param int $userid The user (never from the client).
     * @param string $kind access::KIND_ATTEMPT or access::KIND_PREVIEW.
     * @param int $attemptid The attempt in progress, 0 for a preview.
     * @param int $groupid The room's group, 0 without groups.
     * @param int $room The room number, 0 to start a new room (rooms students open only).
     * @param int|null $now Current time.
     * @return array{peer: \stdClass, peers: array, interval: int, room: int}
     * @throws \moodle_exception errorlivebusy, errorliveroomfull, errorliveroomnotfound, errorliveroomgone,
     *     errorliveroomlimit
     */
    public static function join(
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        int $userid,
        string $kind,
        int $attemptid,
        int $groupid,
        int $room,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $vrroomid = (int)$vrroom->id;
        if ($room < 0 || $room > self::MAX_ROOM_NUMBER) {
            throw new \moodle_exception('errorliveroomnotfound', 'vrroomruntime_builtin');
        }
        $template = (object)[
            'vrroomid' => $vrroomid,
            'groupid' => $groupid,
            'userid' => $userid,
            'kind' => $kind,
            'attemptid' => $attemptid,
        ];
        $max = access::max_peers($vrroom);

        if (access::room_mode($vrroom) !== access::ROOMMODE_DYNAMIC) {
            // Fixed rooms 1..N always exist.
            if ($room < 1 || $room > access::max_rooms($vrroom)) {
                throw new \moodle_exception('errorliveroomnotfound', 'vrroomruntime_builtin');
            }
            $peer = self::join_number($template, $room, $max, false, $now);
        } else if ($room > 0) {
            // An open room: it must still exist when its lock is held.
            $peer = self::join_number($template, $room, $max, true, $now);
        } else {
            $peer = self::start_room($template, access::max_rooms($vrroom), $now);
        }

        return [
            'peer' => $peer,
            'peers' => self::roster($peer, $context, $now),
            'interval' => self::INTERVAL_ACTIVE,
            'room' => (int)$peer->room,
        ];
    }

    /**
     * The rooms of an activity (or of one group of it) with their occupancy, for the room list.
     *
     * A snapshot without locks and without writes: stale presences are left out, not purged.
     * Counts only, never names. The asking user's own presence (another window of theirs) is not
     * counted: joining replaces it, so it takes no place, and counting it showed a room as full
     * ("2 of 2") with a Join button. A room the teacher made smaller than its occupancy shows a
     * count above its capacity and is not joinable.
     *
     * @param \stdClass $vrroom vrroom row.
     * @param int $groupid The group whose rooms to list (already resolved), 0 without groups.
     * @param int $userid The user asking (never from the client).
     * @param int|null $now Current time.
     * @return array{groupid: int, roommode: string, maxrooms: int, capacity: int, rooms: array, canstart: bool,
     *     startreason: string}
     */
    public static function list_rooms(\stdClass $vrroom, int $groupid, int $userid, ?int $now = null): array {
        $now = $now ?? time();
        $vrroomid = (int)$vrroom->id;
        $mode = access::room_mode($vrroom);
        $maxrooms = access::max_rooms($vrroom);
        $capacity = access::max_peers($vrroom);

        $all = self::fresh_rooms($vrroomid, $groupid, $now, 0);
        $others = self::fresh_rooms($vrroomid, $groupid, $now, $userid);

        if ($mode === access::ROOMMODE_DYNAMIC) {
            $numbers = array_slice(array_keys($all), 0, self::MAX_LISTED_ROOMS);
            $open = count($others);
            $canstart = $maxrooms === 0 || $open < $maxrooms;
            $startreason = $canstart ? '' : 'limit';
        } else {
            $numbers = range(1, max(1, $maxrooms));
            $canstart = false;
            $startreason = 'fixed';
        }

        $rooms = [];
        foreach ($numbers as $number) {
            $number = (int)$number;
            $rooms[] = [
                'number' => $number,
                'name' => get_string('liveroomname', 'vrroomruntime_builtin', $number),
                'count' => (int)($others[$number] ?? 0),
                'capacity' => $capacity,
                'joinable' => (int)($others[$number] ?? 0) < $capacity,
            ];
        }

        return [
            'groupid' => $groupid,
            'roommode' => $mode,
            'maxrooms' => $maxrooms,
            'capacity' => $capacity,
            'rooms' => $rooms,
            'canstart' => $canstart,
            'startreason' => $startreason,
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
                    'peerid = :peerid AND vrroomid = :vrroomid AND groupid = :groupid AND room = :room'
                        . ' AND timeseen >= :fresh AND id <> :self',
                    ['peerid' => $to, 'vrroomid' => (int)$peer->vrroomid, 'groupid' => (int)$peer->groupid,
                    'room' => (int)($peer->room ?? 0),
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
     * The other fresh peers of a peer's room (activity, group and room number), with names made on the server.
     *
     * How each person looks comes from the listeners of \mod_vrroom\hook\avatar_appearance (such as
     * local_vravatar), dispatched once for the whole list: 'appearance' is the JSON of the appearance
     * document, 'picture' the profile picture URL and 'assets' the JSON object of the costume model URLs
     * by name ('outfit', 'hat'), all '' when no listener set them ("today's look").
     *
     * @param \stdClass $peer The peer whose room it is.
     * @param \context_module $context Module context.
     * @param int $now Current time.
     * @return array list of ['peerid' => string, 'name' => string, 'kind' => string, 'appearance' => string,
     *     'picture' => string, 'assets' => string]
     */
    public static function roster(\stdClass $peer, \context_module $context, int $now): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u', true, '', '', true);
        $sql = "SELECT p.id, p.peerid, p.kind, p.userid {$userfields->selects}
                  FROM {" . self::TABLE_PEERS . "} p
                  JOIN {user} u ON u.id = p.userid {$userfields->joins}
                 WHERE p.vrroomid = :vrroomid
                   AND p.groupid = :groupid
                   AND p.room = :room
                   AND p.id <> :self
                   AND p.timeseen >= :fresh
              ORDER BY p.timejoined ASC, p.id ASC";
        $rows = $DB->get_records_sql($sql, array_merge($userfields->params, [
            'vrroomid' => (int)$peer->vrroomid,
            'groupid' => (int)$peer->groupid,
            'room' => (int)($peer->room ?? 0),
            'self' => (int)($peer->id ?? 0),
            'fresh' => $now - self::STALE_AFTER,
        ]));
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context, (int)$peer->userid);
        $looks = self::appearances($rows, $context);
        $roster = [];
        foreach ($rows as $row) {
            $peerid = (string)$row->peerid;
            $roster[] = [
                'peerid' => $peerid,
                'name' => fullname($row, $viewfullnames),
                'kind' => (string)$row->kind,
                'appearance' => $looks[$peerid]['appearance'] ?? '',
                'picture' => $looks[$peerid]['picture'] ?? '',
                'assets' => $looks[$peerid]['assets'] ?? '',
            ];
        }
        return $roster;
    }

    /**
     * Ask the avatar_appearance listeners how the listed people look (one dispatch for all of them).
     *
     * @param array $rows Presence rows with peerid and userid.
     * @param \context_module $context Module context.
     * @return array<string, array{appearance: string, picture: string, assets: string}> peerid => JSON of the
     *     document, picture URL and JSON object of the asset URLs ('' when none)
     */
    public static function appearances(array $rows, \context_module $context): array {
        if (!$rows) {
            return [];
        }
        $peers = [];
        foreach ($rows as $row) {
            $peers[] = ['peerid' => (string)$row->peerid, 'userid' => (int)$row->userid];
        }
        $hook = new \mod_vrroom\hook\avatar_appearance($peers, $context);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $looks = [];
        foreach ($hook->get_appearances() as $peerid => $look) {
            $json = $look['appearance'] ? json_encode($look['appearance'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            $assets = !empty($look['assets'])
                ? json_encode((object)$look['assets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            $looks[(string)$peerid] = [
                'appearance' => is_string($json) ? $json : '',
                'picture' => (string)$look['picture'],
                'assets' => is_string($assets) ? $assets : '',
            ];
        }
        return $looks;
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
     * Remove the stale presences of one group's rooms and the expired signals of its activity.
     *
     * Rooms students open vanish with their last presence: there is no rooms table.
     *
     * @param int $vrroomid Activity.
     * @param int $groupid The rooms' group.
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
            'vrroomid = :vrroomid AND groupid = :groupid AND room = :room AND id <> :self'
                . ' AND timejoined > :recent AND timeseen >= :fresh',
            ['vrroomid' => (int)$peer->vrroomid, 'groupid' => (int)$peer->groupid, 'room' => (int)($peer->room ?? 0),
            'self' => (int)($peer->id ?? 0),
            'recent' => $now - self::ACTIVE_WINDOW,
            'fresh' => $now - self::STALE_AFTER]
        );
        return $newcomer ? self::INTERVAL_ACTIVE : self::INTERVAL_IDLE;
    }

    /**
     * Join one room number under its lock.
     *
     * A join into a room students open also takes the activity's start lock first (start before room,
     * as in start_room()): leaving takes no lock, so without it a join that saw the room open just
     * before its last person left could bring the room back while a start opened another one, and
     * the maximum of rooms would be passed. Every way a room students open can come into being is
     * then serialised.
     *
     * @param \stdClass $template Presence values: vrroomid, groupid, userid, kind, attemptid.
     * @param int $room Room number, 1 upwards.
     * @param int $max Room capacity.
     * @param bool $mustexist Whether the room must already have a fresh presence (rooms students open).
     * @param int $now Current time.
     * @return \stdClass The inserted presence.
     * @throws \moodle_exception errorlivebusy, errorliveroomgone, errorliveroomfull
     */
    private static function join_number(\stdClass $template, int $room, int $max, bool $mustexist, int $now): \stdClass {
        global $DB;
        $vrroomid = (int)$template->vrroomid;
        $groupid = (int)$template->groupid;
        $startlock = $mustexist ? self::lock("start_{$vrroomid}") : null;
        try {
            $lock = self::lock("room_{$vrroomid}_{$groupid}_{$room}");
        } catch (\moodle_exception $e) {
            if ($startlock) {
                $startlock->release();
            }
            throw $e;
        }
        try {
            self::purge_room($vrroomid, $groupid, $now);
            $select = 'vrroomid = :vrroomid AND groupid = :groupid AND room = :room AND timeseen >= :fresh';
            $params = ['vrroomid' => $vrroomid, 'groupid' => $groupid, 'room' => $room, 'fresh' => $now - self::STALE_AFTER];

            // An open room exists while it has a fresh presence (the caller's own counts too).
            if ($mustexist && !$DB->record_exists_select(self::TABLE_PEERS, $select, $params)) {
                throw new \moodle_exception('errorliveroomgone', 'vrroomruntime_builtin');
            }

            self::remove_own_presence($vrroomid, (int)$template->userid);

            if ($DB->count_records_select(self::TABLE_PEERS, $select, $params) >= $max) {
                throw new \moodle_exception('errorliveroomfull', 'vrroomruntime_builtin', '', $max);
            }
            return self::insert_presence($template, $room, $now);
        } finally {
            $lock->release();
            if ($startlock) {
                $startlock->release();
            }
        }
    }

    /**
     * Start a new room (rooms students open): the lowest free number, refused at the maximum.
     *
     * Takes the activity's start lock, then the new room's lock (always in this order). Only a
     * start makes a room come into being and starts are serialised, so two people cannot both open
     * the last allowed room.
     *
     * @param \stdClass $template Presence values: vrroomid, groupid, userid, kind, attemptid.
     * @param int $maxrooms Most rooms open at once in the group, 0 for no limit.
     * @param int $now Current time.
     * @return \stdClass The inserted presence.
     * @throws \moodle_exception errorlivebusy, errorliveroomlimit
     */
    private static function start_room(\stdClass $template, int $maxrooms, int $now): \stdClass {
        global $DB;
        $vrroomid = (int)$template->vrroomid;
        $groupid = (int)$template->groupid;
        $startlock = self::lock("start_{$vrroomid}");
        try {
            self::purge_room($vrroomid, $groupid, $now);
            // The caller's own presence is removed first, so leaving a room to start one uses no slot.
            self::remove_own_presence($vrroomid, (int)$template->userid);

            $occupied = self::fresh_rooms($vrroomid, $groupid, $now, 0);
            if ($maxrooms > 0 && count($occupied) >= $maxrooms) {
                throw new \moodle_exception('errorliveroomlimit', 'vrroomruntime_builtin', '', $maxrooms);
            }
            $room = self::lowest_free(array_keys($occupied));
            if ($room > self::MAX_ROOM_NUMBER) {
                throw new \moodle_exception('errorliveroomlimit', 'vrroomruntime_builtin', '', self::MAX_ROOM_NUMBER);
            }

            $roomlock = self::lock("room_{$vrroomid}_{$groupid}_{$room}");
            try {
                $taken = $DB->count_records_select(
                    self::TABLE_PEERS,
                    'vrroomid = :vrroomid AND groupid = :groupid AND room = :room AND timeseen >= :fresh',
                    ['vrroomid' => $vrroomid, 'groupid' => $groupid, 'room' => $room, 'fresh' => $now - self::STALE_AFTER]
                );
                if ($taken > 0) {
                    throw new \moodle_exception('errorlivebusy', 'vrroomruntime_builtin');
                }
                return self::insert_presence($template, $room, $now);
            } finally {
                $roomlock->release();
            }
        } finally {
            $startlock->release();
        }
    }

    /**
     * Take a live room lock.
     *
     * @param string $key Lock key: room_{vrroomid}_{groupid}_{room} or start_{vrroomid}.
     * @return \core\lock\lock
     * @throws \moodle_exception errorlivebusy when the lock cannot be had in time
     */
    private static function lock(string $key): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($key, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('errorlivebusy', 'vrroomruntime_builtin');
        }
        return $lock;
    }

    /**
     * Remove a user's presence in an activity (any group, any room): one presence per user per activity.
     *
     * @param int $vrroomid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function remove_own_presence(int $vrroomid, int $userid): void {
        global $DB;
        $existing = $DB->get_record(self::TABLE_PEERS, ['vrroomid' => $vrroomid, 'userid' => $userid]);
        if ($existing) {
            self::remove_peer($existing);
        }
    }

    /**
     * Insert a new presence.
     *
     * @param \stdClass $template Presence values: vrroomid, groupid, userid, kind, attemptid.
     * @param int $room Room number.
     * @param int $now Current time.
     * @return \stdClass The inserted row with id.
     * @throws \moodle_exception errorlivebusy when a simultaneous join of the same user won the unique index
     */
    private static function insert_presence(\stdClass $template, int $room, int $now): \stdClass {
        global $DB;
        $peer = (object)[
            'vrroomid' => (int)$template->vrroomid,
            'groupid' => (int)$template->groupid,
            'room' => $room,
            'userid' => (int)$template->userid,
            'peerid' => self::new_peerid(),
            'kind' => (string)$template->kind,
            'attemptid' => (int)$template->attemptid,
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
            // A simultaneous join of the same user into another room won the unique index.
            throw new \moodle_exception('errorlivebusy', 'vrroomruntime_builtin');
        }
        return $peer;
    }

    /**
     * The open rooms of a group with their fresh presences.
     *
     * @param int $vrroomid Activity.
     * @param int $groupid Group, 0 without groups.
     * @param int $now Current time.
     * @param int $excludeuserid A user whose presence is not counted, 0 for none.
     * @return array<int, int> room number => people, in number order
     */
    private static function fresh_rooms(int $vrroomid, int $groupid, int $now, int $excludeuserid): array {
        global $DB;
        $params = ['vrroomid' => $vrroomid, 'groupid' => $groupid, 'fresh' => $now - self::STALE_AFTER];
        $exclude = '';
        if ($excludeuserid > 0) {
            $exclude = ' AND userid <> :userid';
            $params['userid'] = $excludeuserid;
        }
        $sql = "SELECT room, COUNT(1) AS people
                  FROM {" . self::TABLE_PEERS . "}
                 WHERE vrroomid = :vrroomid AND groupid = :groupid AND timeseen >= :fresh{$exclude}
              GROUP BY room
              ORDER BY room ASC";
        $rooms = [];
        foreach ($DB->get_records_sql_menu($sql, $params) as $room => $people) {
            $rooms[(int)$room] = (int)$people;
        }
        ksort($rooms);
        return $rooms;
    }

    /**
     * The lowest room number, 1 upwards, not in a list.
     *
     * @param int[] $occupied Room numbers in use.
     * @return int
     */
    private static function lowest_free(array $occupied): int {
        $used = array_flip(array_map('intval', $occupied));
        $room = 1;
        while (isset($used[$room])) {
            $room++;
        }
        return $room;
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
