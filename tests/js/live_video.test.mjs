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
 * Tests of the webcam bookkeeping of a live room (vrroomruntime_builtin/live/videostate) and the
 * camera flag of the state message (vrroomruntime_builtin/live/codec).
 *
 * Run from the repository root: node --test tests/js/live_video.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    CAMERA_CONSTRAINTS, VIDEO_MAX_BITRATE, VIDEO_MAX_FRAMERATE, cameraErrorReason, isVideoShown, peerActionOf,
    createVideoBook,
} from '../../runtime/builtin/amd/src/live/videostate.js';
import {encode, decode} from '../../runtime/builtin/amd/src/live/codec.js';

const PEER = '0123456789abcdef0123456789abcdef';
const OTHER = 'fedcba9876543210fedcba9876543210';

/**
 * A video book that records its changes.
 *
 * @param {boolean} [allowed] Video allowed.
 * @returns {{book: Object, changes: Array}}
 */
const recorder = (allowed) => {
    const changes = [];
    const book = createVideoBook((peerid, stream) => changes.push([peerid, stream]), allowed);
    return {book, changes};
};

/**
 * An element with a data-action inside a [data-peerid] item.
 *
 * @param {string} action data-action.
 * @param {string} peerid data-peerid.
 * @returns {Object}
 */
const button = (action, peerid) => ({
    dataset: {action: action},
    closest: (selector) => (selector === '[data-peerid]' ? {dataset: {peerid: peerid}} : null),
});

test('camera constraints and limits are small and cheap', () => {
    assert.deepEqual(JSON.parse(JSON.stringify(CAMERA_CONSTRAINTS)), {
        width: {ideal: 320},
        height: {ideal: 240},
        frameRate: {ideal: 15, max: 15},
        facingMode: 'user',
    });
    assert.ok(Object.isFrozen(CAMERA_CONSTRAINTS));
    assert.equal(VIDEO_MAX_BITRATE, 150000);
    assert.equal(VIDEO_MAX_FRAMERATE, 15);
});

test('camera errors map to reasons', () => {
    const named = (name) => Object.assign(new Error('x'), {name: name});
    assert.equal(cameraErrorReason(new Error('insecure')), 'insecure');
    assert.equal(cameraErrorReason(named('NotAllowedError')), 'denied');
    assert.equal(cameraErrorReason(named('SecurityError')), 'denied');
    assert.equal(cameraErrorReason(named('NotFoundError')), 'nocamera');
    assert.equal(cameraErrorReason(named('OverconstrainedError')), 'nocamera');
    assert.equal(cameraErrorReason(new Error('notrack')), 'nocamera');
    assert.equal(cameraErrorReason(named('NotReadableError')), 'busy');
    assert.equal(cameraErrorReason(named('AbortError')), 'busy');
    assert.equal(cameraErrorReason(named('TypeError')), 'error');
    assert.equal(cameraErrorReason(null), 'error');
    assert.equal(cameraErrorReason('text'), 'error');
});

test('a video is shown only when allowed, flowing, announced and not hidden', () => {
    const stream = {id: 's'};
    const base = {stream: stream, flowing: true, cam: true, hidden: false};
    assert.equal(isVideoShown(base, true), true);
    assert.equal(isVideoShown(base, false), false);
    assert.equal(isVideoShown(Object.assign({}, base, {stream: null}), true), false);
    assert.equal(isVideoShown(Object.assign({}, base, {flowing: false}), true), false);
    assert.equal(isVideoShown(Object.assign({}, base, {cam: false}), true), false);
    assert.equal(isVideoShown(Object.assign({}, base, {hidden: true}), true), false);
    assert.equal(isVideoShown(null, true), false);
});

