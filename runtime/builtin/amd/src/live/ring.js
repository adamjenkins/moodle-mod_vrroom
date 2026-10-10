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
 * Where the other people of a live room stand in a 360° photo room: everyone looks from the centre
 * of the photo, so each other person is drawn on a ring around it, at an angle from a stable hash
 * of their peer id (the same for every viewer), facing where they look. The positions they send
 * are not used.
 *
 * No imports at all, so it runs in node tests too (the hash is spawn.js's hashString, repeated).
 *
 * @module     vrroomruntime_builtin/live/ring
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Radius of the ring the others stand on (m). */
export const RING_RADIUS = 1.4;

/** Golden ratio conjugate: peer ids with close hashes still get angles far apart. */
const GOLDEN = 0.6180339887498949;

/**
 * A small, stable, non-negative hash of a string (djb2 below 2^31, as spawn.js hashString).
 *
 * @param {*} value The value (turned into a string).
 * @returns {number}
 */
export const hashString = (value) => {
    const str = String(value === null || value === undefined ? '' : value);
    let h = 5381;
    for (let i = 0; i < str.length; i++) {
        h = (h * 33 + str.charCodeAt(i)) % 2147483647;
    }
    return h;
};

/**
 * The angle of a peer on the ring, 0 to 2π, the same for every viewer.
 *
 * @param {string} peerid Peer id.
 * @returns {number} Radians.
 */
export const ringAngle = (peerid) => ((hashString(peerid) * GOLDEN) % 1) * 2 * Math.PI;

/**
 * The floor position of a peer on the ring: [radius·sin(angle), radius·cos(angle)].
 *
 * @param {string} peerid Peer id.
 * @param {number} [radius] Ring radius (m).
 * @returns {number[]} [x, z]
 */
export const ringPosition = (peerid, radius = RING_RADIUS) => {
    const angle = ringAngle(peerid);
    return [radius * Math.sin(angle), radius * Math.cos(angle)];
};

/**
 * A received pose moved onto the ring: the head goes to the peer's ring position (its height and
 * its rotation, so where the person looks, are kept), and the hands move with it.
 *
 * Pose parts are [x, y, z, qx, qy, qz, qw] arrays (h head, l and r hands, null when absent).
 *
 * @param {Object} pose {h, l, r, ...} as the interpolator gives it.
 * @param {string} peerid Peer id.
 * @param {number} [radius] Ring radius (m).
 * @returns {Object} a new pose with the same other fields
 */
export const ringPose = (pose, peerid, radius = RING_RADIUS) => {
    if (!pose || !Array.isArray(pose.h) || pose.h.length < 3) {
        return pose;
    }
    const [x, z] = ringPosition(peerid, radius);
    const dx = x - (Number(pose.h[0]) || 0);
    const dz = z - (Number(pose.h[2]) || 0);
    const move = (part) => {
        if (!Array.isArray(part) || part.length < 3) {
            return part;
        }
        const moved = part.slice();
        moved[0] = (Number(part[0]) || 0) + dx;
        moved[2] = (Number(part[2]) || 0) + dz;
        return moved;
    };
    return Object.assign({}, pose, {h: move(pose.h), l: move(pose.l), r: move(pose.r)});
};
