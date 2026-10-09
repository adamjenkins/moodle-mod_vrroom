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
 * Where the walker may start: the candidate spots around a start point, the spread of the people
 * of a live room around it, and the key that ties a saved position to the layout of a room.
 *
 * No three.js and no Moodle imports, so the helpers run in node tests too.
 *
 * @module     vrroomruntime_builtin/spawn
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Distance between two rings of candidate spots, and between the spots of a ring (m). */
export const STEP = 0.25;

/** How far from the start point a free spot is looked for (m). */
export const MAX_RADIUS = 3;

/** Most spots on one ring. */
const MAX_PER_RING = 48;

/** People of a live room start on a ring this far from the start point (m). */
export const SPREAD_MIN = 0.8;

/** ... up to this far (m). */
export const SPREAD_MAX = 1.2;

/** Golden ratio conjugate: consecutive keys get angles far apart. */
const GOLDEN = 0.6180339887498949;

/** A second irrational step, for the spread radius. */
const PLASTIC = 0.7548776662466927;

/**
 * Candidate floor spots around a point, nearest first: the point itself, then rings every STEP
 * metres up to maxradius, each ring's spots every STEP metres along it (at least 8, at most 48).
 *
 * The order is fixed, so the same room always gives the same spot.
 *
 * @param {number} x Start x.
 * @param {number} z Start z.
 * @param {number} [step] Ring and spot spacing (m).
 * @param {number} [maxradius] Largest ring (m).
 * @returns {number[][]} [[x, z], ...]
 */
export const ringCandidates = (x, z, step = STEP, maxradius = MAX_RADIUS) => {
    const spacing = Number(step) > 0 ? Number(step) : STEP;
    const limit = Number(maxradius) >= 0 ? Number(maxradius) : MAX_RADIUS;
    const list = [[x, z]];
    for (let ring = 1; ring * spacing <= limit + 1e-9; ring++) {
        const r = ring * spacing;
        const count = Math.min(MAX_PER_RING, Math.max(8, Math.ceil(2 * Math.PI * r / spacing)));
        for (let i = 0; i < count; i++) {
            const angle = (i / count) * 2 * Math.PI;
            list.push([x + r * Math.sin(angle), z + r * Math.cos(angle)]);
        }
    }
    return list;
};

/**
 * The first candidate spot around a point that a test accepts.
 *
 * @param {number} x Start x.
 * @param {number} z Start z.
 * @param {Function} accept (x, z) → boolean.
 * @param {Object} [options] {step, maxradius, budget (most spots tested)}.
 * @returns {number[]|null} [x, z], or null when no spot is accepted.
 */
export const findSpot = (x, z, accept, options = {}) => {
    const candidates = ringCandidates(x, z, options.step, options.maxradius);
    const budget = Number(options.budget) > 0 ? Number(options.budget) : candidates.length;
    for (let i = 0; i < candidates.length && i < budget; i++) {
        const [cx, cz] = candidates[i];
        if (accept(cx, cz)) {
            return [cx, cz];
        }
    }
    return null;
};

/**
 * Whether a floor point is inside bounds shrunk by a margin (the whole bounds when they are too
 * small for the margin).
 *
 * @param {Object|null} bounds {min: [x, y, z], max: [x, y, z]} or null (no bounds: always inside).
 * @param {number} x Point x.
 * @param {number} z Point z.
 * @param {number} margin Margin (m).
 * @returns {boolean}
 */
export const insideBounds = (bounds, x, z, margin) => {
    if (!bounds || !Array.isArray(bounds.min) || !Array.isArray(bounds.max)) {
        return true;
    }
    const inAxis = (value, min, max) => {
        if (min + margin <= max - margin) {
            return value >= min + margin - 1e-9 && value <= max - margin + 1e-9;
        }
        return value >= min - 1e-9 && value <= max + 1e-9;
    };
    return inAxis(x, Number(bounds.min[0]), Number(bounds.max[0])) && inAxis(z, Number(bounds.min[2]), Number(bounds.max[2]));
};

/**
 * A small, stable, non-negative hash of a string (djb2, kept below 2^31 without bitwise operators).
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
 * The offset of one person from the start point in a live room, the same every time for a key.
 *
 * The angle and the radius (SPREAD_MIN to SPREAD_MAX) come from the key's hash through irrational
 * steps, so keys that differ by one character (user ids 23 and 24) still end up far apart.
 *
 * @param {*} key Who (for example the user id).
 * @returns {number[]} [dx, dz]
 */
export const spreadOffset = (key) => {
    const h = hashString(key);
    const angle = ((h * GOLDEN) % 1) * 2 * Math.PI;
    const radius = SPREAD_MIN + ((h * PLASTIC) % 1) * (SPREAD_MAX - SPREAD_MIN);
    return [radius * Math.sin(angle), radius * Math.cos(angle)];
};

/**
 * A key of what decides where a room's walkable floor is: its environment and its first start point.
 *
 * A saved position is only used again while the key of its room is unchanged, so a teacher who
 * changes the room's surroundings or moves its start point does not leave students inside a wall.
 *
 * @param {Object|null} doc A vrroom-scene/1 document.
 * @returns {string}
 */
export const layoutKey = (doc) => {
    const scene = doc && typeof doc === 'object' ? doc : {};
    const env = scene.environment && typeof scene.environment === 'object' ? scene.environment : {};
    const file = env.file && typeof env.file === 'object' ? env.file : {};
    const spawns = Array.isArray(scene.spawn) ? scene.spawn : [];
    const first = spawns[0] && typeof spawns[0] === 'object' ? spawns[0] : {};
    const parts = {
        scene: scene.id === undefined ? null : scene.id,
        type: env.type === undefined ? null : env.type,
        preset: env.preset === undefined ? null : env.preset,
        pack: env.pack === undefined ? null : env.pack,
        room: env.room === undefined ? null : env.room,
        file: [file.itemid, file.filepath, file.filename].map((v) => (v === undefined ? null : v)),
        shape: env.shape === undefined ? null : env.shape,
        bounds: env.bounds === undefined ? null : env.bounds,
        spawn: [first.position === undefined ? null : first.position, first.yaw === undefined ? null : first.yaw],
    };
    return `v1-${hashString(JSON.stringify(parts)).toString(36)}`;
};
