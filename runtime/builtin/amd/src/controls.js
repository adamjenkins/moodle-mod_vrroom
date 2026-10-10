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
 * First-person controls for desktop and touch: keyboard walking, drag to look, click or tap to
 * walk to a floor point, a virtual stick on touch screens, and Enter to activate.
 *
 * In a 360° photo room walking is off (setWalk(false)): no WASD moves, no stick and no tap to walk;
 * the turning keys turn, the up and down keys tilt, the wheel and a pinch zoom (onZoom), and on a
 * phone the view can follow the device's orientation (setMotionLook).
 *
 * With a rig (a group holding the camera) the controls move and turn the rig and only pitch the
 * camera inside it, so an immersive WebXR session can take over the camera's pose while teleport
 * and snap turn keep moving the rig.
 *
 * @module     vrroomruntime_builtin/controls
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as THREE from 'vrroomruntime_builtin/three';
import {findSpot, insideBounds} from 'vrroomruntime_builtin/spawn';

/** Walking speed in metres per second. */
const WALK = 2.5;

/** Turning speed for Q and E in radians per second. */
const TURN = 1.8;

/** Radians per pixel when dragging to look. */
const LOOK = 0.005;

/** Pitch limit (±80°). */
const PITCH = 80 * Math.PI / 180;

/** Tilting speed of the up and down keys when walking is off, in radians per second. */
const TILT = 1.2;

/** Zoom factor per wheel pixel (the field of view is multiplied by exp(deltaY · WHEEL)). */
const WHEEL = 0.0015;

/** Pointer travel under which a press counts as a click (px). */
const CLICK = 5;

/** Bounds are shrunk by this margin (m). */
const MARGIN = 0.3;
/** @var {number} How close (metres) the walker's centre gets to a wall or piece of furniture. */
const RADIUS = 0.3;

/** @var {number} Free space a known position (saved or last walked to) needs around it (m). */
const STANDCLEARANCE = 0.1;
/** @var {number[]} Heights (metres above the floor) of the rays that look for obstacles: knee and chest. */
const STEPHEIGHTS = [0.4, 1.1];
/** @var {number} Free space a start spot needs around the body (m): a little more than RADIUS, so the walker can move. */
const CLEARANCE = 0.35;
/** @var {number} A start spot whose chest-height rays all hit something this close is enclosed (m). */
const ENCLOSED = 1;
/** @var {number} Highest floor under a start spot (m): anything higher is furniture. */
const FLOORTOP = 0.35;
/** @var {number} Lowest thing allowed above a start spot (m): lower is a table top or a shelf. */
const HEADROOM = 1.8;
/** @var {number} A start spot faces somewhere else when a wall is closer than this ahead (m). */
const FACEFREE = 1;
/** @var {number} How far the facing rays look (m). */
const FACEREACH = 20;
/** @var {number} How close a start spot may be to another person in a live room (m). */
const PERSONAL = 0.7;
/** @var {number} Most candidate spots tested in one search pass. */
const BUDGET = 500;

/** Radius of the virtual stick's travel (px). */
const STICK = 60;

/** Keys and the movement they request: [forward, right]. */
const MOVES = {
    KeyW: [1, 0], ArrowUp: [1, 0],
    KeyS: [-1, 0], ArrowDown: [-1, 0],
    KeyQ: [0, -1],
    KeyE: [0, 1],
};

/** Keys that turn: +1 turns left, -1 turns right (A/D and the left/right arrows; Q/E step sideways). */
const TURNS = {
    KeyA: 1, ArrowLeft: 1,
    KeyD: -1, ArrowRight: -1,
};

/**
 * Whether the view can follow the device's orientation: the browser has orientation events and the
 * device is a touch device (desktop browsers have the event type but never fire it).
 *
 * @returns {boolean}
 */
export const motionLookAvailable = () => {
    try {
        if (typeof window === 'undefined' || typeof window.DeviceOrientationEvent === 'undefined') {
            return false;
        }
        const coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
        return coarse || 'ontouchstart' in window;
    } catch (e) {
        return false;
    }
};

/**
 * Attach controls to a camera and canvas.
 *
 * Options: bounds {min, max}; eyeHeight (default 1.6); stick (HTMLElement of the virtual stick);
 * rig (THREE.Object3D that holds the camera; optional); onClick(ndc {x, y}) → true when the click
 * hit something (no walking then); onActivate() (Enter); onTouch() (first touch input seen);
 * invertX, invertY (reverse the drag-to-look direction on that axis); lockY (dragging never tilts);
 * obstacles() → THREE.Mesh[] the walker may not pass through (walls, furniture), or none;
 * onZoom(factor) (wheel or pinch while walking is off: multiply the field of view by factor).
 *
 * @param {Object} camera THREE.PerspectiveCamera.
 * @param {HTMLCanvasElement} canvas The canvas.
 * @param {Object} options Options.
 * @returns {Object} {update(dt), teleport(position, yaw), findStart(position, yaw, opts), canStand(position),
 *     getPosition(), getYaw(), setBounds(bounds), setInvert(x, y), setLockY(locked), setEnabled(enabled),
 *     setWalk(on), setPitchLimit(degrees), setMotionLook(on) → Promise<boolean>, isMotionLook(), detach()}
 */
