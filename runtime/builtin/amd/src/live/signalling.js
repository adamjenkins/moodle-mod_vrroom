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
 * Live-room signalling through Moodle web services (join, poll loop, batched send, leave).
 *
 * The poll is also the presence heartbeat: the server drops a peer that has not polled for
 * 20 seconds. The server chooses the poll interval; this client clamps it to 1..10 s and backs
 * off after network errors. Poll and send pass nosessionupdate, so an abandoned tab does not keep
 * the login alive. The server never trusts anything sent here beyond the peer id it issued.
 *
 * @module     vrroomruntime_builtin/live/signalling
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Config from 'core/config';
import Log from 'core/log';

/** Web-service request timeout in ms. */
const REQUEST_TIMEOUT = 10000;

/** Smallest and largest poll interval in ms. */
const MIN_INTERVAL = 1000;
const MAX_INTERVAL = 10000;

/** Retry delays after consecutive network errors, in ms (the last one repeats). */
const BACKOFF = Object.freeze([2000, 4000, 8000, 15000]);

/** Delay before queued signals are sent, in ms. */
const FLUSH_DELAY = 50;

/** Signals per send call (server MAX_SIGNALS_PER_CALL). */
const MAX_PER_CALL = 10;

/** Consecutive poll errors after which the error callback reports trouble. */
export const RECONNECTING_AFTER = 2;

/** Highest live room number (server room_manager::MAX_ROOM_NUMBER). */
const MAX_ROOM_NUMBER = 9999;

/**
 * Call one web service.
 *
 * @param {string} methodname Function name.
 * @param {Object} args Arguments.
 * @param {boolean} nosessionupdate Whether the call must not extend the session.
 * @returns {Promise}
 */
const call = (methodname, args, nosessionupdate) => Promise.resolve(
    Ajax.call([{methodname: methodname, args: args}], true, true, nosessionupdate, REQUEST_TIMEOUT)[0]
);

/**
 * Turn a web-service rejection into an Error carrying an errorcode.
 *
 * Moodle exceptions arrive as objects with an errorcode; anything else is a network failure.
 *
 * @param {*} exception The rejection value.
 * @returns {Error} With errorcode ('network' for network failures) and cause.
 */
const toError = (exception) => {
    const code = exception && typeof exception === 'object' && typeof exception.errorcode === 'string'
        ? exception.errorcode : 'network';
    const message = exception && typeof exception === 'object' && typeof exception.message === 'string'
        ? exception.message : code;
    const error = new Error(message);
    error.errorcode = code;
    error.cause = exception;
    return error;
};

/**
 * Clamp a server interval.
 *
 * @param {*} interval Milliseconds from the server.
 * @returns {number}
 */
const clampInterval = (interval) => {
    const value = Number(interval);
    if (!isFinite(value)) {
        return MAX_INTERVAL;
    }
    return Math.min(MAX_INTERVAL, Math.max(MIN_INTERVAL, Math.round(value)));
};

/**
 * Clean a room number: a whole number 0..MAX_ROOM_NUMBER (0 starts a new room), or null.
 *
 * @param {*} value The value.
 * @returns {number|null}
 */
const cleanRoom = (value) => {
    const room = Number(value);
    if (value === undefined || value === null || value === '' || !isFinite(room) || room < 0) {
        return null;
    }
    return Math.min(MAX_ROOM_NUMBER, Math.floor(room));
};

/**
 * Parse the payload strings of polled signals; signals whose payload is not a JSON object are
 * dropped.
 *
 * @param {Array} signals Signals from live_poll.
 * @returns {Array<{from: string, type: string, payload: Object}>}
 */
const parseSignals = (signals) => {
    const out = [];
    (Array.isArray(signals) ? signals : []).forEach((signal) => {
        if (!signal || typeof signal.from !== 'string' || typeof signal.type !== 'string'
                || typeof signal.payload !== 'string') {
            return;
        }
        let payload;
        try {
            payload = JSON.parse(signal.payload);
        } catch (e) {
            return;
        }
        if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
            return;
        }
        out.push({from: signal.from, type: signal.type, payload: payload});
    });
    return out;
};

/**
 * Signalling client for one live-room presence.
 */
class Signalling {
    /**
     * Constructor.
     *
     * @param {Object} liveconfig The launch config live block (cmid, attemptid, defaultgroupid, and
     *     room: the room number to join, 0 to start a new room).
     */
    constructor(liveconfig) {
        this.cmid = Number(liveconfig.cmid) || 0;
        this.attemptid = Number(liveconfig.attemptid) || 0;
        this.defaultgroupid = Number(liveconfig.defaultgroupid) || 0;
        const room = cleanRoom(liveconfig.room);
        this.room = room === null ? 0 : room;
        this.peerid = null;
        this.interval = MIN_INTERVAL;
        this.running = false;
        this.token = 0;
        this.timer = null;
        this.onpoll = null;
        this.onerror = null;
        this.failures = 0;
        this.refreshice = false;
        this.queue = [];
        this.flushtimer = null;
        this.sending = false;
        this.senderrorhandlers = [];
    }

