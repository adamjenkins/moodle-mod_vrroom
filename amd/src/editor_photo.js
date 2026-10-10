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
 * Photo rooms in the room editor: a room whose surroundings are one 360° photo. Things in it are
 * placed by direction ({yaw, pitch, distance}) from the centre, where the viewer stands.
 *
 * Every write sets both 'placement' and the 'position' derived from it, with the maths of
 * vrroomruntime_builtin/placement, so the editor and the run mode agree.
 *
 * @module     mod_vrroom/editor_photo
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {placementFromDirection, placementPosition} from 'vrroomruntime_builtin/placement';

/** Eye height (metres): the centre of a photo room. */
export const EYE = 1.6;

/** Distance (metres) of a new thing from the centre. */
export const DEFAULT_DISTANCE = 4;

/** Limits of a placement. */
export const LIMITS = Object.freeze({
    yaw: [-180, 180],
    pitch: [-85, 85],
    distance: [0.5, 40],
});

/** Kinds of thing that make no sense in a photo room (no walking, no floor, no furniture). */
export const PHOTO_EXCLUDED = Object.freeze(['zone', 'spawn', 'prop', 'grabbable']);

/** Interactable kinds drawn as flat panels, turned to face the centre. */
const PLANES = ['image', 'text', 'portal', 'media'];

/** Sky colour shown while a photo loads. */
const SKY = '#20242a';

/**
 * Round to one decimal.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const r1 = (value) => Math.round(value * 10) / 10;

/**
 * Round to 3 decimals.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const r3 = (value) => Math.round(value * 1000) / 1000;

/**
 * A number kept within limits.
 *
 * @param {number} value Value.
 * @param {number[]} range [min, max].
 * @returns {number}
 */
const clamp = (value, range) => Math.min(Math.max(value, range[0]), range[1]);

/**
 * An angle in degrees brought into -180..180.
 *
 * @param {number} degrees Angle.
 * @returns {number}
 */
export const wrapYaw = (degrees) => {
    let a = ((Number(degrees) || 0) + 180) % 360;
    if (a < 0) {
        a += 360;
    }
    return a - 180;
};

/**
 * Whether a scene is a photo room.
 *
 * @param {Object|null} scene Scene document.
 * @returns {boolean}
 */
export const isPhotoScene = (scene) => !!scene && !!scene.environment && scene.environment.type === 'panorama';

/**
 * A placement made valid: yaw wrapped, pitch and distance kept within limits, one decimal for the
 * angles and three for the distance.
 *
 * @param {number} yaw Degrees.
 * @param {number} pitch Degrees.
 * @param {number} [distance] Metres.
 * @returns {Object} {yaw, pitch, distance}
 */
export const normalisePlacement = (yaw, pitch, distance = DEFAULT_DISTANCE) => {
    const d = Number(distance);
    return {
        yaw: r1(wrapYaw(yaw)),
        pitch: r1(clamp(Number(pitch) || 0, LIMITS.pitch)),
        distance: r3(clamp(Number.isFinite(d) && d > 0 ? d : DEFAULT_DISTANCE, LIMITS.distance)),
    };
};

/**
 * The position of a placement, from the run mode's helper (accepts [x, y, z] or {x, y, z}).
 *
 * @param {Object} placement {yaw, pitch, distance}.
 * @returns {number[]} [x, y, z], rounded to millimetres.
 */
export const positionOf = (placement) => {
    const p = placementPosition(placement.yaw, placement.pitch, placement.distance);
    const v = Array.isArray(p) ? p : [p.x, p.y, p.z];
    return v.map((n) => r3(Number(n)));
};

/**
 * The placement of a direction from the centre, by the run mode's helper.
 *
 * The helper is given an array that also carries x, y and z, so it works whichever form it reads.
 *
 * @param {number} dx Direction x.
 * @param {number} dy Direction y.
 * @param {number} dz Direction z.
 * @returns {Object} {yaw, pitch} in degrees.
 */
export const directionToAngles = (dx, dy, dz) => {
    const length = Math.hypot(dx, dy, dz) || 1;
    const x = dx / length;
    const y = dy / length;
    const z = dz / length;
    const dir = [x, y, z];
    dir.x = x;
    dir.y = y;
    dir.z = z;
    const result = placementFromDirection(dir) || {};
    if (Number.isFinite(Number(result.yaw)) && Number.isFinite(Number(result.pitch))) {
        return {yaw: Number(result.yaw), pitch: Number(result.pitch)};
    }
    // The contract's formula (TOUR-CONTRACT §1.2), should the helper answer in another shape.
    return {yaw: Math.atan2(-x, -z) * 180 / Math.PI, pitch: Math.asin(Math.max(-1, Math.min(1, y))) * 180 / Math.PI};
};

