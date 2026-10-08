<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_mavr\external;

use core_external\external_api;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\instance_manager;
use mod_mavr\local\template\compiled_activity;

/**
 * Tests of mod_mavr_request_hint.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request_hint::class)]
final class request_hint_test extends \advanced_testcase {

    /**
     * A gallery activity with a graph that has hints, a student and their started attempt.
     *
     * @return \stdClass course, module, context, student, attemptid, zone, sceneid
     */
    private function setup_attempt(): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'template' => 'gallery',
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => 0, 'required' => 1, 'points' => 1,
                    'mediatype' => 'none'],
            ],
        ]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance((int)$module->cmid);
        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $zone = (string)$scene['zones'][0]['id'];
        $graph = [
            'schema' => 'mavr-graph/1',
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => 'Visit', 'zone' => $zone, 'points' => 1,
                    'required' => true, 'hints' => [
                        ['id' => 'hn-1', 'text' => 'Walk to the <i>vase</i>.', 'penalty' => 0.25],
                        ['id' => 'hn-2', 'text' => 'It is on the left.', 'penalty' => 0.25],
                    ]],
            ],
            'rules' => [],
        ];
        instance_manager::apply_compiled($mavr, $context, new compiled_activity([$scene], $graph, []));

        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $start = start_attempt::execute((int)$module->cmid);
        return (object) [
            'course' => $course,
            'module' => $module,
            'context' => $context,
            'student' => $student,
            'attemptid' => (int)$start['attemptid'],
            'zone' => $zone,
            'sceneid' => (string)$scene['id'],
        ];
    }

    /**
     * The response carries the plain-text hint, the counts and the attempt state.
     */
    public function test_response_shape(): void {
        global $DB;
        $fx = $this->setup_attempt();

        $result = external_api::clean_returnvalue(request_hint::execute_returns(),
            request_hint::execute($fx->attemptid, 'v-1'));
        $this->assertSame('hn-1', $result['hintid']);
        $this->assertSame('Walk to the vase.', $result['text']);
        $this->assertSame(1, $result['hintsused']);
        $this->assertSame(2, $result['hintstotal']);
        $this->assertSame($fx->attemptid, $result['state']['attemptid']);
        $this->assertSame('v-1', $result['state']['objectives'][0]['id']);
        $this->assertSame(1, $result['state']['objectives'][0]['hintsused']);
        $this->assertArrayHasKey('timedue', $result['state']);
        $this->assertTrue($DB->record_exists('mavr_events',
            ['attemptid' => $fx->attemptid, 'verb' => 'requested', 'objecttype' => 'hint', 'objectid' => 'hn-1']));

        // Visiting now earns 1 - 0.25 points.
        event_store::insert($fx->attemptid, \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $fx->zone, 'scene' => $fx->sceneid, 'fraction' => null],
            true, null, time());
        $state = attempt_manager::get_state(attempt_manager::get_attempt($fx->attemptid));
        $this->assertEqualsWithDelta(0.75, $state['earnedpoints'], 1e-6);
    }

    /**
     * Another user's attempt is refused.
     */
    public function test_other_users_attempt(): void {
        $fx = $this->setup_attempt();
        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        $this->setUser($other);
        try {
            request_hint::execute($fx->attemptid, 'v-1');
            $this->fail('Expected errornotyourattempt');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotyourattempt', $e->errorcode);
        }
    }

    /**
     * A finished attempt is refused.
     */
    public function test_closed_attempt(): void {
        $fx = $this->setup_attempt();
        attempt_manager::finish(attempt_manager::get_attempt($fx->attemptid));
        try {
            request_hint::execute($fx->attemptid, 'v-1');
            $this->fail('Expected errorattemptclosed');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptclosed', $e->errorcode);
        }
    }

    /**
     * Without mod/mavr:attempt the hint is refused and nothing is recorded.
     */
    public function test_capability(): void {
        global $DB;
        $fx = $this->setup_attempt();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/mavr:attempt', CAP_PROHIBIT, $roleid, $fx->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/mavr:attempt', $fx->context, $fx->student));

        try {
            request_hint::execute($fx->attemptid, 'v-1');
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('mavr_events', ['attemptid' => $fx->attemptid, 'verb' => 'requested']));
    }
}
