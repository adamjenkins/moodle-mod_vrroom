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
 * Placement by direction in 360° photo rooms: an item's {yaw, pitch, distance} as seen from the
 * centre of the photo, and the position and rotation that follow from it.
 *
 * Yaw follows the viewer's convention: yaw θ looks toward (−sin θ, 0, −cos θ), so 0 is straight
 * ahead (−z) and +90 is to the left (−x). Pitch is positive upwards. The centre is at eye height.
 * The PHP twin is \mod_vrroom\local\panorama::placement_position(), with the same maths.
 *
 * No three.js and no Moodle imports, so the helpers run in node tests too.
 *
 * @module     vrroomruntime_builtin/placement
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Height of the centre of a photo room: the viewer's eye height (m). */
export const EYE = 1.6;

/** Distance of an item from the centre when none is given (m). */
export const DEFAULT_DISTANCE = 4;

/** Nearest an item may be (m). */
export const MIN_DISTANCE = 0.5;

/** Furthest an item may be (m): inside the photo sphere (radius 50). */
export const MAX_DISTANCE = 40;

/** Steepest pitch, up or down (degrees). */
export const MAX_PITCH = 85;

/**
 * Euler order of placementRotation(): turn by yaw first, then tilt by pitch about the turned x axis.
 */
export const ROTATION_ORDER = 'YXZ';

/** Kinds that tilt by pitch to face the centre (flat pictures). */
const TILTED = ['image', 'video'];

/** Degrees to radians. */
const DEG = Math.PI / 180;

/**
 * A finite number or a default.
 *
 * @param {*} value Value.
 * @param {number} fallback Default.
 * @returns {number}
 */
const num = (value, fallback) => {
    const n = Number(value);
    return value !== null && value !== '' && Number.isFinite(n) ? n : fallback;
};

/**
 * Turn -0 into 0, so positions compare and print cleanly.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const unsign = (value) => (value === 0 ? 0 : value);

/**
 * The position of a placement: x = −sin(yaw)·cos(pitch)·d, y = EYE + sin(pitch)·d,
 * z = −cos(yaw)·cos(pitch)·d.
 *
 * @param {number} yaw Yaw in degrees.
 * @param {number} pitch Pitch in degrees.
 * @param {number} [distance] Distance in metres (default 4).
 * @returns {number[]} [x, y, z]
 */
export const placementPosition = (yaw, pitch, distance = DEFAULT_DISTANCE) => {
    const y = num(yaw, 0) * DEG;
    const p = num(pitch, 0) * DEG;
    const d = num(distance, DEFAULT_DISTANCE);
    return [
        unsign(-Math.sin(y) * Math.cos(p) * d),
        unsign(EYE + Math.sin(p) * d),
        unsign(-Math.cos(y) * Math.cos(p) * d),
    ];
};

/**
 * The yaw and pitch of a direction from the centre: yaw = atan2(−dx, −dz), pitch = asin(dy).
 *
 * The direction need not be of unit length. Pitch is kept within ±85°.
 *
 * @param {Object|number[]} dir {x, y, z} (a THREE.Vector3 works) or [x, y, z].
 * @returns {Object} {yaw, pitch} in degrees, yaw in (−180, 180]
 */
export const placementFromDirection = (dir) => {
    const v = Array.isArray(dir) ? {x: dir[0], y: dir[1], z: dir[2]} : (dir || {});
    const x = num(v.x, 0);
    const y = num(v.y, 0);
    const z = num(v.z, 0);
    const length = Math.hypot(x, y, z);
    if (length < 1e-9) {
        return {yaw: 0, pitch: 0};
    }
    let yaw = Math.atan2(-x / length, -z / length) / DEG;
    if (yaw <= -180) {
        yaw += 360;
    }
    const pitch = Math.asin(Math.max(-1, Math.min(1, y / length))) / DEG;
    return {yaw: unsign(yaw), pitch: unsign(Math.max(-MAX_PITCH, Math.min(MAX_PITCH, pitch)))};
};

/**
 * The rotation (degrees, to apply in ROTATION_ORDER) that turns an item at a placement to face
 * the centre: [0, yaw, 0], and for images and videos [pitch, yaw, 0] so the picture also tilts
 * towards the centre. Hotspot markers stay upright: [0, 0, 0].
 *
 * A plane faces +z before it is turned, so a turn by yaw makes it face the centre.
 *
 * @param {number} yaw Yaw in degrees.
 * @param {number} pitch Pitch in degrees.
 * @param {string} kind Item kind ('hotspot', 'image', 'video', 'text', 'portal', 'model', ...);
 *     a video media item is 'video'.
 * @returns {number[]} [x, y, z] in degrees
 */
export const placementRotation = (yaw, pitch, kind) => {
    if (kind === 'hotspot') {
        return [0, 0, 0];
    }
    const y = num(yaw, 0);
    const p = TILTED.indexOf(kind) >= 0 ? num(pitch, 0) : 0;
    return [unsign(p), unsign(y), 0];
};

/**
 * An item's placement, cleaned: numbers in range, the default distance filled in.
 *
 * @param {*} placement The item's placement value.
 * @returns {Object|null} {yaw, pitch, distance}, or null when there is no usable placement
 */
export const readPlacement = (placement) => {
    if (!placement || typeof placement !== 'object' || Array.isArray(placement)) {
        return null;
    }
    const yaw = num(placement.yaw, NaN);
    const pitch = num(placement.pitch, NaN);
    if (!Number.isFinite(yaw) || !Number.isFinite(pitch)) {
        return null;
    }
    const distance = num(placement.distance, DEFAULT_DISTANCE);
    return {
        yaw: yaw,
        pitch: Math.max(-MAX_PITCH, Math.min(MAX_PITCH, pitch)),
        distance: Math.max(MIN_DISTANCE, Math.min(MAX_DISTANCE, distance)),
    };
};

/**
 * The kind name placementRotation() uses for an interactable ('video' for a video media item).
 *
 * @param {Object} item Interactable.
 * @returns {string}
 */
export const rotationKind = (item) => {
    const it = item || {};
    return it.kind === 'media' && it.media === 'video' ? 'video' : String(it.kind || '');
};

/**
 * The transform of an interactable with a placement (readers prefer placement over position).
 *
 * @param {Object} item Interactable.
 * @returns {Object|null} {position: [x, y, z], rotation: [x, y, z] degrees, order, distance},
 *     or null when the item has no usable placement
 */
export const placementTransform = (item) => {
    const placement = readPlacement(item && item.placement);
    if (!placement) {
        return null;
    }
    return {
        position: placementPosition(placement.yaw, placement.pitch, placement.distance),
        rotation: placementRotation(placement.yaw, placement.pitch, rotationKind(item)),
        order: ROTATION_ORDER,
        distance: placement.distance,
    };
};
