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
 * WebXR input of the built-in viewer: two controllers with a ray and a reticle, procedural grips,
 * hands drawn as spheres, select (objects, panel buttons, teleport to the floor), snap turn with
 * the thumbstick, and squeeze to grab objects that can be picked up.
 *
 * Controller models are drawn here, not loaded: XRControllerModelFactory would fetch models from
 * a CDN, and the built-in viewer makes no external calls. The controller ray select follows
 * mod_gear's viewer.
 *
 * @module     vrroomruntime_builtin/xrinput
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Length of a ray that hits nothing (m). */
const RAY = 5;

/** Furthest distance a ray selects (m). */
const REACH = 30;

/** Thumbstick deflection that turns, and the deflection below which it re-arms. */
const TURN_ON = 0.6;
const TURN_OFF = 0.3;

/** Snap turn step (degrees). */
const TURN_STEP = 30;

/** Bounds are shrunk by this margin for teleports (m). */
const MARGIN = 0.3;

/** A hit at or below this height counts as the floor (m). */
const FLOOR = 0.05;

/**
 * Whether an object and all its ancestors are visible.
 *
 * @param {Object} object THREE.Object3D
 * @returns {boolean}
 */
const isShown = (object) => {
    let o = object;
    while (o) {
        if (o.visible === false) {
            return false;
        }
        o = o.parent;
    }
    return true;
};

/**
 * Create the XR input of a rig.
 *
 * Options: getTargets() → Object3D[]; onSelect(object3d|null, controller) → true when handled
 * (null: the ray hit nothing); onTeleport(point); teleportAllowed() → bool (also allows snap
 * turn); onGrabStart(itemid, controller) → bool; onGrabEnd(itemid); bounds ({min, max} or a
 * function returning them); camera (the camera inside the rig); onConnect(handedness, grip,
 * inputsource); onDisconnect(handedness).
 *
 * @param {Object} THREE three.js namespace (with XRHandModelFactory).
 * @param {Object} renderer THREE.WebGLRenderer.
 * @param {Object} rig The group holding the camera.
 * @param {Object} options Options.
 * @returns {Object} XrInput {update(dt), dispose(), releaseAll(), moveHeadTo(position, yawdeg), turn(deg), getGrip(handedness)}
 */