test('the book reports each show and hide once', () => {
    const {book, changes} = recorder(true);
    const stream = {id: 's'};
    book.setTrack(PEER, stream, false);
    assert.deepEqual(changes, [], 'track without frames');
    book.setCam(PEER, true);
    assert.deepEqual(changes, [], 'camera on but no frames yet');
    book.setTrack(PEER, stream, true);
    assert.deepEqual(changes, [[PEER, stream]], 'frames arrive: shown');
    book.setCam(PEER, true);
    book.setTrack(PEER, stream, true);
    assert.equal(changes.length, 1, 'no repeat');
    // Camera off: the state message hides it before the track mutes.
    book.setCam(PEER, false);
    assert.deepEqual(changes[1], [PEER, null]);
    book.setTrack(PEER, stream, false);
    assert.equal(changes.length, 2);
    // On again, seamless: same stream, unmuted.
    book.setCam(PEER, true);
    book.setTrack(PEER, stream, true);
    assert.deepEqual(changes[2], [PEER, stream]);
    assert.deepEqual(book.shown(), [{peerid: PEER, stream: stream}]);
    assert.equal(book.shownStream(PEER), stream);
    assert.equal(book.isCamOn(PEER), true);
});

test('hidden for me hides and shows again, and survives the track coming back', () => {
    const {book, changes} = recorder(true);
    const stream = {id: 's'};
    book.setCam(PEER, true);
    book.setTrack(PEER, stream, true);
    book.setHidden(PEER, true);
    assert.deepEqual(changes, [[PEER, stream], [PEER, null]]);
    assert.equal(book.isHidden(PEER), true);
    // The link is re-created: the track goes and comes back, still hidden.
    book.setTrack(PEER, null, false);
    const again = {id: 't'};
    book.setTrack(PEER, again, true);
    assert.equal(changes.length, 2);
    book.setHidden(PEER, false);
    assert.deepEqual(changes[2], [PEER, again]);
    assert.equal(book.isHidden(PEER), false);
});

test('not allowed: nothing is shown; allowing shows what is ready', () => {
    const {book, changes} = recorder(false);
    const stream = {id: 's'};
    book.setCam(PEER, true);
    book.setTrack(PEER, stream, true);
    assert.deepEqual(changes, []);
    book.setAllowed(true);
    assert.deepEqual(changes, [[PEER, stream]]);
    book.setAllowed(false);
    assert.deepEqual(changes[1], [PEER, null]);
});

test('leaving people and clearing report shown videos as gone', () => {
    const {book, changes} = recorder(true);
    const a = {id: 'a'};
    const b = {id: 'b'};
    book.setCam(PEER, true);
    book.setTrack(PEER, a, true);
    book.setCam(OTHER, true);
    book.setTrack(OTHER, b, true);
    book.setHidden(OTHER, true);
    changes.length = 0;
    book.remove(PEER);
    assert.deepEqual(changes, [[PEER, null]]);
    book.remove(PEER);
    assert.equal(changes.length, 1, 'removing twice reports once');
    book.clear();
    assert.equal(changes.length, 1, 'a hidden video was not shown, so nothing to report');
    assert.equal(book.isHidden(OTHER), false, 'clear forgets hidden for me');
    assert.deepEqual(book.shown(), []);
});

test('the focused per-person button is found by action and peer id', () => {
    const actions = ['vrroom-live-mutepeer', 'vrroom-live-hidevideo'];
    assert.deepEqual(peerActionOf(button('vrroom-live-hidevideo', PEER), actions),
        {peerid: PEER, action: 'vrroom-live-hidevideo'});
    assert.equal(peerActionOf(button('vrroom-live-mic', PEER), actions), null);
    assert.equal(peerActionOf(button('vrroom-live-hidevideo', 'not-a-peer"]'), actions), null);
    assert.equal(peerActionOf(null, actions), null);
    assert.equal(peerActionOf({dataset: {action: 'vrroom-live-hidevideo'}}, actions), null);
});

test('the state message carries cam only while the camera is on', () => {
    const state = {t: 's', m: '3d', sc: 'scene-1', mic: 'on'};
    assert.deepEqual(decode('state', encode('state', Object.assign({}, state, {cam: true}))),
        Object.assign({}, state, {cam: true}));
    assert.deepEqual(decode('state', encode('state', Object.assign({}, state, {cam: false}))), state,
        'cam false is left out');
    assert.deepEqual(decode('state', encode('state', state)), state, 'older browsers: no cam');
    assert.equal(encode('state', Object.assign({}, state, {cam: 'yes'})), null);
    assert.equal(decode('state', JSON.stringify(Object.assign({}, state, {cam: 1}))), null);
});
