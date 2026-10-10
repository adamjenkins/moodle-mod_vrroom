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
use vrroomruntime_builtin\local\live\room_manager;

/**
 * Tests of the live room web services.
 *
 * @package    vrroomruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(live_join::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_poll::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_send::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_leave::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_rooms::class)]
final class live_services_test extends \advanced_testcase {
    /** @var string TURN secret of the tests. */
    private const SECRET = 'turn-secret-never-sent';

    /** @var string A valid candidates payload. */
    private const CANDIDATES = '{"gen":0,"candidates":[{"candidate":"candidate:1 1 udp 2122260223 192.0.2.1 54321 typ host",'
        . '"sdpMid":"0","sdpMLineIndex":0,"usernameFragment":"abcd"}]}';

    /**
     * Join, poll, send and leave between two learners, with the return shapes.
     */
    public function test_round_trip(): void {
        global $DB;
        $this->resetAfterTest();
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::install(false);
        $this->configure_ice();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);

        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->assertTrue(room_manager::is_peerid($joina['peerid']));
        $this->assertSame(0, $joina['groupid']);
        $this->assertSame(1, $joina['room']);
        $this->assertSame(get_string('liveroomname', 'vrroomruntime_builtin', 1), $joina['roomname']);
        $this->assertSame(6, $joina['maxpeers']);
        $this->assertSame(room_manager::INTERVAL_ACTIVE, $joina['interval']);
        $this->assertSame([], $joina['peers']);
        $this->assertCount(2, $joina['iceservers']);
        $this->assertSame(['stun:stun.example.org:3478'], $joina['iceservers'][0]['urls']);
        $this->assertSame('', $joina['iceservers'][0]['username']);
        $this->assertStringEndsWith(':' . $joina['peerid'], $joina['iceservers'][1]['username']);
        $this->assertGreaterThan(time(), $joina['iceexpires']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($joina));

        $this->setUser($b);
        $joinb = $this->join($cmid, (int)$attemptb->id);
        $this->assertSame(
            [[
                'peerid' => $joina['peerid'],
                'name' => fullname($a),
                'kind' => 'attempt',
                'appearance' => '',
                'picture' => '',
                'assets' => '',
            ]],
            $joinb['peers']
        );

        $sent = external_api::clean_returnvalue(live_send::execute_returns(), live_send::execute($joinb['peerid'], [
            ['to' => $joina['peerid'], 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]));
        $this->assertSame('ok', $sent['status']);
        $this->assertSame([['to' => $joina['peerid'], 'accepted' => true, 'error' => '']], $sent['results']);

        $this->setUser($a);
        $poll = external_api::clean_returnvalue(live_poll::execute_returns(), live_poll::execute($joina['peerid'], true));
        $this->assertSame('ok', $poll['status']);
        $this->assertSame([$joinb['peerid']], array_column($poll['peers'], 'peerid'));
        $this->assertCount(1, $poll['signals']);
        $this->assertSame($joinb['peerid'], $poll['signals'][0]['from']);
        $this->assertCount(2, $poll['iceservers']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($poll));

        $poll = external_api::clean_returnvalue(live_poll::execute_returns(), live_poll::execute($joina['peerid'], false));
        $this->assertSame([], $poll['iceservers']);
        $this->assertSame(0, $poll['iceexpires']);

        $left = external_api::clean_returnvalue(live_leave::execute_returns(), live_leave::execute($joina['peerid']));
        $this->assertSame(['status' => 'ok'], $left);
        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['peerid' => $joina['peerid']]));

        // Leaving twice is fine; polling afterwards says gone.
        $this->assertSame(['status' => 'ok'], live_leave::execute($joina['peerid']));
        $this->assertSame('gone', live_poll::execute($joina['peerid'])['status']);
    }

    /**
     * Someone not enrolled in the course cannot join.
     */
    public function test_not_enrolled(): void {
        $this->resetAfterTest();
        [$cmid] = $this->create_activity();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        live_join::execute($cmid, 0, 0);
    }

    /**
     * A learner without an attempt cannot join (as a preview or with someone else's attempt).
     */
    public function test_student_without_attempt(): void {
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        [, $otherattempt] = $this->learner($course, $vrroom);
        $this->setUser($student);

        try {
            live_join::execute($cmid, 0, 0);
            $this->fail('A learner joined without an attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivenotallowed', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornotyourattempt', 'mod_vrroom'));
        live_join::execute($cmid, (int)$otherattempt->id, 0);
    }

    /**
     * Live mode off for the activity or the site refuses to join.
     */
    public function test_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $vrroom);
        $this->setUser($a);

        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        try {
            live_join::execute($cmid, (int)$attempta->id, 0);
            $this->fail('Joined with live mode off for the site.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivedisabled', $e->errorcode);
        }
        set_config('liveenabled', 1, 'vrroomruntime_builtin');
        $DB->set_field('vrroom', 'livemode', 0, ['id' => $vrroom->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivedisabled', 'vrroomruntime_builtin'));
        live_join::execute($cmid, (int)$attempta->id, 0);
    }

    /**
     * Another user's peer id gets 'gone' from poll and send and changes nothing.
     */
    public function test_foreign_peerid(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);

        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->setUser($b);
        $joinb = $this->join($cmid, (int)$attemptb->id);
        $before = $DB->get_record('vrroomruntime_builtin_peers', ['peerid' => $joina['peerid']]);

        $poll = external_api::clean_returnvalue(live_poll::execute_returns(), live_poll::execute($joina['peerid'], true));
        $this->assertSame(['status' => 'gone', 'interval' => room_manager::INTERVAL_IDLE, 'peers' => [], 'signals' => [],
            'iceservers' => [], 'iceexpires' => 0], $poll);
        $send = external_api::clean_returnvalue(live_send::execute_returns(), live_send::execute($joina['peerid'], [
            ['to' => $joinb['peerid'], 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]));
        $this->assertSame(['status' => 'gone', 'results' => []], $send);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));

        live_leave::execute($joina['peerid']);
        $this->assertEquals($before, $DB->get_record('vrroomruntime_builtin_peers', ['peerid' => $joina['peerid']]));
    }

    /**
     * More than ten signals in one call are refused before anything else.
     */
    public function test_too_many_signals(): void {
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $vrroom);
        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);

        $signals = array_fill(
            0,
            room_manager::MAX_SIGNALS_PER_CALL + 1,
            ['to' => room_manager::new_peerid(), 'type' => 'candidates', 'payload' => self::CANDIDATES]
        );
        $this->expectException(\invalid_parameter_exception::class);
        live_send::execute($joina['peerid'], $signals);
    }

    /**
     * Join and poll return each person's appearance, picture and assets from the avatar_appearance listeners.
     */
    public function test_appearance_fields(): void {
        $this->resetAfterTest();
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::install();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);
        $doc = ['schema' => 'vrroom-avatar/1', 'body' => 'slim', 'head' => 'square', 'color' => 'green', 'picture' => true];
        $picture = 'https://example.org/pluginfile.php/1/user/icon/boost/f1';
        $assets = [
            'outfit' => 'https://example.org/local/vravatar/costumes/pirate-captain.glb',
            'hat' => 'https://example.org/local/vravatar/costumes/hat-pirate.glb',
        ];
        $callback = function (\mod_vrroom\hook\avatar_appearance $hook) use ($a, $doc, $picture, $assets): void {
            foreach ($hook->get_peers() as $peer) {
                if ($peer['userid'] === (int)$a->id) {
                    $hook->set_appearance($peer['peerid'], $doc, $picture, $assets + ['skin' => 'https://example.org/x']);
                }
            }
        };
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::$callback = $callback;

        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->setUser($b);
        $joinb = $this->join($cmid, (int)$attemptb->id);
        $this->assertCount(1, $joinb['peers']);
        $this->assertSame($doc, json_decode($joinb['peers'][0]['appearance'], true));
        $this->assertSame($picture, $joinb['peers'][0]['picture']);
        $this->assertSame($assets, json_decode($joinb['peers'][0]['assets'], true));

        $this->setUser($a);
        $poll = external_api::clean_returnvalue(live_poll::execute_returns(), live_poll::execute($joina['peerid'], false));
        $this->assertSame([$joinb['peerid']], array_column($poll['peers'], 'peerid'));
        $this->assertSame('', $poll['peers'][0]['appearance']);
        $this->assertSame('', $poll['peers'][0]['picture']);
        $this->assertSame('', $poll['peers'][0]['assets']);
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::reset();
    }

    /**
     * A teacher may join a preview room; a full room is refused.
     */
    public function test_preview_and_full_room(): void {
        global $DB;
        $this->resetAfterTest();
        \mod_vrroom\tests\hook\avatar_appearance_callbacks::install(false);
        [$cmid, $course, $vrroom] = $this->create_activity();
        $DB->set_field('vrroom', 'livemaxusers', 2, ['id' => $vrroom->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);

        $this->setUser($teacher);
        $jointeacher = $this->join($cmid, 0);
        $this->assertSame(2, $jointeacher['maxpeers']);
        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->assertSame(
            [[
                'peerid' => $jointeacher['peerid'],
                'name' => fullname($teacher),
                'kind' => 'preview',
                'appearance' => '',
                'picture' => '',
                'assets' => '',
            ]],
            $joina['peers']
        );

        $this->setUser($b);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorliveroomfull', 'vrroomruntime_builtin', 2));
        live_join::execute($cmid, (int)$attemptb->id, 0, 1);
    }

    /**
     * Fixed rooms: the list shows 1..N with counts and whether each can be joined.
     */
    public function test_rooms_fixed(): void {
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity(1, 3, 2);
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);
        [$c] = $this->learner($course, $vrroom);

        $this->setUser($a);
        $this->join($cmid, (int)$attempta->id, 1);
        $this->setUser($b);
        $joinb = $this->join($cmid, (int)$attemptb->id, 1);
        $this->assertSame(1, $joinb['room']);

        $this->setUser($c);
        $list = $this->rooms($cmid);
        $this->assertSame(0, $list['groupid']);
        $this->assertSame('fixed', $list['roommode']);
        $this->assertSame(3, $list['maxrooms']);
        $this->assertSame(2, $list['capacity']);
        $this->assertFalse($list['canstart']);
        $this->assertSame('fixed', $list['startreason']);
        $this->assertSame(room_manager::ROOMS_INTERVAL, $list['interval']);
        $this->assertSame([1, 2, 3], array_column($list['rooms'], 'number'));
        $this->assertSame([2, 0, 0], array_column($list['rooms'], 'count'));
        $this->assertSame([false, true, true], array_column($list['rooms'], 'joinable'));
        $this->assertSame(get_string('liveroomname', 'vrroomruntime_builtin', 2), $list['rooms'][1]['name']);
        // Counts only: no names or peer ids of the people in the rooms.
        $this->assertStringNotContainsString(fullname($a), json_encode($list));
        $this->assertStringNotContainsString($joinb['peerid'], json_encode($list));
    }

    /**
     * Rooms students open: only open rooms are listed; starting is allowed up to the maximum.
     */
    public function test_rooms_dynamic(): void {
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity(2, 2);
        [$a, $attempta] = $this->learner($course, $vrroom);
        [$b, $attemptb] = $this->learner($course, $vrroom);
        [$c, $attemptc] = $this->learner($course, $vrroom);

        $this->setUser($a);
        $list = $this->rooms($cmid);
        $this->assertSame('dynamic', $list['roommode']);
        $this->assertSame([], $list['rooms']);
        $this->assertTrue($list['canstart']);
        $this->assertSame('', $list['startreason']);

        $joina = $this->join($cmid, (int)$attempta->id, 0);
        $this->assertSame(1, $joina['room']);
        $this->assertSame(get_string('liveroomname', 'vrroomruntime_builtin', 1), $joina['roomname']);
        $this->setUser($b);
        $this->assertSame(2, $this->join($cmid, (int)$attemptb->id, 0)['room']);

        $this->setUser($c);
        $list = $this->rooms($cmid);
        $this->assertSame([1, 2], array_column($list['rooms'], 'number'));
        $this->assertSame([1, 1], array_column($list['rooms'], 'count'));
        $this->assertFalse($list['canstart']);
        $this->assertSame('limit', $list['startreason']);
        try {
            live_join::execute($cmid, (int)$attemptc->id, 0, 0);
            $this->fail('A third room was opened with a maximum of two.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorliveroomlimit', $e->errorcode);
        }
        try {
            live_join::execute($cmid, (int)$attemptc->id, 0, 7);
            $this->fail('A room that is not open was joined.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorliveroomgone', $e->errorcode);
        }
        $this->assertSame(2, $this->join($cmid, (int)$attemptc->id, 2)['room']);
    }

    /**
     * The room list refuses a group the user is not in, users without attempt or manage, and live mode off.
     */
    public function test_rooms_refusals(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $vrroom] = $this->create_activity(1, 2, 6, SEPARATEGROUPS);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        [$a, $attempta] = $this->learner($course, $vrroom);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $a->id]);

        $this->setUser($a);
        $list = $this->rooms($cmid);
        $this->assertSame((int)$groupa->id, $list['groupid']);
        $joina = $this->join($cmid, (int)$attempta->id, 2, (int)$groupa->id);
        $this->assertSame(
            get_string('liveroomnamegroup', 'vrroomruntime_builtin', ['group' => 'Group A', 'number' => 2]),
            $joina['roomname']
        );
        try {
            live_rooms::execute($cmid, (int)$groupb->id);
            $this->fail('A learner listed the rooms of a group they are not in.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivenoroom', $e->errorcode);
        }

        // Without mod/vrroom:attempt or mod/vrroom:manage.
        $role = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/vrroom:attempt', CAP_PROHIBIT, $role, \context_module::instance($cmid)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        try {
            live_rooms::execute($cmid, 0);
            $this->fail('The room list was given to someone who can neither attempt nor manage.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivenotallowed', $e->errorcode);
        }

        $DB->set_field('vrroom', 'livemode', 0, ['id' => $vrroom->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivedisabled', 'vrroomruntime_builtin'));
        live_rooms::execute($cmid, 0);
    }

    /**
     * Join through the service and clean the result.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id.
     * @param int $room Room number, 0 to start a new room.
     * @param int $groupid Group id, 0 for the default.
     * @return array
     */
    private function join(int $cmid, int $attemptid, int $room = 1, int $groupid = 0): array {
        return external_api::clean_returnvalue(
            live_join::execute_returns(),
            live_join::execute($cmid, $attemptid, $groupid, $room)
        );
    }

    /**
     * List the rooms through the service and clean the result.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id, 0 for the default.
     * @return array
     */
    private function rooms(int $cmid, int $groupid = 0): array {
        return external_api::clean_returnvalue(live_rooms::execute_returns(), live_rooms::execute($cmid, $groupid));
    }

    /**
     * Set a STUN server and a TURN server with a secret.
     */
    private function configure_ice(): void {
        set_config('livestunurls', 'stun:stun.example.org:3478', 'vrroomruntime_builtin');
        set_config('liveturnurls', 'turn:turn.example.org:3478?transport=udp', 'vrroomruntime_builtin');
        set_config('liveturnsecret', self::SECRET, 'vrroomruntime_builtin');
    }

    /**
     * A learner with an attempt in progress.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $vrroom vrroom row.
     * @return array [user, attempt]
     */
    private function learner(\stdClass $course, \stdClass $vrroom): array {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        return [$user, $generator->create_attempt((int)$vrroom->id, (int)$user->id)];
    }

    /**
     * Create an activity with live mode on.
     *
     * @param int $livemode 1 fixed rooms, 2 rooms students open.
     * @param int $liverooms Number of rooms (fixed) or most rooms open (0 = no limit).
     * @param int $maxusers Room size.
     * @param int $groupmode Group mode.
     * @return array [cmid, course, vrroom row]
     */
    private function create_activity(
        int $livemode = 1,
        int $liverooms = 1,
        int $maxusers = 6,
        int $groupmode = NOGROUPS
    ): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $DB->update_record('vrroom', (object)['id' => $record->id, 'livemode' => $livemode, 'livemaxusers' => $maxusers,
            'liverooms' => $liverooms]);
        if ($groupmode !== NOGROUPS) {
            $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $record->cmid]);
            rebuild_course_cache((int)$course->id, true);
        }
        return [(int)$record->cmid, $course, $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST)];
    }
}
