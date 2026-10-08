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

/**
 * Runs the golden graph fixtures against the client evaluator (vrroomruntime_builtin/graph).
 *
 * The same fixtures are run against the PHP evaluator by tests/local/graph/evaluator_test.php.
 * Run from the repository root: node --test tests/js/graph_fixtures.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {evaluate, classify, validate, STATUS, OBJECTIVE} from '../../runtime/builtin/amd/src/graph.js';

const DELTA = 1e-6;
const here = path.dirname(fileURLToPath(import.meta.url));
const fixturedir = path.join(here, '..', 'fixtures', 'graph');
const files = fs.readdirSync(fixturedir).filter((f) => f.endsWith('.json')).sort();
const validatedir = path.join(here, '..', 'fixtures', 'graphvalidate');
const validatefiles = fs.readdirSync(validatedir).filter((f) => f.endsWith('.json')).sort();

/**
 * Whether a value is a plain object.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Compare expected with actual: numbers within DELTA, other scalars exactly. Mirrors
 * evaluator_test::assert_matches() in PHP.
 *
 * Lists compare element by element. Objects must have exactly the expected keys in the same order,
 * except where subset is true (the whole expect block, and each objective entry): there every
 * expected key must exist. So the objectives map must list every objective, in graph order.
 *
 * @param {*} expected
 * @param {*} actual
 * @param {string} where location for failure messages
 * @param {boolean} subset whether the actual object may have extra keys
 */
const assertMatches = (expected, actual, where, subset = false) => {
    if (typeof expected === 'number') {
        assert.equal(typeof actual, 'number', `${where}: number expected`);
        assert.ok(Math.abs(expected - actual) <= DELTA, `${where}: expected ${expected}, got ${actual}`);
        return;
    }
    if (Array.isArray(expected)) {
        assert.ok(Array.isArray(actual), `${where}: list expected`);
        assert.equal(actual.length, expected.length, `${where}: length`);
        expected.forEach((value, i) => assertMatches(value, actual[i], `${where}[${i}]`));
        return;
    }
    if (isObject(expected)) {
        assert.ok(isObject(actual), `${where}: object expected`);
        const expectedKeys = Object.keys(expected);
        const actualKeys = Object.keys(actual);
        if (subset) {
            expectedKeys.forEach((key) => assert.ok(actualKeys.includes(key), `${where}.${key}: missing`));
        } else {
            assert.deepEqual(actualKeys, expectedKeys, `${where}: keys`);
        }
        const childSubset = where.endsWith('.objectives');
        expectedKeys.forEach((key) => assertMatches(expected[key], actual[key], `${where}.${key}`, childSubset));
        return;
    }
    assert.equal(actual, expected, where);
};

test('the fixture set is complete', () => {
    assert.deepEqual(files.map((f) => f.replace(/\.json$/, '')), [
        'answer_while_locked', 'collect_keys_lock', 'collect_n_of_set', 'empty_graph', 'escape_chain',
        'finish_on_all_required', 'gallery_ordered', 'hint_locked_completed', 'hint_penalty',
        'interacted_zone_counts', 'locked_until_after', 'pass_fraction_retry', 'rules_show_hide', 'score_at_least',
        'sequence_strict', 'single_visit', 'unlock_action_cascade', 'visit_then_question',
        'zero_points_required_ratio',
    ]);
});

test('the validator fixture set is complete', () => {
    assert.deepEqual(validatefiles.map((f) => f.replace(/\.json$/, '')), [
        'digit_objective_id', 'duplicate_hint_id', 'hints_invalid', 'hints_on_sequence',
        'pass_fraction_invalid', 'timelimit_invalid', 'valid_extensions',
    ]);
});

for (const file of validatefiles) {
    const fixture = JSON.parse(fs.readFileSync(path.join(validatedir, file), 'utf8'));

    test(`validator fixture ${fixture.name}`, () => {
        assert.equal(fixture.name, file.replace(/\.json$/, ''));
        assert.deepEqual(validate(fixture.graph), fixture.errors);
    });
}

