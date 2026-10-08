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

namespace mod_mavr\local\attempt;

use mod_mavr\local\graph\evaluator;
use mod_mavr\local\instance_manager;
use mod_mavr\local\questions\slots;
use mod_mavr\local\template\compiled_activity;

/**
 * Tests of the objective hints.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hint_manager::class)]
final class hint_manager_test extends \advanced_testcase {

    /**
     * A gallery activity whose graph is replaced by one with hints, and a student's attempt.
     *
     * Graph: v-1 visits the station; x-1 (2 points, after v-1) uses the station's first object and
     * has two hints of 0.5 points; v-2 (no hints) is a second visit of the same station.
     *
     * @return \stdClass course, module, mavr, context, student, attempt, zone, target, sceneid
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
        $target = (string)$scene['interactables'][0]['id'];

        $graph = [
            'schema' => 'mavr-graph/1',
            'hidden' => [],
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => 'Visit', 'zone' => $zone, 'points' => 0,
                    'required' => true],
                ['id' => 'x-1', 'type' => 'interact', 'title' => 'Use', 'target' => $target, 'points' => 2,
                    'required' => true, 'unlock' => ['after' => ['v-1']],
                    'hints' => [
                        ['id' => 'hn-1', 'text' => 'First <b>hint</b> &amp; more', 'penalty' => 0.5],
                        ['id' => 'hn-2', 'text' => 'Second hint', 'penalty' => 0.5],
                    ]],
                ['id' => 'v-2', 'type' => 'visit_zone', 'title' => 'Again', 'zone' => $zone, 'points' => 0],
            ],
            'rules' => [],
        ];
        $this->assertSame([], \mod_mavr\local\graph\graph_validator::validate($graph));
        $this->assertSame([], slots::get_slots((int)$mavr->id, $context));
        instance_manager::apply_compiled($mavr, $context, new compiled_activity([$scene], $graph, []));

        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm((int)$module->cmid);
        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, (int)$student->id);

        return (object) [
            'course' => $course,
            'module' => $module,
            'mavr' => $mavr,
            'context' => $context,
            'student' => $student,
            'attempt' => $attempt,
            'zone' => $zone,
            'target' => $target,
            'sceneid' => (string)$scene['id'],
            'graph' => $graph,
        ];
    }

    /**
     * Record a client event as an accepted statement would.
     *
     * @param \stdClass $fx fixture
     * @param string $verb verb
     * @param string $type object type
     * @param string $id object id
     */
    private function record(\stdClass $fx, string $verb, string $type, string $id): void {
        event_store::insert((int)$fx->attempt->id, \core\uuid::generate(),
            ['verb' => $verb, 'type' => $type, 'id' => $id, 'scene' => $fx->sceneid, 'fraction' => null],
            true, null, time());
    }

    /**
     * Assert a moodle_exception error code.
     *
     * @param string $errorcode expected code
     * @param callable $call the call
     */
    private function assert_error(string $errorcode, callable $call): void {
        try {
            $call();
            $this->fail("Expected {$errorcode}");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * strip_texts() removes every hint text and keeps everything else.
     */
    public function test_strip_texts(): void {
        $this->resetAfterTest();
        $graph = [
            'schema' => 'mavr-graph/1',
            'objectives' => [
                ['id' => 'x-1', 'type' => 'interact', 'title' => 'Use', 'target' => 'g-1',
                    'hints' => [['id' => 'hn-1', 'text' => 'Secret', 'penalty' => 0.5], ['id' => 'hn-2', 'text' => 'More']]],
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => 'Visit', 'zone' => 'z-1'],
            ],
            'rules' => [],
        ];
        $stripped = hint_manager::strip_texts($graph);
        $this->assertSame([['id' => 'hn-1', 'penalty' => 0.5], ['id' => 'hn-2']], $stripped['objectives'][0]['hints']);
        $this->assertSame($graph['objectives'][1], $stripped['objectives'][1]);
        $this->assertStringNotContainsString('Secret', json_encode($stripped));
        $this->assertSame([], \mod_mavr\local\graph\graph_validator::validate($graph));
        $this->assertSame(['schema' => 'mavr-graph/1'], hint_manager::strip_texts(['schema' => 'mavr-graph/1']));
    }

    /**
     * plain_text() strips tags and decodes entities.
     */
    public function test_plain_text(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $context = \context_module::instance((int)$module->cmid);
        $this->assertSame('First hint & more', hint_manager::plain_text('First <b>hint</b> &amp; more', $context));
        $this->assertSame('a < b', hint_manager::plain_text('a &lt; b', $context));
    }

    /**
     * Hints are released in order, recorded by the server, and refused when locked, used up,
     * completed or unknown; their penalty is taken off the objective's points.
     */
    public function test_request(): void {
        global $DB;
        $fx = $this->setup_attempt();

        $this->assert_error('errorhintlocked', fn() => hint_manager::request($fx->attempt, 'x-1', $fx->context));
        $this->assert_error('errorhintunknown', fn() => hint_manager::request($fx->attempt, 'v-2', $fx->context));
        $this->assert_error('errorhintunknown', fn() => hint_manager::request($fx->attempt, 'nope', $fx->context));
        $this->assertFalse($DB->record_exists('mavr_events', ['attemptid' => $fx->attempt->id, 'verb' => 'requested']));

        $this->record($fx, 'entered', 'zone', $fx->zone);
        $first = hint_manager::request($fx->attempt, 'x-1', $fx->context);
        $this->assertSame('hn-1', $first['hintid']);
        $this->assertSame('First hint & more', $first['text']);
        $this->assertSame(1, $first['hintsused']);
        $this->assertSame(2, $first['hintstotal']);
        $this->assertSame(1, $first['result']->objectives['x-1']['hintsused']);

        $second = hint_manager::request($fx->attempt, 'x-1', $fx->context);
        $this->assertSame('hn-2', $second['hintid']);
        $this->assertSame('Second hint', $second['text']);
        $this->assertSame(2, $second['hintsused']);

        $this->assert_error('errornomorehints', fn() => hint_manager::request($fx->attempt, 'x-1', $fx->context));

        $rows = array_values($DB->get_records('mavr_events',
            ['attemptid' => $fx->attempt->id, 'verb' => 'requested'], 'id ASC'));
        $this->assertCount(2, $rows);
        $this->assertSame('hint', $rows[0]->objecttype);
        $this->assertSame('hn-1', $rows[0]->objectid);
        $this->assertEquals(0, $rows[0]->clientasserted);
        $this->assertSame(['penalty' => 0.5, 'objective' => 'x-1'], json_decode($rows[0]->resultjson, true));

        // Completing the objective now earns 2 - 2 x 0.5 = 1 of 2 points (score 1 of 2).
        $this->record($fx, 'interacted', 'object', $fx->target);
        $result = attempt_manager::recompute($fx->attempt);
        $this->assertSame(evaluator::OBJECTIVE_COMPLETED, $result->objectives['x-1']['status']);
        $this->assertEqualsWithDelta(1.0, $result->objectives['x-1']['earned'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $result->score, 1e-6);
        $this->assertEqualsWithDelta(0.5, (float)$DB->get_field('mavr_attempts', 'score', ['id' => $fx->attempt->id]), 1e-6);

        $this->assert_error('errorhintcompleted', fn() => hint_manager::request($fx->attempt, 'x-1', $fx->context));
    }

    /**
     * launch_hints() lists the objectives with hints and the texts released so far.
     */
    public function test_launch_hints(): void {
        $fx = $this->setup_attempt();

        $this->assertSame(['x-1' => ['total' => 2, 'used' => []]],
            hint_manager::launch_hints($fx->graph, [], $fx->context));

        $this->record($fx, 'entered', 'zone', $fx->zone);
        hint_manager::request($fx->attempt, 'x-1', $fx->context);
        $events = event_store::get_events((int)$fx->attempt->id);
        $this->assertSame(['x-1' => ['total' => 2, 'used' => [['id' => 'hn-1', 'text' => 'First hint & more']]]],
            hint_manager::launch_hints($fx->graph, $events, $fx->context));

        // A graph without hints has none to release.
        $this->assertSame([], hint_manager::launch_hints(['schema' => 'mavr-graph/1', 'objectives' => []], [], $fx->context));
    }
}
