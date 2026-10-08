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
 * Tests of the live-room message codec and the transport interface
 * (vrroomruntime_builtin/live/codec and vrroomruntime_builtin/live/transport).
 *
 * Run from the repository root: node --test tests/js/live_codec.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {LIMITS, encode, decode, normaliseQuat, isSceneId} from '../../runtime/builtin/amd/src/live/codec.js';
import {EVENTS, STATES, Emitter, Transport} from '../../runtime/builtin/amd/src/live/transport.js';

const DELTA = 1e-9;

/**
 * A valid pose message.
 *
 * @param {Object} [changes] Keys to replace.
 * @returns {Object}
 */
const pose = (changes = {}) => Object.assign({
    t: 'p',
    q: 42,
    ts: 1760000000000,
    sc: 'scene-1',
    m: 'vr',
    h: [1.23456, 1.6, -2.0004, 0, 0, 0, 1],
    l: [0.2, 1.1, -0.3, 0, 0.7071068, 0, 0.7071068],
    r: null,
}, changes);

/**
 * A valid state message.
 *
 * @param {Object} [changes] Keys to replace.
 * @returns {Object}
 */
const state = (changes = {}) => Object.assign({t: 's', m: '3d', sc: 'scene-1', mic: 'on'}, changes);

/**
 * Assert two number arrays are equal within a tolerance.
 *
 * @param {number[]} actual
 * @param {number[]} expected
 * @param {number} [delta]
 */
const near = (actual, expected, delta = DELTA) => {
    assert.equal(actual.length, expected.length);
    actual.forEach((value, i) => {
        assert.ok(Math.abs(value - expected[i]) <= delta, `index ${i}: ${value} != ${expected[i]}`);
    });
};

test('pose round trip keeps the known keys in order', () => {
    const text = encode('pose', Object.assign(pose(), {extra: 'dropped'}));
    assert.equal(typeof text, 'string');
    assert.deepEqual(Object.keys(JSON.parse(text)), ['t', 'q', 'ts', 'sc', 'm', 'h', 'l', 'r']);
    const message = decode('pose', text);
    assert.equal(message.t, 'p');
    assert.equal(message.q, 42);
    assert.equal(message.ts, 1760000000000);
    assert.equal(message.sc, 'scene-1');
    assert.equal(message.m, 'vr');
    assert.equal(message.r, null);
    near(message.h, [1.235, 1.6, -2, 0, 0, 0, 1]);
    near(message.l, [0.2, 1.1, -0.3, 0, 0.7071, 0, 0.7071], 1e-4);
});

test('pose positions are rounded to 3 and quaternions to 4 decimals on encode', () => {
    const wire = JSON.parse(encode('pose', pose({h: [0.12345, -0.0004, 999.9996, 0.123456, 0, 0, 0.992350]})));
    assert.equal(wire.h[0], 0.123);
    assert.equal(wire.h[1], 0);
    assert.ok(!Object.is(wire.h[1], -0), 'no negative zero');
    assert.equal(wire.h[2], 1000);
    // Quaternion normalised before rounding.
    const length = Math.sqrt(0.123456 * 0.123456 + 0.992350 * 0.992350);
    assert.equal(wire.h[3], Math.round(0.123456 / length * 10000) / 10000);
    assert.equal(wire.h[6], Math.round(0.992350 / length * 10000) / 10000);
});

test('quaternions are normalised on decode', () => {
    const text = JSON.stringify(pose({h: [0, 0, 0, 0, 0, 0, 0.5], l: [0, 0, 0, 1.01, 0, 0, 0]}));
    const message = decode('pose', text);
    near(message.h, [0, 0, 0, 0, 0, 0, 1]);
    near(message.l, [0, 0, 0, 1, 0, 0, 0]);
});

test('normaliseQuat', () => {
    near(normaliseQuat([0, 0, 0, 2]), [0, 0, 0, 1]);
    near(normaliseQuat([1, 1, 1, 1]), [0.5, 0.5, 0.5, 0.5]);
    assert.equal(normaliseQuat([0, 0, 0, 0]), null);
    assert.equal(normaliseQuat([0, 0, 1]), null);
    assert.equal(normaliseQuat([0, 0, NaN, 1]), null);
    assert.equal(normaliseQuat('1,0,0,0'), null);
});

