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
 * Tests of the live room pose interpolation (vrroomruntime_builtin/live/interpolation).
 *
 * Run from the repository root: node --test tests/js/live_interpolation.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {lerp, slerp, createInterpolator} from '../../runtime/builtin/amd/src/live/interpolation.js';

const DELTA = 1e-6;
const S = Math.SQRT1_2;

/** Identity rotation. */
const ID = [0, 0, 0, 1];

/** 90 degrees about +Y. */
const Y90 = [0, S, 0, S];

/**
 * Assert two number arrays are equal within DELTA.
 *
 * @param {number[]} actual
 * @param {number[]} expected
 * @param {string} message
 */
const near = (actual, expected, message = '') => {
    assert.equal(actual.length, expected.length, `${message} length`);
    actual.forEach((value, i) => {
        assert.ok(Math.abs(value - expected[i]) < DELTA, `${message} [${i}]: ${value} != ${expected[i]}`);
    });
};

/**
 * Whether two quaternions are the same rotation.
 *
 * @param {number[]} a
 * @param {number[]} b
 * @param {string} message
 */
const sameRotation = (a, b, message = '') => {
    const dot = Math.abs(a[0] * b[0] + a[1] * b[1] + a[2] * b[2] + a[3] * b[3]);
    assert.ok(Math.abs(dot - 1) < DELTA, `${message}: ${a} vs ${b}`);
};

/**
 * A sample.
 *
 * @param {number} seq
 * @param {number} ts
 * @param {number} x
 * @param {number[]} q
 * @param {Object} hands {l, r}
 * @returns {Object}
 */
const sample = (seq, ts, x, q = ID, hands = {}) => ({
    seq: seq,
    ts: ts,
    h: [x, 1.6, 0].concat(q),
    l: hands.l === undefined ? null : hands.l,
    r: hands.r === undefined ? null : hands.r,
});

test('lerp is element-wise', () => {
    near(lerp([0, 10, -2], [10, 20, 2], 0.25), [2.5, 12.5, -1]);
    near(lerp([1, 1], [3, 5], 0), [1, 1]);
    near(lerp([1, 1], [3, 5], 1), [3, 5]);
});

test('slerp ends and middle', () => {
    sameRotation(slerp(ID, Y90, 0), ID, 't 0');
    sameRotation(slerp(ID, Y90, 1), Y90, 't 1');
    const half = Math.PI / 8;
    near(slerp(ID, Y90, 0.5), [0, Math.sin(half), 0, Math.cos(half)], 't 0.5 is 45 degrees');
    const q = slerp(ID, Y90, 0.3);
    near([Math.hypot(q[0], q[1], q[2], q[3])], [1], 'normalised');
});

test('slerp takes the shortest path through a negated quaternion', () => {
    const negated = Y90.map((v) => -v);
    const half = Math.PI / 8;
    // Same rotation as Y90: the middle is 45 degrees, not the long way round.
    sameRotation(slerp(ID, negated, 0.5), [0, Math.sin(half), 0, Math.cos(half)], 'shortest');
});

test('slerp of nearly equal quaternions stays normalised', () => {
    const a = [0, 0, 0, 1];
    const b = [0, 1e-5, 0, 1];
    const q = slerp(a, b, 0.5);
    near([Math.hypot(q[0], q[1], q[2], q[3])], [1]);
});

test('nothing before the first sample', () => {
    const interp = createInterpolator();
    assert.equal(interp.sample(1000), null);
    assert.equal(interp.lastReceived(), 0);
});

test('first sample is shown until a pair brackets the render time', () => {
    const interp = createInterpolator({delay: 100});
    assert.equal(interp.push(sample(1, 5000, 2), 1000), true);
    near(interp.sample(1000).h, [2, 1.6, 0, 0, 0, 0, 1]);
    // Before the first sample's time (render time = now - offset - delay).
    near(interp.sample(1050).h, [2, 1.6, 0, 0, 0, 0, 1]);
    assert.equal(interp.lastReceived(), 1000);
});

