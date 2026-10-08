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

use mod_vrroom\local\attempt\attempt_manager;

/**
 * Tests for live rooms: presence, capacity, isolation, signal delivery and every server limit.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(room_manager::class)]
final class room_manager_test extends \advanced_testcase {
    /** @var string A valid candidates payload. */
    private const CANDIDATES = '{"gen":0,"candidates":[{"candidate":"candidate:1 1 udp 2122260223 192.0.2.1 54321 typ host",'
        . '"sdpMid":"0","sdpMLineIndex":0,"usernameFragment":"abcd"}]}';

    /** @var int Base time of the tests. */
    private int $now;

    /**
     * Set the base time.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->now = time();
    }

    /**
     * A join creates a fresh presence; a second join of the same user replaces it.
     */
    public function test_join_and_rejoin(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$user, $attempt] = $this->learner($activity);

        $joined = $this->join($activity, $user, $attempt);
        $peer = $joined['peer'];
        $this->assertTrue(room_manager::is_peerid($peer->peerid));
        $this->assertSame([], $joined['peers']);
        $this->assertSame(room_manager::INTERVAL_ACTIVE, $joined['interval']);
        $row = $DB->get_record('vrroomruntime_builtin_peers', ['peerid' => $peer->peerid], '*', MUST_EXIST);
        $this->assertSame('attempt', $row->kind);
        $this->assertEquals($attempt->id, $row->attemptid);
        $this->assertEquals($this->now, $row->timejoined);
        $this->assertEquals($this->now, $row->timeseen);

        $again = $this->join($activity, $user, $attempt, 0, $this->now + 1);
        $this->assertNotSame($peer->peerid, $again['peer']->peerid);
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_peers', ['userid' => $user->id]));
        $this->assertNull(room_manager::get_own_peer($peer->peerid, (int)$user->id, $this->now + 1));
    }

    /**
     * The room fills up to its size; a stale presence does not count.
     */
    public function test_capacity(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 2);
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        [$c, $attemptc] = $this->learner($activity);

        $peera = $this->join($activity, $a, $attempta)['peer'];
        $joinedb = $this->join($activity, $b, $attemptb);
        $this->assertCount(1, $joinedb['peers']);
        $this->assertSame($peera->peerid, $joinedb['peers'][0]['peerid']);
        $this->assertSame(fullname($a), $joinedb['peers'][0]['name']);
        $this->assertSame('attempt', $joinedb['peers'][0]['kind']);

        try {
            $this->join($activity, $c, $attemptc);
            $this->fail('A third person joined a room of two.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorliveroomfull', $e->errorcode);
            $this->assertSame(get_string('errorliveroomfull', 'vrroomruntime_builtin', 2), $e->getMessage());
        }

        // A goes stale: C may join, and A's row is purged.
        $DB->set_field(
            'vrroomruntime_builtin_peers',
            'timeseen',
            $this->now - room_manager::STALE_AFTER - 1,
            ['peerid' => $peera->peerid]
        );
        $joinedc = $this->join($activity, $c, $attemptc);
        $this->assertCount(1, $joinedc['peers']);
        $this->assertSame($joinedb['peer']->peerid, $joinedc['peers'][0]['peerid']);
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peera->peerid]));
    }

    /**
     * Rooms of different groups and different activities never see or reach each other.
     */
    public function test_isolation(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(SEPARATEGROUPS);
        $course = $activity['course'];
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        [$a, $attempta] = $this->learner($activity, [(int)$groupa->id]);
        [$a2, $attempta2] = $this->learner($activity, [(int)$groupa->id]);
        [$b, $attemptb] = $this->learner($activity, [(int)$groupb->id]);

        $peera = $this->join($activity, $a, $attempta, (int)$groupa->id)['peer'];
        $peerb = $this->join($activity, $b, $attemptb, (int)$groupb->id)['peer'];
        $joineda2 = $this->join($activity, $a2, $attempta2, (int)$groupa->id);
        $this->assertSame([$peera->peerid], array_column($joineda2['peers'], 'peerid'));

        $other = $this->create_activity(NOGROUPS, 6, $course);
        [$o, $attempto] = $this->learner($other);
        $peero = $this->join($other, $o, $attempto)['peer'];
        $this->assertSame([], room_manager::roster($peero, $other['context'], $this->now));

        $result = $this->send($activity, $peera, [
            ['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
            ['to' => $peero->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
            ['to' => $joineda2['peer']->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(['unknownpeer', 'unknownpeer', ''], array_column($result['results'], 'error'));
        $this->assertSame([false, false, true], array_column($result['results'], 'accepted'));
    }

    /**
     * Sending to oneself, a stale peer or an unknown id is refused.
     */
    public function test_send_unknown_targets(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $DB->set_field(
            'vrroomruntime_builtin_peers',
            'timeseen',
            $this->now - room_manager::STALE_AFTER - 1,
            ['peerid' => $peerb->peerid]
        );

        $result = $this->send($activity, $peera, [
            ['to' => $peera->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
            ['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
            ['to' => room_manager::new_peerid(), 'type' => 'candidates', 'payload' => self::CANDIDATES],
            ['to' => 'not-a-peer-id', 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]);
        $this->assertSame(array_fill(0, 4, 'unknownpeer'), array_column($result['results'], 'error'));
        $this->assertSame('', $result['results'][3]['to']);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));
    }

    /**
     * A signal is delivered once, from the right sender, and deleted.
     */
    public function test_delivery_once(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];

        $this->send($activity, $peera, [['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_signals', ['topeer' => $peerb->peerid]));

        $poll = $this->poll($activity, $peerb, $this->now + 1);
        $this->assertSame('ok', $poll['status']);
        $this->assertCount(1, $poll['signals']);
        $this->assertSame($peera->peerid, $poll['signals'][0]['from']);
        $this->assertSame('candidates', $poll['signals'][0]['type']);
        $this->assertSame(json_decode(self::CANDIDATES, true), json_decode($poll['signals'][0]['payload'], true));
        $this->assertSame([$peera->peerid], array_column($poll['peers'], 'peerid'));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));

        $again = $this->poll($activity, $peerb, $this->now + 2);
        $this->assertSame([], $again['signals']);
    }

    /**
     * An expired signal is never delivered and is purged.
     */
    public function test_expired_signal(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $this->send($activity, $peera, [['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);
        $DB->set_field('vrroomruntime_builtin_signals', 'timecreated', $this->now - room_manager::SIGNAL_TTL - 1, []);

        $poll = $this->poll($activity, $peerb, $this->now);
        $this->assertSame([], $poll['signals']);

        room_manager::purge_room((int)$activity['vrroom']->id, 0, $this->now);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));
    }

    /**
     * Payloads: canonical re-encoding, too large, invalid.
     */
    public function test_payload_checks(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $to = $peerb->peerid;

        $big = ['gen' => 1, 'candidates' => array_fill(0, 30, ['candidate' => str_repeat('a', 512), 'sdpMid' => '0',
            'sdpMLineIndex' => 0, 'usernameFragment' => 'abcd'])];
        $tomany = ['gen' => 1, 'candidates' => array_fill(0, 31, ['candidate' => 'x'])];
        $result = $this->send($activity, $peera, [
            ['to' => $to, 'type' => 'description', 'payload' => '{"gen":2,"type":"offer","sdp":"v=0\r\n","extra":"<b>x</b>"}'],
            ['to' => $to, 'type' => 'candidates', 'payload' => json_encode($big)],
            ['to' => $to, 'type' => 'candidates', 'payload' => '{'],
            ['to' => $to, 'type' => 'bogus', 'payload' => self::CANDIDATES],
            ['to' => $to, 'type' => 'description', 'payload' => '{"gen":0,"type":"offer","sdp":"x=0"}'],
            ['to' => $to, 'type' => 'candidates', 'payload' => json_encode($tomany)],
            ['to' => $to, 'type' => 'description', 'payload' => json_encode(['gen' => 0, 'type' => 'offer', 'sdp' => "v=0\r\né"])],
        ]);
        $this->assertSame(
            ['', 'toolarge', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'],
            array_column($result['results'], 'error')
        );

        $stored = $DB->get_field('vrroomruntime_builtin_signals', 'payload', ['topeer' => $to], MUST_EXIST);
        $this->assertSame('{"gen":2,"type":"offer","sdp":"v=0\r\n"}', $stored);
    }

    /**
     * The 61st signal of a window is refused; a new window resets the count.
     */
    public function test_signal_rate_limit(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $signal = ['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES];

        $result = $this->send($activity, $peera, array_fill(0, room_manager::MAX_SIGNALS_PER_WINDOW, $signal));
        $this->assertSame(array_fill(0, room_manager::MAX_SIGNALS_PER_WINDOW, true), array_column($result['results'], 'accepted'));

        $result = $this->send($activity, $peera, [$signal], $this->now + 1);
        $this->assertSame('ratelimited', $result['results'][0]['error']);

        $result = $this->send($activity, $peera, [$signal], $this->now + room_manager::RATE_WINDOW);
        $this->assertTrue($result['results'][0]['accepted']);
    }

    /**
     * The 21st poll of a window is throttled: roster but no signals, a longer interval.
     */
    public function test_poll_rate_limit(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];

        for ($i = 0; $i < room_manager::MAX_POLLS_PER_WINDOW; $i++) {
            $this->assertNotSame(room_manager::INTERVAL_THROTTLED, $this->poll($activity, $peerb)['interval']);
        }
        $this->send($activity, $peera, [['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);
        $throttled = $this->poll($activity, $peerb);
        $this->assertSame('ok', $throttled['status']);
        $this->assertSame(room_manager::INTERVAL_THROTTLED, $throttled['interval']);
        $this->assertSame([], $throttled['signals']);
        $this->assertSame([$peera->peerid], array_column($throttled['peers'], 'peerid'));

        $next = $this->poll($activity, $peerb, $this->now + room_manager::RATE_WINDOW);
        $this->assertCount(1, $next['signals']);
    }

    /**
     * A recipient's mailbox holds 100 signals; a poll delivers at most 50.
     */
    public function test_mailbox_and_delivery_cap(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $signal = ['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES];

        $this->send($activity, $peera, array_fill(0, 60, $signal), $this->now);
        $result = $this->send($activity, $peera, array_fill(0, 41, $signal), $this->now + room_manager::RATE_WINDOW);
        $errors = array_column($result['results'], 'error');
        $this->assertSame(array_fill(0, 40, ''), array_slice($errors, 0, 40));
        $this->assertSame('mailboxfull', $errors[40]);

        $poll = $this->poll($activity, $peerb, $this->now + room_manager::RATE_WINDOW);
        $this->assertCount(room_manager::MAX_DELIVER, $poll['signals']);
        $this->assertSame(room_manager::INTERVAL_ACTIVE, $poll['interval']);
        $poll = $this->poll($activity, $peerb, $this->now + room_manager::RATE_WINDOW + 1);
        $this->assertCount(50, $poll['signals']);
        $poll = $this->poll($activity, $peerb, $this->now + room_manager::RATE_WINDOW + 2);
        $this->assertCount(0, $poll['signals']);
    }

    /**
     * Another user's peer id is never accepted as one's own, and that row is not touched.
     */
    public function test_get_own_peer_foreign(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];

        $this->assertNull(room_manager::get_own_peer($peera->peerid, (int)$b->id, $this->now + 5));
        $this->assertEquals($this->now, $DB->get_field('vrroomruntime_builtin_peers', 'timeseen', ['peerid' => $peera->peerid]));
        $this->assertNull(room_manager::get_own_peer('nonsense', (int)$a->id, $this->now));
        $this->assertNotNull(room_manager::get_own_peer($peera->peerid, (int)$a->id, $this->now + 5));
        $this->assertNull(room_manager::get_own_peer($peera->peerid, (int)$a->id, $this->now + room_manager::STALE_AFTER + 1));

        // Leaving with another user's id removes nothing.
        room_manager::leave($peera->peerid, (int)$b->id);
        $this->assertTrue($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peera->peerid]));
    }

    /**
     * A poll after the attempt finished closes the presence.
     */
    public function test_poll_after_finish(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        attempt_manager::finish($attempta);

        $poll = $this->poll($activity, $peera);
        $this->assertSame('closed', $poll['status']);
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peera->peerid]));
    }

    /**
     * Poll intervals: short while joining, long when idle, short again for a newcomer.
     */
    public function test_intervals(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];

        $this->assertSame(room_manager::INTERVAL_ACTIVE, $this->poll($activity, $peera, $this->now + 5)['interval']);
        $this->assertSame(
            room_manager::INTERVAL_IDLE,
            $this->poll($activity, $peera, $this->now + room_manager::ACTIVE_WINDOW + 1)['interval']
        );

        $this->join($activity, $b, $attemptb, 0, $this->now + room_manager::ACTIVE_WINDOW + 2);
        $this->assertSame(
            room_manager::INTERVAL_ACTIVE,
            $this->poll($activity, $peera, $this->now + room_manager::ACTIVE_WINDOW + 3)['interval']
        );
    }

    /**
     * Leave removes the presence and its signals; a second leave does nothing.
     */
    public function test_leave(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        $peera = $this->join($activity, $a, $attempta)['peer'];
        $peerb = $this->join($activity, $b, $attemptb)['peer'];
        $this->send($activity, $peera, [['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);
        $this->send($activity, $peerb, [['to' => $peera->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);

        room_manager::leave($peera->peerid, (int)$a->id);
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peera->peerid]));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));
        room_manager::leave($peera->peerid, (int)$a->id);
        room_manager::leave('', (int)$a->id);
        $this->assertTrue($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peerb->peerid]));
    }

    /**
     * Purges by activity, course and user, and the expiry purge.
     */
    public function test_purges(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity();
        $second = $this->create_activity(NOGROUPS, 6, $activity['course']);
        $elsewhere = $this->create_activity();
        $peers = [];
        foreach ([$activity, $second, $elsewhere] as $index => $act) {
            [$a, $attempta] = $this->learner($act);
            [$b, $attemptb] = $this->learner($act);
            $peera = $this->join($act, $a, $attempta)['peer'];
            $peerb = $this->join($act, $b, $attemptb)['peer'];
            $this->send($act, $peera, [['to' => $peerb->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES]]);
            $peers[$index] = [$a, $peera, $b, $peerb];
        }

        room_manager::purge_user((int)$peers[1][0]->id);
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peers[1][1]->peerid]));
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_signals', ['topeer' => $peers[1][3]->peerid]));
        $this->assertTrue($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $peers[1][3]->peerid]));

        room_manager::purge_activity((int)$activity['vrroom']->id);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $activity['vrroom']->id]));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $activity['vrroom']->id]));
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $second['vrroom']->id]));

        room_manager::purge_course((int)$activity['course']->id);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $second['vrroom']->id]));
        $this->assertSame(2, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $elsewhere['vrroom']->id]));
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $elsewhere['vrroom']->id]));

        $removed = room_manager::purge_expired($this->now + room_manager::SIGNAL_TTL + 1);
        $this->assertSame(['peers' => 2, 'signals' => 1], $removed);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers'));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));
    }

    /**
     * Join a room.
     *
     * @param array $activity Activity from create_activity().
     * @param \stdClass $user User.
     * @param \stdClass $attempt Attempt in progress.
     * @param int $groupid Group.
     * @param int|null $now Time.
     * @return array
     */
    private function join(array $activity, \stdClass $user, \stdClass $attempt, int $groupid = 0, ?int $now = null): array {
        return room_manager::join(
            $activity['cm'],
            $activity['context'],
            $activity['vrroom'],
            (int)$user->id,
            'attempt',
            (int)$attempt->id,
            $groupid,
            $now ?? $this->now
        );
    }

    /**
     * Poll as a peer (the peer object is updated in place as the services would reload it).
     *
     * @param array $activity Activity.
     * @param \stdClass $peer Peer.
     * @param int|null $now Time.
     * @return array
     */
    private function poll(array $activity, \stdClass $peer, ?int $now = null): array {
        global $DB;
        $fresh = $DB->get_record('vrroomruntime_builtin_peers', ['id' => $peer->id]) ?: $peer;
        return room_manager::poll(
            $fresh,
            $activity['cm'],
            $activity['context'],
            $activity['vrroom'],
            false,
            $now ?? $this->now
        );
    }

    /**
     * Send as a peer.
     *
     * @param array $activity Activity.
     * @param \stdClass $peer Peer.
     * @param array $signals Signals.
     * @param int|null $now Time.
     * @return array
     */
    private function send(array $activity, \stdClass $peer, array $signals, ?int $now = null): array {
        global $DB;
        $fresh = $DB->get_record('vrroomruntime_builtin_peers', ['id' => $peer->id]) ?: $peer;
        return room_manager::send(
            $fresh,
            $activity['cm'],
            $activity['context'],
            $activity['vrroom'],
            $signals,
            $now ?? $this->now
        );
    }

    /**
     * A learner with an attempt in progress, optionally in groups.
     *
     * @param array $activity Activity.
     * @param int[] $groupids Groups to add the learner to.
     * @return array [user, attempt]
     */
    private function learner(array $activity, array $groupids = []): array {
        $user = $this->getDataGenerator()->create_and_enrol($activity['course'], 'student');
        foreach ($groupids as $groupid) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupid, 'userid' => $user->id]);
        }
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        return [$user, $generator->create_attempt((int)$activity['vrroom']->id, (int)$user->id)];
    }

    /**
     * Create an activity with live mode on.
     *
     * @param int $groupmode Group mode.
     * @param int $maxusers Room size.
     * @param \stdClass|null $course Course, or null for a new one.
     * @return array ['course', 'cm', 'context', 'vrroom']
     */
    private function create_activity(int $groupmode = NOGROUPS, int $maxusers = 6, ?\stdClass $course = null): array {
        global $DB;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $DB->update_record('vrroom', (object)['id' => $record->id, 'livemode' => 1, 'livemaxusers' => $maxusers]);
        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $record->cmid]);
        rebuild_course_cache((int)$course->id, true);
        $cm = get_fast_modinfo($course->id)->get_cm($record->cmid);
        return [
            'course' => $course,
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'vrroom' => $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST),
        ];
    }
}
