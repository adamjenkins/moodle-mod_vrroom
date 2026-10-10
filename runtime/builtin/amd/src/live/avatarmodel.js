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
 * A simple procedural avatar for a person in a live room: a head with a face screen, a body,
 * hands in VR, a name label and a ring that shows when the person speaks.
 *
 * The same builder draws the live avatars and the preview of the avatar designer (local_avatar),
 * so what a person designs is exactly what the others see.
 *
 * Appearance document "vrroom-avatar/1" (only names are stored and sent; the hex values are here):
 *
 *     {"schema": "vrroom-avatar/1", "body": "capsule|slim|broad", "head": "round|square",
 *      "color": <colour>, "accent": <colour>, "hair": "none|short|long|bun", "haircolor": <hair colour>,
 *      "glasses": bool, "hat": "none|cap|beanie|sunhat", "picture": bool}
 *
 * Colours: red #d32f2f, orange #f57c00, yellow #fbc02d, green #388e3c, teal #00897b, cyan #00acc1,
 * blue #1e88e5, indigo #3949ab, purple #8e24aa, pink #d81b60, brown #6d4c41, grey #757575.
 * Hair colours: black #1c1c1c, brown #5d3a1a, blonde #e0c068, auburn #8d3b1f, grey #9e9e9e,
 * white #eeeeee. The body colour is used for the head, body and hands; the accent colour for the
 * collar, the belt and the hat.
 *
 * Without an appearance (null) the avatar looks as before: one colour worked out from the seed
 * (the anonymous peer id) and a dark visor band. The webcam still works on such an avatar: the video
 * fades in on a screen over the visor.
 *
 * The face screen is on the front of the head (-Z): it shows the designed face (eyes and a smile),
 * the profile picture as a round badge when the appearance asks for it and a picture URL is given,
 * and the live webcam when setVideo() is given a playing video element. The video is shown
 * un-mirrored (as the camera sees the person, which is how the others see them), cropped to fill the
 * screen, and cross-faded in and out over about 300 ms. While the video shows, the glasses are hidden
 * (the real face is on the screen).
 *
 * A new avatar stands in its group with its head centre at (0, STANDING_HEAD_HEIGHT, 0) looking
 * along -Z, until setPose() moves it; a preview camera should look at it from -Z.
 *
 * The avatar as a group with a sphere head follows mod_gear's collaborative viewer.
 *
 * @module     vrroomruntime_builtin/live/avatarmodel
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Schema name of the appearance document. */
export const SCHEMA = 'vrroom-avatar/1';

/** Body and accent colours: name → hex. */
export const COLOURS = Object.freeze({
    red: '#d32f2f',
    orange: '#f57c00',
    yellow: '#fbc02d',
    green: '#388e3c',
    teal: '#00897b',
    cyan: '#00acc1',
    blue: '#1e88e5',
    indigo: '#3949ab',
    purple: '#8e24aa',
    pink: '#d81b60',
    brown: '#6d4c41',
    grey: '#757575',
});

/** Hair colours: name → hex. */
export const HAIR_COLOURS = Object.freeze({
    black: '#1c1c1c',
    brown: '#5d3a1a',
    blonde: '#e0c068',
    auburn: '#8d3b1f',
    grey: '#9e9e9e',
    white: '#eeeeee',
});

/** The allowed values of each choice of the appearance document. */
export const CHOICES = Object.freeze({
    body: Object.freeze(['capsule', 'slim', 'broad']),
    head: Object.freeze(['round', 'square']),
    color: Object.freeze(Object.keys(COLOURS)),
    accent: Object.freeze(Object.keys(COLOURS)),
    hair: Object.freeze(['none', 'short', 'long', 'bun']),
    haircolor: Object.freeze(Object.keys(HAIR_COLOURS)),
    hat: Object.freeze(['none', 'cap', 'beanie', 'sunhat']),
});

/** The on/off choices of the appearance document. */
export const FLAGS = Object.freeze(['glasses', 'picture']);

/** The default appearance. */
export const DEFAULT_APPEARANCE = Object.freeze({
    schema: SCHEMA,
    body: 'capsule',
    head: 'round',
    color: 'blue',
    accent: 'grey',
    hair: 'none',
    haircolor: 'brown',
    glasses: false,
    hat: 'none',
    picture: true,
});

/** Longest appearance document accepted (characters of JSON). */
export const APPEARANCE_MAX = 2048;

/** Duration of the webcam cross-fade (ms). */
export const FADE_MS = 300;