export const createXrInput = (THREE, renderer, rig, options = {}) => {
    const raycaster = new THREE.Raycaster();
    const rotation = new THREE.Matrix4();
    const floorplane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    const yaxis = new THREE.Vector3(0, 1, 0);
    const camera = options.camera || rig.children.find((c) => c.isCamera) || null;
    const handfactory = typeof THREE.XRHandModelFactory === 'function' ? new THREE.XRHandModelFactory() : null;
    const linegeometry = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0, 0), new THREE.Vector3(0, 0, -1)]);
    const reticlegeometry = new THREE.RingGeometry(0.012, 0.022, 24);
    const gripgeometry = new THREE.BoxGeometry(0.035, 0.035, 0.12);
    const entries = [];
    const removers = [];
    let disposed = false;

    const listen = (target, type, handler) => {
        target.addEventListener(type, handler);
        removers.push(() => target.removeEventListener(type, handler));
    };

    const getBounds = () => (typeof options.bounds === 'function' ? options.bounds() : options.bounds) || null;

    const getHead = () => {
        const head = new THREE.Vector3();
        if (camera) {
            camera.getWorldPosition(head);
        } else {
            rig.getWorldPosition(head);
        }
        return head;
    };

    // Point a raycaster along a controller's ray.
    const aim = (entry, far) => {
        entry.controller.updateMatrixWorld(true);
        rotation.identity().extractRotation(entry.controller.matrixWorld);
        raycaster.ray.origin.setFromMatrixPosition(entry.controller.matrixWorld);
        raycaster.ray.direction.set(0, 0, -1).applyMatrix4(rotation);
        raycaster.near = 0;
        raycaster.far = far;
    };

    // The first visible thing a controller's ray hits (sprites are see-through).
    const cast = (entry, far) => {
        aim(entry, far);
        const targets = options.getTargets ? options.getTargets() : [];
        const hits = raycaster.intersectObjects(targets, true);
        for (const hit of hits) {
            if (!isShown(hit.object) || hit.object.isSprite || hit.object.isLine || hit.object.isLineSegments) {
                continue;
            }
            return hit;
        }
        return null;
    };

    const inBounds = (point) => {
        const bounds = getBounds();
        if (!bounds) {
            return true;
        }
        return point.x >= bounds.min[0] + MARGIN && point.x <= bounds.max[0] - MARGIN &&
            point.z >= bounds.min[2] + MARGIN && point.z <= bounds.max[2] - MARGIN;
    };

    const teleportAllowed = () => !!(options.teleportAllowed && options.teleportAllowed());

    const api = {
        /**
         * Move the rig so the head is above a floor point, optionally facing a yaw.
         *
         * @param {number[]} position [x, y, z] (y is ignored).
         * @param {number|null} yawdeg Yaw in degrees, or null to keep the facing.
         */
        moveHeadTo(position, yawdeg) {
            if (yawdeg !== null && yawdeg !== undefined && Number.isFinite(Number(yawdeg)) && camera) {
                const dir = camera.getWorldDirection(new THREE.Vector3());
                const current = Math.atan2(-dir.x, -dir.z);
                api.turn((Number(yawdeg) * Math.PI / 180 - current) * 180 / Math.PI);
            }
            const head = getHead();
            rig.position.x += Number(position[0]) - head.x;
            rig.position.z += Number(position[2]) - head.z;
            rig.updateMatrixWorld(true);
        },

        /**
         * Turn the rig around the head.
         *
         * @param {number} deg Degrees, positive turns left.
         */
        turn(deg) {
            const angle = Number(deg) * Math.PI / 180;
            if (!Number.isFinite(angle) || angle === 0) {
                return;
            }
            const head = getHead();
            head.y = rig.position.y;
            const offset = rig.position.clone().sub(head).applyAxisAngle(yaxis, angle);
            rig.position.copy(head).add(offset);
            rig.rotation.y += angle;
            rig.updateMatrixWorld(true);
        },

        /**
         * The grip of a hand, when that controller is connected.
         *
         * @param {string} handedness 'left' or 'right'.
         * @returns {Object|null}
         */
        getGrip(handedness) {
            const entry = entries.find((e) => e.source && e.source.handedness === handedness);
            return entry ? entry.grip : null;
        },

        /** Put every held object back into its room. */
        releaseAll() {
            entries.forEach((entry) => release(entry));
        },

        /**
         * Update rays, reticles and snap turn.
         *
         * @param {number} dt Seconds since the last frame.
         */
        update(dt) {
            if (disposed || !(dt >= 0)) {
                return;
            }
            entries.forEach((entry) => {
                if (!entry.source || !entry.line.visible) {
                    entry.reticle.visible = false;
                    return;
                }
                const hit = cast(entry, REACH);
                if (hit) {
                    entry.line.scale.z = hit.distance;
                    entry.reticle.position.set(0, 0, -hit.distance + 0.005);
                    entry.reticle.visible = true;
                } else {
                    entry.line.scale.z = RAY;
                    entry.reticle.visible = false;
                }
                const gamepad = entry.source.gamepad;
                if (gamepad && gamepad.axes && gamepad.axes.length && teleportAllowed()) {
                    const x = gamepad.axes.length >= 4 ? gamepad.axes[2] : gamepad.axes[0];
                    if (entry.turnready && Math.abs(x) > TURN_ON) {
                        entry.turnready = false;
                        api.turn(x > 0 ? -TURN_STEP : TURN_STEP);
                    } else if (Math.abs(x) < TURN_OFF) {
                        entry.turnready = true;
                    }
                }
            });
        },

        /** Remove the controllers, grips and hands from the rig and release everything. */
        dispose() {
            if (disposed) {
                return;
            }
            api.releaseAll();
            disposed = true;
            removers.forEach((remove) => remove());
            removers.length = 0;
            entries.forEach((entry) => {
                entry.controller.remove(entry.line);
                entry.controller.remove(entry.reticle);
                entry.grip.remove(entry.gripmesh);
                if (entry.handmodel) {
                    entry.hand.remove(entry.handmodel);
                }
                [entry.controller, entry.grip, entry.hand].forEach((o) => {
                    if (o.parent === rig) {
                        rig.remove(o);
                    }
                });
                entry.line.material.dispose();
                entry.reticle.material.dispose();
                entry.gripmesh.material.dispose();
            });
            linegeometry.dispose();
            reticlegeometry.dispose();
            gripgeometry.dispose();
            entries.length = 0;
        },
    };

    const release = (entry) => {
        const grabbed = entry.grabbed;
        if (!grabbed) {
            return;
        }
        entry.grabbed = null;
        if (grabbed.parent && grabbed.parent.parent) {
            grabbed.parent.attach(grabbed.object);
            // Nothing falls through the floor.
            grabbed.object.position.y = Math.max(grabbed.object.position.y, FLOOR);
        } else if (grabbed.object.parent) {
            grabbed.object.parent.remove(grabbed.object);
        }
        if (options.onGrabEnd) {
            options.onGrabEnd(grabbed.id);
        }
    };

    const onSelect = (entry) => {
        if (disposed || !entry.source) {
            return;
        }
        const hit = cast(entry, REACH);
        const handled = options.onSelect ? options.onSelect(hit ? hit.object : null, entry.controller) : false;
        if (handled || !teleportAllowed()) {
            return;
        }
        let point = null;
        if (hit) {
            if (hit.point.y <= FLOOR) {
                point = hit.point.clone();
            }
        } else {
            aim(entry, REACH);
            const onfloor = new THREE.Vector3();
            if (raycaster.ray.intersectPlane(floorplane, onfloor) && onfloor.distanceTo(raycaster.ray.origin) <= REACH) {
                point = onfloor;
            }
        }
        if (point && inBounds(point) && options.onTeleport) {
            options.onTeleport(point);
        }
    };

    const onGrab = (entry) => {
        if (disposed || !entry.source || entry.grabbed) {
            return;
        }
        const hit = cast(entry, REACH);
        if (!hit) {
            return;
        }
        let object = hit.object;
        while (object && !(object.userData.vrroomKind === 'grabbable' && object.userData.vrroomItem !== undefined)) {
            object = object.parent;
        }
        if (!object) {
            return;
        }
        const id = object.userData.vrroomItem;
        // Taking it from the other hand.
        entries.forEach((other) => {
            if (other !== entry && other.grabbed && other.grabbed.object === object) {
                other.grabbed = null;
            }
        });
        if (options.onGrabStart && !options.onGrabStart(id, entry.controller)) {
            return;
        }
        const parent = object.userData.vrroomHome || object.parent;
        object.userData.vrroomHome = parent;
        entry.grabbed = {object: object, parent: parent, id: id};
        entry.controller.attach(object);
    };

    for (let i = 0; i < 2; i++) {
        const controller = renderer.xr.getController(i);
        const grip = renderer.xr.getControllerGrip(i);
        const hand = renderer.xr.getHand(i);

        const line = new THREE.Line(linegeometry, new THREE.LineBasicMaterial({color: 0xffffff, transparent: true, opacity: 0.6}));
        line.name = 'vrroom-xr-ray';
        line.scale.z = RAY;
        line.visible = false;
        controller.add(line);

        const reticle = new THREE.Mesh(reticlegeometry, new THREE.MeshBasicMaterial({
            color: 0xffffff, side: THREE.DoubleSide, transparent: true, opacity: 0.9, depthTest: false,
        }));
        reticle.name = 'vrroom-xr-reticle';
        reticle.renderOrder = 20;
        reticle.visible = false;
        controller.add(reticle);

        const gripmesh = new THREE.Mesh(gripgeometry, new THREE.MeshStandardMaterial({color: 0x495057, roughness: 0.6}));
        gripmesh.name = 'vrroom-xr-grip';
        gripmesh.visible = false;
        grip.add(gripmesh);

        let handmodel = null;
        if (handfactory) {
            handmodel = handfactory.createHandModel(hand, 'spheres');
            hand.add(handmodel);
        }

        rig.add(controller);
        rig.add(grip);
        rig.add(hand);

        const entry = {controller, grip, hand, line, reticle, gripmesh, handmodel, source: null, grabbed: null, turnready: true};
        entries.push(entry);

        listen(controller, 'connected', (e) => {
            const source = e.data || null;
            entry.source = source;
            const pointer = !!source && source.targetRayMode === 'tracked-pointer';
            line.visible = pointer;
            gripmesh.visible = pointer && !source.hand;
            if (source && options.onConnect) {
                options.onConnect(source.handedness, grip, source);
            }
        });
        listen(controller, 'disconnected', () => {
            const handedness = entry.source ? entry.source.handedness : null;
            release(entry);
            entry.source = null;
            line.visible = false;
            reticle.visible = false;
            gripmesh.visible = false;
            if (options.onDisconnect) {
                options.onDisconnect(handedness);
            }
        });
        listen(controller, 'select', () => onSelect(entry));
        listen(controller, 'squeezestart', () => onGrab(entry));
        listen(controller, 'squeezeend', () => release(entry));
    }

    return api;
};
