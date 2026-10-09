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
 * Tests of the start spot helpers (vrroomruntime_builtin/spawn).
 *
 * Run from the repository root: node --test tests/js/spawn.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    STEP, MAX_RADIUS, SPREAD_MIN, SPREAD_MAX,
    ringCandidates, findSpot, insideBounds, hashString, spreadOffset, layoutKey,
} from '../../runtime/builtin/amd/src/spawn.js';

const DELTA = 1e-9;

test('candidates start at the point and grow outwards in rings', () => {
    const list = ringCandidates(2, -1);
    assert.deepEqual(list[0], [2, -1]);
    let last = 0;
    list.forEach(([x, z]) => {
        const r = Math.hypot(x - 2, z + 1);
        assert.ok(r >= last - DELTA, 'never closer than the spot before');
        assert.ok(r <= MAX_RADIUS + DELTA, 'within the search radius');
        last = r;
    });
    // The first ring is STEP away, with at least 8 spots.
    const firstring = list.slice(1).filter(([x, z]) => Math.abs(Math.hypot(x - 2, z + 1) - STEP) < 1e-6);
    assert.ok(firstring.length >= 8);
    // The same input gives the same list.
    assert.deepEqual(ringCandidates(2, -1), list);
});

test('a zero radius gives only the point itself', () => {
    assert.deepEqual(ringCandidates(1, 1, STEP, 0), [[1, 1]]);
});

test('findSpot returns the nearest accepted spot', () => {
    // Everything within 1 m of the origin is blocked (say, a pillar).
    const spot = findSpot(0, 0, (x, z) => Math.hypot(x, z) > 1);
    assert.ok(spot);
    const r = Math.hypot(spot[0], spot[1]);
    assert.ok(r > 1 && r <= 1 + STEP + DELTA, `nearest free ring, got ${r}`);
    assert.deepEqual(findSpot(0, 0, (x, z) => Math.hypot(x, z) > 1), spot);
});

test('findSpot keeps a free start point and gives up when nothing fits', () => {
    assert.deepEqual(findSpot(3, 4, () => true), [3, 4]);
    assert.equal(findSpot(0, 0, () => false), null);
    let calls = 0;
    assert.equal(findSpot(0, 0, () => {
        calls++;
        return false;
    }, {budget: 10}), null);
    assert.equal(calls, 10);
});

test('insideBounds applies the margin and copes with narrow bounds', () => {
    const bounds = {min: [-2, 0, -3], max: [2, 0, 3]};
    assert.ok(insideBounds(bounds, 0, 0, 0.3));
    assert.ok(insideBounds(bounds, 1.7, 2.7, 0.3));
    assert.ok(!insideBounds(bounds, 1.8, 0, 0.3));
    assert.ok(!insideBounds(bounds, 0, -2.9, 0.3));
    assert.ok(insideBounds(null, 100, 100, 0.3));
    // Narrower than twice the margin: the whole width counts.
    const narrow = {min: [0, 0, 0], max: [0.4, 0, 5]};
    assert.ok(insideBounds(narrow, 0.1, 2, 0.3));
    assert.ok(!insideBounds(narrow, 0.5, 2, 0.3));
});

test('hashString is stable and non-negative', () => {
    assert.equal(hashString('abc'), hashString('abc'));
    assert.notEqual(hashString('23'), hashString('24'));
    assert.ok(hashString('x'.repeat(1000)) >= 0);
    assert.equal(hashString(null), hashString(''));
});

test('spread offsets lie on the ring and differ for neighbouring user ids', () => {
    const offsets = [];
    for (let id = 1; id <= 40; id++) {
        const [dx, dz] = spreadOffset(String(id));
        const r = Math.hypot(dx, dz);
        assert.ok(r >= SPREAD_MIN - DELTA && r <= SPREAD_MAX + DELTA, `radius ${r} for user ${id}`);
        assert.deepEqual(spreadOffset(String(id)), [dx, dz], 'the same place every time');
        offsets.push([dx, dz]);
    }
    // Ids one apart (1 to 9: hashes one apart) land well apart: the golden-ratio step turns 137.5°.
    for (let i = 1; i < 9; i++) {
        const gap = Math.hypot(offsets[i][0] - offsets[i - 1][0], offsets[i][1] - offsets[i - 1][1]);
        assert.ok(gap > 0.5, `users ${i} and ${i + 1} are ${gap} m apart`);
    }
});

test('the layout key follows the environment and the start point only', () => {
    const scene = {
        id: 's-1',
        name: 'Hall',
        environment: {type: 'pack', pack: 'rooms', room: 'kitchen', bounds: {min: [-2, 0, -2], max: [2, 0, 2]}, url: 'a'},
        spawn: [{id: 'sp', position: [0, 0, 1], yaw: 0}],
        zones: [{id: 'z-1'}],
    };
    const key = layoutKey(scene);
    assert.equal(typeof key, 'string');
    assert.equal(layoutKey(JSON.parse(JSON.stringify(scene))), key);
    // Names, zones and the model URL (site address) do not matter.
    assert.equal(layoutKey(Object.assign({}, scene, {name: 'Other', zones: []})), key);
    assert.equal(layoutKey(Object.assign({}, scene, {environment: Object.assign({}, scene.environment, {url: 'b'})})), key);
    // Other surroundings, a moved start point or another room do.
    assert.notEqual(layoutKey(Object.assign({}, scene, {environment: Object.assign({}, scene.environment, {room: 'cave'})})), key);
    assert.notEqual(layoutKey(Object.assign({}, scene, {spawn: [{id: 'sp', position: [0, 0, 2], yaw: 0}]})), key);
    assert.notEqual(layoutKey(Object.assign({}, scene, {spawn: [{id: 'sp', position: [0, 0, 1], yaw: 90}]})), key);
    assert.notEqual(layoutKey(Object.assign({}, scene, {id: 's-2'})), key);
    // Anything odd still gives a key.
    assert.equal(typeof layoutKey(null), 'string');
});
