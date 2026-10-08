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
 * The live-room transport interface.
 *
 * Avatars, voice, the participant panel and the live controller talk only to this interface.
 * Phase 3 implements it as a browser-to-browser mesh (vrroomruntime_builtin/live/mesh); a later
 * companion service implements the same interface, so nothing above it changes.
 *
 * Events (detail objects):
 * - state       {state, reason}       state one of STATES; reason '', 'full', 'closed', 'replaced',
 *                                      'gone', 'network', 'disabled', 'noroom', 'busy',
 *                                      'notallowed', 'roomgone' (a room students opened has
 *                                      closed), 'limit' (no more rooms can be opened) or 'error'
 * - peerjoined  {peer}
 * - peerupdated {peer}
 * - peerleft    {peerid, reason}
 * - message     {peerid, channel, message}   message already decoded by the codec, never null
 * - track       {peerid, stream}
 * - trackended  {peerid}
 *
 * A Peer is {peerid: string, name: string, kind: 'attempt'|'preview', connected: boolean}.
 *
 * Dependency-free ES module (no imports) so that both grunt (AMD build) and node --test load it.
 *
 * @module     vrroomruntime_builtin/live/transport
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Event types a transport emits. */
export const EVENTS = Object.freeze(['state', 'peerjoined', 'peerupdated', 'peerleft', 'message', 'track', 'trackended']);

/** Transport states. */
export const STATES = Object.freeze(['idle', 'joining', 'joined', 'reconnecting', 'left', 'failed']);

/**
 * Report a listener error without throwing it.
 *
 * @param {*} error The error a listener threw.
 */
const report = (error) => {
    const con = typeof globalThis !== 'undefined' ? globalThis.console : undefined;
    if (con && typeof con.error === 'function') {
        con.error(error);
    }
};

/**
 * Minimal event emitter. Listener errors are caught and reported, never thrown.
 */
export class Emitter {
    /**
     * Constructor.
     */
    constructor() {
        /** @type {Map<string, Array<{fn: Function}>>} */
        this.listeners = new Map();
    }

    /**
     * Add a listener.
     *
     * @param {string} type Event type.
     * @param {Function} listener Called with the event detail.
     * @returns {Function} Call it to remove this listener again.
     */
    on(type, listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('Listener must be a function');
        }
        const entry = {fn: listener};
        if (!this.listeners.has(type)) {
            this.listeners.set(type, []);
        }
        this.listeners.get(type).push(entry);
        return () => {
            const list = this.listeners.get(type);
            if (!list) {
                return;
            }
            const index = list.indexOf(entry);
            if (index !== -1) {
                list.splice(index, 1);
            }
            if (!list.length) {
                this.listeners.delete(type);
            }
        };
    }

    /**
     * Call every listener of a type with the detail.
     *
     * @param {string} type Event type.
     * @param {*} detail Event detail.
     */
    emit(type, detail) {
        const list = this.listeners.get(type);
        if (!list) {
            return;
        }
        list.slice().forEach((entry) => {
            try {
                entry.fn(detail);
            } catch (error) {
                report(error);
            }
        });
    }

    /**
     * Remove every listener.
     */
    clear() {
        this.listeners.clear();
    }
}

/**
 * Throw for a method a concrete transport must implement.
 *
 * @returns {never}
 */
const notImplemented = () => {
    throw new Error('Not implemented');
};

/**
 * The transport interface. Concrete transports extend this class and implement every method.
 */
export class Transport extends Emitter {
    /**
     * Transport kind: 'mesh' or 'companion'.
     *
     * @returns {string}
     */
    get kind() {
        return notImplemented();
    }

    /**
     * What this transport can do.
     *
     * @returns {{voice: boolean, maxpeers: number}}
     */
    get capabilities() {
        return notImplemented();
    }

    /**
     * Join a room.
     *
     * A transport with several rooms per activity (the mesh) takes {groupid, room}: room is the
     * room number, 0 starts a new room; room.number in the result is the number actually joined.
     * A transport with one room ignores room.
     *
     * @param {number|{groupid: number, room: number}} [groupid] Group whose room to join (the default
     *     group when left out), or {groupid, room}.
     * @returns {Promise<{self: Object, room: {groupid: number, number: number, name: string, maxpeers: number},
     *     peers: Object[]}>}
     */
    join(groupid) { // eslint-disable-line no-unused-vars
        return notImplemented();
    }

    /**
     * Leave the room. Idempotent.
     *
     * @returns {Promise<void>}
     */
    leave() {
        return notImplemented();
    }

    /**
     * Best-effort synchronous leave while the page unloads.
     */
    leaveOnUnload() {
        notImplemented();
    }

    /**
     * The local peer while joined.
     *
     * @returns {Object|null}
     */
    getSelf() {
        return notImplemented();
    }

    /**
     * The other peers of the room.
     *
     * @returns {Object[]}
     */
    getPeers() {
        return notImplemented();
    }

    /**
     * Send a message to everybody; dropped while not connected.
     *
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message Plain message object.
     */
    broadcast(channel, message) { // eslint-disable-line no-unused-vars
        notImplemented();
    }

    /**
     * Send a message to one peer; dropped while not connected.
     *
     * @param {string} peerid Target peer.
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message Plain message object.
     */
    send(peerid, channel, message) { // eslint-disable-line no-unused-vars
        notImplemented();
    }

    /**
     * Set (or clear) the local audio track sent to everybody.
     *
     * @param {MediaStreamTrack|null} track The microphone track or null.
     * @returns {Promise<void>}
     */
    setLocalAudio(track) { // eslint-disable-line no-unused-vars
        return notImplemented();
    }

    /**
     * The remote audio stream of a peer.
     *
     * @param {string} peerid The peer.
     * @returns {MediaStream|null}
     */
    getRemoteStream(peerid) { // eslint-disable-line no-unused-vars
        return notImplemented();
    }

    /**
     * Release everything.
     */
    dispose() {
        notImplemented();
    }
}