    /**
     * The peer id issued by the server, or null.
     *
     * @returns {string|null}
     */
    getPeerId() {
        return this.peerid;
    }

    /**
     * Join a room. Stops a running loop first.
     *
     * The server checks the group, the room number and the room's capacity again; nothing sent
     * here is trusted.
     *
     * @param {number} [groupid] Group whose rooms to use; the default group when left out or 0.
     * @param {number} [room] Room number; 0 starts a new room; the configured room when left out.
     * @returns {Promise<Object>} The live_join result; rejects with an Error carrying errorcode.
     */
    join(groupid, room) {
        this.stop();
        this.peerid = null;
        const group = Number(groupid);
        const number = cleanRoom(room);
        if (number !== null) {
            this.room = number;
        }
        const args = {
            cmid: this.cmid,
            attemptid: this.attemptid,
            groupid: isFinite(group) && group > 0 ? Math.floor(group) : this.defaultgroupid,
            room: this.room,
        };
        return call('vrroomruntime_builtin_live_join', args, false).then((result) => {
            this.peerid = result.peerid;
            this.interval = clampInterval(result.interval);
            return result;
        }, (exception) => {
            throw toError(exception);
        });
    }

    /**
     * Start the poll loop. The first poll runs after the join interval.
     *
     * @param {Function} onPoll Called with every poll result ({status, interval, peers, signals,
     *     iceservers, iceexpires}); signal payloads are already parsed. A 'gone' or 'closed' status
     *     stops the loop before onPoll is called.
     * @param {Function} [onError] Called with (consecutive failures, Error) after a failed poll.
     */
    start(onPoll, onError) {
        if (!this.peerid) {
            return;
        }
        this.onpoll = onPoll;
        this.onerror = typeof onError === 'function' ? onError : null;
        this.running = true;
        this.failures = 0;
        this.token++;
        this.schedule(this.interval);
    }

    /**
     * Schedule the next poll.
     *
     * @param {number} delay Milliseconds.
     */
    schedule(delay) {
        if (this.timer) {
            clearTimeout(this.timer);
        }
        const token = this.token;
        this.timer = setTimeout(() => {
            this.timer = null;
            this.poll(token);
        }, delay);
    }

    /**
     * Run one poll.
     *
     * @param {number} token Loop token; a stale token means the loop was stopped or restarted.
     */
    poll(token) {
        if (!this.running || token !== this.token || !this.peerid) {
            return;
        }
        const refreshice = this.refreshice;
        this.refreshice = false;
        call('vrroomruntime_builtin_live_poll', {peerid: this.peerid, refreshice: refreshice}, true).then((result) => {
            if (!this.running || token !== this.token) {
                return;
            }
            this.failures = 0;
            const status = result && typeof result.status === 'string' ? result.status : 'gone';
            const response = {
                status: status,
                interval: clampInterval(result ? result.interval : MAX_INTERVAL),
                peers: result && Array.isArray(result.peers) ? result.peers : [],
                signals: parseSignals(result ? result.signals : []),
                iceservers: result && Array.isArray(result.iceservers) ? result.iceservers : [],
                iceexpires: result ? Number(result.iceexpires) || 0 : 0,
            };
            if (status !== 'ok') {
                this.running = false;
                this.token++;
                this.peerid = null;
                this.dropQueue();
            } else {
                this.schedule(response.interval);
            }
            try {
                this.onpoll(response);
            } catch (error) {
                Log.error(error);
            }
            return;
        }, (exception) => {
            if (!this.running || token !== this.token) {
                return;
            }
            if (refreshice) {
                this.refreshice = true;
            }
            this.failures++;
            this.schedule(BACKOFF[Math.min(this.failures, BACKOFF.length) - 1]);
            if (this.onerror) {
                try {
                    this.onerror(this.failures, toError(exception));
                } catch (error) {
                    Log.error(error);
                }
            }
        }).catch((error) => Log.error(error));
    }

    /**
     * Queue a signal for a peer of the room.
     *
     * @param {string} to Target peer id.
     * @param {string} type 'description' or 'candidates'.
     * @param {Object} payload Payload object (sent as JSON).
     */
    send(to, type, payload) {
        if (!this.peerid) {
            return;
        }
        let json;
        try {
            json = JSON.stringify(payload);
        } catch (e) {
            this.reportSendError({to: to, type: type, error: 'invalid'});
            return;
        }
        this.queue.push({to: to, type: type, payload: json});
        if (!this.flushtimer && !this.sending) {
            this.flushtimer = setTimeout(() => this.flush(), FLUSH_DELAY);
        }
    }