for (const file of files) {
    const fixture = JSON.parse(fs.readFileSync(path.join(fixturedir, file), 'utf8'));

    test(`fixture ${fixture.name}`, () => {
        assert.equal(fixture.name, file.replace(/\.json$/, ''));
        assert.deepEqual(validate(fixture.graph), [], 'fixture graph must be valid');

        const result = evaluate(fixture.graph, fixture.events);
        assert.equal(result.eventstatus.length, fixture.events.length);
        assertMatches(fixture.expect, result, 'expect', true);

        // The result is plain JSON data.
        assertMatches(fixture.expect, JSON.parse(JSON.stringify(result)), 'roundtrip', true);

        // Classify agrees with the status evaluate() records for the same event.
        fixture.events.forEach((event, i) => {
            assert.equal(classify(fixture.graph, fixture.events.slice(0, i), event), result.eventstatus[i],
                `classify event ${i}`);
        });
    });
}

test('classify and constants', () => {
    const graph = {
        schema: 'vrroom-graph/1',
        objectives: [
            {id: 'v1', type: 'visit_zone', title: 'Visit', zone: 'z1', required: true},
            {id: 'q1', type: 'answer_question', title: 'Answer', slot: 1, points: 2, unlock: {after: ['v1']}},
        ],
        rules: [],
    };
    const visit = {verb: 'entered', type: 'zone', id: 'z1', scene: null, fraction: null};
    const answer = {verb: 'answered', type: 'question', id: '1', scene: null, fraction: 1};
    const other = {verb: 'entered', type: 'zone', id: 'z9', scene: null, fraction: null};
    assert.equal(classify(graph, [], answer), STATUS.LOCKED);
    assert.equal(classify(graph, [], visit), STATUS.APPLIED);
    assert.equal(classify(graph, [], other), STATUS.IGNORED);
    assert.equal(classify(graph, [visit], answer), STATUS.APPLIED);
    assert.equal(classify(graph, [visit, answer], answer), STATUS.APPLIED);
    assert.equal(classify(graph, [visit], {...answer, id: 1}), STATUS.APPLIED);
    assert.equal(classify(graph, [answer], answer), STATUS.LOCKED);
    assert.equal(evaluate(graph, [visit]).objectives.q1.status, OBJECTIVE.AVAILABLE);
});

test('validator messages match the PHP validator', () => {
    const cycle = {
        schema: 'vrroom-graph/1',
        objectives: [
            {id: 'a', type: 'visit_zone', title: 'A', zone: 'z-a', unlock: {after: ['c']}},
            {id: 'b', type: 'visit_zone', title: 'B', zone: 'z-b', unlock: {after: ['a']}},
            {id: 'c', type: 'visit_zone', title: 'C', zone: 'z-c', unlock: {after: ['b']}},
        ],
        rules: [],
    };
    assert.deepEqual(validate(cycle), ['unlock.after cycle: "b" waits for "a"']);

    const bad = {
        schema: 'vrroom-graph/2',
        hidden: ['has space'],
        objectives: [
            {id: 'v-1', type: 'visit_zone', title: 'Visit', zone: 'z-1'},
            {id: 'v-1', type: 'teleport_zone', points: -1},
            {id: 'q-1', type: 'answer_question', title: 'Q', slot: 1.5},
            {id: 'c-1', type: 'collect', title: 'C', targets: ['m-1'], count: 2},
            {id: 's-1', type: 'sequence', title: 'S', steps: ['s-1', 'x']},
        ],
        rules: [
            {id: 'r-1', on: {on: 'timer'}, actions: []},
            {id: 'r-1', on: {on: 'event', verb: 'jumped'}, once: 'no',
                actions: [{do: 'explode'}, {do: 'open_question', slot: 7}, {do: 'unlock', objective: 'nope'}]},
        ],
    };
    assert.deepEqual(validate(bad), [
        'schema must be "vrroom-graph/1"',
        'hidden[0] is not a valid id',
        'duplicate objective id "v-1"',
        'objectives[1].type is unknown',
        'objectives[1].title must be a string',
        'objectives[1].points must be a number >= 0',
        'objectives[2].slot must be an integer >= 1',
        'objectives[3].count must be an integer from 1 to the number of targets',
        'objectives[4].steps cannot contain the sequence itself',
        'objectives[4].steps refers to unknown objective "x"',
        'rules[0].on.on is an unknown trigger',
        'rules[0].actions must be a non-empty array',
        'duplicate rule id "r-1"',
        'rules[1].once must be true or false',
        'rules[1].on.verb is unknown',
        'rules[1].actions[0].do is an unknown action',
        'rules[1].actions[1].slot 7 has no answer_question objective',
        'rules[1].actions[2].objective refers to unknown objective "nope"',
    ]);
});
