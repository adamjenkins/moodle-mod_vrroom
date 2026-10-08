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

namespace mod_mavr\local\graph;

/**
 * Tests for the server-side graph evaluator, driven by the golden fixtures.
 *
 * The same fixtures are run against the client evaluator by tests/js/graph_fixtures.test.mjs.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(evaluator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(evaluation_result::class)]
final class evaluator_test extends \advanced_testcase {
    /** @var float Tolerance for number comparisons (contract §4.4.1). */
    private const DELTA = 1e-6;

    /**
     * Every golden fixture file.
     *
     * @return array fixture name => [absolute path]
     */
    public static function fixture_provider(): array {
        $cases = [];
        $files = glob(__DIR__ . '/../../fixtures/graph/*.json');
        sort($files);
        foreach ($files as $file) {
            $cases[basename($file, '.json')] = [$file];
        }
        return $cases;
    }

    /**
     * There are exactly the 19 fixtures the contracts name (guards against a vacuous provider).
     */
    public function test_fixture_set_is_complete(): void {
        $this->resetAfterTest();
        $expected = [
            'answer_while_locked', 'collect_keys_lock', 'collect_n_of_set', 'empty_graph', 'escape_chain',
            'finish_on_all_required', 'gallery_ordered', 'hint_locked_completed', 'hint_penalty',
            'interacted_zone_counts', 'locked_until_after', 'pass_fraction_retry', 'rules_show_hide', 'score_at_least',
            'sequence_strict', 'single_visit', 'unlock_action_cascade', 'visit_then_question',
            'zero_points_required_ratio',
        ];
        $this->assertSame($expected, array_keys(self::fixture_provider()));
    }

    /**
     * Each fixture's graph is valid and evaluates to its expected result.
     *
     * @param string $file absolute path of the fixture
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixture_provider')]
    public function test_fixture(string $file): void {
        $this->resetAfterTest();
        $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(basename($file, '.json'), $fixture['name']);

        $this->assertSame([], graph_validator::validate($fixture['graph']), 'fixture graph must be valid');

        $result = (new evaluator($fixture['graph']))->evaluate($fixture['events'])->to_array();
        $this->assertCount(count($fixture['events']), $result['eventstatus']);
        $this->assert_matches($fixture['expect'], $result, 'expect', true);

        // The result must survive a JSON round trip unchanged in shape (it is sent to the client).
        $roundtrip = json_decode(json_encode($result), true);
        $this->assert_matches($fixture['expect'], $roundtrip, 'roundtrip', true);
    }

    /**
     * Classify agrees with the status evaluate() records for the same event.
     *
     * @param string $file absolute path of the fixture
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixture_provider')]
    public function test_classify_agrees_with_evaluate(string $file): void {
        $this->resetAfterTest();
        $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $evaluator = new evaluator($fixture['graph']);
        $statuses = $evaluator->evaluate($fixture['events'])->eventstatus;
        foreach ($fixture['events'] as $i => $event) {
            $before = array_slice($fixture['events'], 0, $i);
            $this->assertSame($statuses[$i], $evaluator->classify($before, $event), "event {$i}");
        }
    }

    /**
     * Classify returns applied, ignored and locked as §4.4.1 describes.
     */
    public function test_classify(): void {
        $this->resetAfterTest();
        $graph = [
            'schema' => 'mavr-graph/1',
            'objectives' => [
                ['id' => 'v1', 'type' => 'visit_zone', 'title' => 'Visit', 'zone' => 'z1', 'required' => true],
                ['id' => 'q1', 'type' => 'answer_question', 'title' => 'Answer', 'slot' => 1, 'points' => 2,
                    'unlock' => ['after' => ['v1']]],
            ],
            'rules' => [],
        ];
        $evaluator = new evaluator($graph);
        $visit = ['verb' => 'entered', 'type' => 'zone', 'id' => 'z1', 'scene' => null, 'fraction' => null];
        $answer = ['verb' => 'answered', 'type' => 'question', 'id' => '1', 'scene' => null, 'fraction' => 1.0];
        $other = ['verb' => 'entered', 'type' => 'zone', 'id' => 'z9', 'scene' => null, 'fraction' => null];

        $this->assertSame(evaluator::STATUS_LOCKED, $evaluator->classify([], $answer));
        $this->assertSame(evaluator::STATUS_APPLIED, $evaluator->classify([], $visit));
        $this->assertSame(evaluator::STATUS_IGNORED, $evaluator->classify([], $other));
        $this->assertSame(evaluator::STATUS_APPLIED, $evaluator->classify([$visit], $answer));
        // A completed objective still classifies as applied (a repeat is harmless, not rejected).
        $this->assertSame(evaluator::STATUS_APPLIED, $evaluator->classify([$visit, $answer], $answer));
        // Integer ids are compared as strings.
        $intanswer = ['verb' => 'answered', 'type' => 'question', 'id' => 1, 'scene' => null, 'fraction' => 1];
        $this->assertSame(evaluator::STATUS_APPLIED, $evaluator->classify([$visit], $intanswer));
        // A locked event in the history changes nothing.
        $this->assertSame(evaluator::STATUS_LOCKED, $evaluator->classify([$answer], $answer));
    }

    /**
     * Fractions are clamped to 0..1 and a missing fraction counts as 0.
     */
    public function test_fraction_clamped(): void {
        $this->resetAfterTest();
        $graph = [
            'schema' => 'mavr-graph/1',
            'objectives' => [
                ['id' => 'q1', 'type' => 'answer_question', 'title' => 'One', 'slot' => 1, 'points' => 2],
                ['id' => 'q2', 'type' => 'answer_question', 'title' => 'Two', 'slot' => 2, 'points' => 2],
                ['id' => 'q3', 'type' => 'answer_question', 'title' => 'Three', 'slot' => 3, 'points' => 2],
            ],
        ];
        $events = [
            ['verb' => 'answered', 'type' => 'question', 'id' => '1', 'scene' => null, 'fraction' => 1.5],
            ['verb' => 'answered', 'type' => 'question', 'id' => '2', 'scene' => null, 'fraction' => -1],
            ['verb' => 'answered', 'type' => 'question', 'id' => '3', 'scene' => null, 'fraction' => null],
        ];
        $result = (new evaluator($graph))->evaluate($events);
        $this->assertEqualsWithDelta(2.0, $result->objectives['q1']['earned'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $result->objectives['q2']['earned'], self::DELTA);
        $this->assertSame(evaluator::OBJECTIVE_COMPLETED, $result->objectives['q3']['status']);
        $this->assertEqualsWithDelta(0.0, $result->objectives['q3']['earned'], self::DELTA);
        $this->assertEqualsWithDelta(2.0, $result->earnedpoints, self::DELTA);
        $this->assertEqualsWithDelta(6.0, $result->maxpoints, self::DELTA);
        $this->assertSame(['q1', 'q2', 'q3'], $result->completed_ids());
    }

    /**
     * Every objective entry reports hintsused, right after required; a hint event matches only
     * the objective owning the hint and never a sequence.
     */
    public function test_hintsused_and_matching(): void {
        $this->resetAfterTest();
        $graph = [
            'schema' => 'mavr-graph/1',
            'objectives' => [
                ['id' => 'x-1', 'type' => 'interact', 'title' => 'One', 'target' => 'g-1', 'points' => 1,
                    'hints' => [['id' => 'hn-1', 'text' => 'A', 'penalty' => 0.25]]],
                ['id' => 'x-2', 'type' => 'interact', 'title' => 'Two', 'target' => 'g-2', 'points' => 1],
            ],
        ];
        $evaluator = new evaluator($graph);
        $hint = ['verb' => 'requested', 'type' => 'hint', 'id' => 'hn-1', 'scene' => null, 'fraction' => null];
        $other = ['verb' => 'requested', 'type' => 'hint', 'id' => 'hn-9', 'scene' => null, 'fraction' => null];
        $this->assertSame(evaluator::STATUS_APPLIED, $evaluator->classify([], $hint));
        $this->assertSame(evaluator::STATUS_IGNORED, $evaluator->classify([], $other));

        $use = ['verb' => 'interacted', 'type' => 'object', 'id' => 'g-1', 'scene' => null, 'fraction' => null];
        $result = $evaluator->evaluate([$hint, $use]);
        $this->assertSame(['status', 'earned', 'points', 'required', 'hintsused'], array_keys($result->objectives['x-1']));
        $this->assertSame(1, $result->objectives['x-1']['hintsused']);
        $this->assertSame(0, $result->objectives['x-2']['hintsused']);
        $this->assertEqualsWithDelta(0.75, $result->objectives['x-1']['earned'], self::DELTA);
    }

    /**
     * A rule loop is cut off after TRIGGER_CAP triggers for one event.
     */
    public function test_trigger_cap(): void {
        $this->resetAfterTest();
        // Each rule unlocks the next objective; 150 objectives in a chain of unlock actions.
        $objectives = [['id' => 'start', 'type' => 'visit_zone', 'title' => 'Start', 'zone' => 'z0']];
        $rules = [['id' => 'r-start', 'on' => ['on' => 'objective_completed', 'objective' => 'start'],
            'actions' => [['do' => 'unlock', 'objective' => 'o1']]]];
        for ($n = 1; $n <= 150; $n++) {
            $objectives[] = ['id' => "o{$n}", 'type' => 'interact', 'title' => "O{$n}", 'target' => "t{$n}",
                'unlock' => ['after' => ['start'], 'score_at_least' => 1000]];
            $action = $n < 150 ? ['do' => 'unlock', 'objective' => 'o' . ($n + 1)] : ['do' => 'finish'];
            $rules[] = ['id' => "r{$n}", 'on' => ['on' => 'objective_unlocked', 'objective' => "o{$n}"],
                'actions' => [$action]];
        }
        $graph = ['schema' => 'mavr-graph/1', 'objectives' => $objectives, 'rules' => $rules];
        $this->assertSame([], graph_validator::validate($graph));

        $events = [['verb' => 'entered', 'type' => 'zone', 'id' => 'z0', 'scene' => null, 'fraction' => null]];
        $result = (new evaluator($graph))->evaluate($events);
        // Triggers: event(1), objective_completed start(2) -> unlock o1, objective_unlocked o1(3) -> o2, ...
        // the 100th trigger is objective_unlocked o98, which unlocks o99; its trigger is dropped.
        $this->assertSame(evaluator::OBJECTIVE_AVAILABLE, $result->objectives['o99']['status']);
        $this->assertSame(evaluator::OBJECTIVE_LOCKED, $result->objectives['o100']['status']);
        $this->assertSame(evaluator::OBJECTIVE_LOCKED, $result->objectives['o150']['status']);
    }

    /**
     * event_from_record converts mavr_events rows.
     */
    public function test_event_from_record(): void {
        $this->resetAfterTest();
        $answered = (object) [
            'id' => 5, 'attemptid' => 1, 'verb' => 'answered', 'objecttype' => 'question', 'sceneid' => null,
            'objectid' => '2', 'resultjson' => '{"fraction": 0.5, "state": "gradedpartial", "mark": 0.5, "maxmark": 1}',
        ];
        $this->assertSame(
            ['verb' => 'answered', 'type' => 'question', 'id' => '2', 'scene' => null, 'fraction' => 0.5],
            evaluator::event_from_record($answered)
        );

        $entered = (object) [
            'verb' => 'entered', 'objecttype' => 'zone', 'sceneid' => 'scene-1', 'objectid' => 'z-1',
            'resultjson' => null,
        ];
        $this->assertSame(
            ['verb' => 'entered', 'type' => 'zone', 'id' => 'z-1', 'scene' => 'scene-1', 'fraction' => null],
            evaluator::event_from_record($entered)
        );

        // Activity rows have no object id; a result on a non-answered row is not a fraction.
        $completed = (object) [
            'verb' => 'completed', 'objecttype' => 'activity', 'sceneid' => '', 'objectid' => null,
            'resultjson' => '{"score": 0.75, "grade": 75.0}',
        ];
        $this->assertSame(
            ['verb' => 'completed', 'type' => 'activity', 'id' => '', 'scene' => null, 'fraction' => null],
            evaluator::event_from_record($completed)
        );

        // An answered row with unusable result JSON has no fraction.
        $broken = (object) [
            'verb' => 'answered', 'objecttype' => 'question', 'sceneid' => null, 'objectid' => '1',
            'resultjson' => 'not json',
        ];
        $this->assertNull(evaluator::event_from_record($broken)['fraction']);

        // Integer fractions become floats.
        $whole = (object) [
            'verb' => 'answered', 'objecttype' => 'question', 'sceneid' => null, 'objectid' => '1',
            'resultjson' => '{"fraction": 1}',
        ];
        $this->assertSame(1.0, evaluator::event_from_record($whole)['fraction']);
    }

    /**
     * Compare an expected value with an actual one: numbers within DELTA, other scalars exactly.
     *
     * Lists compare element by element. Objects (associative arrays) must have exactly the expected
     * keys in the same order, except where $subset is true (the whole expect block, and each
     * objective entry, which lists only status and earned): there every expected key must exist.
     * So the objectives map must list every objective, in graph order.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $path location for failure messages
     * @param bool $subset whether the actual object may have keys the expected one does not
     */
    private function assert_matches(mixed $expected, mixed $actual, string $path, bool $subset = false): void {
        if (is_int($expected) || is_float($expected)) {
            $this->assertTrue(is_int($actual) || is_float($actual), "{$path}: number expected");
            $this->assertEqualsWithDelta($expected, $actual, self::DELTA, $path);
            return;
        }
        if (!is_array($expected)) {
            $this->assertSame($expected, $actual, $path);
            return;
        }
        $this->assertIsArray($actual, $path);
        if ($expected === []) {
            $this->assertSame([], $actual, $path);
            return;
        }
        if (array_is_list($expected)) {
            $this->assertTrue(array_is_list($actual), "{$path}: list expected");
            $this->assertCount(count($expected), $actual, $path);
            foreach ($expected as $i => $value) {
                $this->assert_matches($value, $actual[$i], "{$path}[{$i}]");
            }
            return;
        }
        $expectedkeys = array_map('strval', array_keys($expected));
        $actualkeys = array_map('strval', array_keys($actual));
        if ($subset) {
            foreach ($expectedkeys as $key) {
                $this->assertContains($key, $actualkeys, "{$path}.{$key}: missing");
            }
        } else {
            $this->assertSame($expectedkeys, $actualkeys, "{$path}: keys");
        }
        $childsubset = str_ends_with($path, '.objectives');
        foreach ($expected as $key => $value) {
            $this->assert_matches($value, $actual[$key], "{$path}.{$key}", $childsubset);
        }
    }
}