/** Height of the head centre of a new avatar, before setPose() (m). */
export const STANDING_HEAD_HEIGHT = 1.6;

/** Head radius (m). */
const HEAD_RADIUS = 0.12;

/** Half size of the rounded-square head (m). */
const HEAD_HALF = 0.11;

/** Body shapes: capsule radius and length of its straight part (m). */
const BODIES = {
    capsule: {radius: 0.18, length: 0.5},
    slim: {radius: 0.13, length: 0.58},
    broad: {radius: 0.23, length: 0.42},
};

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

/** Face screen: width, height and centre height in the head (m). */
const SCREEN_WIDTH = 0.15;
const SCREEN_HEIGHT = 0.11;
const SCREEN_Y = -0.005;

/** Face screen: distance of its centre from the head centre, round and square head (m). */
const SCREEN_ROUND = HEAD_RADIUS + 0.007;
const SCREEN_SQUARE = HEAD_HALF + 0.008;

/** Face canvas size (px). */
const FACE_WIDTH = 192;
const FACE_HEIGHT = Math.round(FACE_WIDTH * SCREEN_HEIGHT / SCREEN_WIDTH);

/** Eyes (and glasses): distance from the middle and height in the head (m). */
const EYE_X = 0.035;
const EYE_Y = 0.012;

/** Colour of the face screen and the visor. */
const SCREEN_COLOUR = '#1b1f24';

/** Colour of the designed eyes and smile. */
const FACE_COLOUR = '#e8f4ff';

/** Colour of the glasses frame. */
const GLASSES_COLOUR = '#202326';

/**
 * Whether a value is a plain object (not null, not an array).
 *
 * @param {*} value Anything.
 * @returns {boolean}
 */
const isObject = (value) => !!value && typeof value === 'object' && !Array.isArray(value);

/**
 * A clean appearance document, or null for "today's look".
 *
 * Accepts the object or its JSON text. Anything that is not a "vrroom-avatar/1" document gives null;
 * unknown keys are dropped and unknown values replaced by the defaults.
 *
 * @param {Object|string|null} input Appearance.
 * @returns {Object|null} A new object with every key of DEFAULT_APPEARANCE, or null.
 */
export const cleanAppearance = (input) => {
    let value = input;
    if (typeof value === 'string') {
        if (value === '' || value.length > APPEARANCE_MAX) {
            return null;
        }
        try {
            value = JSON.parse(value);
        } catch (error) {
            return null;
        }
    }
    if (!isObject(value) || value.schema !== SCHEMA) {
        return null;
    }
    const clean = {schema: SCHEMA};
    Object.keys(DEFAULT_APPEARANCE).forEach((key) => {
        if (key === 'schema') {
            return;
        }
        if (FLAGS.indexOf(key) >= 0) {
            // A real true or false is kept; anything else is the default, as on the server.
            clean[key] = typeof value[key] === 'boolean' ? value[key] : DEFAULT_APPEARANCE[key];
        } else {
            const allowed = CHOICES[key];
            clean[key] = typeof value[key] === 'string' && allowed.indexOf(value[key]) >= 0 ? value[key] : DEFAULT_APPEARANCE[key];
        }
    });
    return clean;
};

/**
 * The appearance of a peer as the server sends it: JSON text, '' for none.
 *
 * @param {*} text JSON text of the appearance document.
 * @returns {Object|null} Clean appearance or null ('' or invalid).
 */
export const parseAppearance = (text) => (typeof text === 'string' ? cleanAppearance(text) : null);

/**
 * A picture URL that may be loaded: http(s) only, else ''.
 *
 * @param {*} url URL.
 * @returns {string}
 */
export const cleanPictureUrl = (url) => {
    const text = typeof url === 'string' ? url.trim() : '';
    return /^https?:\/\//i.test(text) && text.length <= 2048 ? text : '';
};

/**
 * Texture repeat and offset that fill a screen with an image without stretching it (cover crop).
 *
 * @param {number} source Aspect ratio (width / height) of the image.
 * @param {number} target Aspect ratio (width / height) of the screen.
 * @returns {Object} {repeatx, repeaty, offsetx, offsety}
 */
export const coverCrop = (source, target) => {
    const s = Number(source);
    const t = Number(target);
    if (!(s > 0) || !(t > 0) || !Number.isFinite(s) || !Number.isFinite(t)) {
        return {repeatx: 1, repeaty: 1, offsetx: 0, offsety: 0};
    }
    if (s > t) {
        const r = t / s;
        return {repeatx: r, repeaty: 1, offsetx: (1 - r) / 2, offsety: 0};
    }
    const r = s / t;
    return {repeatx: 1, repeaty: r, offsetx: 0, offsety: (1 - r) / 2};
};