export const attach = (camera, canvas, options = {}) => {
    const eye = Number.isFinite(options.eyeHeight) ? options.eyeHeight : 1.6;
    const rig = options.rig || null;
    let bounds = options.bounds || null;
    let enabled = true;
    const position = new THREE.Vector3(0, 0, 0);
    let yaw = 0;
    let pitch = 0;
    let invertx = !!options.invertX;
    let inverty = !!options.invertY;
    let locky = !!options.lockY;
    let target = null;
    const keys = new Set();
    let shift = false;
    const stickinput = {x: 0, y: 0};
    let drag = null;
    let stickpointer = null;
    const raycaster = new THREE.Raycaster();
    const floor = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    const obstacles = typeof options.obstacles === 'function' ? options.obstacles : () => [];
    const ray = new THREE.Raycaster();
    const origin = new THREE.Vector3();
    const direction = new THREE.Vector3();
    const listeners = [];
    // Walking (off in a 360° photo room), the pitch limit, touch pointers for a pinch.
    let walk = true;
    let pitchlimit = PITCH;
    const touches = new Map();
    let pinch = null;
    // Looking by moving the device: {listening, on, sample: {alpha, beta, gamma, orient}|null, offset|null}.
    const motion = {listening: false, on: false, sample: null, offset: null};
    const zee = new THREE.Vector3(0, 0, 1);
    const devicequaternion = new THREE.Quaternion();
    const screenturn = new THREE.Quaternion();
    // The camera looks out of the back of the device, not out of its top: −90° about x.
    const backturn = new THREE.Quaternion(-Math.sqrt(0.5), 0, 0, Math.sqrt(0.5));
    const deviceeuler = new THREE.Euler();
    const lookdir = new THREE.Vector3();

    camera.rotation.order = 'YXZ';

    const listen = (element, type, handler, opts) => {
        element.addEventListener(type, handler, opts);
        listeners.push(() => element.removeEventListener(type, handler, opts));
    };

    const clamp = (p) => {
        if (!bounds) {
            return p;
        }
        const minx = bounds.min[0] + MARGIN;
        const maxx = bounds.max[0] - MARGIN;
        const minz = bounds.min[2] + MARGIN;
        const maxz = bounds.max[2] - MARGIN;
        p.x = minx <= maxx ? Math.min(maxx, Math.max(minx, p.x)) : (bounds.min[0] + bounds.max[0]) / 2;
        p.z = minz <= maxz ? Math.min(maxz, Math.max(minz, p.z)) : (bounds.min[2] + bounds.max[2]) / 2;
        p.y = 0;
        return p;
    };

    /**
     * Run fn with every material of the meshes double-sided, then give each material its own side back.
     *
     * Sides are saved per material, not per mesh: meshes often share a material, and saving per mesh
     * would save the already-changed side of a shared material and leave it double-sided for good.
     *
     * @param {Object[]} meshes THREE.Mesh list.
     * @param {Function} fn Called with a Map material → original side; its result is returned.
     * @returns {*}
     */
    const withDoubleSided = (meshes, fn) => {
        const originals = new Map();
        meshes.forEach((mesh) => {
            const materials = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
            materials.forEach((material) => {
                if (material && !originals.has(material)) {
                    originals.set(material, material.side);
                    material.side = THREE.DoubleSide;
                }
            });
        });
        try {
            return fn(originals);
        } finally {
            originals.forEach((side, material) => {
                material.side = side;
            });
        }
    };

    /**
     * The nearest obstacle within reach along a horizontal direction, at knee or chest height.
     *
     * Faces count from both sides, so single-sided walls stop the walker from either side.
     *
     * @param {number} dirx Unit direction x.
     * @param {number} dirz Unit direction z.
     * @param {number} reach How far to look, in metres.
     * @returns {Object|null} {distance, normal (horizontal unit THREE.Vector3 facing the walker)}
     */
    const nearestObstacle = (dirx, dirz, reach) => {
        const meshes = obstacles();
        if (!meshes || !meshes.length) {
            return null;
        }
        direction.set(dirx, 0, dirz);
        ray.far = reach;
        const best = withDoubleSided(meshes, () => {
            let nearest = null;
            STEPHEIGHTS.forEach((height) => {
                origin.set(position.x, height, position.z);
                ray.set(origin, direction);
                const hit = ray.intersectObjects(meshes, false)[0];
                if (hit && hit.face && (!nearest || hit.distance < nearest.distance)) {
                    nearest = hit;
                }
            });
            return nearest;
        });
        if (!best) {
            return null;
        }
        const normal = best.face.normal.clone().transformDirection(best.object.matrixWorld);
        normal.y = 0;
        if (normal.lengthSq() < 1e-6) {
            normal.set(-dirx, 0, -dirz);
        }
        normal.normalize();
        if (normal.x * dirx + normal.z * dirz > 0) {
            normal.negate();
        }
        return {distance: best.distance, normal: normal};
    };

    /**
     * Move by (dx, dz), stopping a body radius short of obstacles and sliding along them.
     *
     * @param {number} dx Wanted move in x.
     * @param {number} dz Wanted move in z.
     * @returns {boolean} Whether the walker moved at all.
     */
    const moveBy = (dx, dz) => {
        const length = Math.hypot(dx, dz);
        if (length < 1e-6) {
            return false;
        }
        const ux = dx / length;
        const uz = dz / length;
        const hit = nearestObstacle(ux, uz, length + RADIUS);
        if (!hit) {
            position.x += dx;
            position.z += dz;
            return true;
        }
        const allowed = Math.max(0, Math.min(length, hit.distance - RADIUS));
        position.x += ux * allowed;
        position.z += uz * allowed;
        // Slide: the rest of the move without its component into the obstacle.
        const restx = dx - ux * allowed;
        const restz = dz - uz * allowed;
        const into = restx * hit.normal.x + restz * hit.normal.z;
        const slidex = restx - hit.normal.x * into;
        const slidez = restz - hit.normal.z * into;
        const slide = Math.hypot(slidex, slidez);
        if (slide > 1e-4) {
            const blocked = nearestObstacle(slidex / slide, slidez / slide, slide + RADIUS);
            const free = blocked ? Math.max(0, Math.min(slide, blocked.distance - RADIUS)) : slide;
            position.x += slidex / slide * free;
            position.z += slidez / slide * free;
            return allowed > 1e-4 || free > 1e-4;
        }
        return allowed > 1e-4;
    };

    // Start spots: probes of their own, so a search never disturbs the walking rays.
    const probe = new THREE.Raycaster();
    const probeorigin = new THREE.Vector3();
    const probedir = new THREE.Vector3();
    const up = new THREE.Vector3(0, 1, 0);
    const down = new THREE.Vector3(0, -1, 0);

    /**
     * The nearest hit of one ray against the obstacles (made double-sided by the caller).
     *
     * @param {Object[]} meshes Obstacles.
     * @param {number} x Origin x.
     * @param {number} y Origin height.
     * @param {number} z Origin z.
     * @param {Object} dir Unit THREE.Vector3.
     * @param {number} far How far to look (m).
     * @returns {Object|null} three.js intersection
     */
    const firstHit = (meshes, x, y, z, dir, far) => {
        probeorigin.set(x, y, z);
        probe.set(probeorigin, dir);
        probe.near = 0;
        probe.far = far;
        return probe.intersectObjects(meshes, false)[0] || null;
    };

    /**
     * Whether a ray hit the back of a single-sided face: it started inside a solid, or outside a room's shell.
     *
     * @param {Object} hit three.js intersection.
     * @param {Object} dir The ray's direction.
     * @param {Map} originals material → its own side (from withDoubleSided).
     * @returns {boolean}
     */
    const isBackHit = (hit, dir, originals) => {
        if (!hit.face) {
            return false;
        }
        const material = Array.isArray(hit.object.material) ? hit.object.material[hit.face.materialIndex] : hit.object.material;
        if (!material || originals.get(material) !== THREE.FrontSide) {
            return false;
        }
        return hit.face.normal.clone().transformDirection(hit.object.matrixWorld).dot(dir) > 0;
    };

    /**
     * Whether a person fits on a floor spot: a floor under it (not a table top or a bed of rock),
     * nothing low overhead, free space around the body, not boxed in and not behind single-sided
     * faces (inside a solid or outside the room's walls).
     *
     * @param {Object[]} meshes Obstacles, double-sided meanwhile.
     * @param {Map} originals material → its own side.
     * @param {number} x Spot x.
     * @param {number} z Spot z.
     * @param {boolean} needfloor Whether a floor must be found under the spot.
     * @param {number} clearance Free space needed around the body (m).
     * @param {boolean} [checkback] Whether to refuse spots that see the back of single-sided faces.
     *     Only for spots nobody chose: some rooms are built of single-sided faces seen from behind
     *     everywhere (a pack room's own start point does), so a walked-to or authored spot skips it.
     * @returns {boolean}
     */
    const roomToStand = (meshes, originals, x, z, needfloor, clearance, checkback = true) => {
        const below = firstHit(meshes, x, eye, z, down, eye + 1);
        if (below) {
            if (eye - below.distance > FLOORTOP) {
                return false;
            }
        } else if (needfloor) {
            return false;
        }
        const knee = STEPHEIGHTS[0];
        const chest = STEPHEIGHTS[STEPHEIGHTS.length - 1];
        if (firstHit(meshes, x, knee, z, up, HEADROOM - knee)) {
            return false;
        }
        let enclosed = 0;
        let back = 0;
        for (let i = 0; i < 8; i++) {
            const angle = i * Math.PI / 4;
            probedir.set(Math.sin(angle), 0, Math.cos(angle));
            if (firstHit(meshes, x, knee, z, probedir, clearance)) {
                return false;
            }
            const hit = firstHit(meshes, x, chest, z, probedir, FACEREACH);
            if (!hit) {
                continue;
            }
            if (hit.distance < clearance) {
                return false;
            }
            if (hit.distance < ENCLOSED) {
                enclosed++;
            }
            if (isBackHit(hit, probedir, originals)) {
                back++;
            }
        }
        return enclosed < 8 && (!checkback || back < 2);
    };

    /**
     * A view direction from a spot that does not stare at a nearby wall: the given yaw when it is open,
     * otherwise the most open of the eight directions around it (into the room).
     *
     * @param {Object[]} meshes Obstacles, double-sided meanwhile.
     * @param {number} x Spot x.
     * @param {number} z Spot z.
     * @param {number} yawdeg Wanted yaw in degrees.
     * @returns {number} Yaw in degrees.
     */
    const openYaw = (meshes, x, z, yawdeg) => {
        const chest = STEPHEIGHTS[STEPHEIGHTS.length - 1];
        const openness = (deg) => {
            const rad = deg * Math.PI / 180;
            // Yaw θ looks toward (−sin θ, 0, −cos θ).
            probedir.set(-Math.sin(rad), 0, -Math.cos(rad));
            const hit = firstHit(meshes, x, chest, z, probedir, FACEREACH);
            return hit ? hit.distance : FACEREACH;
        };
        if (openness(yawdeg) >= FACEFREE) {
            return yawdeg;
        }
        let best = yawdeg;
        let bestdistance = -1;
        for (let i = 1; i < 8; i++) {
            const distance = openness(yawdeg + i * 45);
            if (distance > bestdistance + 1e-6) {
                best = yawdeg + i * 45;
                bestdistance = distance;
            }
        }
        return ((best % 360) + 360) % 360;
    };

    /**
     * The nearest spot to a start point where a person fits, inside the bounds, facing into the room.
     *
     * Options: avoid [[x, z]] (other people: keep PERSONAL metres away), from [x, z] (the spot must be
     * in sight of this point at chest height, so a spread never lands behind a wall), maxradius (m).
     * Passes: floor found and clear of others; then without the floor test; then ignoring the others.
     *
     * @param {number[]} pos Start [x, y, z].
     * @param {number} yawdeg Start yaw in degrees.
     * @param {Object} [opts] Options.
     * @returns {Object} {position: [x, 0, z], yaw, free (false: nothing fits, the clamped start is returned)}
     */
    const findStart = (pos, yawdeg, opts = {}) => {
        const start = clamp(new THREE.Vector3(Number(pos && pos[0]) || 0, 0, Number(pos && pos[2]) || 0));
        const yawin = Number.isFinite(Number(yawdeg)) ? Number(yawdeg) : 0;
        const avoid = Array.isArray(opts.avoid) ? opts.avoid.filter((a) => Array.isArray(a) && a.length >= 2) : [];
        const from = Array.isArray(opts.from) && opts.from.length >= 2 ? opts.from : null;
        const search = {maxradius: opts.maxradius, budget: BUDGET};
        const inside = (x, z) => insideBounds(bounds, x, z, MARGIN);
        const clearOf = (x, z) => avoid.every((a) => Math.hypot(Number(a[0]) - x, Number(a[1]) - z) >= PERSONAL);
        const meshes = obstacles() || [];
        if (!meshes.length) {
            const spot = findSpot(start.x, start.z, (x, z) => inside(x, z) && clearOf(x, z), search);
            const chosen = spot || [start.x, start.z];
            return {position: [chosen[0], 0, chosen[1]], yaw: yawin, free: !!spot};
        }
        return withDoubleSided(meshes, (originals) => {
            const inSight = (x, z) => {
                if (!from) {
                    return true;
                }
                const dx = x - from[0];
                const dz = z - from[1];
                const distance = Math.hypot(dx, dz);
                if (distance < 1e-6) {
                    return true;
                }
                probedir.set(dx / distance, 0, dz / distance);
                return !firstHit(meshes, from[0], STEPHEIGHTS[STEPHEIGHTS.length - 1], from[1], probedir, distance + CLEARANCE);
            };
            // The start point as authored, when a person fits there and nobody else stands on it.
            if (inside(start.x, start.z) && clearOf(start.x, start.z) && inSight(start.x, start.z)
                    && roomToStand(meshes, originals, start.x, start.z, true, CLEARANCE, false)) {
                return {position: [start.x, 0, start.z], yaw: openYaw(meshes, start.x, start.z, yawin), free: true};
            }
            const passes = [{floor: true, others: true}, {floor: false, others: true}];
            if (avoid.length) {
                passes.push({floor: true, others: false});
            }
            for (const pass of passes) {
                const spot = findSpot(start.x, start.z, (x, z) => inside(x, z) && (!pass.others || clearOf(x, z)) &&
                    inSight(x, z) && roomToStand(meshes, originals, x, z, pass.floor, CLEARANCE), search);
                if (spot) {
                    return {position: [spot[0], 0, spot[1]], yaw: openYaw(meshes, spot[0], spot[1], yawin), free: true};
                }
            }
            return {position: [start.x, 0, start.z], yaw: yawin, free: false};
        });
    };

    const apply = () => {
        if (rig) {
            rig.position.set(position.x, 0, position.z);
            rig.rotation.set(0, yaw, 0);
            camera.position.set(0, eye, 0);
            camera.rotation.set(pitch, 0, 0, 'YXZ');
            rig.updateMatrixWorld(true);
        } else {
            camera.position.set(position.x, position.y + eye, position.z);
            camera.rotation.set(pitch, yaw, 0, 'YXZ');
            camera.updateMatrixWorld(true);
        }
    };

    const ndc = (clientx, clienty) => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: ((clientx - rect.left) / Math.max(1, rect.width)) * 2 - 1,
            y: -((clienty - rect.top) / Math.max(1, rect.height)) * 2 + 1,
        };
    };

    const walkToFloor = (point) => {
        camera.updateMatrixWorld(true);
        raycaster.setFromCamera(new THREE.Vector2(point.x, point.y), camera);
        const hit = new THREE.Vector3();
        if (raycaster.ray.intersectPlane(floor, hit)) {
            if (hit.distanceTo(camera.getWorldPosition(new THREE.Vector3())) < 60) {
                target = clamp(hit);
            }
        }
    };

    // Keyboard (only while the canvas has focus).
    listen(canvas, 'keydown', (e) => {
        if (!enabled || e.altKey || e.ctrlKey || e.metaKey) {
            return;
        }
        shift = e.shiftKey;
        if (MOVES[e.code] || TURNS[e.code]) {
            keys.add(e.code);
            if (MOVES[e.code]) {
                target = null;
            }
            e.preventDefault();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (options.onActivate) {
                options.onActivate();
            }
        }
    });
    listen(window, 'keyup', (e) => {
        shift = e.shiftKey;
        keys.delete(e.code);
    });
    listen(canvas, 'blur', () => {
        keys.clear();
        shift = false;
    });

    // Pointer on the canvas: drag to look, click or tap to act or walk.
    listen(canvas, 'pointerdown', (e) => {
        if (!enabled) {
            return;
        }
        if (e.pointerType === 'touch' && options.onTouch) {
            options.onTouch();
        }
        if (e.pointerType === 'touch') {
            touches.set(e.pointerId, {x: e.clientX, y: e.clientY});
            if (!walk && touches.size === 2) {
                // Two fingers: a pinch zooms; it is no drag and no tap.
                const [a, b] = Array.from(touches.values());
                pinch = {distance: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y))};
                drag = null;
                return;
            }
        }
        if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') {
            return;
        }
        if (drag || pinch) {
            return;
        }
        drag = {id: e.pointerId, x: e.clientX, y: e.clientY, lastx: e.clientX, lasty: e.clientY, moved: 0};
        try {
            canvas.setPointerCapture(e.pointerId);
        } catch (err) {
            // Capture is optional.
        }
        canvas.focus({preventScroll: true});
    });
    listen(canvas, 'pointermove', (e) => {
        if (touches.has(e.pointerId)) {
            touches.set(e.pointerId, {x: e.clientX, y: e.clientY});
        }
        if (pinch) {
            if (touches.size >= 2 && enabled) {
                const [a, b] = Array.from(touches.values());
                const distance = Math.max(1, Math.hypot(a.x - b.x, a.y - b.y));
                if (options.onZoom) {
                    // Fingers apart (a longer distance) zoom in: a narrower view.
                    options.onZoom(pinch.distance / distance);
                }
                pinch.distance = distance;
            }
            return;
        }
        if (!drag || e.pointerId !== drag.id) {
            return;
        }
        const dx = e.clientX - drag.lastx;
        const dy = e.clientY - drag.lasty;
        drag.lastx = e.clientX;
        drag.lasty = e.clientY;
        drag.moved = Math.max(drag.moved, Math.hypot(e.clientX - drag.x, e.clientY - drag.y));
        if (drag.moved >= CLICK) {
            const turn = (invertx ? -dx : dx) * LOOK;
            if (motion.on && motion.offset !== null) {
                // The device sets the view; dragging turns it on top of that.
                motion.offset -= turn;
            } else {
                yaw -= turn;
            }
            if (!locky && !motion.on) {
                pitch = Math.max(-pitchlimit, Math.min(pitchlimit, pitch - (inverty ? -dy : dy) * LOOK));
            }
        }
    });
    const endDrag = (e, cancelled) => {
        touches.delete(e.pointerId);
        if (pinch && touches.size < 2) {
            pinch = null;
        }
        if (!drag || e.pointerId !== drag.id) {
            return;
        }
        const click = !cancelled && drag.moved < CLICK;
        drag = null;
        if (!click) {
            return;
        }
        const point = ndc(e.clientX, e.clientY);
        const handled = options.onClick ? options.onClick(point) : false;
        if (!handled && walk) {
            walkToFloor(point);
        }
    };
    listen(canvas, 'pointerup', (e) => endDrag(e, false));
    listen(canvas, 'pointercancel', (e) => endDrag(e, true));

    // The wheel zooms while walking is off (it scrolls the page otherwise).
    listen(canvas, 'wheel', (e) => {
        if (!enabled || walk || !options.onZoom) {
            return;
        }
        e.preventDefault();
        options.onZoom(Math.exp(Math.max(-200, Math.min(200, Number(e.deltaY) || 0)) * WHEEL));
    }, {passive: false});

    // The device's orientation (looking by moving the phone).
    const onOrientation = (e) => {
        if (e.alpha === null && e.beta === null && e.gamma === null) {
            return;
        }
        const scr = window.screen && window.screen.orientation ? window.screen.orientation.angle : window.orientation;
        motion.sample = {
            alpha: (Number(e.alpha) || 0) * Math.PI / 180,
            beta: (Number(e.beta) || 0) * Math.PI / 180,
            gamma: (Number(e.gamma) || 0) * Math.PI / 180,
            orient: (Number(scr) || 0) * Math.PI / 180,
        };
    };
    // Yaw and pitch of the device's view from the last orientation sample.
    const deviceLook = () => {
        const sample = motion.sample;
        deviceeuler.set(sample.beta, sample.alpha, -sample.gamma, 'YXZ');
        devicequaternion.setFromEuler(deviceeuler);
        devicequaternion.multiply(backturn);
        devicequaternion.multiply(screenturn.setFromAxisAngle(zee, -sample.orient));
        lookdir.set(0, 0, -1).applyQuaternion(devicequaternion);
        return {yaw: Math.atan2(-lookdir.x, -lookdir.z), pitch: Math.asin(Math.max(-1, Math.min(1, lookdir.y)))};
    };

    // Virtual stick (touch).
    const stick = options.stick || null;
    const knob = stick ? stick.querySelector('[data-region="vrroom-stick-knob"]') : null;
    const setKnob = (x, y) => {
        if (knob) {
            knob.style.transform = `translate(${x * STICK}px, ${y * STICK}px)`;
        }
    };
    const stickMove = (e) => {
        const rect = stick.getBoundingClientRect();
        let x = (e.clientX - (rect.left + rect.width / 2)) / STICK;
        let y = (e.clientY - (rect.top + rect.height / 2)) / STICK;
        const length = Math.hypot(x, y);
        if (length > 1) {
            x /= length;
            y /= length;
        }
        stickinput.x = x;
        stickinput.y = y;
        setKnob(x, y);
    };
    if (stick) {
        listen(stick, 'pointerdown', (e) => {
            if (!enabled || !walk) {
                return;
            }
            if (options.onTouch && e.pointerType === 'touch') {
                options.onTouch();
            }
            stickpointer = e.pointerId;
            target = null;
            try {
                stick.setPointerCapture(e.pointerId);
            } catch (err) {
                // Capture is optional.
            }
            stickMove(e);
            e.preventDefault();
        });
        listen(stick, 'pointermove', (e) => {
            if (e.pointerId === stickpointer) {
                stickMove(e);
                e.preventDefault();
            }
        });
        const release = (e) => {
            if (e.pointerId === stickpointer) {
                stickpointer = null;
                stickinput.x = 0;
                stickinput.y = 0;
                setKnob(0, 0);
            }
        };
        listen(stick, 'pointerup', release);
        listen(stick, 'pointercancel', release);
    }

    apply();

    return {
        /**
         * Advance movement by dt seconds.
         *
         * @param {number} dt Seconds since the last frame.
         */
        update(dt) {
            if (!enabled) {
                return;
            }
            const step = Math.min(0.1, Math.max(0, dt));
            let turn = 0;
            let forward = 0;
            let right = 0;
            keys.forEach((code) => {
                if (TURNS[code]) {
                    turn += TURNS[code];
                } else if (MOVES[code]) {
                    forward += MOVES[code][0];
                    right += MOVES[code][1];
                }
            });
            if (motion.on && motion.offset !== null) {
                motion.offset += Math.sign(turn) * TURN * step;
            } else {
                yaw += Math.sign(turn) * TURN * step;
            }
            if (motion.on && motion.sample) {
                const look = deviceLook();
                if (motion.offset === null) {
                    // Carry on from where the view is now.
                    motion.offset = yaw - look.yaw;
                }
                yaw = look.yaw + motion.offset;
                pitch = locky ? 0 : Math.max(-pitchlimit, Math.min(pitchlimit, look.pitch));
            }
            if (!walk) {
                // No walking: the forward and back keys tilt the view instead.
                if (!locky && !motion.on && forward !== 0) {
                    pitch = Math.max(-pitchlimit, Math.min(pitchlimit, pitch + Math.sign(forward) * TILT * step));
                }
                apply();
                return;
            }
            forward -= stickinput.y;
            right += stickinput.x;
            const speed = WALK * (shift ? 2 : 1);
            const sin = Math.sin(yaw);
            const cos = Math.cos(yaw);
            if (forward !== 0 || right !== 0) {
                const length = Math.max(1, Math.hypot(forward, right));
                forward /= length;
                right /= length;
                // Yaw θ looks toward (−sin θ, 0, −cos θ); right is (cos θ, 0, −sin θ).
                moveBy((-sin * forward + cos * right) * speed * step, (-cos * forward - sin * right) * speed * step);
                clamp(position);
            } else if (target) {
                const dx = target.x - position.x;
                const dz = target.z - position.z;
                const distance = Math.hypot(dx, dz);
                const move = WALK * step;
                const reach = Math.min(distance, move);
                // A walk that an obstacle stops (no progress at all) ends there.
                if (!moveBy(dx / distance * reach, dz / distance * reach) || distance <= Math.max(0.05, move)) {
                    target = null;
                }
                clamp(position);
            }
            apply();
        },

        /**
         * Move to a floor position and face a yaw.
         *
         * @param {number[]} pos [x, y, z]
         * @param {number|null} yawdeg Yaw in degrees (null keeps the current one).
         */
        teleport(pos, yawdeg) {
            if (Array.isArray(pos) && pos.length === 3) {
                position.set(Number(pos[0]) || 0, 0, Number(pos[2]) || 0);
                clamp(position);
            }
            if (yawdeg !== null && yawdeg !== undefined && Number.isFinite(Number(yawdeg))) {
                yaw = Number(yawdeg) * Math.PI / 180;
                pitch = 0;
                // Looking by moving the device carries on from the new facing.
                motion.offset = null;
            }
            target = null;
            apply();
        },

        /**
         * Find where a person fits nearest to a start point (see findStart), without moving there.
         *
         * @param {number[]} pos Start [x, y, z].
         * @param {number} yawdeg Start yaw in degrees.
         * @param {Object} [opts] {avoid: [[x, z]], from: [x, z], maxradius}
         * @returns {Object} {position: [x, 0, z], yaw, free}
         */
        findStart(pos, yawdeg, opts) {
            return findStart(pos, yawdeg, opts || {});
        },

        /**
         * Whether a person fits at a floor position: inside the bounds, and not in or right against
         * a wall, a piece of furniture or a model (a saved position is checked with this).
         *
         * @param {number[]} pos [x, y, z]
         * @returns {boolean}
         */
        canStand(pos) {
            if (!Array.isArray(pos) || pos.length !== 3) {
                return false;
            }
            const x = Number(pos[0]);
            const z = Number(pos[2]);
            if (!Number.isFinite(x) || !Number.isFinite(z) || !insideBounds(bounds, x, z, 0)) {
                return false;
            }
            const meshes = obstacles() || [];
            if (!meshes.length) {
                return true;
            }
            // Walking keeps the body radius only along the direction of travel, so a walked-to spot
            // can be closer than that to the side: only a spot in or right against geometry is refused.
            return withDoubleSided(meshes, (originals) => roomToStand(meshes, originals, x, z, false, STANDCLEARANCE, false));
        },

        /**
         * Current floor position.
         *
         * @returns {number[]} [x, 0, z]
         */
        getPosition() {
            return [position.x, 0, position.z];
        },

        /**
         * Current yaw in degrees, normalised to (−180, 180].
         *
         * @returns {number}
         */
        getYaw() {
            let deg = yaw * 180 / Math.PI;
            deg = ((deg % 360) + 360) % 360;
            return deg > 180 ? deg - 360 : deg;
        },

        /**
         * Replace the walking bounds (another room) and keep the position inside them.
         *
         * @param {Object|null} newbounds {min, max} or null for none.
         */
        setBounds(newbounds) {
            bounds = newbounds || null;
            clamp(position);
            target = null;
            apply();
        },

        /**
         * Reverse (or restore) the drag-to-look direction of each axis.
         *
         * @param {boolean} x Reverse left/right.
         * @param {boolean} y Reverse up/down.
         */
        setInvert(x, y) {
            invertx = !!x;
            inverty = !!y;
        },

        /**
         * Lock (or unlock) up/down dragging; locking also levels the view.
         *
         * @param {boolean} locked Whether dragging may tilt the view.
         */
        setLockY(locked) {
            locky = !!locked;
            if (locky) {
                pitch = 0;
                apply();
            }
        },

        /**
         * Suspend or resume the controls (an immersive session drives the camera meanwhile).
         *
         * Resuming puts the camera back at eye height above the current position.
         *
         * @param {boolean} on Whether the controls react to input.
         */
        setEnabled(on) {
            enabled = !!on;
            keys.clear();
            target = null;
            drag = null;
            stickinput.x = 0;
            stickinput.y = 0;
            setKnob(0, 0);
            if (enabled) {
                apply();
            }
        },

        /**
         * Allow or stop walking (a 360° photo room stands still at its centre). Turning, looking and
         * clicking stay; without walking the stick is hidden and the wheel and a pinch zoom.
         *
         * @param {boolean} on Whether the walker may move.
         */
        setWalk(on) {
            walk = !!on;
            target = null;
            stickinput.x = 0;
            stickinput.y = 0;
            stickpointer = null;
            setKnob(0, 0);
            pinch = null;
            if (stick) {
                stick.classList.toggle('d-none', !walk);
            }
        },

        /**
         * Set how far up and down the view may tilt.
         *
         * @param {number} degrees Limit in degrees (1 to 89).
         */
        setPitchLimit(degrees) {
            const value = Number(degrees);
            pitchlimit = Number.isFinite(value) ? Math.max(1, Math.min(89, value)) * Math.PI / 180 : PITCH;
            pitch = Math.max(-pitchlimit, Math.min(pitchlimit, pitch));
            if (enabled) {
                // Not while an immersive session moves the rig.
                apply();
            }
        },

        /**
         * Look around by moving the device, or stop. On iOS this asks for permission, so call it
         * straight from the user's click or change event.
         *
         * @param {boolean} on Whether the view follows the device.
         * @returns {Promise<boolean>} whether it is on now
         */
        setMotionLook(on) {
            if (!on) {
                motion.on = false;
                motion.offset = null;
                return Promise.resolve(false);
            }
            if (!motionLookAvailable()) {
                return Promise.resolve(false);
            }
            const start = () => {
                if (!motion.listening) {
                    motion.listening = true;
                    listen(window, 'deviceorientation', onOrientation);
                }
                motion.on = true;
                motion.offset = null;
                motion.sample = null;
                return true;
            };
            const Orientation = window.DeviceOrientationEvent;
            if (Orientation && typeof Orientation.requestPermission === 'function') {
                // Called synchronously from the gesture; the answer comes later.
                return Promise.resolve(Orientation.requestPermission())
                    .then((answer) => (answer === 'granted' ? start() : false))
                    .catch(() => false);
            }
            return Promise.resolve(start());
        },

        /**
         * Whether the view follows the device's orientation.
         *
         * @returns {boolean}
         */
        isMotionLook() {
            return motion.on;
        },

        /** Remove every listener. */
        detach() {
            listeners.forEach((remove) => remove());
            listeners.length = 0;
            motion.listening = false;
            motion.on = false;
            touches.clear();
            keys.clear();
        },
    };
};
