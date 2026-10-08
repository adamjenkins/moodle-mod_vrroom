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
 * Jitter buffer and interpolation for the poses of the other people in a live room.
 *
 * Poses arrive about 12 times a second over a lossy channel. The receiver shows them a little in
 * the past (the delay) and interpolates between the two samples around that time: positions
 * linearly, rotations with a spherical interpolation on the shortest path.
 *
 * A pose is an array of 7 numbers: position x, y, z and quaternion x, y, z, w.
 *
 * This module is pure: it has no imports, so node's test runner can load it.
 *
 * @module     mavrruntime_builtin/live/interpolation
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How many clock offsets the minimum is taken over. */
const OFFSET_SAMPLES = 20;

/** A sequence number this far below the newest one means the sender started counting again. */
const SEQ_RESTART = 1000;

/** Above this dot product two quaternions are interpolated linearly (then normalised). */
const SLERP_LINEAR = 0.9995;

/**
 * Element-wise linear interpolation.
 *
 * @param {number[]} a From.
 * @param {number[]} b To (same length as a).
 * @param {number} t 0..1 (not clamped).
 * @returns {number[]} A new array.
 */
export const lerp = (a, b, t) => a.map((value, i) => value + (b[i] - value) * t);

/**
 * Normalise a quaternion; the identity when its length is 0 or not finite.
 *
 * @param {number[]} q [x, y, z, w]
 * @returns {number[]} A new array.
 */
const normalise = (q) => {
    const length = Math.sqrt(q[0] * q[0] + q[1] * q[1] + q[2] * q[2] + q[3] * q[3]);
    if (!(length > 0) || !Number.isFinite(length)) {
        return [0, 0, 0, 1];
    }
    return [q[0] / length, q[1] / length, q[2] / length, q[3] / length];
};

/**
 * Spherical linear interpolation between two quaternions on the shortest path.
 *
 * @param {number[]} qa From [x, y, z, w].
 * @param {number[]} qb To [x, y, z, w].
 * @param {number} t 0..1 (clamped).
 * @returns {number[]} A new, normalised quaternion.
 */
export const slerp = (qa, qb, t) => {
    const s = Math.min(1, Math.max(0, Number(t) || 0));
    const a = normalise(qa);
    let b = normalise(qb);
    let dot = a[0] * b[0] + a[1] * b[1] + a[2] * b[2] + a[3] * b[3];
    if (dot < 0) {
        // The same rotation the short way round.
        b = [-b[0], -b[1], -b[2], -b[3]];
        dot = -dot;
    }
    if (dot > SLERP_LINEAR) {
        return normalise(lerp(a, b, s));
    }
    const theta = Math.acos(Math.min(1, dot));
    const sin = Math.sin(theta);
    const wa = Math.sin((1 - s) * theta) / sin;
    const wb = Math.sin(s * theta) / sin;
    return normalise([
        a[0] * wa + b[0] * wb,
        a[1] * wa + b[1] * wb,
        a[2] * wa + b[2] * wb,
        a[3] * wa + b[3] * wb,
    ]);
};

/**
 * Whether a value is a pose: 7 finite numbers.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isPose = (value) => Array.isArray(value) && value.length === 7
    && value.every((v) => typeof v === 'number' && Number.isFinite(v));

/**
 * Interpolate between two poses.
 *
 * @param {number[]} a From.
 * @param {number[]} b To.
 * @param {number} t 0..1.
 * @returns {number[]} A new pose.
 */
const mixPose = (a, b, t) => lerp(a.slice(0, 3), b.slice(0, 3), t).concat(slerp(a.slice(3, 7), b.slice(3, 7), t));

/**
 * A copy of a stored sample's poses.
 *
 * @param {Object} s Stored sample.
 * @returns {Object} {h, l, r}
 */
const copyOf = (s) => ({h: s.h.slice(), l: s.l ? s.l.slice() : null, r: s.r ? s.r.slice() : null});

/**
 * Create the jitter buffer of one remote peer.
 *
 * Options: delay (ms behind the newest sample time, default 120), maxsamples (buffer length,
 * default 20), holdafter (ms: samples older than this before the render time are dropped from
 * the buffer once a newer pair brackets the render time, default 1000).
 *
 * The sender's clock is not the receiver's. For each sample the receiver notes (now - ts); the
 * smallest of the last 20 is the clock offset (the sample that travelled fastest), so the render
 * time on the sender's clock is now - offset - delay.
 *
 * @param {Object} options Options.
 * @returns {Object} Interpolator {push(sample, now), sample(now), lastReceived(), reset()}
 */
