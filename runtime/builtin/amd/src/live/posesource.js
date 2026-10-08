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
 * The own pose for a live room: head, and the hands in an immersive session, in room coordinates.
 *
 * Room coordinates are relative to the current room root, so a person who placed the room in
 * mixed reality and a person at a desktop agree on where things are.
 *
 * Hands: the grip of a controller when it has a pose; for hand tracking (where three.js updates
 * the hand joints and not the grip) the 'wrist' joint of the hand with that handedness.
 *
 * @module     mavrruntime_builtin/live/posesource
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The two hands, in the order of the pose message fields l and r. */
const HANDEDNESS = ['left', 'right'];

/**
 * Create the pose source.
 *
 * Options: camera (THREE.Camera), getRoomRoot() → Object3D, getGrip(handedness) → Object3D|null,
 * getHand(index) → Object3D|null (index 0 and 1, as renderer.xr.getHand), isXr() → bool.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} options Options.
 * @returns {Object} PoseSource {sample(), dispose()}
 */
export const createPoseSource = (THREE, options) => {
    const camera = options.camera;
    const inverse = new THREE.Matrix4();
    const relative = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3();

    // Handedness of each hand space, learned from three.js's 'connected' events.
    const handedness = [null, null];
    const removers = [];
    [0, 1].forEach((index) => {
        const hand = typeof options.getHand === 'function' ? options.getHand(index) : null;
        if (!hand || typeof hand.addEventListener !== 'function') {
            return;
        }
        const onConnected = (event) => {
            handedness[index] = event && event.data && typeof event.data.handedness === 'string' ? event.data.handedness : null;
        };
        const onDisconnected = () => {
            handedness[index] = null;
        };
        hand.addEventListener('connected', onConnected);
        hand.addEventListener('disconnected', onDisconnected);
        removers.push(() => {
            hand.removeEventListener('connected', onConnected);
            hand.removeEventListener('disconnected', onDisconnected);
        });
    });

    // Whether an object and its parents are shown.
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

    // An object's world pose relative to the room root, as [x, y, z, qx, qy, qz, qw].
    const poseOf = (object) => {
        object.updateWorldMatrix(true, false);
        relative.multiplyMatrices(inverse, object.matrixWorld);
        relative.decompose(position, quaternion, scale);
        quaternion.normalize();
        const values = [position.x, position.y, position.z, quaternion.x, quaternion.y, quaternion.z, quaternion.w];
        return values.every((v) => Number.isFinite(v)) ? values : null;
    };

    // The object that stands for one hand, or null.
    const handObject = (which) => {
        const grip = typeof options.getGrip === 'function' ? options.getGrip(which) : null;
        if (grip && isShown(grip)) {
            return grip;
        }
        const index = handedness.indexOf(which);
        if (index < 0) {
            return null;
        }
        const hand = options.getHand(index);
        const wrist = hand && hand.joints ? hand.joints.wrist : null;
        return wrist && isShown(wrist) ? wrist : null;
    };

    return {
        /**
         * The own pose now.
         *
         * @returns {Object|null} {h, l, r} (arrays of 7, hands null when not tracked), or null
         *     when there is no room root yet.
         */
        sample() {
            const room = options.getRoomRoot ? options.getRoomRoot() : null;
            if (!room || !camera) {
                return null;
            }
            room.updateWorldMatrix(true, false);
            inverse.copy(room.matrixWorld).invert();
            const head = poseOf(camera);
            if (!head) {
                return null;
            }
            const xr = typeof options.isXr === 'function' && options.isXr();
            const hands = HANDEDNESS.map((which) => {
                if (!xr) {
                    return null;
                }
                const object = handObject(which);
                return object ? poseOf(object) : null;
            });
            return {h: head, l: hands[0], r: hands[1]};
        },

        /** Stop listening to the hand spaces. */
        dispose() {
            removers.forEach((remove) => remove());
            removers.length = 0;
        },
    };
};
