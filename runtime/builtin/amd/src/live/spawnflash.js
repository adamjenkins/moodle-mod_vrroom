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
 * The "beam in" effect shown where another person's avatar appears in a live room.
 *
 * A soft column of light in the person's accent colour rises at the spot, a ring spreads across the
 * floor, the avatar grows inside the light, and the light fades: one smooth pulse of about 1.5 s,
 * never a flicker (safe for people sensitive to flashing light). With reduced motion asked for, there
 * is no light and the avatar simply appears.
 *
 * timeline() has no imports, so node can test it; createSpawnFlash() takes THREE as an argument.
 *
 * @module     vrroomruntime_builtin/live/spawnflash
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} How long the effect lasts (ms). */
export const FLASH_MS = 1500;

/** @var {number} Height of the column of light (m), a little taller than a person. */
const COLUMN_HEIGHT = 2.4;

/** @var {number} Radius of the column of light (m). */
const COLUMN_RADIUS = 0.55;

/** @var {number} Brightest the light gets (opacity): bright, never blinding. */
const PEAK = 0.65;

const clamp01 = (value) => Math.min(1, Math.max(0, value));

/**
 * Ease out (fast start, gentle end).
 *
 * @param {number} t 0..1
 * @returns {number}
 */
const easeOut = (t) => 1 - Math.pow(1 - clamp01(t), 3);

/**
 * Ease in and out.
 *
 * @param {number} t 0..1
 * @returns {number}
 */
const smooth = (t) => {
    const x = clamp01(t);
    return x * x * (3 - 2 * x);
};

/**
 * The state of the effect at a moment.
 *
 * The light rises over 0.25 s, holds, then fades out by the end; the brightness only rises once and
 * falls once (one pulse). The avatar grows from a thin sliver to full size between 0.15 s and 0.8 s.
 *
 * @param {number} elapsed Time since the avatar appeared (ms).
 * @param {boolean} reducedmotion Whether the viewer asked for reduced motion.
 * @returns {Object} {done, column: {opacity, height}, ring: {opacity, scale}, avatar: {xz, y}}
 */
export const timeline = (elapsed, reducedmotion) => {
    const t = Math.max(0, Number(elapsed) || 0);
    if (reducedmotion) {
        return {done: true, column: {opacity: 0, height: 1}, ring: {opacity: 0, scale: 1}, avatar: {xz: 1, y: 1}};
    }
    const rise = smooth(t / 250);
    const fade = 1 - smooth((t - 700) / (FLASH_MS - 700));
    const grow = easeOut((t - 150) / 650);
    const spread = easeOut(t / 1100);
    return {
        done: t >= FLASH_MS,
        column: {opacity: PEAK * rise * fade, height: 0.3 + 0.7 * easeOut(t / 350)},
        ring: {opacity: 0.8 * (1 - spread), scale: 0.6 + 2.4 * spread},
        avatar: {xz: 0.85 + 0.15 * grow, y: 0.05 + 0.95 * grow},
    };
};

/**
 * Whether the viewer asked the system for reduced motion.
 *
 * @returns {boolean}
 */
export const prefersReducedMotion = () => {
    try {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (error) {
        return false;
    }
};

/**
 * Build the effect at the floor of a spot (add its object at the avatar's floor position).
 *
 * @param {Object} THREE three.js
 * @param {Object} options {colour: CSS colour of the light}
 * @returns {Object} {object, update(elapsed) → state from timeline(), dispose()}
 */
export const createSpawnFlash = (THREE, options) => {
    const colour = new THREE.Color(options && options.colour ? options.colour : '#9fd3ff');
    const group = new THREE.Group();
    group.name = 'vrroom-spawnflash';

    const material = (opacity) => new THREE.MeshBasicMaterial({
        color: colour,
        transparent: true,
        opacity: opacity,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        side: THREE.DoubleSide,
    });

    // An open cylinder standing on the floor; its base sits at y = 0.
    const columngeometry = new THREE.CylinderGeometry(COLUMN_RADIUS * 0.8, COLUMN_RADIUS, COLUMN_HEIGHT, 24, 1, true);
    columngeometry.translate(0, COLUMN_HEIGHT / 2, 0);
    const columnmaterial = material(0);
    const column = new THREE.Mesh(columngeometry, columnmaterial);
    column.renderOrder = 10;
    group.add(column);

    const ringgeometry = new THREE.RingGeometry(0.28, 0.4, 40);
    ringgeometry.rotateX(-Math.PI / 2);
    const ringmaterial = material(0);
    const ring = new THREE.Mesh(ringgeometry, ringmaterial);
    ring.position.y = 0.02;
    ring.renderOrder = 10;
    group.add(ring);

    return {
        object: group,
        update(elapsed) {
            const state = timeline(elapsed, false);
            columnmaterial.opacity = state.column.opacity;
            column.scale.set(1, state.column.height, 1);
            ringmaterial.opacity = state.ring.opacity;
            ring.scale.set(state.ring.scale, 1, state.ring.scale);
            group.visible = !state.done;
            return state;
        },
        dispose() {
            if (group.parent) {
                group.parent.remove(group);
            }
            columngeometry.dispose();
            columnmaterial.dispose();
            ringgeometry.dispose();
            ringmaterial.dispose();
        },
    };
};