test('interpolated sample at a known time between two pushes', () => {
    const interp = createInterpolator({delay: 100});
    // Sender clock 5000 at local 1000: offset -4000.
    interp.push(sample(1, 5000, 0, ID), 1000);
    interp.push(sample(2, 5100, 10, Y90), 1100);
    // Render time on the sender clock: 1150 + 4000 - 100 = 5050, halfway.
    const s = interp.sample(1150);
    near(s.h.slice(0, 3), [5, 1.6, 0], 'position');
    const half = Math.PI / 8;
    near(s.h.slice(3), [0, Math.sin(half), 0, Math.cos(half)], 'rotation');
    // A quarter of the way.
    near(interp.sample(1125).h.slice(0, 3), [2.5, 1.6, 0]);
});

test('out-of-order sequence numbers are dropped', () => {
    const interp = createInterpolator({delay: 0});
    assert.equal(interp.push(sample(5, 1000, 0), 1000), true);
    assert.equal(interp.push(sample(4, 1050, 99), 1050), false);
    assert.equal(interp.push(sample(5, 1060, 99), 1060), false);
    assert.equal(interp.lastReceived(), 1000);
    assert.equal(interp.push(sample(6, 1100, 10), 1100), true);
    near(interp.sample(1100).h.slice(0, 3), [10, 1.6, 0]);
    near(interp.sample(1050).h.slice(0, 3), [5, 1.6, 0]);
});

test('a sequence far below the last one is a restarted sender', () => {
    const interp = createInterpolator({delay: 0});
    interp.push(sample(5000, 1000, 0), 1000);
    assert.equal(interp.push(sample(1, 2000, 7), 1100), true);
    near(interp.sample(1100).h.slice(0, 3), [7, 1.6, 0]);
});

test('the last sample is held after the newest time', () => {
    const interp = createInterpolator({delay: 100});
    interp.push(sample(1, 0, 0), 0);
    interp.push(sample(2, 100, 4), 100);
    near(interp.sample(5000).h.slice(0, 3), [4, 1.6, 0]);
    near(interp.sample(60000).h.slice(0, 3), [4, 1.6, 0]);
});

test('clock offset is the minimum of now - ts', () => {
    const interp = createInterpolator({delay: 0});
    // Offsets: 1000 (fastest), 1050 (late), 1000.
    interp.push(sample(1, 0, 0), 1000);
    interp.push(sample(2, 100, 10), 1150);
    interp.push(sample(3, 200, 20), 1200);
    // Offset 1000: local 1150 is sender time 150, halfway between the 2nd and 3rd samples.
    near(interp.sample(1150).h.slice(0, 3), [15, 1.6, 0]);
    // A late sample does not move the offset.
    interp.push(sample(4, 300, 30), 1500);
    near(interp.sample(1250).h.slice(0, 3), [25, 1.6, 0]);
});

test('hands are present only when in both bracketing samples', () => {
    const hand = (x) => [x, 1, 0, 0, 0, 0, 1];
    const interp = createInterpolator({delay: 0});
    interp.push(sample(1, 0, 0, ID, {l: hand(0), r: hand(2)}), 0);
    interp.push(sample(2, 100, 10, ID, {l: hand(1), r: null}), 100);
    interp.push(sample(3, 200, 20, ID, {l: null, r: null}), 200);
    const first = interp.sample(50);
    near(first.l.slice(0, 3), [0.5, 1, 0], 'left in both');
    assert.equal(first.r, null, 'right only in the first');
    const second = interp.sample(150);
    assert.equal(second.l, null);
    assert.equal(second.r, null);
});

test('invalid samples are refused and reset forgets everything', () => {
    const interp = createInterpolator();
    assert.equal(interp.push({seq: 1, ts: 0, h: [0, 0, 0]}, 0), false);
    assert.equal(interp.push({seq: 1, ts: 0, h: [0, 0, NaN, 0, 0, 0, 1]}, 0), false);
    assert.equal(interp.push(null, 0), false);
    assert.equal(interp.push(sample(1, 0, 1), 10), true);
    interp.reset();
    assert.equal(interp.sample(10), null);
    assert.equal(interp.lastReceived(), 0);
    assert.equal(interp.push(sample(1, 0, 1), 20), true, 'the same seq is accepted after a reset');
});

test('samples returned are copies', () => {
    const interp = createInterpolator({delay: 0});
    const input = sample(1, 0, 1);
    interp.push(input, 0);
    input.h[0] = 99;
    const out = interp.sample(0);
    assert.equal(out.h[0], 1);
    out.h[0] = 42;
    assert.equal(interp.sample(0).h[0], 1);
});