test('isSceneId', () => {
    assert.ok(isSceneId('a'));
    assert.ok(isSceneId('0f8e-12ab'));
    assert.ok(isSceneId('a'.repeat(36)));
    assert.ok(!isSceneId(''));
    assert.ok(!isSceneId('a'.repeat(37)));
    // Same character set as scene_validator::ID_PATTERN (uppercase and underscore allowed).
    assert.ok(isSceneId('Scene'));
    assert.ok(isSceneId('scene_1'));
    assert.ok(!isSceneId('scene 1'));
    assert.ok(!isSceneId('scene.1'));
    assert.ok(!isSceneId('scène'));
    assert.ok(!isSceneId(12));
    assert.ok(!isSceneId(null));
});

test('invalid poses encode and decode to null', () => {
    const bad = [
        pose({t: 's'}),
        pose({q: -1}),
        pose({q: 2147483648}),
        pose({q: 1.5}),
        pose({ts: -5}),
        pose({ts: 1.5}),
        pose({sc: 'Bad id'}),
        pose({sc: ''}),
        pose({m: 'list'}),
        pose({m: 'xr'}),
        pose({h: null}),
        pose({h: [0, 0, 0, 0, 0, 1]}),
        pose({h: [NaN, 0, 0, 0, 0, 0, 1]}),
        pose({h: [Infinity, 0, 0, 0, 0, 0, 1]}),
        pose({h: [1000.001, 0, 0, 0, 0, 0, 1]}),
        pose({h: [0, -1000.5, 0, 0, 0, 0, 1]}),
        pose({h: [0, 0, 0, 0, 0, 0, 1.02]}),
        pose({h: [0, 0, 0, 0, 0, 0, 0]}),
        pose({h: [0, 0, '0', 0, 0, 0, 1]}),
        pose({l: [0, 0, 0]}),
        pose({r: [0, 0, 0, 0, 0, 0, 0]}),
        pose({l: 'none'}),
    ];
    bad.forEach((message, i) => {
        assert.equal(encode('pose', message), null, `encode case ${i}`);
        // JSON.stringify turns NaN/Infinity into null, which is invalid as well.
        assert.equal(decode('pose', JSON.stringify(message)), null, `decode case ${i}`);
    });
    assert.equal(encode('pose', null), null);
    assert.equal(encode('pose', [pose()]), null);
});

test('missing hands decode to null', () => {
    const message = pose();
    delete message.l;
    delete message.r;
    const decoded = decode('pose', JSON.stringify(message));
    assert.equal(decoded.l, null);
    assert.equal(decoded.r, null);
});

test('decode refuses non-strings, bad JSON, wrong channel and oversize data', () => {
    const good = encode('pose', pose());
    assert.notEqual(decode('pose', good), null);
    assert.equal(decode('state', good), null, 'pose on the state channel');
    assert.equal(decode('other', good), null);
    assert.equal(decode('pose', JSON.parse(good)), null, 'object instead of string');
    assert.equal(decode('pose', '{"t":"p",'), null);
    assert.equal(decode('pose', 'null'), null);
    assert.equal(decode('pose', '[1,2]'), null);
    const padded = JSON.stringify(Object.assign(pose(), {pad: 'x'.repeat(LIMITS.pose)}));
    assert.ok(padded.length > LIMITS.pose);
    assert.equal(decode('pose', padded), null, 'oversize');
    // Multi-byte characters count as bytes: 400 three-byte characters exceed 1024 bytes.
    const wide = JSON.stringify(Object.assign(pose(), {pad: '€'.repeat(400)}));
    assert.ok(wide.length <= LIMITS.pose);
    assert.equal(decode('pose', wide), null, 'oversize in bytes');
});

test('state round trip and validation', () => {
    assert.deepEqual(decode('state', encode('state', state())), state());
    assert.deepEqual(decode('state', encode('state', state({m: 'list', sc: '', mic: 'muted'}))),
        {t: 's', m: 'list', sc: '', mic: 'muted'});
    assert.deepEqual(Object.keys(JSON.parse(encode('state', Object.assign(state(), {x: 1})))), ['t', 'm', 'sc', 'mic']);
    [
        state({t: 'p'}),
        state({m: 'xr'}),
        state({mic: 'loud'}),
        state({sc: 'Scene!'}),
        state({sc: null}),
        state({sc: 'a'.repeat(37)}),
    ].forEach((message, i) => {
        assert.equal(encode('state', message), null, `encode case ${i}`);
        assert.equal(decode('state', JSON.stringify(message)), null, `decode case ${i}`);
    });
    assert.equal(encode('other', state()), null);
    assert.equal(decode('state', JSON.stringify(Object.assign(state(), {pad: 'x'.repeat(LIMITS.state)}))), null);
});

