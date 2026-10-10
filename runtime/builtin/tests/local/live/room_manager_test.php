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
 * Tests for live rooms: presence, capacity, room numbers, isolation, signal delivery and every server limit.
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
     * Fixed rooms: only numbers 1..N can be joined, and the presence records its number.
     */
    public function test_fixed_room_numbers(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 1, 3);
        [$user, $attempt] = $this->learner($activity);

        $joined = $this->join($activity, $user, $attempt, 0, null, 2);
        $this->assertSame(2, $joined['room']);
        $this->assertEquals(2, $DB->get_field('vrroomruntime_builtin_peers', 'room', ['peerid' => $joined['peer']->peerid]));

        foreach ([4, 0, -1, room_manager::MAX_ROOM_NUMBER + 1] as $room) {
            $e = $this->refused($activity, $user, $attempt, $room, 'errorliveroomnotfound');
            $this->assertSame(get_string('errorliveroomnotfound', 'vrroomruntime_builtin'), $e->getMessage());
        }
        // A refused join leaves the earlier presence alone.
        $this->assertTrue($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $joined['peer']->peerid]));
    }

    /**
     * Capacity is per room: a full room 1 refuses, room 2 still takes people; the list shows it.
     */
    public function test_capacity_per_room(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 2, null, 1, 2);
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        [$c, $attemptc] = $this->learner($activity);

        $this->join($activity, $a, $attempta, 0, null, 1);
        $this->join($activity, $b, $attemptb, 0, null, 1);
        $e = $this->refused($activity, $c, $attemptc, 1, 'errorliveroomfull');
        $this->assertSame(get_string('errorliveroomfull', 'vrroomruntime_builtin', 2), $e->getMessage());

        $list = room_manager::list_rooms($activity['vrroom'], 0, (int)$c->id, $this->now);
        $this->assertSame('fixed', $list['roommode']);
        $this->assertSame(2, $list['maxrooms']);
        $this->assertSame(2, $list['capacity']);
        $this->assertFalse($list['canstart']);
        $this->assertSame('fixed', $list['startreason']);
        $this->assertSame([
            ['number' => 1, 'name' => get_string('liveroomname', 'vrroomruntime_builtin', 1), 'count' => 2, 'capacity' => 2,
                'joinable' => false],
            ['number' => 2, 'name' => get_string('liveroomname', 'vrroomruntime_builtin', 2), 'count' => 0, 'capacity' => 2,
                'joinable' => true],
        ], $list['rooms']);

        // A member of a full room may re-enter it (their own presence does not count).
        $this->assertTrue(room_manager::list_rooms($activity['vrroom'], 0, (int)$a->id, $this->now)['rooms'][0]['joinable']);

        $joined = $this->join($activity, $c, $attemptc, 0, null, 2);
        $this->assertSame(2, $joined['room']);
        $this->assertSame([], $joined['peers']);
    }

    /**
     * The list does not count the asking user's own presence (another window), so a room is never
     * shown full while it can be joined; a room made smaller than its occupancy is shown above
     * capacity and not joinable.
     */
    public function test_list_counts_without_own_presence(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 3, null, 1, 2);
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        [$c, $attemptc] = $this->learner($activity);
        [$d, $attemptd] = $this->learner($activity);

        $this->join($activity, $a, $attempta, 0, null, 1);
        $this->join($activity, $b, $attemptb, 0, null, 1);
        $this->join($activity, $c, $attemptc, 0, null, 1);

        // A's other window: room 1 holds two other people and can be joined.
        $rooma = room_manager::list_rooms($activity['vrroom'], 0, (int)$a->id, $this->now)['rooms'][0];
        $this->assertSame(2, $rooma['count']);
        $this->assertTrue($rooma['joinable']);
        // Someone outside the room sees all three.
        $roomd = room_manager::list_rooms($activity['vrroom'], 0, (int)$d->id, $this->now)['rooms'][0];
        $this->assertSame(3, $roomd['count']);
        $this->assertFalse($roomd['joinable']);

        // The teacher lowers the room size to 2 with three people in: above capacity, not joinable.
        $DB->set_field('vrroom', 'livemaxusers', 2, ['id' => $activity['vrroom']->id]);
        $activity['vrroom'] = $DB->get_record('vrroom', ['id' => $activity['vrroom']->id], '*', MUST_EXIST);
        $list = room_manager::list_rooms($activity['vrroom'], 0, (int)$d->id, $this->now);
        $this->assertSame(2, $list['capacity']);
        $this->assertSame(3, $list['rooms'][0]['count']);
        $this->assertSame(2, $list['rooms'][0]['capacity']);
        $this->assertFalse($list['rooms'][0]['joinable']);
        $this->refused($activity, $d, $attemptd, 1, 'errorliveroomfull');
    }

    /**
     * Roster, signal targets and the newcomer interval are scoped to the room.
     */
    public function test_rooms_are_apart(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 1, 2);
        [$a, $attempta] = $this->learner($activity);
        [$b, $attemptb] = $this->learner($activity);
        [$a2, $attempta2] = $this->learner($activity);

        $peera = $this->join($activity, $a, $attempta, 0, null, 1)['peer'];
        $joinedb = $this->join($activity, $b, $attemptb, 0, null, 2);
        $this->assertSame([], $joinedb['peers']);
        $this->assertSame([], room_manager::roster($peera, $activity['context'], $this->now));

        $result = $this->send($activity, $peera, [
            ['to' => $joinedb['peer']->peerid, 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]);
        $this->assertSame('unknownpeer', $result['results'][0]['error']);

        // Someone new in room 2 does not speed up the polls of room 1; someone new in room 1 does.
        $this->assertSame(
            room_manager::INTERVAL_IDLE,
            $this->poll($activity, $peera, $this->now + room_manager::ACTIVE_WINDOW + 1)['interval']
        );
        $this->join($activity, $b, $attemptb, 0, $this->now + room_manager::ACTIVE_WINDOW + 2, 2);
        $this->assertSame(
            room_manager::INTERVAL_IDLE,
            $this->poll($activity, $peera, $this->now + room_manager::ACTIVE_WINDOW + 3)['interval']
        );
        $this->join($activity, $a2, $attempta2, 0, $this->now + room_manager::ACTIVE_WINDOW + 4, 1);
        $this->assertSame(
            room_manager::INTERVAL_ACTIVE,
            $this->poll($activity, $peera, $this->now + room_manager::ACTIVE_WINDOW + 5)['interval']
        );
    }

    /**
     * Starting a room takes the lowest free number.
     */
    public function test_start_lowest_free(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 2, 0);
        [$x, $attemptx] = $this->learner($activity);
        [$y, $attempty] = $this->learner($activity);
        $this->presence($activity, 1);
        $this->presence($activity, 3);

        $this->assertSame(2, $this->join($activity, $x, $attemptx, 0, null, 0)['room']);
        $this->assertSame(4, $this->join($activity, $y, $attempty, 0, null, 0)['room']);

        $list = room_manager::list_rooms($activity['vrroom'], 0, (int)$x->id, $this->now);
        $this->assertSame('dynamic', $list['roommode']);
        $this->assertSame(0, $list['maxrooms']);
        $this->assertSame([1, 2, 3, 4], array_column($list['rooms'], 'number'));
        $this->assertTrue($list['canstart']);
        $this->assertSame('', $list['startreason']);
    }

    /**
     * Starting a room is refused at the maximum; one start after another at max - 1 lets only the first in.
     */
    public function test_start_limit(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 2, 2);
        [$x, $attemptx] = $this->learner($activity);
        [$y, $attempty] = $this->learner($activity);
        $this->presence($activity, 1);

        // Two starts at max - 1 open rooms: serialised by the start lock, the second sees the limit.
        $this->assertSame(2, $this->join($activity, $x, $attemptx, 0, null, 0)['room']);
        $e = $this->refused($activity, $y, $attempty, 0, 'errorliveroomlimit');
        $this->assertSame(get_string('errorliveroomlimit', 'vrroomruntime_builtin', 2), $e->getMessage());

        $list = room_manager::list_rooms($activity['vrroom'], 0, (int)$y->id, $this->now);
        $this->assertFalse($list['canstart']);
        $this->assertSame('limit', $list['startreason']);

        // An existing room with space can still be joined.
        $this->assertSame(1, $this->join($activity, $y, $attempty, 0, null, 1)['room']);
    }

    /**
     * Someone alone in a room who starts a new one does not use up a slot.
     */
    public function test_start_alone_does_not_count(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 2, 1);
        [$x, $attemptx] = $this->learner($activity);

        $this->assertSame(1, $this->join($activity, $x, $attemptx, 0, null, 0)['room']);
        $list = room_manager::list_rooms($activity['vrroom'], 0, (int)$x->id, $this->now);
        $this->assertTrue($list['canstart']);

        $again = $this->join($activity, $x, $attemptx, 0, $this->now + 1, 0);
        $this->assertSame(1, $again['room']);
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_peers', ['userid' => $x->id]));
    }

    /**
     * Rooms students open: a number must be open to be joined.
     */
    public function test_dynamic_join_existing(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 2, 0);
        [$x, $attemptx] = $this->learner($activity);
        $other = $this->presence($activity, 1);

        $e = $this->refused($activity, $x, $attemptx, 5, 'errorliveroomgone');
        $this->assertSame(get_string('errorliveroomgone', 'vrroomruntime_builtin'), $e->getMessage());

        $joined = $this->join($activity, $x, $attemptx, 0, null, 1);
        $this->assertSame(1, $joined['room']);
        $this->assertSame([$other->peerid], array_column($joined['peers'], 'peerid'));

        // A number beyond the column's range is no room at all.
        $this->refused($activity, $x, $attemptx, room_manager::MAX_ROOM_NUMBER + 1, 'errorliveroomnotfound');
    }

    /**
     * Empty rooms students open disappear after the last leave and after the last presence goes stale.
     */
    public function test_dynamic_rooms_disappear(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 2, 0);
        [$x, $attemptx] = $this->learner($activity);
        [$y, $attempty] = $this->learner($activity);

        $peer = $this->join($activity, $x, $attemptx, 0, null, 0)['peer'];
        $rooms = room_manager::list_rooms($activity['vrroom'], 0, (int)$y->id, $this->now)['rooms'];
        $this->assertSame([1], array_column($rooms, 'number'));
        room_manager::leave($peer->peerid, (int)$x->id);
        $this->assertSame([], room_manager::list_rooms($activity['vrroom'], 0, (int)$y->id, $this->now)['rooms']);
        $this->refused($activity, $y, $attempty, 1, 'errorliveroomgone');

        $this->join($activity, $y, $attempty, 0, null, 0);
        $this->assertCount(1, room_manager::list_rooms($activity['vrroom'], 0, (int)$x->id, $this->now)['rooms']);
        $later = $this->now + room_manager::STALE_AFTER + 1;
        $this->assertSame([], room_manager::list_rooms($activity['vrroom'], 0, (int)$x->id, $later)['rooms']);
    }

    /**
     * Groups have their own rooms, counts and room maximum.
     */
    public function test_groups_have_own_rooms(): void {
        $this->resetAfterTest();
        $activity = $this->create_activity(SEPARATEGROUPS, 6, null, 2, 1);
        $course = $activity['course'];
        $groupa = (int)$this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $groupb = (int)$this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        [$a, $attempta] = $this->learner($activity, [$groupa]);
        [$a2, $attempta2] = $this->learner($activity, [$groupa]);
        [$b, $attemptb] = $this->learner($activity, [$groupb]);

        $peera = $this->join($activity, $a, $attempta, $groupa, null, 0)['peer'];
        $joinedb = $this->join($activity, $b, $attemptb, $groupb, null, 0);
        $this->assertSame(1, $joinedb['room']);
        $this->assertSame([], $joinedb['peers']);
        $this->assertSame(1, (int)$peera->room);

        $this->refused($activity, $a2, $attempta2, 0, 'errorliveroomlimit', $groupa);

        $lista = room_manager::list_rooms($activity['vrroom'], $groupa, (int)$a2->id, $this->now);
        $this->assertSame($groupa, $lista['groupid']);
        $this->assertCount(1, $lista['rooms']);
        $this->assertSame(1, $lista['rooms'][0]['count']);
        $this->assertSame([], room_manager::roster($peera, $activity['context'], $this->now));
    }

    /**
     * A second join into another room replaces the first presence.
     */
    public function test_rejoin_other_room(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->create_activity(NOGROUPS, 6, null, 1, 2);
        [$user, $attempt] = $this->learner($activity);

        $first = $this->join($activity, $user, $attempt, 0, null, 1)['peer'];
        $second = $this->join($activity, $user, $attempt, 0, $this->now + 1, 2)['peer'];
        $rows = $DB->get_records('vrroomruntime_builtin_peers', ['userid' => $user->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($second->peerid, $row->peerid);
        $this->assertEquals(2, $row->room);
        $this->assertNotSame($first->peerid, $second->peerid);
    }

    /**
     * Join a room.
     *
     * @param array $activity Activity from create_activity().
     * @param \stdClass $user User.
     * @param \stdClass $attempt Attempt in progress.
     * @param int $groupid Group.
     * @param int|null $now Time.
     * @param int $room Room number, 0 to start a new room.
     * @return array
     */
    private function join(
        array $activity,
        \stdClass $user,
        \stdClass $attempt,
        int $groupid = 0,
        ?int $now = null,
        int $room = 1
    ): array {
        return room_manager::join(
            $activity['cm'],
            $activity['context'],
            $activity['vrroom'],
            (int)$user->id,
            'attempt',
            (int)$attempt->id,
            $groupid,
            $room,
            $now ?? $this->now
        );
    }

    /**
     * The peer list asks the avatar_appearance listeners once per list, for all listed people, and
     * carries what they set; people they leave out, and lists without listeners, get '' (today's look).
     */
    public function test_roster_appearances(): void {
        $this->resetAfterTest();
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::install();
        $activity = $this->create_activity();
        $other = $this->presence($activity, 1);
        $plain = $this->presence($activity, 1);
        [$user, $attempt] = $this->learner($activity);
        $doc = ['schema' => 'vrroom-avatar/1', 'body' => 'broad', 'color' => 'red', 'picture' => true];
        $picture = 'https://example.org/pluginfile.php/1/user/icon/boost/f1';
        $outfit = 'https://example.org/local/avatar/costumes/robot-screen.glb';
        $callback = function (\mod_vrroom\hook\avatar_appearance $hook) use ($other, $plain, $doc, $picture, $outfit): void {
            $this->assertInstanceOf(\context_module::class, $hook->get_context());
            $hook->set_appearance((string)$other->peerid, $doc, $picture, ['outfit' => $outfit, 'hat' => 'data:x', 'x' => $outfit]);
            $hook->set_appearance((string)$plain->peerid, $doc, '', ['outfit' => 'javascript:alert(1)']);
        };
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::$callback = $callback;

        $joined = $this->join($activity, $user, $attempt);
        $this->assertSame(1, \mod_vrroom\tests\hook\avatar_appearance_callbacks::$calls);
        $this->assertEqualsCanonicalizing(
            [
                ['peerid' => (string)$other->peerid, 'userid' => (int)$other->userid],
                ['peerid' => (string)$plain->peerid, 'userid' => (int)$plain->userid],
            ],
            \mod_vrroom\tests\hook\avatar_appearance_callbacks::$peerlists[0]
        );
        $bypeer = array_column($joined['peers'], null, 'peerid');
        $this->assertSame($doc, json_decode($bypeer[$other->peerid]['appearance'], true));
        $this->assertSame($picture, $bypeer[$other->peerid]['picture']);
        $this->assertSame(['outfit' => $outfit], json_decode($bypeer[$other->peerid]['assets'], true));
        // A look whose only asset was invalid keeps the look, with no assets.
        $this->assertSame($doc, json_decode($bypeer[$plain->peerid]['appearance'], true));
        $this->assertSame('', $bypeer[$plain->peerid]['picture']);
        $this->assertSame('', $bypeer[$plain->peerid]['assets']);

        // A poll asks again (looks can change while people are in the room).
        $poll = $this->poll($activity, $joined['peer']);
        $this->assertSame(2, \mod_vrroom\tests\hook\avatar_appearance_callbacks::$calls);
        $this->assertSame($picture, array_column($poll['peers'], null, 'peerid')[$other->peerid]['picture']);

        // Alone in a room: nobody to ask about, no dispatch.
        $activity2 = $this->create_activity(NOGROUPS, 6, $activity['course']);
        [$user2, $attempt2] = $this->learner($activity2);
        $this->assertSame([], $this->join($activity2, $user2, $attempt2)['peers']);
        $this->assertSame(2, \mod_vrroom\tests\hook\avatar_appearance_callbacks::$calls);

        // Without listeners every look is today's.
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::install(false);
        $poll = $this->poll($activity, $joined['peer']);
        foreach ($poll['peers'] as $entry) {
            $this->assertSame('', $entry['appearance']);
            $this->assertSame('', $entry['picture']);
            $this->assertSame('', $entry['assets']);
        }
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::reset();
    }

    /**
     * Join and expect a refusal.
     *
     * @param array $activity Activity from create_activity().
     * @param \stdClass $user User.
     * @param \stdClass $attempt Attempt in progress.
     * @param int $room Room number, 0 to start a new room.
     * @param string $errorcode The expected error code.
     * @param int $groupid Group.
     * @return \moodle_exception
     */
    private function refused(
        array $activity,
        \stdClass $user,
        \stdClass $attempt,
        int $room,
        string $errorcode,
        int $groupid = 0
    ): \moodle_exception {
        try {
            $this->join($activity, $user, $attempt, $groupid, null, $room);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            return $e;
        }
        $this->fail("Joining room {$room} was not refused with {$errorcode}.");
    }

    /**
     * A fresh presence made directly (another user, no checks).
     *
     * @param array $activity Activity.
     * @param int $room Room number.
     * @param int $groupid Group.
     * @return \stdClass The presence.
     */
    private function presence(array $activity, int $room, int $groupid = 0): \stdClass {
        /** @var \vrroomruntime_builtin_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('vrroomruntime_builtin');
        $user = $this->getDataGenerator()->create_user();
        return $generator->create_peer([
            'vrroomid' => (int)$activity['vrroom']->id,
            'userid' => (int)$user->id,
            'groupid' => $groupid,
            'room' => $room,
            'timeseen' => $this->now,
        ]);
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
     * @param int $livemode 1 fixed rooms, 2 rooms students open.
     * @param int $liverooms Number of rooms (fixed) or most rooms open (0 = no limit).
     * @return array ['course', 'cm', 'context', 'vrroom']
     */
    private function create_activity(
        int $groupmode = NOGROUPS,
        int $maxusers = 6,
        ?\stdClass $course = null,
        int $livemode = 1,
        int $liverooms = 1
    ): array {
        global $DB;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $DB->update_record('vrroom', (object)['id' => $record->id, 'livemode' => $livemode, 'livemaxusers' => $maxusers,
            'liverooms' => $liverooms]);
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
