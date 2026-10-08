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

namespace mod_vrroom\local\graph;

/**
 * Tests for the vrroom-graph/1 validator.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(graph_validator::class)]
final class graph_validator_test extends \advanced_testcase {
    /**
     * The example graph from contract §4.4.
     *
     * @return array
     */
    private static function example(): array {
        $json = <<<'JSON'
        {
          "schema": "vrroom-graph/1",
          "hidden": [],
          "objectives": [
            {"id": "v-1", "type": "visit_zone", "title": "Visit The Vase", "zone": "z-1",
             "scene": "5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11", "points": 0, "required": true},
            {"id": "q-1", "type": "answer_question", "title": "Answer the question at The Vase",
             "slot": 1, "points": 2, "required": true, "unlock": {"after": ["v-1"]}},
            {"id": "v-2", "type": "visit_zone", "title": "Visit Portrait", "zone": "z-2",
             "points": 1, "required": false, "unlock": {"after": ["v-1"]}},
            {"id": "c-1", "type": "collect", "title": "Touch two exhibits",
             "targets": ["m-1", "i-2", "h-1"], "count": 2, "points": 1},
            {"id": "s-1", "type": "sequence", "title": "Visit the stations in order",
             "steps": ["v-1", "v-2"], "points": 0},
            {"id": "i-1", "type": "interact", "title": "Open the notice", "target": "t-3",
             "points": 0, "unlock": {"score_at_least": 2}}
          ],
          "rules": [
            {"id": "r-1", "on": {"on": "objective_completed", "objective": "v-1"},
             "actions": [{"do": "open_question", "slot": 1}]},
            {"id": "r-2", "on": {"on": "all_required_completed"}, "actions": [{"do": "finish"}]},
            {"id": "r-3", "on": {"on": "event", "verb": "entered", "type": "zone", "id": "z-2"},
             "once": false, "actions": [{"do": "show", "target": "t-3"}]}
          ]
        }
        JSON;
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Every validator fixture (tests/fixtures/graphvalidate), shared with the node tests.
     *
     * @return array fixture name => [absolute path]
     */
    public static function validate_fixture_provider(): array {
        $cases = [];
        $files = glob(__DIR__ . '/../../fixtures/graphvalidate/*.json');
        sort($files);
        foreach ($files as $file) {
            $cases[basename($file, '.json')] = [$file];
        }
        return $cases;
    }

    /**
     * There are exactly the 7 validator fixtures the Phase 2 contract names (guards against a vacuous provider).
     */
    public function test_validate_fixture_set_is_complete(): void {
        $this->resetAfterTest();
        $expected = ['digit_objective_id', 'duplicate_hint_id', 'hints_invalid', 'hints_on_sequence',
            'pass_fraction_invalid', 'timelimit_invalid', 'valid_extensions'];
        $this->assertSame($expected, array_keys(self::validate_fixture_provider()));
    }

    /**
     * Each validator fixture produces exactly its expected messages, in order (the node twin checks the same).
     *
     * @param string $file absolute path of the fixture
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validate_fixture_provider')]
    public function test_validate_fixture(string $file): void {
        $this->resetAfterTest();
        $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(basename($file, '.json'), $fixture['name']);
        $this->assertSame($fixture['errors'], graph_validator::validate($fixture['graph']));
    }

    /**
     * Time limits at the edges of the allowed range.
     */
    public function test_timelimit_bounds(): void {
        $this->resetAfterTest();
        $message = 'timelimit must be an integer from 0 to 604800';
        foreach ([0, 1, 604800, 900.0] as $ok) {
            $graph = self::example();
            $graph['timelimit'] = $ok;
            $this->assertSame([], graph_validator::validate($graph), (string)$ok);
        }
        foreach ([-1, 604801, '900', true, null] as $bad) {
            $graph = self::example();
            $graph['timelimit'] = $bad;
            $this->assertSame([$message], graph_validator::validate($graph), var_export($bad, true));
        }
    }

    /**
     * Hint texts are counted in characters, not bytes, and penalties default to none.
     */
    public function test_hint_text_length(): void {
        $this->resetAfterTest();
        $graph = self::example();
        $graph['objectives'][5]['hints'] = [['id' => 'hn-1', 'text' => str_repeat('é', 1000)]];
        $this->assertSame([], graph_validator::validate($graph));
        $graph['objectives'][5]['hints'][0]['text'] = str_repeat('é', 1001);
        $this->assertSame(
            ['objectives[5].hints[0].text must be a non-empty string of at most 1000 characters'],
            graph_validator::validate($graph)
        );
    }

    /**
     * The contract example and a minimal graph are valid.
     */
    public function test_valid_graphs(): void {
        $this->resetAfterTest();
        $this->assertSame([], graph_validator::validate(self::example()));
        $this->assertSame([], graph_validator::validate(['schema' => 'vrroom-graph/1', 'objectives' => []]));
        // Integral floats count as integers (JSON 1.0 and 1 are the same number in JavaScript).
        $graph = self::example();
        $graph['objectives'][1]['slot'] = 1.0;
        $this->assertSame([], graph_validator::validate($graph));
    }

    /**
     * Invalid graphs and the exact error each produces.
     *
     * @return array case => [callable mutator name, expected error]
     */
    public static function invalid_provider(): array {
        return [
            'schema' => ['schema', 'schema must be "vrroom-graph/1"'],
            'objectives missing' => ['objectives_missing', 'objectives must be an array'],
            'rules not a list' => ['rules_not_list', 'rules must be an array'],
            'hidden bad id' => ['hidden_bad_id', 'hidden[0] is not a valid id'],
            'objective bad id' => ['objective_bad_id', 'objectives[0].id is not a valid id'],
            'duplicate objective id' => ['duplicate_objective', 'duplicate objective id "v-1"'],
            'unknown objective type' => ['unknown_type', 'objectives[0].type is unknown'],
            'title missing' => ['title_missing', 'objectives[0].title must be a string'],
            'negative points' => ['negative_points', 'objectives[1].points must be a number >= 0'],
            'points boolean' => ['points_boolean', 'objectives[1].points must be a number >= 0'],
            'required not bool' => ['required_not_bool', 'objectives[0].required must be true or false'],
            'unlock after not list' => ['after_not_list', 'objectives[1].unlock.after must be an array of ids'],
            'score_at_least string' => ['score_string', 'objectives[5].unlock.score_at_least must be a number or null'],
            'unknown after ref' => ['unknown_after', 'objectives[1].unlock.after refers to unknown objective "nope"'],
            'self cycle' => ['self_cycle', 'unlock.after cycle: "v-1" waits for "v-1"'],
            // Depth-first from q-1 (graph order) reaches v-2, whose edge back to q-1 closes the cycle.
            'two-step cycle' => ['two_cycle', 'unlock.after cycle: "v-2" waits for "q-1"'],
            'zone missing' => ['zone_missing', 'objectives[0].zone is not a valid id'],
            'interact target' => ['interact_target', 'objectives[5].target is not a valid id'],
            'slot zero' => ['slot_zero', 'objectives[1].slot must be an integer >= 1'],
            'slot fractional' => ['slot_fractional', 'objectives[1].slot must be an integer >= 1'],
            'duplicate slot' => ['duplicate_slot', 'duplicate question slot 1'],
            'collect empty' => ['collect_empty', 'objectives[3].targets must be a non-empty array of distinct ids'],
            'collect duplicate' => ['collect_duplicate',
                'objectives[3].targets must be a non-empty array of distinct ids'],
            'collect count too high' => ['count_high',
                'objectives[3].count must be an integer from 1 to the number of targets'],
            'sequence self' => ['sequence_self', 'objectives[4].steps cannot contain the sequence itself'],
            'sequence nested' => ['sequence_nested', 'objectives[6].steps cannot contain another sequence "s-1"'],
            'sequence unknown step' => ['sequence_unknown', 'objectives[4].steps refers to unknown objective "x"'],
            'sequence empty' => ['sequence_empty', 'objectives[4].steps must be a non-empty array of distinct ids'],
            'duplicate rule id' => ['duplicate_rule', 'duplicate rule id "r-1"'],
            'unknown trigger' => ['unknown_trigger', 'rules[0].on.on is an unknown trigger'],
            'trigger unknown objective' => ['trigger_unknown_objective',
                'rules[0].on.objective refers to unknown objective "nope"'],
            'trigger unknown verb' => ['trigger_unknown_verb', 'rules[2].on.verb is unknown'],
            'trigger unknown type' => ['trigger_unknown_type', 'rules[2].on.type is unknown'],
            'once not bool' => ['once_not_bool', 'rules[2].once must be true or false'],
            'actions empty' => ['actions_empty', 'rules[1].actions must be a non-empty array'],
            'unknown action' => ['unknown_action', 'rules[1].actions[0].do is an unknown action'],
            'unlock unknown objective' => ['unlock_unknown',
                'rules[1].actions[0].objective refers to unknown objective "nope"'],
            'open_question unknown slot' => ['open_unknown_slot',
                'rules[0].actions[0].slot 7 has no answer_question objective'],
            'show without target' => ['show_no_target', 'rules[2].actions[0].target is not a valid id'],
        ];
    }

    /**
     * Each invalid graph produces its expected error.
     *
     * @param string $mutation name of the change applied to the example graph
     * @param string $error expected error message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(string $mutation, string $error): void {
        $this->resetAfterTest();
        $graph = self::mutate(self::example(), $mutation);
        $errors = graph_validator::validate($graph);
        $this->assertContains($error, $errors, 'errors were: ' . implode(' | ', $errors));
    }

    /**
     * A cycle reports exactly one error per back edge, and nothing else.
     */
    public function test_cycle_reports_once(): void {
        $this->resetAfterTest();
        $graph = [
            'schema' => 'vrroom-graph/1',
            'objectives' => [
                ['id' => 'a', 'type' => 'visit_zone', 'title' => 'A', 'zone' => 'z-a', 'unlock' => ['after' => ['c']]],
                ['id' => 'b', 'type' => 'visit_zone', 'title' => 'B', 'zone' => 'z-b', 'unlock' => ['after' => ['a']]],
                ['id' => 'c', 'type' => 'visit_zone', 'title' => 'C', 'zone' => 'z-c', 'unlock' => ['after' => ['b']]],
            ],
            'rules' => [],
        ];
        $this->assertSame(['unlock.after cycle: "b" waits for "a"'], graph_validator::validate($graph));
    }

    /**
     * Apply a named change to the example graph.
     *
     * @param array $graph example graph
     * @param string $mutation change name from invalid_provider()
     * @return array
     */
    private static function mutate(array $graph, string $mutation): array {
        $o = &$graph['objectives'];
        $r = &$graph['rules'];
        switch ($mutation) {
            case 'schema':
                $graph['schema'] = 'vrroom-graph/2';
                break;
            case 'objectives_missing':
                unset($graph['objectives']);
                break;
            case 'rules_not_list':
                unset($graph['rules']);
                $graph['rules'] = 'none';
                break;
            case 'hidden_bad_id':
                $graph['hidden'] = ['has space'];
                break;
            case 'objective_bad_id':
                $o[0]['id'] = 'v/1';
                break;
            case 'duplicate_objective':
                $o[2]['id'] = 'v-1';
                break;
            case 'unknown_type':
                $o[0]['type'] = 'teleport_zone';
                break;
            case 'title_missing':
                unset($o[0]['title']);
                break;
            case 'negative_points':
                $o[1]['points'] = -1;
                break;
            case 'points_boolean':
                $o[1]['points'] = true;
                break;
            case 'required_not_bool':
                $o[0]['required'] = 1;
                break;
            case 'after_not_list':
                $o[1]['unlock']['after'] = 'v-1';
                break;
            case 'score_string':
                $o[5]['unlock']['score_at_least'] = '2';
                break;
            case 'unknown_after':
                $o[1]['unlock']['after'] = ['nope'];
                break;
            case 'self_cycle':
                $o[0]['unlock'] = ['after' => ['v-1']];
                break;
            case 'two_cycle':
                // Example order: v-1, q-1 (after v-1), v-2 (after v-1). Make q-1 wait for v-2 and v-2 for q-1.
                $o[1]['unlock']['after'] = ['v-2'];
                $o[2]['unlock']['after'] = ['q-1'];
                break;
            case 'zone_missing':
                unset($o[0]['zone']);
                break;
            case 'interact_target':
                $o[5]['target'] = '';
                break;
            case 'slot_zero':
                $o[1]['slot'] = 0;
                break;
            case 'slot_fractional':
                $o[1]['slot'] = 1.5;
                break;
            case 'duplicate_slot':
                $o[] = ['id' => 'q-2', 'type' => 'answer_question', 'title' => 'Again', 'slot' => 1];
                break;
            case 'collect_empty':
                $o[3]['targets'] = [];
                break;
            case 'collect_duplicate':
                $o[3]['targets'] = ['m-1', 'm-1'];
                break;
            case 'count_high':
                $o[3]['count'] = 4;
                break;
            case 'sequence_self':
                $o[4]['steps'] = ['v-1', 's-1'];
                break;
            case 'sequence_nested':
                $o[] = ['id' => 's-2', 'type' => 'sequence', 'title' => 'Nested', 'steps' => ['s-1', 'v-1']];
                break;
            case 'sequence_unknown':
                $o[4]['steps'] = ['v-1', 'x'];
                break;
            case 'sequence_empty':
                $o[4]['steps'] = [];
                break;
            case 'duplicate_rule':
                $r[1]['id'] = 'r-1';
                break;
            case 'unknown_trigger':
                $r[0]['on'] = ['on' => 'timer'];
                break;
            case 'trigger_unknown_objective':
                $r[0]['on']['objective'] = 'nope';
                break;
            case 'trigger_unknown_verb':
                $r[2]['on']['verb'] = 'jumped';
                break;
            case 'trigger_unknown_type':
                $r[2]['on']['type'] = 'planet';
                break;
            case 'once_not_bool':
                $r[2]['once'] = 'no';
                break;
            case 'actions_empty':
                $r[1]['actions'] = [];
                break;
            case 'unknown_action':
                $r[1]['actions'] = [['do' => 'explode']];
                break;
            case 'unlock_unknown':
                $r[1]['actions'] = [['do' => 'unlock', 'objective' => 'nope']];
                break;
            case 'open_unknown_slot':
                $r[0]['actions'][0]['slot'] = 7;
                break;
            case 'show_no_target':
                unset($r[2]['actions'][0]['target']);
                break;
        }
        unset($o, $r);
        return $graph;
    }
}