test('limits', () => {
    assert.equal(LIMITS.pose, 1024);
    assert.equal(LIMITS.state, 512);
    assert.equal(LIMITS.maxcoord, 1000);
    assert.ok(Object.isFrozen(LIMITS));
    // The largest possible pose stays within its limit.
    const big = [-999.999, -999.999, -999.999, -0.5, -0.5, -0.5, -0.5];
    const text = encode('pose', pose({q: 2147483647, ts: Number.MAX_SAFE_INTEGER, sc: 'a'.repeat(36), h: big, l: big, r: big}));
    assert.equal(typeof text, 'string');
    assert.ok(text.length <= LIMITS.pose);
});

test('Emitter on, unsubscribe and emit', () => {
    const emitter = new Emitter();
    const seen = [];
    const off = emitter.on('peerjoined', (detail) => seen.push(['a', detail]));
    emitter.on('peerjoined', (detail) => seen.push(['b', detail]));
    emitter.on('other', () => seen.push(['other']));
    emitter.emit('peerjoined', 1);
    off();
    off();
    emitter.emit('peerjoined', 2);
    emitter.emit('nobody', 3);
    assert.deepEqual(seen, [['a', 1], ['b', 1], ['b', 2]]);
    emitter.clear();
    emitter.emit('peerjoined', 4);
    emitter.emit('other', 5);
    assert.equal(seen.length, 3);
    assert.throws(() => emitter.on('x', null), TypeError);
});

test('a throwing listener does not stop the others', (t) => {
    const reported = [];
    t.mock.method(console, 'error', (error) => reported.push(error));
    const emitter = new Emitter();
    const seen = [];
    emitter.on('state', () => {
        throw new Error('boom');
    });
    emitter.on('state', (detail) => seen.push(detail));
    assert.doesNotThrow(() => emitter.emit('state', {state: 'joined', reason: ''}));
    assert.deepEqual(seen, [{state: 'joined', reason: ''}]);
    assert.equal(reported.length, 1);
    assert.equal(reported[0].message, 'boom');
});

test('a listener removed during emit still runs for that emit, not afterwards', () => {
    const emitter = new Emitter();
    const seen = [];
    let offb = null;
    emitter.on('message', () => {
        seen.push('a');
        offb();
    });
    offb = emitter.on('message', () => seen.push('b'));
    emitter.emit('message', null);
    emitter.emit('message', null);
    assert.deepEqual(seen, ['a', 'b', 'a']);
});

test('Transport base methods throw', () => {
    const transport = new Transport();
    assert.ok(transport instanceof Emitter);
    assert.throws(() => transport.kind, /Not implemented/);
    assert.throws(() => transport.capabilities, /Not implemented/);
    [
        () => transport.join(),
        () => transport.leave(),
        () => transport.leaveOnUnload(),
        () => transport.getSelf(),
        () => transport.getPeers(),
        () => transport.broadcast('pose', {}),
        () => transport.send('a', 'state', {}),
        () => transport.setLocalAudio(null),
        () => transport.getRemoteStream('a'),
        () => transport.dispose(),
    ].forEach((fn) => assert.throws(fn, /Not implemented/));
    // The emitter part works on the base class.
    const seen = [];
    transport.on('state', (detail) => seen.push(detail.state));
    transport.emit('state', {state: 'idle', reason: ''});
    assert.deepEqual(seen, ['idle']);
});

test('event and state lists', () => {
    assert.deepEqual(Array.from(EVENTS), ['state', 'peerjoined', 'peerupdated', 'peerleft', 'message', 'track', 'trackended']);
    assert.deepEqual(Array.from(STATES), ['idle', 'joining', 'joined', 'reconnecting', 'left', 'failed']);
    assert.ok(Object.isFrozen(EVENTS));
    assert.ok(Object.isFrozen(STATES));
});
