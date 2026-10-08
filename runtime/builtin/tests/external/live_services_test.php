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
use mavrruntime_builtin\local\live\room_manager;

/**
 * Tests of the live room web services.
 *
 * @package    mavrruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(live_join::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_poll::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_send::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(live_leave::class)]
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
        $this->configure_ice();
        [$cmid, $course, $mavr] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $mavr);
        [$b, $attemptb] = $this->learner($course, $mavr);

        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->assertTrue(room_manager::is_peerid($joina['peerid']));
        $this->assertSame(0, $joina['groupid']);
        $this->assertSame(format_string($mavr->name), $joina['roomname']);
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
        $this->assertSame([['peerid' => $joina['peerid'], 'name' => fullname($a), 'kind' => 'attempt']], $joinb['peers']);

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
        $this->assertFalse($DB->record_exists('mavrruntime_builtin_peers', ['peerid' => $joina['peerid']]));

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
        [$cmid, $course, $mavr] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        [, $otherattempt] = $this->learner($course, $mavr);
        $this->setUser($student);

        try {
            live_join::execute($cmid, 0, 0);
            $this->fail('A learner joined without an attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivenotallowed', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornotyourattempt', 'mod_mavr'));
        live_join::execute($cmid, (int)$otherattempt->id, 0);
    }

    /**
     * Live mode off for the activity or the site refuses to join.
     */
    public function test_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $mavr] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $mavr);
        $this->setUser($a);

        set_config('liveenabled', 0, 'mavrruntime_builtin');
        try {
            live_join::execute($cmid, (int)$attempta->id, 0);
            $this->fail('Joined with live mode off for the site.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivedisabled', $e->errorcode);
        }
        set_config('liveenabled', 1, 'mavrruntime_builtin');
        $DB->set_field('mavr', 'livemode', 0, ['id' => $mavr->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivedisabled', 'mavrruntime_builtin'));
        live_join::execute($cmid, (int)$attempta->id, 0);
    }

    /**
     * Another user's peer id gets 'gone' from poll and send and changes nothing.
     */
    public function test_foreign_peerid(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $mavr] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $mavr);
        [$b, $attemptb] = $this->learner($course, $mavr);

        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->setUser($b);
        $joinb = $this->join($cmid, (int)$attemptb->id);
        $before = $DB->get_record('mavrruntime_builtin_peers', ['peerid' => $joina['peerid']]);

        $poll = external_api::clean_returnvalue(live_poll::execute_returns(), live_poll::execute($joina['peerid'], true));
        $this->assertSame(['status' => 'gone', 'interval' => room_manager::INTERVAL_IDLE, 'peers' => [], 'signals' => [],
            'iceservers' => [], 'iceexpires' => 0], $poll);
        $send = external_api::clean_returnvalue(live_send::execute_returns(), live_send::execute($joina['peerid'], [
            ['to' => $joinb['peerid'], 'type' => 'candidates', 'payload' => self::CANDIDATES],
        ]));
        $this->assertSame(['status' => 'gone', 'results' => []], $send);
        $this->assertSame(0, $DB->count_records('mavrruntime_builtin_signals'));

        live_leave::execute($joina['peerid']);
        $this->assertEquals($before, $DB->get_record('mavrruntime_builtin_peers', ['peerid' => $joina['peerid']]));
    }

    /**
     * More than ten signals in one call are refused before anything else.
     */
    public function test_too_many_signals(): void {
        $this->resetAfterTest();
        [$cmid, $course, $mavr] = $this->create_activity();
        [$a, $attempta] = $this->learner($course, $mavr);
        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);

        $signals = array_fill(0, room_manager::MAX_SIGNALS_PER_CALL + 1,
            ['to' => room_manager::new_peerid(), 'type' => 'candidates', 'payload' => self::CANDIDATES]);
        $this->expectException(\invalid_parameter_exception::class);
        live_send::execute($joina['peerid'], $signals);
    }

    /**
     * A teacher may join a preview room; a full room is refused.
     */
    public function test_preview_and_full_room(): void {
        global $DB;
        $this->resetAfterTest();
        [$cmid, $course, $mavr] = $this->create_activity();
        $DB->set_field('mavr', 'livemaxusers', 2, ['id' => $mavr->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        [$a, $attempta] = $this->learner($course, $mavr);
        [$b, $attemptb] = $this->learner($course, $mavr);

        $this->setUser($teacher);
        $jointeacher = $this->join($cmid, 0);
        $this->assertSame(2, $jointeacher['maxpeers']);
        $this->setUser($a);
        $joina = $this->join($cmid, (int)$attempta->id);
        $this->assertSame([['peerid' => $jointeacher['peerid'], 'name' => fullname($teacher), 'kind' => 'preview']],
            $joina['peers']);

        $this->setUser($b);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorliveroomfull', 'mavrruntime_builtin', 2));
        live_join::execute($cmid, (int)$attemptb->id, 0);
    }

    /**
     * Join through the service and clean the result.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id.
     * @return array
     */
    private function join(int $cmid, int $attemptid): array {
        return external_api::clean_returnvalue(live_join::execute_returns(), live_join::execute($cmid, $attemptid, 0));
    }

    /**
     * Set a STUN server and a TURN server with a secret.
     */
    private function configure_ice(): void {
        set_config('livestunurls', 'stun:stun.example.org:3478', 'mavrruntime_builtin');
        set_config('liveturnurls', 'turn:turn.example.org:3478?transport=udp', 'mavrruntime_builtin');
        set_config('liveturnsecret', self::SECRET, 'mavrruntime_builtin');
    }

    /**
     * A learner with an attempt in progress.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $mavr mavr row.
     * @return array [user, attempt]
     */
    private function learner(\stdClass $course, \stdClass $mavr): array {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_mavr_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_mavr');
        return [$user, $generator->create_attempt((int)$mavr->id, (int)$user->id)];
    }

    /**
     * Create an activity with live mode on.
     *
     * @return array [cmid, course, mavr row]
     */
    private function create_activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $DB->update_record('mavr', (object)['id' => $record->id, 'livemode' => 1, 'livemaxusers' => 6]);
        return [(int)$record->cmid, $course, $DB->get_record('mavr', ['id' => $record->id], '*', MUST_EXIST)];
    }
}
