// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Reading server answers for the live room: why a room was closed for someone, and the current
 * room size. No DOM and no Moodle modules, so it is tested under node (tests/js).
 *
 * @module     vrroomruntime_builtin/live/roomstatus
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Seconds after the deadline during which an attempt still counts as on time (attempt_manager::DEADLINE_GRACE). */
export const DEADLINE_GRACE = 5;

/** Peer ids are 32 lowercase hex characters (room_manager::is_peerid()). */
const PEERID = /^[0-9a-f]{32}$/;

/**
 * A whole number of at least 0 from a server value.
 *
 * @param {*} value The value.
 * @returns {number}
 */
const whole = (value) => {
    const number = Number(value);
    return isFinite(number) && number > 0 ? Math.floor(number) : 0;
};

/**
 * Whether an attempt state (mod_vrroom_get_attempt_state) says the attempt can no longer be in a
 * live room: finished, abandoned, or past its deadline (attempt_manager::is_overdue()).
 *
 * @param {Object|null} state The answer.
 * @returns {boolean} false when the answer says nothing.
 */
export const attemptEnded = (state) => {
    if (!state || typeof state !== 'object' || typeof state.state !== 'string' || state.state === '') {
        return false;
    }
    if (state.state !== 'inprogress') {
        return true;
    }
    const due = whole(state.timedue);
    const now = whole(state.servertime);
    return due > 0 && now > due + DEADLINE_GRACE;
};

/**
 * Whether a room one was in is no longer in a list of rooms because the teacher lowered the
 * number of fixed rooms (rooms students open just close when they are empty: not this).
 *
 * @param {Object|null} answer live_rooms answer {groupid, roommode, rooms}.
 * @param {Object|null} room {groupid, number} of the room one was in.
 * @returns {boolean}
 */
export const roomRemoved = (answer, room) => {
    if (!answer || !room || answer.roommode !== 'fixed' || !Array.isArray(answer.rooms)) {
        return false;
    }
    const number = whole(room.number);
    if (!number || whole(answer.groupid) !== whole(room.groupid)) {
        return false;
    }
    return !answer.rooms.some((r) => r && whole(r.number) === number);
};

/**
 * The room size a live_rooms answer gives (the teacher may have changed it since the page loaded).
 *
 * @param {Object|null} answer live_rooms answer {capacity, rooms}.
 * @param {number} [number] The room asked about; its own capacity wins when it is listed.
 * @returns {number} 0 when the answer says nothing.
 */
export const listCapacity = (answer, number) => {
    if (!answer || typeof answer !== 'object') {
        return 0;
    }
    const wanted = whole(number);
    if (wanted && Array.isArray(answer.rooms)) {
        const room = answer.rooms.find((r) => r && whole(r.number) === wanted);
        if (room && whole(room.capacity)) {
            return whole(room.capacity);
        }
    }
    return whole(answer.capacity);
};

/**
 * The peer id whose "Mute for me" button an element is, or '' (to give the focus back to the same
 * person's button after the list of people is drawn again).
 *
 * @param {Object|null} element The focused element (anything with dataset and closest()).
 * @returns {string}
 */
export const mutePeerOf = (element) => {
    if (!element || !element.dataset || element.dataset.action !== 'vrroom-live-mutepeer'
            || typeof element.closest !== 'function') {
        return '';
    }
    const item = element.closest('[data-peerid]');
    const peerid = item && item.dataset ? String(item.dataset.peerid || '') : '';
    return PEERID.test(peerid) ? peerid : '';
};
