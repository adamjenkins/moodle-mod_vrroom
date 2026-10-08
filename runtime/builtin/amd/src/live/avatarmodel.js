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
 * A simple procedural avatar for a person in a live room: a head with a visor, a body, hands in
 * VR, a name label and a ring that shows when the person speaks.
 *
 * The avatar as a group with a sphere head follows mod_gear's collaborative viewer.
 *
 * @module     vrroomruntime_builtin/live/avatarmodel
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Head radius (m). */
const HEAD_RADIUS = 0.12;

/** Body capsule radius and length of its straight part (m). */
const BODY_RADIUS = 0.18;
const BODY_LENGTH = 0.5;

/** Gap between the bottom of the head and the top of the body (m). */
const NECK = 0.05;

/** Hand radius (m). */
const HAND_RADIUS = 0.045;

/** Height of the name label above the head centre (m). */
const LABEL_HEIGHT = 0.3;

/** Size of the label in the room (m). */
const LABEL_WIDTH = 0.5;

/** Label canvas size (px). */
const CANVAS_WIDTH = 256;
const CANVAS_HEIGHT = 64;

/** Longest name shown on the label (characters, then an ellipsis). */
const NAME_MAX = 24;

/** Height of the speaking ring above the head centre (m). */
const RING_HEIGHT = 0.42;

/** Colours of the microphone dot on the label. */
const MIC_COLOURS = {off: '#9e9e9e', on: '#43a047', muted: '#e53935'};

/** Smallest horizontal look direction for which the body turns with the head. */
const MIN_YAW_LENGTH = 0.05;

/**
 * A stable hue (0..359) from a peer id.
 *
 * @param {string} peerid Peer id.
 * @returns {number}
 */
const hueOf = (peerid) => {
    const text = String(peerid || '');
    // Polynomial string hash kept below 2^31 so it stays exact in floating point.
    let hash = 7;
    for (let i = 0; i < text.length; i++) {
        hash = (hash * 31 + text.charCodeAt(i)) % 2147483647;
    }
    return hash % 360;
};

/**
 * A name cut to the label length.
 *
 * @param {string} name Plain text name.
 * @returns {string}
 */
const shortName = (name) => {
    const chars = Array.from(String(name || '').replace(/\s+/g, ' ').trim());
    return chars.length > NAME_MAX ? chars.slice(0, NAME_MAX).join('') + '…' : chars.join('');
};

/**
 * Make an object invisible to raycasts and shadows.
 *
 * @param {Object} object THREE.Object3D
 */
const inert = (object) => {
    object.traverse((child) => {
        child.castShadow = false;
        child.receiveShadow = false;
        child.raycast = () => undefined;
    });
};

/**
 * Create an avatar.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} options {peerid, name}
 * @returns {Object} Avatar {object, setPose(pose, mode), setName(name), setSpeaking(level),
 *     setMicState(state), dispose()}
 */