/**
 * The value of a fade at a time.
 *
 * @param {Object} fade {from, to, start} (start in ms).
 * @param {number} now Time in ms.
 * @param {number} duration Fade duration in ms.
 * @returns {number} Between from and to.
 */
export const fadeValue = (fade, now, duration = FADE_MS) => {
    if (!fade || !(duration > 0)) {
        return fade ? fade.to : 0;
    }
    const t = Math.min(1, Math.max(0, (Number(now) - fade.start) / duration));
    return fade.from + (fade.to - fade.from) * t;
};

/**
 * Whether a media stream has a video track that is live and not muted.
 *
 * @param {Object|null} stream MediaStream.
 * @returns {boolean}
 */
export const hasLiveVideo = (stream) => {
    if (!stream || typeof stream.getVideoTracks !== 'function') {
        return false;
    }
    return stream.getVideoTracks().some((track) => track && track.readyState === 'live' && !track.muted &&
        track.enabled !== false);
};

/**
 * A stable hue (0..359) from a peer id.
 *
 * @param {string} peerid Peer id.
 * @returns {number}
 */
export const hueOf = (peerid) => {
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
 * Distance from the centre to the surface of a rounded cube (superellipsoid, power 4) along a
 * unit direction, for a half size of 1.
 *
 * @param {number} x Direction x.
 * @param {number} y Direction y.
 * @param {number} z Direction z.
 * @returns {number}
 */
const squareRadius = (x, y, z) => Math.pow(x ** 4 + y ** 4 + z ** 4, -0.25);

/**
 * Shape a unit sphere (or a part of one) to the head: round, or a rounded square.
 *
 * @param {Object} geometry THREE.SphereGeometry of radius 1.
 * @param {number} scale Size relative to the head (1 = the head itself).
 * @param {boolean} square Rounded-square head.
 * @returns {Object} The same geometry.
 */
const shapeToHead = (geometry, scale, square) => {
    const position = geometry.attributes.position;
    for (let i = 0; i < position.count; i++) {
        let x = position.getX(i);
        let y = position.getY(i);
        let z = position.getZ(i);
        const length = Math.hypot(x, y, z) || 1;
        x /= length;
        y /= length;
        z /= length;
        const r = square ? HEAD_HALF * squareRadius(x, y, z) * scale : HEAD_RADIUS * scale;
        position.setXYZ(i, x * r, y * r, z * r);
    }
    position.needsUpdate = true;
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();
    return geometry;
};

/**
 * Draw a rounded rectangle path.
 *
 * @param {CanvasRenderingContext2D} ctx Context.
 * @param {number} x Left.
 * @param {number} y Top.
 * @param {number} w Width.
 * @param {number} h Height.
 * @param {number} r Corner radius.
 */
const roundRect = (ctx, x, y, w, h, r) => {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
};

/**
 * A canvas of a size.
 *
 * @param {number} width Width (px).
 * @param {number} height Height (px).
 * @returns {HTMLCanvasElement}
 */
const makeCanvas = (width, height) => {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    return canvas;
};

/**
 * Create an avatar.
 *
 * Options: name (plain text for the label), pictureurl (profile picture, used when the appearance
 * has picture: true), seed (the anonymous peer id: the colour of an avatar without appearance and
 * the peer id kept in userData; peerid is accepted as an alias).
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object|string|null} appearance Appearance document (object or JSON), or null for today's look.
 * @param {Object} options {name, pictureurl, seed}
 * @returns {Object} Avatar {group, object (the same group), setPose(pose, mode), setName(name),
 *     setSpeaking(level), setMicState(state), setAppearance(appearance, pictureurl), setVideo(video|null),
 *     update(nowms), dispose()}
 */
export const createAvatar = (THREE, appearance, options) => {
    const opts = options || {};
    const seed = String(opts.seed || opts.peerid || '');
    const srgb = (texture) => {
        if (THREE.SRGBColorSpace) {
            texture.colorSpace = THREE.SRGBColorSpace;
        }
        return texture;
    };
    const now = () => (typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now());

    // Resources that live as long as the avatar.
    const geometries = [];
    const materials = [];
    const textures = [];
    // Resources of the current appearance, replaced by setAppearance().
    let built = {geometries: [], materials: [], textures: []};
    const keep = (list, item) => {
        list.push(item);
        return item;
    };
    const bg = (item) => keep(built.geometries, item);
    const bm = (item) => keep(built.materials, item);

    const group = new THREE.Group();
    group.name = 'vrroom-avatar';
    group.userData.vrroomPeer = seed;

    // Head (moved and turned by the head pose); its parts are rebuilt with the appearance.
    const head = new THREE.Group();
    head.name = 'head';
    group.add(head);
    const headparts = new THREE.Group();
    headparts.name = 'headparts';
    head.add(headparts);

    // Body (turns with the head's yaw only).
    const body = new THREE.Group();
    body.name = 'body';
    group.add(body);

    // Hands: one shared geometry, the material follows the appearance.
    const handgeometry = keep(geometries, new THREE.SphereGeometry(HAND_RADIUS, 16, 12));
    const hands = [new THREE.Mesh(handgeometry), new THREE.Mesh(handgeometry)];
    hands.forEach((hand, i) => {
        hand.name = i === 0 ? 'lefthand' : 'righthand';
        hand.visible = false;
        group.add(hand);
    });

    // Face canvas: designed face or picture badge (drawn with canvas calls, never HTML).
    const facecanvas = makeCanvas(FACE_WIDTH, FACE_HEIGHT);
    const facetexture = keep(textures, srgb(new THREE.CanvasTexture(facecanvas)));
    const facematerial = keep(materials, new THREE.MeshBasicMaterial({map: facetexture, alphaTest: 0.5, toneMapped: false}));
    const facescreen = new THREE.Mesh(undefined, facematerial);
    facescreen.name = 'facescreen';

    // Webcam screen in front of the face screen, with rounded corners from an alpha mask.
    const maskcanvas = makeCanvas(64, Math.round(64 * SCREEN_HEIGHT / SCREEN_WIDTH));
    const maskctx = maskcanvas.getContext('2d');
    if (maskctx) {
        maskctx.fillStyle = '#000000';
        maskctx.fillRect(0, 0, maskcanvas.width, maskcanvas.height);
        maskctx.fillStyle = '#ffffff';
        roundRect(maskctx, 0, 0, maskcanvas.width, maskcanvas.height, 9);
        maskctx.fill();
    }
    const masktexture = keep(textures, new THREE.CanvasTexture(maskcanvas));
    const videomaterial = keep(materials, new THREE.MeshBasicMaterial({
        color: 0xffffff,
        alphaMap: masktexture,
        transparent: true,
        opacity: 0,
        depthWrite: false,
        toneMapped: false,
    }));
    const videoscreen = new THREE.Mesh(undefined, videomaterial);
    videoscreen.name = 'videoscreen';
    videoscreen.renderOrder = 1;
    videoscreen.visible = false;
    head.add(facescreen);
    head.add(videoscreen);

    // Name label: a canvas drawn with fillText (never HTML).
    const canvas = makeCanvas(CANVAS_WIDTH, CANVAS_HEIGHT);
    const texture = keep(textures, srgb(new THREE.CanvasTexture(canvas)));
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

    let name = '';
    let micstate = 'off';
    let yaw = 0;
    let disposed = false;
    let look = null;
    let pictureurl = '';
    let picture = null;
    let picturetoken = 0;
    let bodydrop = HEAD_RADIUS + NECK + BODIES.capsule.radius + BODIES.capsule.length / 2;
    let lastpose = {h: [0, STANDING_HEAD_HEIGHT, 0, 0, 0, 0, 1], l: null, r: null};
    let lastmode = '3d';
    let glasses = null;
    let accentcss = COLOURS.grey;
    const quaternion = new THREE.Quaternion();
    const forward = new THREE.Vector3();

    // Webcam state.
    let video = null;
    let pendingvideo = null;
    let videotexture = null;
    let fade = {from: 0, to: 0, start: 0};
    const videolisteners = [];

    const drawLabel = () => {
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }
        ctx.clearRect(0, 0, CANVAS_WIDTH, CANVAS_HEIGHT);
        ctx.fillStyle = 'rgba(20, 24, 28, 0.72)';
        roundRect(ctx, 0, 4, CANVAS_WIDTH, CANVAS_HEIGHT - 8, 14);
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

    /**
     * Canvas pixel of a point of the head.
     *
     * @param {number} x Across the face (m).
     * @param {number} y Height (m).
     * @returns {number[]} [px, py]
     */
    const facePixel = (x, y) => [
        (0.5 + x / SCREEN_WIDTH) * FACE_WIDTH,
        (0.5 - (y - SCREEN_Y) / SCREEN_HEIGHT) * FACE_HEIGHT,
    ];

    const drawFace = () => {
        const ctx = facecanvas.getContext('2d');
        if (!ctx) {
            return;
        }
        ctx.clearRect(0, 0, FACE_WIDTH, FACE_HEIGHT);
        ctx.fillStyle = SCREEN_COLOUR;
        roundRect(ctx, 0, 0, FACE_WIDTH, FACE_HEIGHT, 26);
        ctx.fill();
        if (picture) {
            // Round badge, cropped to fill the circle.
            const cx = FACE_WIDTH / 2;
            const cy = FACE_HEIGHT / 2;
            const radius = FACE_HEIGHT * 0.45;
            const iw = picture.naturalWidth || picture.width || 1;
            const ih = picture.naturalHeight || picture.height || 1;
            const side = Math.min(iw, ih);
            ctx.save();
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.closePath();
            ctx.clip();
            try {
                ctx.drawImage(picture, (iw - side) / 2, (ih - side) / 2, side, side, cx - radius, cy - radius,
                    radius * 2, radius * 2);
            } catch (error) {
                // A picture that cannot be drawn leaves the badge empty.
            }
            ctx.restore();
            ctx.strokeStyle = accentcss;
            ctx.lineWidth = 4;
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.stroke();
        } else {
            // Designed face: two eyes and a smile.
            ctx.fillStyle = FACE_COLOUR;
            [-EYE_X, EYE_X].forEach((x) => {
                const [px, py] = facePixel(x, EYE_Y);
                ctx.beginPath();
                ctx.ellipse(px, py, 10, 13, 0, 0, Math.PI * 2);
                ctx.fill();
            });
            const [mx, my] = facePixel(0, -0.022);
            ctx.strokeStyle = FACE_COLOUR;
            ctx.lineWidth = 6;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.arc(mx, my - 14, 26, Math.PI * 0.22, Math.PI * 0.78);
            ctx.stroke();
        }
        facetexture.needsUpdate = true;
    };

    /**
     * Face screen geometry: a plane bent round the head (round) or flat in front of it (square),
     * facing -Z, with u running to the right of someone who looks at the face.
     *
     * @param {boolean} square Whether the head is the rounded square.
     * @param {number} lift How far in front of the head the screen sits (m).
     * @returns {Object} THREE.BufferGeometry
     */
    const screenGeometry = (square, lift) => {
        const geometry = new THREE.PlaneGeometry(SCREEN_WIDTH, SCREEN_HEIGHT, square ? 1 : 8, 1);
        const position = geometry.attributes.position;
        const radius = (square ? SCREEN_SQUARE : SCREEN_ROUND) + lift;
        for (let i = 0; i < position.count; i++) {
            const x = position.getX(i);
            const y = position.getY(i) + SCREEN_Y;
            if (square) {
                position.setXYZ(i, -x, y, -radius);
            } else {
                const angle = x / radius;
                position.setXYZ(i, -radius * Math.sin(angle), y, -radius * Math.cos(angle));
            }
        }
        position.needsUpdate = true;
        geometry.computeVertexNormals();
        geometry.computeBoundingSphere();
        return geometry;
    };

    /**
     * A piece of a unit sphere shaped to the head.
     *
     * @param {number} scale Size relative to the head.
     * @param {boolean} square Whether the head is the rounded square.
     * @param {number} phistart Sphere phi start.
     * @param {number} philength Sphere phi length.
     * @param {number} thetastart Sphere theta start.
     * @param {number} thetalength Sphere theta length.
     * @returns {Object} THREE.BufferGeometry
     */
    const headPiece = (scale, square, phistart, philength, thetastart, thetalength) => bg(shapeToHead(
        new THREE.SphereGeometry(1, 16, 6, phistart, philength, thetastart, thetalength), scale, square));

    const buildHair = (style, square, material) => {
        if (style === 'none') {
            return;
        }
        // Front of the head is -Z, which is phi = 1.5 pi on THREE.SphereGeometry.
        const crown = new THREE.Mesh(headPiece(1.07, square, 0, Math.PI * 2, 0, Math.PI * 0.28), material);
        headparts.add(crown);
        const sidesend = style === 'long' ? Math.PI * 0.66 : Math.PI * 0.6;
        const sides = new THREE.Mesh(headPiece(1.07, square, Math.PI * 1.8, Math.PI * 1.4, Math.PI * 0.28,
            sidesend - Math.PI * 0.28), material);
        headparts.add(sides);
        const back = square ? HEAD_HALF : HEAD_RADIUS;
        if (style === 'long') {
            const fall = new THREE.Mesh(bg(new THREE.BoxGeometry(back * 1.8, 0.22, 0.035)), material);
            fall.position.set(0, -0.1, back * 0.82);
            headparts.add(fall);
        } else if (style === 'bun') {
            const bun = new THREE.Mesh(bg(new THREE.SphereGeometry(0.05, 10, 8)), material);
            bun.position.set(0, back * 0.7, back * 0.95);
            headparts.add(bun);
        }
    };

    const buildHat = (style, square, material) => {
        const top = square ? HEAD_HALF : HEAD_RADIUS;
        if (style === 'cap') {
            headparts.add(new THREE.Mesh(headPiece(1.1, square, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            // Brim: the front half of a thin disc.
            const brim = new THREE.Mesh(bg(new THREE.CylinderGeometry(0.085, 0.085, 0.008, 12, 1, false,
                Math.PI * 0.5, Math.PI)), material);
            brim.position.set(0, top * 0.5, -top * 0.82);
            brim.rotation.x = -0.12;
            headparts.add(brim);
        } else if (style === 'beanie') {
            headparts.add(new THREE.Mesh(headPiece(1.1, square, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            headparts.add(new THREE.Mesh(headPiece(1.14, square, 0, Math.PI * 2, Math.PI * 0.3, Math.PI * 0.08), material));
            const bobble = new THREE.Mesh(bg(new THREE.SphereGeometry(0.028, 8, 6)), material);
            bobble.position.set(0, top * 1.12 + 0.02, 0);
            headparts.add(bobble);
        } else if (style === 'sunhat') {
            headparts.add(new THREE.Mesh(headPiece(1.13, square, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            const brim = new THREE.Mesh(bg(new THREE.CylinderGeometry(0.25, 0.25, 0.006, 24)), material);
            brim.position.y = 0.057;
            headparts.add(brim);
        }
    };

    const buildGlasses = (square) => {
        const material = bm(new THREE.MeshStandardMaterial({color: GLASSES_COLOUR, roughness: 0.4, metalness: 0.3}));
        const front = (square ? SCREEN_SQUARE : SCREEN_ROUND) + 0.012;
        const frame = new THREE.Group();
        frame.name = 'glasses';
        const lens = bg(new THREE.TorusGeometry(0.026, 0.004, 4, 14));
        [-EYE_X, EYE_X].forEach((x) => {
            const rim = new THREE.Mesh(lens, material);
            rim.position.set(x, EYE_Y, -front);
            frame.add(rim);
        });
        const bridge = new THREE.Mesh(bg(new THREE.CylinderGeometry(0.003, 0.003, EYE_X * 2 - 0.052, 4)), material);
        bridge.rotation.z = Math.PI / 2;
        bridge.position.set(0, EYE_Y, -front);
        frame.add(bridge);
        const armlength = front;
        const arm = bg(new THREE.BoxGeometry(0.004, 0.004, armlength));
        [-1, 1].forEach((side) => {
            const piece = new THREE.Mesh(arm, material);
            piece.position.set(side * (EYE_X + 0.026), EYE_Y, -front + armlength / 2);
            frame.add(piece);
        });
        headparts.add(frame);
        return frame;
    };

    const buildBody = (shape, material, accent) => {
        const spec = BODIES[shape] || BODIES.capsule;
        bodydrop = HEAD_RADIUS + NECK + spec.radius + spec.length / 2;
        // Today's capsule keeps its segments; designed bodies use a few less.
        const geometry = accent ? new THREE.CapsuleGeometry(spec.radius, spec.length, 4, 14) :
            new THREE.CapsuleGeometry(spec.radius, spec.length, 6, 16);
        body.add(new THREE.Mesh(bg(geometry), material));
        if (accent) {
            // Collar and belt: short open cylinders just outside the body.
            const collar = new THREE.Mesh(bg(new THREE.CylinderGeometry(spec.radius * 0.78, spec.radius * 0.95, 0.05, 14, 1, true)),
                accent);
            collar.position.y = spec.length / 2 + spec.radius * 0.45;
            body.add(collar);
            const belt = new THREE.Mesh(bg(new THREE.CylinderGeometry(spec.radius * 1.03, spec.radius * 1.03, 0.06, 14, 1, true)),
                accent);
            belt.position.y = -spec.length * 0.2;
            body.add(belt);
        }
    };

    const clearParts = () => {
        [headparts, body].forEach((parent) => {
            while (parent.children.length) {
                parent.remove(parent.children[0]);
            }
        });
        built.geometries.forEach((g) => g.dispose());
        built.materials.forEach((m) => m.dispose());
        built.textures.forEach((t) => t.dispose());
        built = {geometries: [], materials: [], textures: []};
        glasses = null;
    };

    const build = () => {
        clearParts();
        const square = !!look && look.head === 'square';
        const colour = look ? new THREE.Color(COLOURS[look.color]) :
            new THREE.Color().setHSL(hueOf(seed) / 360, 0.55, 0.55);
        accentcss = look ? COLOURS[look.accent] : COLOURS.grey;
        const doubleside = THREE.DoubleSide;
        const skin = bm(new THREE.MeshStandardMaterial({color: colour, roughness: 0.6, metalness: 0}));
        hands.forEach((hand) => {
            hand.material = skin;
        });

        if (look) {
            const headgeometry = square ? shapeToHead(new THREE.SphereGeometry(1, 20, 14), 1, true) :
                new THREE.SphereGeometry(HEAD_RADIUS, 20, 14);
            headparts.add(new THREE.Mesh(bg(headgeometry), skin));
            const accent = bm(new THREE.MeshStandardMaterial({color: new THREE.Color(accentcss), roughness: 0.7,
                metalness: 0, side: doubleside}));
            const hair = bm(new THREE.MeshStandardMaterial({color: new THREE.Color(HAIR_COLOURS[look.haircolor]),
                roughness: 0.9, metalness: 0, side: doubleside}));
            buildHair(look.hair, square, hair);
            buildHat(look.hat, square, accent);
            if (look.glasses) {
                glasses = buildGlasses(square);
            }
            buildBody(look.body, skin, accent);
            facescreen.visible = true;
        } else {
            // Today's look: a sphere head with a visor band on its front (-Z).
            headparts.add(new THREE.Mesh(bg(new THREE.SphereGeometry(HEAD_RADIUS, 24, 16)), skin));
            const visormaterial = bm(new THREE.MeshStandardMaterial({color: 0x1b1f24, roughness: 0.3, metalness: 0.2}));
            const visor = new THREE.Mesh(bg(new THREE.CylinderGeometry(HEAD_RADIUS * 1.02, HEAD_RADIUS * 1.02, 0.05, 24, 1,
                true, Math.PI * 0.7, Math.PI * 0.6)), visormaterial);
            visor.position.y = 0.02;
            headparts.add(visor);
            buildBody('capsule', skin, null);
            facescreen.visible = false;
        }
        facescreen.geometry = bg(screenGeometry(square, 0));
        videoscreen.geometry = bg(screenGeometry(square, 0.002));
        inert(group);
        drawFace();
        applyPose();
        applyVideoState(now());
    };

    const loadPicture = () => {
        picturetoken++;
        picture = null;
        const url = look && look.picture ? pictureurl : '';
        if (!url || typeof Image === 'undefined') {
            return;
        }
        const token = picturetoken;
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.decoding = 'async';
        image.onload = () => {
            if (!disposed && token === picturetoken) {
                picture = image;
                drawFace();
            }
        };
        // A picture that does not load leaves the designed face.
        image.onerror = () => undefined;
        image.src = url;
    };

    const placeHand = (mesh, pose) => {
        if (Array.isArray(pose) && pose.length === 7) {
            mesh.position.set(pose[0], pose[1], pose[2]);
            mesh.visible = true;
        } else {
            mesh.visible = false;
        }
    };

    /**
     * Put the head, body and hands where the last pose says.
     */
    function applyPose() {
        const h = lastpose.h;
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
        const immersive = lastmode === 'vr' || lastmode === 'mr' || lastmode === 'ar';
        placeHand(hands[0], immersive ? lastpose.l : null);
        placeHand(hands[1], immersive ? lastpose.r : null);
    }

    // Webcam.

    const fitVideo = () => {
        if (!videotexture || !video) {
            return;
        }
        const crop = coverCrop((video.videoWidth || 4) / (video.videoHeight || 3), SCREEN_WIDTH / SCREEN_HEIGHT);
        videotexture.repeat.set(crop.repeatx, crop.repeaty);
        videotexture.offset.set(crop.offsetx, crop.offsety);
    };

    const unlistenVideo = () => {
        videolisteners.forEach(([element, type, handler]) => element.removeEventListener(type, handler));
        videolisteners.length = 0;
    };

    const listenVideo = (element, type, handler) => {
        element.addEventListener(type, handler);
        videolisteners.push([element, type, handler]);
    };

    const releaseVideo = () => {
        unlistenVideo();
        if (videotexture) {
            videomaterial.map = null;
            videomaterial.needsUpdate = true;
            videotexture.dispose();
            videotexture = null;
        }
        video = null;
    };

    const fadeTo = (target, at) => {
        const current = fadeValue(fade, at);
        fade = {from: current, to: target, start: at};
    };

    /**
     * Show the webcam layer as far as its fade has got.
     *
     * @param {number} at Now (ms).
     */
    function applyVideoState(at) {
        const value = fadeValue(fade, at);
        videomaterial.opacity = value;
        videoscreen.visible = value > 0.001 || fade.to > 0;
        if (glasses) {
            glasses.visible = value < 0.5;
        }
        if (fade.to === 0 && value <= 0.001 && videotexture && !pendingvideo) {
            releaseVideo();
        }
    }

    const showVideo = (element) => {
        if (disposed || pendingvideo !== element) {
            return;
        }
        pendingvideo = null;
        if (video !== element) {
            releaseVideo();
            video = element;
            videotexture = srgb(new THREE.VideoTexture(element));
            videotexture.generateMipmaps = false;
            videotexture.minFilter = THREE.LinearFilter;
            videotexture.magFilter = THREE.LinearFilter;
            videomaterial.map = videotexture;
            videomaterial.needsUpdate = true;
            listenVideo(element, 'loadedmetadata', fitVideo);
            listenVideo(element, 'resize', fitVideo);
            fitVideo();
        }
        fadeTo(1, now());
        applyVideoState(now());
    };

    // The fade moves on whenever the screen is drawn, also where nobody calls update().
    videoscreen.onBeforeRender = () => applyVideoState(now());

    const avatar = {
        /** @type {Object} THREE.Group holding head, body, hands, label and ring. */
        group: group,

        /** @type {Object} The same group (older name). */
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
            lastpose = {h: pose.h, l: pose.l || null, r: pose.r || null};
            lastmode = typeof mode === 'string' ? mode : '3d';
            applyPose();
            applyVideoState(now());
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
            drawLabel();
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
            drawLabel();
        },

        /**
         * Change the look. Nothing happens when it is the same as before.
         *
         * @param {Object|string|null} value Appearance document (object or JSON), or null for today's look.
         * @param {string} url Profile picture URL, or ''.
         */
        setAppearance(value, url = '') {
            if (disposed) {
                return;
            }
            const next = cleanAppearance(value);
            const nexturl = cleanPictureUrl(url);
            const changed = JSON.stringify(next) !== JSON.stringify(look);
            const picturechanged = nexturl !== pictureurl;
            look = next;
            pictureurl = nexturl;
            if (changed) {
                build();
            }
            if (changed || picturechanged) {
                loadPicture();
                drawFace();
            }
        },

        /**
         * Show a live webcam on the face screen, or go back to the face.
         *
         * The video fades in once it has a frame to show, and fades out on null. The element stays
         * the caller's: it is never played, paused or removed here.
         *
         * @param {HTMLVideoElement|null} element A playing video element, or null.
         */
        setVideo(element) {
            if (disposed) {
                return;
            }
            const at = now();
            if (!element) {
                pendingvideo = null;
                fadeTo(0, at);
                applyVideoState(at);
                return;
            }
            if (element === video && fade.to === 1) {
                return;
            }
            pendingvideo = element;
            // HAVE_CURRENT_DATA: a frame can be shown.
            if (element.readyState >= 2 || element === video) {
                showVideo(element);
            } else {
                const ready = () => {
                    element.removeEventListener('loadeddata', ready);
                    showVideo(element);
                };
                element.addEventListener('loadeddata', ready);
            }
        },

        /**
         * Move the webcam fade on (also done by setPose() and when the screen is drawn).
         *
         * @param {number} nowms performance.now()
         */
        update(nowms) {
            if (!disposed) {
                applyVideoState(Number.isFinite(Number(nowms)) ? Number(nowms) : now());
            }
        },

        /** Remove the avatar and release its GPU resources. */
        dispose() {
            if (disposed) {
                return;
            }
            disposed = true;
            picturetoken++;
            pendingvideo = null;
            releaseVideo();
            if (group.parent) {
                group.parent.remove(group);
            }
            clearParts();
            geometries.forEach((g) => g.dispose());
            materials.forEach((m) => m.dispose());
            textures.forEach((t) => t.dispose());
        },
    };

    name = String(opts.name || '');
    drawLabel();
    look = cleanAppearance(appearance);
    pictureurl = cleanPictureUrl(opts.pictureurl);
    build();
    loadPicture();
    return avatar;
};