/**
 * The placement an item has, or the one its position implies (direction and distance from the eye).
 *
 * @param {Object} item Interactable.
 * @returns {Object} {yaw, pitch, distance}
 */
export const placementOf = (item) => {
    const p = item && item.placement;
    if (p && Number.isFinite(Number(p.yaw)) && Number.isFinite(Number(p.pitch))) {
        return normalisePlacement(p.yaw, p.pitch, p.distance === undefined ? DEFAULT_DISTANCE : p.distance);
    }
    const position = item && Array.isArray(item.position) && item.position.length === 3 ? item.position.map(Number) : null;
    if (!position || !position.every(Number.isFinite)) {
        return normalisePlacement(0, 0, DEFAULT_DISTANCE);
    }
    const dx = position[0];
    const dy = position[1] - EYE;
    const dz = position[2];
    const distance = Math.hypot(dx, dy, dz);
    if (distance < 1e-6) {
        return normalisePlacement(0, 0, DEFAULT_DISTANCE);
    }
    const angles = directionToAngles(dx, dy, dz);
    return normalisePlacement(angles.yaw, angles.pitch, distance);
};

/**
 * Put an item at a placement (changes it): 'placement', the derived 'position', and for flat
 * panels a rotation that turns them to the centre.
 *
 * @param {Object} item Interactable.
 * @param {number} yaw Degrees.
 * @param {number} pitch Degrees.
 * @param {number} [distance] Metres.
 * @returns {Object} The item.
 */
export const setPlacement = (item, yaw, pitch, distance = DEFAULT_DISTANCE) => {
    const placement = normalisePlacement(yaw, pitch, distance);
    item.placement = placement;
    item.position = positionOf(placement);
    if (PLANES.includes(item.kind)) {
        item.rotation = [0, placement.yaw, 0];
    }
    return item;
};

/**
 * Turn a room into a photo room (changes it): the photo as its surroundings, every start point at
 * the centre, and everything in it placed by direction.
 *
 * @param {Object} scene Draft scene.
 * @param {Object} fileref The photo's file reference.
 * @returns {boolean} Always true.
 */
export const makePhotoScene = (scene, fileref) => {
    const env = scene.environment || {};
    const wasphoto = env.type === 'panorama';
    const northoffset = wasphoto && Number.isFinite(Number(env.northoffset)) ? Number(env.northoffset) : 0;
    scene.environment = {
        type: 'panorama',
        file: fileref,
        northoffset: northoffset,
        sky: /^#[0-9a-fA-F]{6}$/.test(env.sky || '') ? env.sky : SKY,
    };
    (Array.isArray(scene.spawn) ? scene.spawn : []).forEach((spawn) => {
        if (spawn) {
            spawn.position = [0, 0, 0];
        }
    });
    (Array.isArray(scene.interactables) ? scene.interactables : []).forEach((item) => {
        if (item) {
            const p = placementOf(item);
            setPlacement(item, p.yaw, p.pitch, p.distance);
        }
    });
    // An AR scale is for walkable rooms; the run mode offers no AR in a photo room.
    if (scene.xr && Object.prototype.hasOwnProperty.call(scene.xr, 'arscale')) {
        delete scene.xr.arscale;
        if (!Object.keys(scene.xr).length) {
            delete scene.xr;
        }
    }
    return true;
};

/**
 * Leave photo mode (changes the scene): placements are dropped (the validator refuses them outside
 * a photo room); the positions they gave stay.
 *
 * @param {Object} scene Draft scene whose environment has just been replaced.
 */
export const dropPlacements = (scene) => {
    (Array.isArray(scene.interactables) ? scene.interactables : []).forEach((item) => {
        if (item && item.placement) {
            delete item.placement;
        }
    });
};

/**
 * A new photo room.
 *
 * @param {string} id Scene id.
 * @param {string} name Room name.
 * @param {string} spawnid Start point id.
 * @param {string} spawnname Start point name.
 * @param {Object} fileref The photo's file reference.
 * @returns {Object} Scene document.
 */
export const newPhotoScene = (id, name, spawnid, spawnname, fileref) => ({
    schema: 'vrroom-scene/1',
    id: id,
    name: name,
    environment: {type: 'panorama', file: fileref, northoffset: 0, sky: SKY},
    spawn: [{id: spawnid, name: spawnname, position: [0, 0, 0], yaw: 0}],
    zones: [],
    props: [],
    interactables: [],
});

/**
 * Whether an asset is a 360° photo (the server flags those; an upload of kind panorama is one).
 *
 * @param {Object} asset Asset entry.
 * @returns {boolean}
 */
export const isPhotoAsset = (asset) => !!asset && (asset.kind === 'panorama' || (asset.kind === 'image' && !!asset.panorama));