export const createAvatar = (THREE, options) => {
    const peerid = String(options.peerid || '');
    const colour = new THREE.Color().setHSL(hueOf(peerid) / 360, 0.55, 0.55);
    const geometries = [];
    const materials = [];
    const keep = (list, item) => {
        list.push(item);
        return item;
    };

    const group = new THREE.Group();
    group.name = 'vrroom-avatar';
    group.userData.vrroomPeer = peerid;

    const skin = keep(materials, new THREE.MeshStandardMaterial({color: colour, roughness: 0.6, metalness: 0}));
    const visormaterial = keep(materials, new THREE.MeshStandardMaterial({color: 0x1b1f24, roughness: 0.3, metalness: 0.2}));

    // Head with a visor band on its front (-Z).
    const head = new THREE.Group();
    head.name = 'head';
    head.add(new THREE.Mesh(keep(geometries, new THREE.SphereGeometry(HEAD_RADIUS, 24, 16)), skin));
    const visor = new THREE.Mesh(keep(geometries, new THREE.CylinderGeometry(
        HEAD_RADIUS * 1.02, HEAD_RADIUS * 1.02, 0.05, 24, 1, true, Math.PI * 0.7, Math.PI * 0.6)), visormaterial);
    visor.position.y = 0.02;
    head.add(visor);
    group.add(head);

    // Body: turns with the head's yaw only.
    const body = new THREE.Mesh(keep(geometries, new THREE.CapsuleGeometry(BODY_RADIUS, BODY_LENGTH, 6, 16)), skin);
    body.name = 'body';
    const bodydrop = HEAD_RADIUS + NECK + BODY_RADIUS + BODY_LENGTH / 2;
    group.add(body);

    // Hands.
    const handgeometry = keep(geometries, new THREE.SphereGeometry(HAND_RADIUS, 16, 12));
    const hands = [new THREE.Mesh(handgeometry, skin), new THREE.Mesh(handgeometry, skin)];
    hands.forEach((hand, i) => {
        hand.name = i === 0 ? 'lefthand' : 'righthand';
        hand.visible = false;
        group.add(hand);
    });

    // Name label: a canvas drawn with fillText (never HTML).
    const canvas = document.createElement('canvas');
    canvas.width = CANVAS_WIDTH;
    canvas.height = CANVAS_HEIGHT;
    const texture = new THREE.CanvasTexture(canvas);
    if (THREE.SRGBColorSpace) {
        texture.colorSpace = THREE.SRGBColorSpace;
    }
    const labelmaterial = keep(materials, new THREE.SpriteMaterial({map: texture, transparent: true, depthTest: true}));
    const label = new THREE.Sprite(labelmaterial);
    label.name = 'label';
    label.scale.set(LABEL_WIDTH, LABEL_WIDTH * CANVAS_HEIGHT / CANVAS_WIDTH, 1);
    group.add(label);

    // Speaking ring above the label.
    const ringmaterial = keep(materials, new THREE.MeshBasicMaterial({
        color: 0xffffff,
        transparent: true,
        opacity: 0,
        depthWrite: false,
    }));
    const ring = new THREE.Mesh(keep(geometries, new THREE.TorusGeometry(0.07, 0.007, 8, 40)), ringmaterial);
    ring.name = 'ring';
    ring.rotation.x = Math.PI / 2;
    ring.visible = false;
    group.add(ring);

    inert(group);

    let name = '';
    let micstate = 'off';
    let yaw = 0;
    let disposed = false;
    const quaternion = new THREE.Quaternion();
    const forward = new THREE.Vector3();

    const draw = () => {
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }
        ctx.clearRect(0, 0, CANVAS_WIDTH, CANVAS_HEIGHT);
        // Rounded background.
        const r = 14;
        ctx.fillStyle = 'rgba(20, 24, 28, 0.72)';
        ctx.beginPath();
        ctx.moveTo(r, 4);
        ctx.lineTo(CANVAS_WIDTH - r, 4);
        ctx.quadraticCurveTo(CANVAS_WIDTH, 4, CANVAS_WIDTH, 4 + r);
        ctx.lineTo(CANVAS_WIDTH, CANVAS_HEIGHT - 4 - r);
        ctx.quadraticCurveTo(CANVAS_WIDTH, CANVAS_HEIGHT - 4, CANVAS_WIDTH - r, CANVAS_HEIGHT - 4);
        ctx.lineTo(r, CANVAS_HEIGHT - 4);
        ctx.quadraticCurveTo(0, CANVAS_HEIGHT - 4, 0, CANVAS_HEIGHT - 4 - r);
        ctx.lineTo(0, 4 + r);
        ctx.quadraticCurveTo(0, 4, r, 4);
        ctx.closePath();
        ctx.fill();
        // Microphone dot.
        ctx.fillStyle = MIC_COLOURS[micstate] || MIC_COLOURS.off;
        ctx.beginPath();
        ctx.arc(24, CANVAS_HEIGHT / 2, 9, 0, Math.PI * 2);
        ctx.fill();
        // Name, shrunk to fit.
        const text = shortName(name);
        const space = CANVAS_WIDTH - 48;
        let size = 28;
        ctx.font = `600 ${size}px sans-serif`;
        while (size > 14 && ctx.measureText(text).width > space) {
            size -= 2;
            ctx.font = `600 ${size}px sans-serif`;
        }
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, 42, CANVAS_HEIGHT / 2 + 1, space);
        texture.needsUpdate = true;
    };

    const placeHand = (mesh, pose) => {
        if (Array.isArray(pose) && pose.length === 7) {
            mesh.position.set(pose[0], pose[1], pose[2]);
            mesh.visible = true;
        } else {
            mesh.visible = false;
        }
    };

    const avatar = {
        /** @type {Object} THREE.Group holding head, body, hands, label and ring. */
        object: group,

        /**
         * Show a pose (room coordinates).
         *
         * @param {Object} pose {h: number[7], l: number[7]|null, r: number[7]|null}
         * @param {string} mode The person's mode ('3d', 'vr', 'mr', 'ar').
         */
        setPose(pose, mode) {
            if (disposed || !pose || !Array.isArray(pose.h) || pose.h.length !== 7) {
                return;
            }
            const h = pose.h;
            head.position.set(h[0], h[1], h[2]);
            quaternion.set(h[3], h[4], h[5], h[6]).normalize();
            head.quaternion.copy(quaternion);
            forward.set(0, 0, -1).applyQuaternion(quaternion);
            if (Math.hypot(forward.x, forward.z) > MIN_YAW_LENGTH) {
                yaw = Math.atan2(-forward.x, -forward.z);
            }
            body.position.set(h[0], h[1] - bodydrop, h[2]);
            body.rotation.set(0, yaw, 0);
            label.position.set(h[0], h[1] + LABEL_HEIGHT, h[2]);
            ring.position.set(h[0], h[1] + RING_HEIGHT, h[2]);
            const immersive = mode === 'vr' || mode === 'mr' || mode === 'ar';
            placeHand(hands[0], immersive ? pose.l : null);
            placeHand(hands[1], immersive ? pose.r : null);
        },

        /**
         * Set the name on the label.
         *
         * @param {string} value Plain text.
         */
        setName(value) {
            const text = String(value || '');
            if (disposed || text === name) {
                return;
            }
            name = text;
            draw();
        },

        /**
         * Show how loud the person speaks.
         *
         * @param {number} level 0..1
         */
        setSpeaking(level) {
            if (disposed) {
                return;
            }
            const value = Math.min(1, Math.max(0, Number(level) || 0));
            ring.visible = value > 0.02;
            ring.scale.setScalar(1 + value * 0.8);
            ringmaterial.opacity = 0.25 + 0.75 * value;
        },

        /**
         * Show the microphone state.
         *
         * @param {string} state 'off', 'on' or 'muted'.
         */
        setMicState(state) {
            const value = Object.prototype.hasOwnProperty.call(MIC_COLOURS, state) ? state : 'off';
            if (disposed || value === micstate) {
                return;
            }
            micstate = value;
            draw();
        },

        /** Remove the avatar and release its GPU resources. */
        dispose() {
            if (disposed) {
                return;
            }
            disposed = true;
            if (group.parent) {
                group.parent.remove(group);
            }
            geometries.forEach((g) => g.dispose());
            materials.forEach((m) => m.dispose());
            texture.dispose();
        },
    };

    name = String(options.name || '');
    draw();
    return avatar;
};
