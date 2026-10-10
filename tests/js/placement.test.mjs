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
 * Tests of placement by direction in 360° photo rooms (vrroomruntime_builtin/placement).
 *
 * Run from the repository root: node --test tests/js/placement.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    EYE, DEFAULT_DISTANCE, MAX_PITCH, ROTATION_ORDER,
    placementPosition, placementFromDirection, placementRotation, readPlacement, placementTransform, rotationKind,
} from '../../runtime/builtin/amd/src/placement.js';

const DELTA = 1e-9;

/**
 * Assert that two vectors are equal within DELTA.
 *
 * @param {number[]} actual Actual.
 * @param {number[]} expected Expected.
 * @param {string} [message] Message.
 */
const near = (actual, expected, message = '') => {
    assert.equal(actual.length, expected.length, message);
    actual.forEach((v, i) => assert.ok(Math.abs(v - expected[i]) < 1e-6, `${message} [${i}]: ${v} != ${expected[i]}`));
};

test('the constants are the contract values', () => {
    assert.equal(EYE, 1.6);
    assert.equal(DEFAULT_DISTANCE, 4);
    assert.equal(MAX_PITCH, 85);
    assert.equal(ROTATION_ORDER, 'YXZ');
});

test('yaw 0 is straight ahead (−z) at eye height, 4 m away by default', () => {
    near(placementPosition(0, 0), [0, EYE, -4]);
    near(placementPosition(0, 0, 2), [0, EYE, -2]);
});

test('yaw +90 is to the left (−x) and −90 to the right (+x), 180 behind', () => {
    near(placementPosition(90, 0, 4), [-4, EYE, 0]);
    near(placementPosition(-90, 0, 4), [4, EYE, 0]);
    near(placementPosition(180, 0, 4), [0, EYE, 4]);
});

test('pitch raises and lowers the point and pulls it in', () => {
    const [x, y, z] = placementPosition(0, 30, 4);
    assert.ok(Math.abs(x) < DELTA);
    assert.ok(Math.abs(y - (EYE + 2)) < 1e-9, `y ${y}`);
    assert.ok(Math.abs(z + 4 * Math.cos(Math.PI / 6)) < 1e-9, `z ${z}`);
    near(placementPosition(0, -90, 3), [0, EYE - 3, 0], 'straight down');
});

test('the formula matches the contract for an arbitrary placement', () => {
    const yaw = 37.5;
    const pitch = -12.25;
    const d = 6.5;
    const r = Math.PI / 180;
    near(placementPosition(yaw, pitch, d), [
        -Math.sin(yaw * r) * Math.cos(pitch * r) * d,
        EYE + Math.sin(pitch * r) * d,
        -Math.cos(yaw * r) * Math.cos(pitch * r) * d,
    ]);
});

test('no negative zero in positions', () => {
    const p = placementPosition(0, 0, 4);
    assert.ok(!Object.is(p[0], -0));
});

test('a direction gives back its yaw and pitch (any length)', () => {
    [[0, 0], [90, 0], [-90, 0], [45, 20], [-135, -40], [170, 60], [180, 0]].forEach(([yaw, pitch]) => {
        const p = placementPosition(yaw, pitch, 5);
        const dir = {x: p[0] * 3, y: (p[1] - EYE) * 3, z: p[2] * 3};
        const back = placementFromDirection(dir);
        assert.ok(Math.abs(back.yaw - yaw) < 1e-6, `yaw ${back.yaw} != ${yaw}`);
        assert.ok(Math.abs(back.pitch - pitch) < 1e-6, `pitch ${back.pitch} != ${pitch}`);
    });
});

test('direction: arrays work, yaw stays in (−180, 180], pitch is capped and zero length is ahead', () => {
    assert.deepEqual(placementFromDirection([0, 0, -1]), {yaw: 0, pitch: 0});
    assert.equal(placementFromDirection([0, 0, 1]).yaw, 180);
    assert.equal(placementFromDirection({x: 0, y: 1, z: 0}).pitch, MAX_PITCH);
    assert.equal(placementFromDirection({x: 0, y: -1, z: -0.0001}).pitch, -MAX_PITCH);
    assert.deepEqual(placementFromDirection({x: 0, y: 0, z: 0}), {yaw: 0, pitch: 0});
    assert.deepEqual(placementFromDirection(null), {yaw: 0, pitch: 0});
});

test('rotation: planes face the centre, pictures also tilt, hotspots stay upright', () => {
    assert.deepEqual(placementRotation(30, 20, 'portal'), [0, 30, 0]);
    assert.deepEqual(placementRotation(30, 20, 'text'), [0, 30, 0]);
    assert.deepEqual(placementRotation(30, 20, 'image'), [20, 30, 0]);
    assert.deepEqual(placementRotation(30, 20, 'video'), [20, 30, 0]);
    assert.deepEqual(placementRotation(30, 20, 'hotspot'), [0, 0, 0]);
    assert.deepEqual(placementRotation(-45, 10, 'media'), [0, -45, 0], 'audio media does not tilt');
});

test('a plane turned by placementRotation faces the centre', () => {
    // A plane faces +z; turned by yaw (about y) its normal is (sin yaw, 0, cos yaw), towards the centre.
    [0, 45, 90, -120, 180].forEach((yaw) => {
        const [x, , z] = placementPosition(yaw, 0, 4);
        const r = placementRotation(yaw, 0, 'image')[1] * Math.PI / 180;
        const normal = [Math.sin(r), Math.cos(r)];
        const tocentre = [-x / 4, -z / 4];
        assert.ok(Math.abs(normal[0] - tocentre[0]) < 1e-9 && Math.abs(normal[1] - tocentre[1]) < 1e-9, `yaw ${yaw}`);
    });
});

test('readPlacement cleans the values and refuses what is not a placement', () => {
    assert.deepEqual(readPlacement({yaw: 10, pitch: 5}), {yaw: 10, pitch: 5, distance: DEFAULT_DISTANCE});
    assert.deepEqual(readPlacement({yaw: '10', pitch: 100, distance: 99}), {yaw: 10, pitch: 85, distance: 40});
    assert.deepEqual(readPlacement({yaw: 0, pitch: -100, distance: 0.1}), {yaw: 0, pitch: -85, distance: 0.5});
    assert.equal(readPlacement(null), null);
    assert.equal(readPlacement([1, 2]), null);
    assert.equal(readPlacement({yaw: 'east', pitch: 0}), null);
    assert.equal(readPlacement({yaw: 10}), null);
});

test('placementTransform prefers the placement and uses the video kind of a media item', () => {
    const t = placementTransform({kind: 'media', media: 'video', placement: {yaw: 90, pitch: 10, distance: 3}});
    near(t.position, placementPosition(90, 10, 3));
    assert.deepEqual(t.rotation, [10, 90, 0]);
    assert.equal(t.order, 'YXZ');
    assert.equal(t.distance, 3);
    assert.equal(placementTransform({kind: 'hotspot', position: [1, 2, 3]}), null);
    assert.equal(rotationKind({kind: 'media', media: 'audio'}), 'media');
    assert.equal(rotationKind({kind: 'image'}), 'image');
});