    /**
     * Send queued signals, at most MAX_PER_CALL per call and one call at a time (so that a
     * description always reaches the server before the candidates that follow it).
     */
    flush() {
        this.flushtimer = null;
        if (this.sending || !this.queue.length || !this.peerid) {
            return;
        }
        const batch = this.queue.splice(0, MAX_PER_CALL);
        const peerid = this.peerid;
        this.sending = true;
        call('vrroomruntime_builtin_live_send', {peerid: peerid, signals: batch}, true).then((result) => {
            if (!result || result.status !== 'ok') {
                const status = result && typeof result.status === 'string' ? result.status : 'gone';
                batch.forEach((signal) => this.reportSendError({to: signal.to, type: signal.type, error: status}));
                if (this.peerid === peerid) {
                    this.dropQueue();
                }
                return;
            }
            (Array.isArray(result.results) ? result.results : []).forEach((item, index) => {
                if (item && !item.accepted && batch[index]) {
                    this.reportSendError({to: batch[index].to, type: batch[index].type, error: item.error || 'invalid'});
                }
            });
            return;
        }, () => {
            batch.forEach((signal) => this.reportSendError({to: signal.to, type: signal.type, error: 'network'}));
        }).catch((error) => Log.error(error)).then(() => {
            this.sending = false;
            if (this.queue.length && this.peerid && !this.flushtimer) {
                this.flushtimer = setTimeout(() => this.flush(), 0);
            }
            return;
        }).catch((error) => Log.error(error));
    }

    /**
     * Register a handler for signals the server refused or that could not be sent.
     *
     * @param {Function} fn Called with {to, type, error}; error one of 'unknownpeer', 'invalid',
     *     'toolarge', 'ratelimited', 'mailboxfull', 'gone', 'closed', 'network'.
     * @returns {Function} Call it to remove the handler.
     */
    onSendError(fn) {
        if (typeof fn !== 'function') {
            return () => undefined;
        }
        this.senderrorhandlers.push(fn);
        return () => {
            const index = this.senderrorhandlers.indexOf(fn);
            if (index !== -1) {
                this.senderrorhandlers.splice(index, 1);
            }
        };
    }

    /**
     * Tell every send-error handler.
     *
     * @param {Object} detail {to, type, error}.
     */
    reportSendError(detail) {
        this.senderrorhandlers.slice().forEach((fn) => {
            try {
                fn(detail);
            } catch (error) {
                Log.error(error);
            }
        });
    }

    /**
     * Ask for fresh ICE servers (TURN credentials) with the next poll.
     */
    refreshIce() {
        this.refreshice = true;
    }

    /**
     * Leave the room and stop the loop. Never rejects.
     *
     * @returns {Promise<void>}
     */
    leave() {
        const peerid = this.peerid;
        this.stop();
        this.peerid = null;
        if (!peerid) {
            return Promise.resolve();
        }
        return call('vrroomruntime_builtin_live_leave', {peerid: peerid}, false).then(() => undefined, (exception) => {
            Log.debug(exception);
        });
    }

    /**
     * Best-effort leave while the page unloads (pagehide); synchronous, no answer awaited. When it
     * does not arrive, the server drops the presence after 20 seconds.
     */
    leaveOnUnload() {
        const peerid = this.peerid;
        this.stop();
        this.peerid = null;
        if (!peerid) {
            return;
        }
        const url = Config.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(Config.sesskey)
            + '&info=vrroomruntime_builtin_live_leave';
        const body = JSON.stringify([{index: 0, methodname: 'vrroomruntime_builtin_live_leave', args: {peerid: peerid}}]);
        try {
            if (typeof fetch === 'function') {
                fetch(url, {
                    method: 'POST',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: body,
                }).catch(() => undefined);
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(url, new Blob([body], {type: 'application/json'}));
            }
        } catch (e) {
            // Unloading: nothing more can be done; the presence times out on the server.
        }
    }

    /**
     * Drop every queued signal.
     */
    dropQueue() {
        this.queue = [];
        if (this.flushtimer) {
            clearTimeout(this.flushtimer);
            this.flushtimer = null;
        }
    }

    /**
     * Stop the poll loop and drop queued signals (the peer id is kept).
     */
    stop() {
        this.running = false;
        this.token++;
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        this.dropQueue();
    }
}

/**
 * Fetch the live rooms of an activity (or of a group) with their occupancy. Read only; does not
 * extend the session.
 *
 * @param {number} cmid Course module id.
 * @param {number} [groupid] Group whose rooms to list; 0 for the default group.
 * @returns {Promise<Object>} The live_rooms result; rejects with an Error carrying errorcode.
 */
export const fetchRooms = (cmid, groupid) => {
    const group = Number(groupid);
    const args = {
        cmid: Number(cmid) || 0,
        groupid: isFinite(group) && group > 0 ? Math.floor(group) : 0,
    };
    return call('vrroomruntime_builtin_live_rooms', args, true).catch((exception) => {
        throw toError(exception);
    });
};

/**
 * Create a signalling client.
 *
 * @param {Object} liveconfig The launch config live block.
 * @returns {Signalling}
 */
export const createSignalling = (liveconfig) => new Signalling(liveconfig || {});