export const createInterpolator = (options = {}) => {
    const delay = Number.isFinite(Number(options.delay)) ? Math.max(0, Number(options.delay)) : 120;
    const maxsamples = Number.isFinite(Number(options.maxsamples)) ? Math.max(2, Math.floor(Number(options.maxsamples))) : 20;
    const holdafter = Number.isFinite(Number(options.holdafter)) ? Math.max(0, Number(options.holdafter)) : 1000;

    let samples = [];
    let offsets = [];
    let lastseq = null;
    let received = 0;

    const offset = () => Math.min.apply(null, offsets);

    // Drop samples that can no longer be used: keep the newest one at or before the render time.
    const prune = (rendertime) => {
        while (samples.length > 2 && samples[1].ts <= rendertime && samples[0].ts < rendertime - holdafter) {
            samples.shift();
        }
    };

    const api = {
        /**
         * Add a received sample.
         *
         * @param {Object} sample {seq, ts, h: number[7], l: number[7]|null, r: number[7]|null}
         * @param {number} now Local time (ms).
         * @returns {boolean} Whether the sample was kept (false: out of order or invalid).
         */
        push(sample, now) {
            if (!sample || !isPose(sample.h) || !Number.isFinite(Number(sample.seq)) || !Number.isFinite(Number(sample.ts)) ||
                    !Number.isFinite(Number(now))) {
                return false;
            }
            const seq = Number(sample.seq);
            if (lastseq !== null && seq <= lastseq) {
                if (lastseq - seq < SEQ_RESTART) {
                    return false;
                }
                // The sender started again (a new page with the same peer): start again too.
                api.reset();
            }
            lastseq = seq;
            const ts = Number(sample.ts);
            const stored = {
                seq: seq,
                ts: ts,
                h: sample.h.slice(),
                l: isPose(sample.l) ? sample.l.slice() : null,
                r: isPose(sample.r) ? sample.r.slice() : null,
            };
            // Usually appended; a sender clock that stepped back is sorted in.
            let index = samples.length;
            while (index > 0 && samples[index - 1].ts > ts) {
                index--;
            }
            samples.splice(index, 0, stored);
            while (samples.length > maxsamples) {
                samples.shift();
            }
            offsets.push(Number(now) - ts);
            while (offsets.length > OFFSET_SAMPLES) {
                offsets.shift();
            }
            received = Number(now);
            return true;
        },

        /**
         * The pose to show now.
         *
         * Before the first pair: the first sample. After the last sample: the last one, held.
         * Hands are null unless present in both bracketing samples.
         *
         * @param {number} now Local time (ms).
         * @returns {Object|null} {h, l, r} or null before any sample.
         */
        sample(now) {
            if (!samples.length) {
                return null;
            }
            const rendertime = Number(now) - offset() - delay;
            const first = samples[0];
            const last = samples[samples.length - 1];
            if (!(rendertime > first.ts)) {
                return copyOf(first);
            }
            if (rendertime >= last.ts) {
                prune(rendertime);
                return copyOf(last);
            }
            let i = 0;
            while (i < samples.length - 2 && samples[i + 1].ts <= rendertime) {
                i++;
            }
            const a = samples[i];
            const b = samples[i + 1];
            const span = b.ts - a.ts;
            const t = span > 0 ? Math.min(1, Math.max(0, (rendertime - a.ts) / span)) : 1;
            const result = {
                h: mixPose(a.h, b.h, t),
                l: a.l && b.l ? mixPose(a.l, b.l, t) : null,
                r: a.r && b.r ? mixPose(a.r, b.r, t) : null,
            };
            prune(rendertime);
            return result;
        },

        /**
         * The local time of the newest kept sample.
         *
         * @returns {number} ms, or 0 before any sample.
         */
        lastReceived() {
            return received;
        },

        /** Forget every sample and the clock offset. */
        reset() {
            samples = [];
            offsets = [];
            lastseq = null;
            received = 0;
        },
    };
    return api;
};
