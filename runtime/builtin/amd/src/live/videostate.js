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
 * Webcam bookkeeping of a live room, without any browser API: which person's video is shown on
 * their avatar, why a camera could not be started, and which per-person button had the focus.
 *
 * A person's video is shown when all of these hold:
 * - video is allowed in this room (site and activity setting, live config 'video');
 * - their video track is there and flowing (live and not muted);
 * - their last state message says their camera is on (cam: true), so a camera that was just
 *   turned off fades out at once instead of freezing on the last frame until the track mutes;
 * - this user has not hidden that person's video ("Hide video for me").
 *
 * Dependency-free ES module (no imports) so that both grunt (AMD build) and node --test load it.
 *
 * @module     vrroomruntime_builtin/live/videostate
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** A peer id: 32 hex characters (server format). */
const PEERID = /^[0-9a-f]{32}$/;

/** Camera constraints: small and cheap for a mesh of up to 6 (about 320×240 at 15 fps). */
export const CAMERA_CONSTRAINTS = Object.freeze({
    width: Object.freeze({ideal: 320}),
    height: Object.freeze({ideal: 240}),
    frameRate: Object.freeze({ideal: 15, max: 15}),
    facingMode: 'user',
});

/** Most bits per second sent for the video of one link. */
export const VIDEO_MAX_BITRATE = 150000;

/** Most frames per second sent for the video of one link. */
export const VIDEO_MAX_FRAMERATE = 15;

/**
 * Why a camera could not be started, from the error getUserMedia (or enableCam) rejected with.
 *
 * @param {*} error The error.
 * @returns {string} 'insecure', 'denied', 'nocamera', 'busy' or 'error'.
 */
export const cameraErrorReason = (error) => {
    const name = error && typeof error.name === 'string' ? error.name : '';
    const message = error && typeof error.message === 'string' ? error.message : '';
    if (message === 'insecure') {
        return 'insecure';
    }
    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
        return 'denied';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError'
            || message === 'notrack') {
        return 'nocamera';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
        return 'busy';
    }
    return 'error';
};

/**
 * Whether a person's video is shown.
 *
 * @param {Object} entry {flowing: boolean, stream: *, cam: boolean, hidden: boolean}
 * @param {boolean} allowed Video is allowed in this room.
 * @returns {boolean}
 */
export const isVideoShown = (entry, allowed) => !!(allowed && entry && entry.stream && entry.flowing && entry.cam
    && !entry.hidden);

/**
 * The peer id of a per-person button that has the focus, when it is one of the given actions.
 *
 * @param {Object|null} element The focused element (anything with dataset and closest()).
 * @param {string[]} actions data-action values.
 * @returns {{peerid: string, action: string}|null}
 */
export const peerActionOf = (element, actions) => {
    if (!element || !element.dataset || actions.indexOf(element.dataset.action) < 0
            || typeof element.closest !== 'function') {
        return null;
    }
    const item = element.closest('[data-peerid]');
    const peerid = item && item.dataset ? String(item.dataset.peerid || '') : '';
    return PEERID.test(peerid) ? {peerid: peerid, action: element.dataset.action} : null;
};

/**
 * The per-person video records of a room. Every change that shows or hides someone's video calls
 * onChange(peerid, stream|null) once.
 */
export class VideoBook {
    /**
     * Constructor.
     *
     * @param {Function} onChange (peerid, stream|null) => void
     * @param {boolean} [allowed] Video is allowed in this room.
     */
    constructor(onChange, allowed) {
        this.onChange = typeof onChange === 'function' ? onChange : () => undefined;
        this.allowed = allowed !== false;
        /** @type {Map<string, Object>} peer id → {stream, flowing, cam, hidden, shown} */
        this.entries = new Map();
        /** @type {Set<string>} people hidden for me (kept while they reconnect) */
        this.hidden = new Set();
    }

