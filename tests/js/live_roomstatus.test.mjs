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
 * Tests of the live room answer helpers (vrroomruntime_builtin/live/roomstatus).
 *
 * Run from the repository root: node --test tests/js/live_roomstatus.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {DEADLINE_GRACE, attemptEnded, roomRemoved, listCapacity, mutePeerOf}
    from '../../runtime/builtin/amd/src/live/roomstatus.js';

const PEERID = '0123456789abcdef0123456789abcdef';

/**
 * A stand-in for a focused element: dataset and closest('[data-peerid]').
 *
 * @param {string} action data-action of the element.
 * @param {string|null} peerid data-peerid of its line, or null for none.
 * @returns {Object}
 */
const element = (action, peerid) => ({
    dataset: {action: action},
    closest: () => (peerid === null ? null : {dataset: {peerid: peerid}}),
});

test('attemptEnded: finished, abandoned and overdue attempts have ended', () => {
    assert.equal(attemptEnded({state: 'finished'}), true);
    assert.equal(attemptEnded({state: 'abandoned'}), true);
    assert.equal(attemptEnded({state: 'inprogress', timedue: 0, servertime: 2000}), false);
    assert.equal(attemptEnded({state: 'inprogress', timedue: 1000, servertime: 1000 + DEADLINE_GRACE}), false);
    assert.equal(attemptEnded({state: 'inprogress', timedue: 1000, servertime: 1001 + DEADLINE_GRACE}), true);
});

test('attemptEnded: an answer that says nothing is not an ended attempt', () => {
    assert.equal(attemptEnded(null), false);
    assert.equal(attemptEnded(undefined), false);
    assert.equal(attemptEnded({}), false);
    assert.equal(attemptEnded({state: ''}), false);
    assert.equal(attemptEnded('finished'), false);
});

test('roomRemoved: a fixed room missing from the list of its group was removed', () => {
    const answer = {groupid: 4, roommode: 'fixed', rooms: [{number: 1}]};
    assert.equal(roomRemoved(answer, {groupid: 4, number: 2}), true);
    assert.equal(roomRemoved(answer, {groupid: 4, number: 1}), false);
    // Another group's list says nothing about the room.
    assert.equal(roomRemoved(answer, {groupid: 5, number: 2}), false);
});

test('roomRemoved: rooms students open, and missing data, are never "removed"', () => {
    assert.equal(roomRemoved({groupid: 0, roommode: 'dynamic', rooms: []}, {groupid: 0, number: 1}), false);
    assert.equal(roomRemoved(null, {groupid: 0, number: 1}), false);
    assert.equal(roomRemoved({groupid: 0, roommode: 'fixed', rooms: []}, null), false);
    assert.equal(roomRemoved({groupid: 0, roommode: 'fixed', rooms: []}, {groupid: 0, number: 0}), false);
    assert.equal(roomRemoved({groupid: 0, roommode: 'fixed'}, {groupid: 0, number: 1}), false);
});

test('listCapacity: the room asked about first, then the list, then nothing', () => {
    const answer = {capacity: 3, rooms: [{number: 1, capacity: 2}, {number: 2, capacity: 0}]};
    assert.equal(listCapacity(answer, 1), 2);
    assert.equal(listCapacity(answer, 2), 3);
    assert.equal(listCapacity(answer, 9), 3);
    assert.equal(listCapacity(answer, 0), 3);
    assert.equal(listCapacity(answer), 3);
    assert.equal(listCapacity(null, 1), 0);
    assert.equal(listCapacity({capacity: 'x'}, 1), 0);
});

test('mutePeerOf: only a mute button in a line with a valid peer id', () => {
    assert.equal(mutePeerOf(element('vrroom-live-mutepeer', PEERID)), PEERID);
    assert.equal(mutePeerOf(element('vrroom-live-leave', PEERID)), '');
    assert.equal(mutePeerOf(element('vrroom-live-mutepeer', null)), '');
    // Never anything that could break out of a selector.
    assert.equal(mutePeerOf(element('vrroom-live-mutepeer', '"] *, [x="')), '');
    assert.equal(mutePeerOf(element('vrroom-live-mutepeer', PEERID.toUpperCase())), '');
    assert.equal(mutePeerOf(null), '');
    assert.equal(mutePeerOf({dataset: {action: 'vrroom-live-mutepeer'}}), '');
});
