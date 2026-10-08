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
 * With a rig (a group holding the camera) the controls move and turn the rig and only pitch the
 * camera inside it, so an immersive WebXR session can take over the camera's pose while teleport
 * and snap turn keep moving the rig.
 *
 * @module     vrroomruntime_builtin/controls
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as THREE from 'vrroomruntime_builtin/three';

/** Walking speed in metres per second. */
const WALK = 2.5;

/** Turning speed for Q and E in radians per second. */
const TURN = 1.8;

/** Radians per pixel when dragging to look. */
const LOOK = 0.005;

/** Pitch limit (±80°). */
const PITCH = 80 * Math.PI / 180;

/** Pointer travel under which a press counts as a click (px). */
const CLICK = 5;

/** Bounds are shrunk by this margin (m). */
const MARGIN = 0.3;

/** Radius of the virtual stick's travel (px). */
const STICK = 60;

/** Keys and the movement they request: [forward, right]. */
const MOVES = {
    KeyW: [1, 0], ArrowUp: [1, 0],
    KeyS: [-1, 0], ArrowDown: [-1, 0],
    KeyA: [0, -1], ArrowLeft: [0, -1],
    KeyD: [0, 1], ArrowRight: [0, 1],
};

/**
 * Attach controls to a camera and canvas.
 *
 * Options: bounds {min, max}; eyeHeight (default 1.6); stick (HTMLElement of the virtual stick);
 * rig (THREE.Object3D that holds the camera; optional); onClick(ndc {x, y}) → true when the click
 * hit something (no walking then); onActivate() (Enter); onTouch() (first touch input seen);
 * invertX, invertY (reverse the drag-to-look direction on that axis); lockY (dragging never tilts).
 *
 * @param {Object} camera THREE.PerspectiveCamera.
 * @param {HTMLCanvasElement} canvas The canvas.
 * @param {Object} options Options.
 * @returns {Object} {update(dt), teleport(position, yaw), getPosition(), getYaw(), setBounds(bounds),
 *     setInvert(x, y), setLockY(locked), setEnabled(enabled), detach()}
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
    let turn = 0;
    let shift = false;
    const stickinput = {x: 0, y: 0};
    let drag = null;
    let stickpointer = null;
    const raycaster = new THREE.Raycaster();
    const floor = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    const listeners = [];

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
        if (MOVES[e.code]) {
            keys.add(e.code);
            target = null;
            e.preventDefault();
        } else if (e.code === 'KeyQ') {
            turn = 1;
            e.preventDefault();
        } else if (e.code === 'KeyE') {
            turn = -1;
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
        if (e.code === 'KeyQ' || e.code === 'KeyE') {
            turn = 0;
        }
    });
    listen(canvas, 'blur', () => {
        keys.clear();
        turn = 0;
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
        if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') {
            return;
        }
        if (drag) {
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
        if (!drag || e.pointerId !== drag.id) {
            return;
        }
        const dx = e.clientX - drag.lastx;
        const dy = e.clientY - drag.lasty;
        drag.lastx = e.clientX;
        drag.lasty = e.clientY;
        drag.moved = Math.max(drag.moved, Math.hypot(e.clientX - drag.x, e.clientY - drag.y));
        if (drag.moved >= CLICK) {
            yaw -= (invertx ? -dx : dx) * LOOK;
            if (!locky) {
                pitch = Math.max(-PITCH, Math.min(PITCH, pitch - (inverty ? -dy : dy) * LOOK));
            }
        }
    });
    const endDrag = (e, cancelled) => {
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
        if (!handled) {
            walkToFloor(point);
        }
    };
    listen(canvas, 'pointerup', (e) => endDrag(e, false));
    listen(canvas, 'pointercancel', (e) => endDrag(e, true));

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
            if (!enabled) {
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
            yaw += turn * TURN * step;
            let forward = 0;
            let right = 0;
            keys.forEach((code) => {
                forward += MOVES[code][0];
                right += MOVES[code][1];
            });
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
                position.x += (-sin * forward + cos * right) * speed * step;
                position.z += (-cos * forward - sin * right) * speed * step;
                clamp(position);
            } else if (target) {
                const dx = target.x - position.x;
                const dz = target.z - position.z;
                const distance = Math.hypot(dx, dz);
                const move = WALK * step;
                if (distance <= Math.max(0.05, move)) {
                    position.x = target.x;
                    position.z = target.z;
                    target = null;
                } else {
                    position.x += dx / distance * move;
                    position.z += dz / distance * move;
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
            }
            target = null;
            apply();
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
            turn = 0;
            target = null;
            drag = null;
            stickinput.x = 0;
            stickinput.y = 0;
            setKnob(0, 0);
            if (enabled) {
                apply();
            }
        },

        /** Remove every listener. */
        detach() {
            listeners.forEach((remove) => remove());
            listeners.length = 0;
            keys.clear();
        },
    };
};