    /**
     * The record of a person, made when missing.
     *
     * @param {string} peerid Peer id.
     * @returns {Object}
     */
    entry(peerid) {
        let entry = this.entries.get(peerid);
        if (!entry) {
            entry = {stream: null, flowing: false, cam: false, hidden: this.hidden.has(peerid), shown: null};
            this.entries.set(peerid, entry);
        }
        return entry;
    }

    /**
     * Recompute one person and report a change.
     *
     * @param {string} peerid Peer id.
     */
    update(peerid) {
        const entry = this.entries.get(peerid);
        if (!entry) {
            return;
        }
        const shown = isVideoShown(entry, this.allowed) ? entry.stream : null;
        if (shown !== entry.shown) {
            entry.shown = shown;
            this.onChange(peerid, shown);
        }
    }

    /**
     * Allow or forbid video in this room.
     *
     * @param {boolean} allowed Allowed.
     */
    setAllowed(allowed) {
        this.allowed = !!allowed;
        Array.from(this.entries.keys()).forEach((peerid) => this.update(peerid));
    }

    /**
     * A person's video track arrived, changed or went (stream null).
     *
     * @param {string} peerid Peer id.
     * @param {*} stream MediaStream with the video track, or null.
     * @param {boolean} flowing The track is live and not muted.
     */
    setTrack(peerid, stream, flowing) {
        const entry = this.entry(peerid);
        entry.stream = stream || null;
        entry.flowing = !!(stream && flowing);
        this.update(peerid);
    }

    /**
     * A person's camera state from their state message.
     *
     * @param {string} peerid Peer id.
     * @param {boolean} on Their camera is on.
     */
    setCam(peerid, on) {
        const entry = this.entry(peerid);
        entry.cam = !!on;
        this.update(peerid);
    }

    /**
     * Hide or show a person's video for me.
     *
     * @param {string} peerid Peer id.
     * @param {boolean} hidden Hidden.
     */
    setHidden(peerid, hidden) {
        if (hidden) {
            this.hidden.add(peerid);
        } else {
            this.hidden.delete(peerid);
        }
        const entry = this.entry(peerid);
        entry.hidden = !!hidden;
        this.update(peerid);
    }

    /**
     * Whether a person's video is hidden for me.
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isHidden(peerid) {
        return this.hidden.has(peerid);
    }

    /**
     * Whether a person's camera is on (their last state message).
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isCamOn(peerid) {
        const entry = this.entries.get(peerid);
        return !!(entry && entry.cam);
    }

    /**
     * The stream shown for a person, or null.
     *
     * @param {string} peerid Peer id.
     * @returns {*}
     */
    shownStream(peerid) {
        const entry = this.entries.get(peerid);
        return entry ? entry.shown : null;
    }

    /**
     * Every person whose video is shown now.
     *
     * @returns {Array<{peerid: string, stream: *}>}
     */
    shown() {
        const list = [];
        this.entries.forEach((entry, peerid) => {
            if (entry.shown) {
                list.push({peerid: peerid, stream: entry.shown});
            }
        });
        return list;
    }

    /**
     * Forget a person (they left); a shown video is reported as gone.
     *
     * @param {string} peerid Peer id.
     */
    remove(peerid) {
        const entry = this.entries.get(peerid);
        if (!entry) {
            return;
        }
        this.entries.delete(peerid);
        if (entry.shown) {
            this.onChange(peerid, null);
        }
    }

    /**
     * Forget everybody (left the room). "Hidden for me" is forgotten too.
     */
    clear() {
        Array.from(this.entries.keys()).forEach((peerid) => this.remove(peerid));
        this.hidden.clear();
    }
}

/**
 * Create the video records of a room.
 *
 * @param {Function} onChange (peerid, stream|null) => void
 * @param {boolean} [allowed] Video is allowed in this room.
 * @returns {VideoBook}
 */
export const createVideoBook = (onChange, allowed) => new VideoBook(onChange, allowed);
