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
 * Tests of the ring the others of a live room stand on in a 360° photo room
 * (vrroomruntime_builtin/live/ring).
 *
 * Run from the repository root: node --test tests/js/live_ring.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {RING_RADIUS, hashString, ringAngle, ringPosition, ringPose} from '../../runtime/builtin/amd/src/live/ring.js';
import {hashString as spawnHash} from '../../runtime/builtin/amd/src/spawn.js';

const PEERS = ['0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210',
    '0123456789abcdef0123456789abcdee', 'a', 'b', ''];

test('the ring is 1.4 m and the hash is the one spawn.js uses', () => {
    assert.equal(RING_RADIUS, 1.4);
    PEERS.forEach((peer) => assert.equal(hashString(peer), spawnHash(peer)));
});

test('every peer gets an angle in [0, 2π), the same every time', () => {
    PEERS.forEach((peer) => {
        const angle = ringAngle(peer);
        assert.ok(angle >= 0 && angle < 2 * Math.PI, `${peer}: ${angle}`);
        assert.equal(ringAngle(peer), angle);
    });
});

test('peer ids one character apart stand far apart', () => {
    const a = ringPosition(PEERS[0]);
    const b = ringPosition(PEERS[2]);
    assert.ok(Math.hypot(a[0] - b[0], a[1] - b[1]) > 0.3, `${a} and ${b}`);
});

test('ring positions are on the ring', () => {
    PEERS.forEach((peer) => {
        const [x, z] = ringPosition(peer);
        assert.ok(Math.abs(Math.hypot(x, z) - RING_RADIUS) < 1e-9);
    });
    const [x, z] = ringPosition(PEERS[1], 2);
    assert.ok(Math.abs(Math.hypot(x, z) - 2) < 1e-9);
});

test('a pose moves onto the ring: the received position is ignored, height, rotation and hands kept', () => {
    const q = [0, 0.3826834, 0, 0.9238795];
    const pose = {seq: 4, h: [5, 1.7, -3, ...q], l: [5.2, 1.2, -3.1, 0, 0, 0, 1], r: null, other: 'kept'};
    const moved = ringPose(pose, PEERS[0]);
    const [x, z] = ringPosition(PEERS[0]);
    assert.deepEqual(moved.h.slice(0, 3).map((v) => Math.round(v * 1e9) / 1e9), [x, 1.7, z].map((v) => Math.round(v * 1e9) / 1e9));
    assert.deepEqual(moved.h.slice(3), q, 'faces where the peer looks');
    assert.ok(Math.abs(moved.l[0] - (x + 0.2)) < 1e-9 && Math.abs(moved.l[2] - (z - 0.1)) < 1e-9, 'the hand moves with the head');
    assert.equal(moved.l[1], 1.2);
    assert.equal(moved.r, null);
    assert.equal(moved.other, 'kept');
    assert.equal(moved.seq, 4);
    assert.deepEqual(pose.h, [5, 1.7, -3, ...q], 'the received pose is not changed');
    // Wherever the peer says it stands, the result is the same place.
    const elsewhere = ringPose(Object.assign({}, pose, {h: [-8, 1.7, 9, ...q]}), PEERS[0]);
    assert.ok(Math.abs(elsewhere.h[0] - moved.h[0]) < 1e-9 && Math.abs(elsewhere.h[2] - moved.h[2]) < 1e-9);
    assert.equal(ringPose(null, PEERS[0]), null);
});
