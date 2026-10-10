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
 * Appearance document "vrroom-avatar/1" (only names are stored and sent; the hex values are here).
 * Every key is optional; a missing or unknown value is the default (marked *), and the defaults
 * draw the look of the first version, so designs saved before a key existed look the same:
 *
 *     {"schema": "vrroom-avatar/1",
 *      "body": "capsule*|slim|broad",
 *      "head": "round*|square|oval|heart",
 *      "color": <colour, blue*>, "accent": <colour, grey*>,
 *      "skin": "match*|porcelain|fair|light|medium|tan|brown|dark|deep",
 *      "hair": "none*|short|long|bun|curly|afro|ponytail|braids|buzz|bob|mohawk|sidepart|pigtails|topknot",
 *      "haircolor": <hair colour, brown*>,
 *      "eyes": "dots*|round|almond|sleepy", "brows": "none*|thin|thick|arched",
 *      "nose": "none*|small|round|pointed", "ears": bool (false*),
 *      "beard": "none*|stubble|moustache|goatee|full",
 *      "glasses": bool (false*), "hat": "none*|cap|beanie|sunhat", "picture": bool (true*),
 *      "outfit": "none*"}
 *
 * Colours: red #d32f2f, orange #f57c00, yellow #fbc02d, green #388e3c, teal #00897b, cyan #00acc1,
 * blue #1e88e5, indigo #3949ab, purple #8e24aa, pink #d81b60, brown #6d4c41, grey #757575.
 * Hair colours: black #1c1c1c, brown #5d3a1a, blonde #e0c068, auburn #8d3b1f, grey #9e9e9e,
 * white #eeeeee, red #b8452a, pink #e78fb3, blue #3f6fd8, purple #7b4bb5.
 * Skin tones: porcelain #f9e4d8, fair #f1cfb6, light #e3b48f, medium #c99266, tan #b07646,
 * brown #8a5636, dark #683f25, deep #472a19; "match" uses the body colour, as the first version did.
 * The body colour is used for the body, and for the head and hands when the skin is "match"; the
 * accent colour for the collar, the belt, the hat and hair ties. Beards use the hair colour (the
 * moustache has a pale rim of it so it shows on the dark screen; stubble is a soft shadow in a light
 * tint of it on the face screen).
 * "outfit" is reserved for costumes: only "none" is allowed for now.
 *
 * Head shapes: round (a sphere), square (a rounded cube), oval (taller and narrower) and heart
 * (wider at the brow, narrow chin). The hair, hats, glasses, ears, beard and the face screen follow
 * the shape. Hair never covers the face screen; ears are left out under hair that covers them
 * (long, bob, afro, curly), and other hair is cut round the ears when they show. Under a hat, the
 * parts of the hair a hat would squash are left out (the mohawk's spikes, the top knot, the curls
 * and afro clumps on top, and the afro is flatter), and over the afro, the bob and the side part the
 * hat is drawn a little bigger, so the hat shows from every side.
 *
 * Without an appearance (null) the avatar looks as before: one colour worked out from the seed
 * (the anonymous peer id) and a dark visor band. The webcam still works on such an avatar: the video
 * fades in on a screen over the visor.
 *
 * The face screen is on the front of the head (-Z): it shows the designed face (eyes, brows, a
 * smile and stubble), the profile picture as a round badge when the appearance asks for it and a
 * picture URL is given, and the live webcam when setVideo() is given a playing video element. The
 * video is shown un-mirrored (as the camera sees the person, which is how the others see them),
 * cropped to fill the screen, and cross-faded in and out over about 300 ms. While the video shows,
 * the glasses, the nose and the beard are hidden (the real face is on the screen); while the profile
 * picture shows, the nose and the moustache are hidden so they do not cover it.
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

/** Hair colours (also beards): name → hex. */
export const HAIR_COLOURS = Object.freeze({
    black: '#1c1c1c',
    brown: '#5d3a1a',
    blonde: '#e0c068',
    auburn: '#8d3b1f',
    grey: '#9e9e9e',
    white: '#eeeeee',
    red: '#b8452a',
    pink: '#e78fb3',
    blue: '#3f6fd8',
    purple: '#7b4bb5',
});

/**
 * Skin tones of the head and hands, light to deep: name → hex. The choice "match" (the default) is
 * not here: it uses the body colour, as the first version did.
 */
export const SKIN_TONES = Object.freeze({
    porcelain: '#f9e4d8',
    fair: '#f1cfb6',
    light: '#e3b48f',
    medium: '#c99266',
    tan: '#b07646',
    brown: '#8a5636',
    dark: '#683f25',
    deep: '#472a19',
});

/** The allowed values of each choice of the appearance document. */
export const CHOICES = Object.freeze({
    body: Object.freeze(['capsule', 'slim', 'broad']),
    head: Object.freeze(['round', 'square', 'oval', 'heart']),
    color: Object.freeze(Object.keys(COLOURS)),
    accent: Object.freeze(Object.keys(COLOURS)),
    hair: Object.freeze(['none', 'short', 'long', 'bun', 'curly', 'afro', 'ponytail', 'braids', 'buzz', 'bob', 'mohawk',
        'sidepart', 'pigtails', 'topknot']),
    haircolor: Object.freeze(Object.keys(HAIR_COLOURS)),
    hat: Object.freeze(['none', 'cap', 'beanie', 'sunhat']),
    skin: Object.freeze(['match', ...Object.keys(SKIN_TONES)]),
    eyes: Object.freeze(['dots', 'round', 'almond', 'sleepy']),
    brows: Object.freeze(['none', 'thin', 'thick', 'arched']),
    nose: Object.freeze(['none', 'small', 'round', 'pointed']),
    beard: Object.freeze(['none', 'stubble', 'moustache', 'goatee', 'full']),
    // Reserved for costumes; nothing but "none" yet.
    outfit: Object.freeze(['none']),
});

/** The on/off choices of the appearance document. */
export const FLAGS = Object.freeze(['glasses', 'picture', 'ears']);

/** The default appearance: the look of the first version. */
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
    skin: 'match',
    eyes: 'dots',
    brows: 'none',
    nose: 'none',
    ears: false,
    beard: 'none',
    outfit: 'none',
});

/** Hair styles that cover the ears (no ears are drawn under them). */
const HAIR_OVER_EARS = Object.freeze(['long', 'bob', 'afro', 'curly']);

/**
 * Whether a hair style covers the ears, so that no ears are drawn.
 *
 * @param {string} style Hair style.
 * @returns {boolean}
 */
export const hairCoversEars = (style) => HAIR_OVER_EARS.indexOf(style) >= 0;

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

/**
 * How much the head grows while the webcam shows (1 = twice its size), so the face on the screen can
 * be seen across the room. It grows with the video's fade, upwards from the neck.
 */
export const VIDEO_HEAD_GROWTH = 1;

/**
 * Head scale and how far the head is lifted (m) at a webcam fade value.
 *
 * @param {number} value Video fade, 0 (no video) to 1 (video fully shown).
 * @returns {{scale: number, lift: number}}
 */
export const videoHeadScale = (value) => {
    const grow = Math.min(1, Math.max(0, Number(value) || 0)) * VIDEO_HEAD_GROWTH;
    return {scale: 1 + grow, lift: grow * HEAD_RADIUS};
};

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

/** Oval head: half width, half height and half depth (m). */
const OVAL = Object.freeze({x: 0.104, y: 0.136, z: 0.116});

/** Gap between an oval or heart head and its face screen (m). */
const SCREEN_GAP = 0.008;

/** Height of the nose and of the moustache in the head (m). */
const NOSE_Y = -0.006;
const MOUSTACHE_Y = -0.016;

/** Height of the mouth in the head (m). */
const MOUTH_Y = -0.022;

/**
 * Sphere theta (0 is the crown) of the lower edge of the hair band above the ears: where hair
 * that leaves the ears free is cut round them.
 */
const EAR_CUT = Math.PI * 0.435;

/** Sphere phi of the ears (the left one at 0, the right one at pi) and half the width of the cut round them. */
const EAR_GAP = Math.PI * 0.09;

/**
 * How much bigger a hat is drawn over a hair style that stands out further than the hat (1 = its
 * own size), so the hat sits on the hair and shows from every side.
 */
const HAT_FIT = Object.freeze({afro: 1.25, bob: 1.04, sidepart: 1.04});

/** Warm colour the skin glows a little in, so light skin tones do not look grey in cool light. */
const SKIN_GLOW = '#ff9466';

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
 * How much wider than a sphere the heart head is at a height: wider at the brow, narrow at the chin.
 *
 * @param {number} y Height of a unit direction (-1 chin to 1 crown).
 * @returns {number} Width factor.
 */
const heartWidth = (y) => (y >= 0.15 ? 1.07 : 1.07 - 0.37 * Math.pow((0.15 - y) / 1.15, 1.4));

/**
 * The point of the head surface (or of a shell around it) in a direction from the head centre.
 *
 * @param {string} shape Head shape: round, square, oval or heart.
 * @param {number} x Direction x.
 * @param {number} y Direction y.
 * @param {number} z Direction z.
 * @param {number} scale Size relative to the head (1 = the head itself).
 * @returns {number[]} [x, y, z] (m).
 */
const headPoint = (shape, x, y, z, scale = 1) => {
    const length = Math.hypot(x, y, z) || 1;
    const ux = x / length;
    const uy = y / length;
    const uz = z / length;
    let r = HEAD_RADIUS * scale;
    if (shape === 'square') {
        r = HEAD_HALF * squareRadius(ux, uy, uz) * scale;
    } else if (shape === 'oval') {
        r = scale / Math.hypot(ux / OVAL.x, uy / OVAL.y, uz / OVAL.z);
    } else if (shape === 'heart') {
        const width = heartWidth(uy);
        return [ux * r * width, uy * r, uz * r * (1 + (width - 1) * 0.4)];
    }
    return [ux * r, uy * r, uz * r];
};

/**
 * How far the head reaches to the side, the top, the front and the back (m).
 *
 * @param {string} shape Head shape.
 * @returns {{side: number, top: number, front: number, back: number}}
 */
const headExtent = (shape) => ({
    side: headPoint(shape, 1, 0, 0)[0],
    top: headPoint(shape, 0, 1, 0)[1],
    front: -headPoint(shape, 0, 0, -1)[2],
    back: headPoint(shape, 0, 0, 1)[2],
});

/**
 * How the face screen sits on a head: flat (square head), or bent round an ellipse (circle for the
 * round head) with half width a and half depth c at the screen's height.
 *
 * @param {string} shape Head shape.
 * @returns {{flat: boolean, a: number, c: number}}
 */
const screenFrame = (shape) => {
    if (shape === 'square') {
        return {flat: true, a: SCREEN_SQUARE, c: SCREEN_SQUARE};
    }
    if (shape === 'oval' || shape === 'heart') {
        const extent = headExtent(shape);
        return {flat: false, a: extent.side + SCREEN_GAP, c: extent.front + SCREEN_GAP};
    }
    return {flat: false, a: SCREEN_ROUND, c: SCREEN_ROUND};
};

/**
 * The point of the front of an ellipse (in the XZ plane, front at -Z) at an arc length from its
 * front centre, so that a screen bent round it keeps its width.
 *
 * @param {number} a Half width (m).
 * @param {number} c Half depth (m).
 * @param {number} s Arc length, signed (m).
 * @returns {number[]} [x, z]
 */
const ellipseArc = (a, c, s) => {
    const steps = 96;
    const target = Math.abs(s);
    const sign = s < 0 ? -1 : 1;
    let length = 0;
    let px = 0;
    let pz = c;
    for (let i = 1; i <= steps; i++) {
        const t = Math.PI / 2 * i / steps;
        const nx = a * Math.sin(t);
        const nz = c * Math.cos(t);
        const d = Math.hypot(nx - px, nz - pz);
        if (length + d >= target) {
            const k = d > 0 ? (target - length) / d : 0;
            return [sign * (px + (nx - px) * k), -(pz + (nz - pz) * k)];
        }
        length += d;
        px = nx;
        pz = nz;
    }
    return [sign * px, -pz];
};

/** Line width of each eyebrow style on the face canvas (px). */
const BROW_WIDTHS = {thick: 7, thin: 3, arched: 4.5};

/**
 * Mix two hex colours.
 *
 * @param {string} from Colour '#rrggbb'.
 * @param {string} to Colour '#rrggbb'.
 * @param {number} t 0 (from) to 1 (to).
 * @returns {string} Colour '#rrggbb'.
 */
const mixHex = (from, to, t) => {
    let out = '#';
    // The red, green and blue pairs of hex digits.
    [1, 3, 5].forEach((at) => {
        const ca = parseInt(from.slice(at, at + 2), 16);
        const cb = parseInt(to.slice(at, at + 2), 16);
        out += Math.round(ca + (cb - ca) * t).toString(16).padStart(2, '0');
    });
    return out;
};

/**
 * Lightness of a hex colour, 0 (black) to 1 (white), roughly as the eye sees it.
 *
 * @param {string} hex Colour '#rrggbb'.
 * @returns {number}
 */
const lightness = (hex) => {
    const channel = (at) => parseInt(hex.slice(at, at + 2), 16);
    return (0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)) / 255;
};

/**
 * Evenly spread directions on a sphere (golden spiral).
 *
 * @param {number} count How many.
 * @returns {number[][]} [x, y, z] unit directions.
 */
const spiralDirections = (count) => {
    const list = [];
    for (let i = 0; i < count; i++) {
        const y = 1 - (2 * i + 1) / count;
        const r = Math.sqrt(1 - y * y);
        const angle = i * 2.399963;
        list.push([Math.cos(angle) * r, y, Math.sin(angle) * r]);
    }
    return list;
};

/**
 * Directions of the clumps of an afro over its base volume: the crown, and the sides and back,
 * never in front of the face.
 */
const AFRO_DIRECTIONS = Object.freeze(spiralDirections(40).filter(([, y, z]) => y > 0.6 || (z > -0.1 && y > -0.6)));

/**
 * Directions of the curls of curly hair: evenly spread (golden spiral), on the crown, the sides and
 * the back, never in front of the face.
 */
const CURL_DIRECTIONS = Object.freeze((() => {
    const list = [];
    const count = 34;
    for (let i = 0; i < count; i++) {
        const y = 1 - (2 * i + 1) / count;
        const r = Math.sqrt(1 - y * y);
        const angle = i * 2.399963;
        const x = Math.cos(angle) * r;
        const z = Math.sin(angle) * r;
        if (y > 0.68 || (z > -0.3 && y > -0.3)) {
            list.push([x, y, z]);
        }
    }
    return list;
})());

/**
 * Shape a unit sphere (or a part of one) to the head, or to a shell around it.
 *
 * Options: flare (the shell grows by up to this much towards the bottom, for hair that stands out
 * at the chin) and tilt (the piece is turned by this angle round the Z axis before it is shaped,
 * which moves its edges and seams to one side).
 *
 * @param {Object} geometry THREE.SphereGeometry of radius 1.
 * @param {number} scale Size relative to the head (1 = the head itself).
 * @param {string} shape Head shape.
 * @param {Object} options {flare, tilt}
 * @returns {Object} The same geometry.
 */
const shapeToHead = (geometry, scale, shape, options = {}) => {
    const flare = options.flare || 0;
    const tilt = options.tilt || 0;
    const cos = Math.cos(tilt);
    const sin = Math.sin(tilt);
    const position = geometry.attributes.position;
    for (let i = 0; i < position.count; i++) {
        let x = position.getX(i);
        let y = position.getY(i);
        const z = position.getZ(i);
        const length = Math.hypot(x, y, z) || 1;
        x /= length;
        y /= length;
        if (tilt) {
            const tx = x * cos - y * sin;
            y = x * sin + y * cos;
            x = tx;
        }
        const grow = flare ? flare * Math.max(0, 0.3 - y) / 1.3 : 0;
        const point = headPoint(shape, x, y, z / length, scale + grow);
        position.setXYZ(i, point[0], point[1], point[2]);
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
    // The webcam fade the head's size follows (videoHeadScale()).
    let headgrow = 0;
    let lastmode = '3d';
    let glasses = null;
    // Parts that hide while the webcam (or the profile picture) shows; null when not built.
    let nose = null;
    let beardface = null;
    let beardlower = null;
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
            // Designed face: stubble, eyes, brows and a smile.
            const style = look || DEFAULT_APPEARANCE;
            if (style.beard === 'stubble') {
                drawStubble(ctx, mixHex(HAIR_COLOURS[style.haircolor], '#ffffff', 0.3));
            }
            drawEyes(ctx, style.eyes);
            drawBrows(ctx, style.brows);
            const [mx, my] = facePixel(0, MOUTH_Y);
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
     * Draw the eyes of the designed face.
     *
     * @param {CanvasRenderingContext2D} ctx Face canvas context.
     * @param {string} style dots (the first version's), round, almond or sleepy.
     */
    function drawEyes(ctx, style) {
        [-EYE_X, EYE_X].forEach((x) => {
            const [px, py] = facePixel(x, EYE_Y);
            // -1 for the eye on the left of the canvas, 1 for the right one.
            const side = x < 0 ? -1 : 1;
            ctx.fillStyle = FACE_COLOUR;
            ctx.beginPath();
            if (style === 'round') {
                ctx.arc(px, py, 13, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = SCREEN_COLOUR;
                ctx.beginPath();
                ctx.arc(px, py + 1, 6.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = FACE_COLOUR;
                ctx.beginPath();
                ctx.arc(px + 2.5, py - 2, 2.2, 0, Math.PI * 2);
                ctx.fill();
            } else if (style === 'almond') {
                // Outer corners a little up.
                ctx.ellipse(px, py, 15, 8, -side * 0.2, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = SCREEN_COLOUR;
                ctx.beginPath();
                ctx.arc(px, py, 5.5, 0, Math.PI * 2);
                ctx.fill();
            } else if (style === 'sleepy') {
                // The lower half of the eye under a straight lid.
                ctx.ellipse(px, py + 2, 13, 9, 0, 0, Math.PI);
                ctx.closePath();
                ctx.fill();
                ctx.strokeStyle = FACE_COLOUR;
                ctx.lineWidth = 4;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(px - 14, py + 2);
                ctx.lineTo(px + 14, py + 2);
                ctx.stroke();
            } else {
                ctx.ellipse(px, py, 10, 13, 0, 0, Math.PI * 2);
                ctx.fill();
            }
        });
    }

    /**
     * Draw the brows of the designed face.
     *
     * @param {CanvasRenderingContext2D} ctx Face canvas context.
     * @param {string} style none, thin, thick or arched.
     */
    function drawBrows(ctx, style) {
        if (style !== 'thin' && style !== 'thick' && style !== 'arched') {
            return;
        }
        ctx.strokeStyle = FACE_COLOUR;
        ctx.lineCap = 'round';
        ctx.lineWidth = BROW_WIDTHS[style] || BROW_WIDTHS.arched;
        [-EYE_X, EYE_X].forEach((x) => {
            const [px, py] = facePixel(x, EYE_Y);
            const side = x < 0 ? -1 : 1;
            const y = py - 24;
            const inner = px - side * 13;
            const outer = px + side * 14;
            ctx.beginPath();
            if (style === 'arched') {
                ctx.moveTo(inner, y + 3);
                ctx.quadraticCurveTo(px + side * 2, y - 9, outer, y + 4);
            } else {
                ctx.moveTo(inner, y + 1);
                ctx.lineTo(outer, y - 1);
            }
            ctx.stroke();
        });
    }

    /**
     * Draw stubble on the lower face: a soft shadow over the jaw and cheeks, thickest at the chin
     * and fading out towards the cheeks, in a tint of the hair colour that shows on the dark screen.
     *
     * The shadow is a stack of see-through ellipses (each adds a little colour), so it needs no
     * canvas gradient. It stays inside the screen and below the nose.
     *
     * @param {CanvasRenderingContext2D} ctx Face canvas context.
     * @param {string} colour CSS colour of the shadow.
     */
    function drawStubble(ctx, colour) {
        const [mx, my] = facePixel(0, MOUTH_Y);
        const top = facePixel(0, NOSE_Y)[1] + 4;
        const steps = 7;
        ctx.save();
        roundRect(ctx, 0, 0, FACE_WIDTH, FACE_HEIGHT, 26);
        ctx.clip();
        ctx.beginPath();
        ctx.rect(0, top, FACE_WIDTH, FACE_HEIGHT - top);
        ctx.clip();
        ctx.fillStyle = colour;
        ctx.globalAlpha = 0.085;
        for (let i = 0; i < steps; i++) {
            // From the widest (the cheeks) to the smallest (the chin).
            const k = 1 - i / steps;
            ctx.beginPath();
            ctx.ellipse(mx, my + 14 + 10 * (1 - k), 18 + 68 * k, 16 + 44 * k, 0, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
        ctx.globalAlpha = 1;
    }

    /**
     * Face screen geometry: a plane bent round the head (round, oval and heart) or flat in front of
     * it (square), facing -Z, with u running to the right of someone who looks at the face.
     *
     * @param {string} shape Head shape.
     * @param {number} lift How far in front of the head the screen sits (m).
     * @returns {Object} THREE.BufferGeometry
     */
    const screenGeometry = (shape, lift) => {
        const frame = screenFrame(shape);
        const geometry = new THREE.PlaneGeometry(SCREEN_WIDTH, SCREEN_HEIGHT, frame.flat ? 1 : 8, 1);
        const position = geometry.attributes.position;
        const radius = frame.c + lift;
        for (let i = 0; i < position.count; i++) {
            const x = position.getX(i);
            const y = position.getY(i) + SCREEN_Y;
            if (frame.flat) {
                position.setXYZ(i, -x, y, -radius);
            } else if (frame.a === frame.c) {
                const angle = x / radius;
                position.setXYZ(i, -radius * Math.sin(angle), y, -radius * Math.cos(angle));
            } else {
                const [ex, ez] = ellipseArc(frame.a + lift, radius, x);
                position.setXYZ(i, -ex, y, ez);
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
     * Options: w and h (sphere segments, default 16 and 6), and flare and tilt (see shapeToHead()).
     *
     * @param {number} scale Size relative to the head.
     * @param {string} shape Head shape.
     * @param {number} phistart Sphere phi start (the front of the head, -Z, is phi 1.5 pi).
     * @param {number} philength Sphere phi length.
     * @param {number} thetastart Sphere theta start (0 is the crown).
     * @param {number} thetalength Sphere theta length.
     * @param {Object} options {w, h, flare, tilt}
     * @returns {Object} THREE.BufferGeometry
     */
    const headPiece = (scale, shape, phistart, philength, thetastart, thetalength, options = {}) => bg(shapeToHead(
        new THREE.SphereGeometry(1, options.w || 16, options.h || 6, phistart, philength, thetastart, thetalength),
        scale, shape, options));

    /**
     * Move a geometry into place (it is changed and returned).
     *
     * @param {Object} geometry THREE.BufferGeometry
     * @param {number[]} position [x, y, z]
     * @param {Object|null} turn THREE.Quaternion, or null.
     * @param {number[]} scale [x, y, z]
     * @returns {Object} The same geometry.
     */
    const placeGeometry = (geometry, position, turn, scale = [1, 1, 1]) => geometry.applyMatrix4(new THREE.Matrix4().compose(
        new THREE.Vector3(position[0], position[1], position[2]), turn || new THREE.Quaternion(),
        new THREE.Vector3(scale[0], scale[1], scale[2])));

    /**
     * The turn that points one direction along another.
     *
     * @param {number[]} from Direction [x, y, z].
     * @param {number[]} to Direction [x, y, z].
     * @returns {Object} THREE.Quaternion
     */
    const turnTo = (from, to) => new THREE.Quaternion().setFromUnitVectors(
        new THREE.Vector3(from[0], from[1], from[2]).normalize(), new THREE.Vector3(to[0], to[1], to[2]).normalize());

    /**
     * One geometry made of several (positions, normals and indices), so many small parts cost one
     * draw call. The parts are disposed.
     *
     * @param {Object[]} parts Indexed THREE.BufferGeometry objects, already in place.
     * @returns {Object} THREE.BufferGeometry
     */
    const mergeGeometries = (parts) => {
        let vertices = 0;
        let indices = 0;
        parts.forEach((part) => {
            vertices += part.attributes.position.count;
            indices += part.index ? part.index.count : part.attributes.position.count;
        });
        const positions = new Float32Array(vertices * 3);
        const normals = new Float32Array(vertices * 3);
        const index = vertices > 65535 ? new Uint32Array(indices) : new Uint16Array(indices);
        let v = 0;
        let i = 0;
        parts.forEach((part) => {
            const count = part.attributes.position.count;
            for (let k = 0; k < count; k++) {
                positions[(v + k) * 3] = part.attributes.position.getX(k);
                positions[(v + k) * 3 + 1] = part.attributes.position.getY(k);
                positions[(v + k) * 3 + 2] = part.attributes.position.getZ(k);
                normals[(v + k) * 3] = part.attributes.normal.getX(k);
                normals[(v + k) * 3 + 1] = part.attributes.normal.getY(k);
                normals[(v + k) * 3 + 2] = part.attributes.normal.getZ(k);
            }
            if (part.index) {
                for (let k = 0; k < part.index.count; k++) {
                    index[i++] = part.index.getX(k) + v;
                }
            } else {
                for (let k = 0; k < count; k++) {
                    index[i++] = v + k;
                }
            }
            v += count;
            part.dispose();
        });
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));
        geometry.setIndex(new THREE.BufferAttribute(index, 1));
        geometry.computeBoundingSphere();
        return bg(geometry);
    };

    /**
     * A geometry from a grid of points: rows of columns, each row joined to the next.
     *
     * @param {number[][][]} rows Rows of [x, y, z] points, all of the same length.
     * @returns {Object} THREE.BufferGeometry
     */
    const gridGeometry = (rows) => {
        const columns = rows[0].length;
        const positions = new Float32Array(rows.length * columns * 3);
        const index = [];
        rows.forEach((row, r) => {
            row.forEach((point, c) => {
                positions.set(point, (r * columns + c) * 3);
                if (r > 0 && c > 0) {
                    const a = (r - 1) * columns + c - 1;
                    const b = (r - 1) * columns + c;
                    const d = r * columns + c - 1;
                    const e = r * columns + c;
                    index.push(a, d, b, b, d, e);
                }
            });
        });
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setIndex(index);
        geometry.computeVertexNormals();
        geometry.computeBoundingSphere();
        return bg(geometry);
    };

    /**
     * The back of long hair: a curved, thick shell that lies against the back of the head and
     * hangs to the shoulders, a little fuller at the bottom, with a soft rounded lower edge that is
     * longest in the middle. Its thickness runs out at the two side edges, so it is closed all round
     * except at the top, which is tucked under the sides of the hair.
     *
     * @param {string} shape Head shape.
     * @returns {Object} THREE.BufferGeometry
     */
    const hairFall = (shape) => {
        const ext = headExtent(shape);
        // Half the angle round the back (0 is straight behind), columns across and rows down.
        const span = Math.PI * 0.6;
        const columns = 12;
        const down = 5;
        const top = -0.02;
        const bottom = -0.21;
        const thick = 0.02;
        const roll = [0.25, 0.5, 0.75].map((t) => t * Math.PI);
        const point = (u, t, offset, sag) => {
            const angle = (u * 2 - 1) * span;
            const grow = 0.98 + 0.16 * t;
            const a = ext.side * grow + offset;
            const c = ext.back * grow + offset;
            // Longest in the middle of the back, rising clearly towards the sides (a rounded edge).
            const lowest = bottom + 0.1 * Math.abs(u * 2 - 1) ** 1.5;
            return [a * Math.sin(angle), top + (lowest - top) * t - sag, c * Math.cos(angle)];
        };
        const rows = [];
        const row = (fn) => {
            const list = [];
            for (let i = 0; i <= columns; i++) {
                const u = i / columns;
                list.push(fn(u, thick / 2 * Math.sqrt(Math.sin(Math.PI * u))));
            }
            rows.push(list);
        };
        // Down the outside, round the lower edge, and back up the inside.
        for (let k = 0; k <= down; k++) {
            row((u, half) => point(u, k / down, half, 0));
        }
        roll.forEach((angle) => row((u, half) => point(u, 1, half * Math.cos(angle), half * Math.sin(angle) * 0.8)));
        for (let k = down; k >= 0; k--) {
            row((u, half) => point(u, k / down, -half, 0));
        }
        return gridGeometry(rows);
    };

    /**
     * Build a hair style in a group named "hair" on the head.
     *
     * All styles are pieces of shells round the head or small parts placed on its surface, so they
     * follow the head shape and grow with the head while the webcam shows. None covers the face
     * screen (front of the head, below the hairline).
     *
     * Options: ears (the ears show: hair round the sides is cut round them) and hat (the hat worn:
     * the parts a hat would squash are left out, so the hat shows from every side).
     *
     * @param {string} style Hair style.
     * @param {string} shape Head shape.
     * @param {Object} material Hair material.
     * @param {Object} tie Material of hair ties (the accent colour).
     * @param {Object} options {ears, hat}
     * @returns {Object} THREE.Group
     */
    // One plain branch per hair style reads better than a table of fourteen small builders.
    // eslint-disable-next-line complexity
    const buildHair = (style, shape, material, tie, options = {}) => {
        const hair = new THREE.Group();
        hair.name = 'hair';
        headparts.add(hair);
        if (style === 'none' || !style) {
            return hair;
        }
        const ears = !!options.ears;
        const hat = !!options.hat && options.hat !== 'none';
        const ext = headExtent(shape);
        const PI = Math.PI;
        const add = (geometry, mat = material) => {
            const mesh = new THREE.Mesh(geometry, mat);
            hair.add(mesh);
            return mesh;
        };
        const piece = (scale, phistart, philength, thetastart, thetalength, opts) =>
            add(headPiece(scale, shape, phistart, philength, thetastart, thetalength, opts));
        // The sides and back from the hairline down to sidesend, open at the front; round the ears
        // when they show (a band above them all round, and below it the back and the sideburns).
        const sides = (scale, from, to, opts = {}) => {
            if (!ears || to <= EAR_CUT) {
                piece(scale, PI * 1.8, PI * 1.4, from, to - from, opts);
                return;
            }
            const w = opts.w || 16;
            piece(scale, PI * 1.8, PI * 1.4, from, EAR_CUT - from, {...opts, w: w, h: 2});
            const left = PI * 2 - EAR_GAP;
            const right = PI * 3 - EAR_GAP;
            [[PI * 1.8, left - PI * 1.8, 2], [left + EAR_GAP * 2, right - left - EAR_GAP * 2, Math.max(6, w - 6)],
                [right + EAR_GAP * 2, PI * 3.2 - right - EAR_GAP * 2, 2]].forEach(([start, length, segments]) => {
                piece(scale, start, length, EAR_CUT, to - EAR_CUT, {...opts, w: segments, h: 3});
            });
        };
        // Crown down to the hairline, and the sides and back down to sidesend.
        const capAndSides = (scale, sidesend, opts, hairline = PI * 0.28) => {
            piece(scale, 0, PI * 2, 0, hairline, opts);
            sides(scale, hairline, sidesend, opts);
        };
        // A hanging lock from root to tip, thick at the root.
        const lock = (root, tip, rroot, rtip) => {
            const dir = [root[0] - tip[0], root[1] - tip[1], root[2] - tip[2]];
            const length = Math.hypot(dir[0], dir[1], dir[2]);
            const geometry = new THREE.CylinderGeometry(rroot, rtip, length, 8, 1);
            placeGeometry(geometry, [(root[0] + tip[0]) / 2, (root[1] + tip[1]) / 2, (root[2] + tip[2]) / 2],
                turnTo([0, 1, 0], dir));
            add(bg(geometry));
            const ball = new THREE.SphereGeometry(rroot * 1.1, 8, 5);
            placeGeometry(ball, root, null);
            add(bg(ball));
        };
        // A hair tie round an axis.
        const hairTie = (point, axis, radius) => {
            const geometry = new THREE.TorusGeometry(radius, 0.006, 4, 10);
            placeGeometry(geometry, point, turnTo([0, 0, 1], axis));
            return add(bg(geometry), tie);
        };

        if (style === 'short' || style === 'long' || style === 'bun') {
            capAndSides(1.07, style === 'long' ? PI * 0.66 : PI * 0.6);
            if (style === 'long') {
                const fall = add(hairFall(shape));
                fall.name = 'fall';
            } else if (style === 'bun') {
                const bun = add(bg(new THREE.SphereGeometry(0.05, 10, 8)));
                bun.position.set(0, ext.back * 0.7, ext.back * 0.95);
            }
        } else if (style === 'buzz') {
            // Cropped: a very thin cap tight to the head with a high hairline, in a darker, duller
            // tint of the hair colour (the skin shows through cropped hair).
            const cropped = bm(material.clone());
            const hsl = cropped.color.getHSL({});
            cropped.color.setHSL(hsl.h, hsl.s * 0.6, hsl.l * 0.82);
            cropped.roughness = 1;
            piece(1.012, 0, PI * 2, 0, PI * 0.22, {w: 14, h: 4}).material = cropped;
            const before = hair.children.length;
            sides(1.012, PI * 0.22, PI * 0.52, {w: 14, h: 4});
            hair.children.slice(before).forEach((mesh) => {
                mesh.material = cropped;
            });
        } else if (style === 'curly') {
            // A close cap under clustered curls (under a hat, only the curls below it).
            capAndSides(1.04, PI * 0.62, {w: 12, h: 4});
            const curls = add(mergeGeometries(CURL_DIRECTIONS.filter((dir) => !hat || dir[1] < 0.4).map((dir) =>
                placeGeometry(new THREE.SphereGeometry(0.03, 5, 4), headPoint(shape, dir[0], dir[1], dir[2], 1.02), null))));
            curls.name = 'curls';
        } else if (style === 'afro') {
            // A big round volume of low-poly clumps over a base volume round the crown, sides and
            // back, open at the face; under a hat it is flatter and has no clumps on top, and the
            // hat is drawn bigger to sit on it (HAT_FIT).
            const axes = hat ? [ext.side * 1.3, ext.top * 1.02, ext.back * 1.28] : [ext.side * 1.42, ext.top * 1.18,
                ext.back * 1.38];
            const centre = [0, 0.018, 0.012];
            const puff = (phistart, philength, thetastart, thetalength, w, h) => {
                const geometry = new THREE.SphereGeometry(1, w, h, phistart, philength, thetastart, thetalength);
                geometry.scale(axes[0], axes[1], axes[2]);
                geometry.translate(centre[0], centre[1], centre[2]);
                geometry.computeVertexNormals();
                return bg(geometry);
            };
            add(puff(0, PI * 2, 0, PI * 0.3, 12, 3));
            add(puff(PI * 1.8, PI * 1.4, PI * 0.3, PI * 0.46, 12, 5));
            const clump = (dir, reach, radius) => placeGeometry(new THREE.IcosahedronGeometry(radius, 0),
                [centre[0] + dir[0] * axes[0] * reach, centre[1] + dir[1] * axes[1] * reach,
                    centre[2] + dir[2] * axes[2] * reach], null);
            const clumps = AFRO_DIRECTIONS.filter((dir) => !hat || dir[1] < 0.15).map((dir) => clump(dir, 0.94, 0.042));
            // Round the edge of the opening at the cheeks, so it has no flat cut.
            [PI * 1.84, PI * 1.16].forEach((phi) => {
                [0.4, 0.52, 0.64].forEach((t) => {
                    const theta = PI * t;
                    clumps.push(clump([-Math.cos(phi) * Math.sin(theta), Math.cos(theta), Math.sin(phi) * Math.sin(theta)],
                        0.97, 0.036));
                });
            });
            const blobs = add(mergeGeometries(clumps));
            blobs.name = 'clumps';
        } else if (style === 'ponytail') {
            capAndSides(1.06, PI * 0.58, {w: 14, h: 5});
            const root = [0, ext.top * 0.2, ext.back * 1.06];
            const tip = [0, root[1] - 0.2, root[2] + 0.06];
            lock(root, tip, 0.028, 0.01);
            hairTie([0, root[1] - 0.012, root[2] + 0.004], [0, -0.2, 0.06], 0.026);
        } else if (style === 'topknot') {
            // Under a hat the knot is left out (the hat sits where it was).
            capAndSides(1.05, PI * 0.58, {w: 14, h: 5});
            if (!hat) {
                const knot = add(bg(new THREE.SphereGeometry(0.042, 10, 8)));
                knot.name = 'knot';
                knot.position.set(0, ext.top * 1.05 + 0.028, 0);
                hairTie([0, ext.top * 1.05 + 0.002, 0], [0, 1, 0], 0.026);
            }
        } else if (style === 'pigtails') {
            capAndSides(1.06, PI * 0.6, {w: 14, h: 5});
            [-1, 1].forEach((side) => {
                // Behind the ears.
                const root = [side * ext.side * 1.04, ext.top * 0.25, ext.back * 0.55];
                const down = [side * 0.07, -0.15, 0.03];
                lock(root, [root[0] + down[0], root[1] + down[1], root[2] + down[2]], 0.024, 0.009);
                hairTie([root[0] + down[0] * 0.08, root[1] + down[1] * 0.08, root[2] + down[2] * 0.08], down, 0.024);
            });
        } else if (style === 'braids') {
            capAndSides(1.06, PI * 0.62, {w: 12, h: 4});
            const segments = [];
            [-1, 1].forEach((side) => {
                // Behind the ears.
                for (let k = 0; k < 5; k++) {
                    segments.push(placeGeometry(new THREE.SphereGeometry(0.024 - 0.0015 * k, 6, 4),
                        [side * (ext.side * 0.92 + 0.012 * k), -0.02 - 0.036 * k, ext.back * 0.5 + 0.004 * k], null,
                        [1, 1.2, 1]));
                }
                hairTie([side * (ext.side * 0.92 + 0.048), -0.02 - 0.036 * 4 - 0.022, ext.back * 0.5 + 0.016], [0, 1, 0],
                    0.012);
            });
            add(mergeGeometries(segments));
        } else if (style === 'bob') {
            // Chin length, standing out a little at the bottom, with a fringe.
            piece(1.09, 0, PI * 2, 0, PI * 0.28, {w: 16, h: 4});
            piece(1.09, PI * 1.78, PI * 1.44, PI * 0.28, PI * 0.48, {w: 16, h: 6, flare: 0.1});
            piece(1.1, PI * 1.2, PI * 0.6, PI * 0.15, PI * 0.18, {w: 8, h: 3});
        } else if (style === 'mohawk') {
            // A close strip from the forehead to the nape, and a crest of flat spikes on it (left
            // out under a hat, which would squash them).
            piece(1.03, PI * 1.45, PI * 0.1, 0, PI * 0.3, {w: 3, h: 4});
            piece(1.03, PI * 0.45, PI * 0.1, 0, PI * 0.62, {w: 3, h: 6});
            if (!hat) {
                const spikes = [];
                for (let k = 0; k < 8; k++) {
                    const angle = 0.6 - k * 2.6 / 7;
                    const dir = [0, Math.cos(angle), -Math.sin(angle)];
                    const height = 0.045 + 0.03 * Math.sin(PI * (k + 0.5) / 8);
                    const base = headPoint(shape, dir[0], dir[1], dir[2], 1.02);
                    const out = height / 2 - 0.01;
                    spikes.push(placeGeometry(new THREE.ConeGeometry(0.02, height, 5, 1),
                        [base[0], base[1] + dir[1] * out, base[2] + dir[2] * out], turnTo([0, 1, 0], dir), [0.45, 1, 1.4]));
                }
                add(mergeGeometries(spikes)).name = 'spikes';
            }
        } else if (style === 'sidepart') {
            // Two halves of the crown with a step between them (the parting, moved to one side by the
            // tilt), the fuller half swept over with a fringe, and the sides as short hair.
            const tilt = 0.28;
            piece(1.06, PI * 1.5, PI, 0, PI * 0.3, {w: 8, h: 5, tilt: tilt});
            piece(1.1, PI * 0.5, PI, 0, PI * 0.33, {w: 8, h: 5, tilt: tilt});
            sides(1.07, PI * 0.2, PI * 0.6);
            piece(1.11, PI * 1.2, PI * 0.3, PI * 0.18, PI * 0.14, {w: 5, h: 2, tilt: tilt});
        }
        return hair;
    };

    /**
     * Build a hat in a group named "hat" on the head.
     *
     * @param {string} style Hat style.
     * @param {string} shape Head shape.
     * @param {Object} material Hat material (the accent colour).
     * @param {number} fit How much bigger than the head's own hat it is drawn (HAT_FIT), so it sits
     *     on hair that stands out further than the hat.
     */
    const buildHat = (style, shape, material, fit = 1) => {
        if (style !== 'cap' && style !== 'beanie' && style !== 'sunhat') {
            return;
        }
        const ext = headExtent(shape);
        const hat = new THREE.Group();
        hat.name = 'hat';
        hat.scale.setScalar(fit);
        headparts.add(hat);
        if (style === 'cap') {
            hat.add(new THREE.Mesh(headPiece(1.1, shape, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            // Brim: the front half of a thin disc.
            const brim = new THREE.Mesh(bg(new THREE.CylinderGeometry(0.085, 0.085, 0.008, 12, 1, false,
                Math.PI * 0.5, Math.PI)), material);
            brim.position.set(0, ext.top * 0.5, -ext.front * 0.82);
            brim.rotation.x = -0.12;
            hat.add(brim);
        } else if (style === 'beanie') {
            hat.add(new THREE.Mesh(headPiece(1.1, shape, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            hat.add(new THREE.Mesh(headPiece(1.14, shape, 0, Math.PI * 2, Math.PI * 0.3, Math.PI * 0.08), material));
            const bobble = new THREE.Mesh(bg(new THREE.SphereGeometry(0.028, 8, 6)), material);
            bobble.position.set(0, ext.top * 1.12 + 0.02, 0);
            hat.add(bobble);
        } else {
            hat.add(new THREE.Mesh(headPiece(1.13, shape, 0, Math.PI * 2, 0, Math.PI * 0.36), material));
            const brim = new THREE.Mesh(bg(new THREE.CylinderGeometry(0.25, 0.25, 0.006, 24)), material);
            brim.position.y = Math.max(0.057, ext.top * 0.475);
            hat.add(brim);
        }
    };

    const buildGlasses = (shape) => {
        const material = bm(new THREE.MeshStandardMaterial({color: GLASSES_COLOUR, roughness: 0.4, metalness: 0.3}));
        const front = screenFrame(shape).c + 0.012;
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

    /**
     * Build the nose, the ears and the beard of a designed avatar (stubble is drawn on the face).
     *
     * The nose and the moustache sit on the face screen, so they hide while the webcam or the
     * profile picture shows; the goatee and the full beard sit under and round the screen's lower
     * edge and hide only while the webcam shows (applyFaceParts()).
     *
     * @param {Object} style Clean appearance.
     * @param {string} shape Head shape.
     * @param {Object} skin Skin material.
     * @param {Object} hairmaterial Hair material (beards are in the hair colour).
     */
    const buildFeatures = (style, shape, skin, hairmaterial) => {
        const depth = screenFrame(shape).c;
        const ext = headExtent(shape);
        if (style.nose === 'small' || style.nose === 'round' || style.nose === 'pointed') {
            let geometry;
            let out = depth + 0.004;
            if (style.nose === 'pointed') {
                geometry = new THREE.ConeGeometry(0.0075, 0.018, 8);
                // Tip forwards (-Z).
                geometry.rotateX(-Math.PI / 2);
                out = depth + 0.009;
            } else if (style.nose === 'round') {
                geometry = new THREE.SphereGeometry(0.0095, 10, 8);
                geometry.scale(1, 0.95, 0.8);
                out = depth + 0.005;
            } else {
                geometry = new THREE.SphereGeometry(0.0075, 8, 6);
                geometry.scale(1, 0.85, 1);
            }
            // The skin tone, lit softly (it glows a little in its own colour), so a small nose in
            // front of the dark screen reads as skin, not as a dark ball.
            const nosematerial = bm(new THREE.MeshStandardMaterial({color: skin.color.clone(),
                emissive: skin.color.clone(), emissiveIntensity: 0.35, roughness: 0.9, metalness: 0}));
            nose = new THREE.Mesh(bg(geometry), nosematerial);
            nose.name = 'nose';
            nose.position.set(0, NOSE_Y, -out);
            headparts.add(nose);
        }
        if (style.ears && !hairCoversEars(style.hair)) {
            const geometry = bg(new THREE.SphereGeometry(0.028, 8, 6));
            const ears = new THREE.Group();
            ears.name = 'ears';
            [-1, 1].forEach((side) => {
                const ear = new THREE.Mesh(geometry, skin);
                ear.scale.set(0.4, 1, 0.7);
                ear.position.set(side * (ext.side + 0.004), -0.008, 0.008);
                ears.add(ear);
            });
            headparts.add(ears);
        }
        if (style.beard === 'moustache' || style.beard === 'full') {
            beardface = new THREE.Group();
            beardface.name = 'beardface';
            const geometry = bg(new THREE.CapsuleGeometry(0.0055, 0.02, 2, 6));
            // A light rim round it in a pale tint of the hair colour, so that it shows on the dark
            // screen in every hair colour (black too).
            const rim = bm(new THREE.MeshBasicMaterial({color: new THREE.Color(mixHex(HAIR_COLOURS[style.haircolor] ||
                HAIR_COLOURS.brown, '#ffffff', 0.55)), toneMapped: false}));
            [-1, 1].forEach((side) => {
                const half = new THREE.Mesh(geometry, hairmaterial);
                // Lying across the lip, the outer end a little lower.
                half.rotation.z = Math.PI / 2 - side * 0.3;
                half.position.set(side * 0.013, MOUSTACHE_Y, -(depth + 0.006));
                beardface.add(half);
                const outline = new THREE.Mesh(geometry, rim);
                outline.name = 'moustacherim';
                outline.rotation.z = half.rotation.z;
                outline.scale.set(1.45, 1.12, 0.6);
                outline.position.set(half.position.x, MOUSTACHE_Y, -(depth + 0.003));
                beardface.add(outline);
            });
            headparts.add(beardface);
        }
        if (style.beard === 'goatee' || style.beard === 'full') {
            beardlower = new THREE.Group();
            beardlower.name = 'beardlower';
            const chin = headPoint(shape, 0, -0.62, -0.78);
            const size = style.beard === 'full' ? 1.2 : 1;
            const tuft = new THREE.Mesh(bg(new THREE.SphereGeometry(0.022, 8, 6)), hairmaterial);
            tuft.scale.set(0.95 * size, 1.3 * size, 0.7 * size);
            tuft.position.set(chin[0], chin[1] - 0.004, chin[2] - 0.006);
            beardlower.add(tuft);
            if (style.beard === 'full') {
                // Jaw and sideburns: a shell round the lower front of the head, behind the screen.
                beardlower.add(new THREE.Mesh(headPiece(1.04, shape, Math.PI * 0.92, Math.PI * 1.16, Math.PI * 0.52,
                    Math.PI * 0.36, {w: 14, h: 4}), hairmaterial));
            }
            headparts.add(beardlower);
        }
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
        nose = null;
        beardface = null;
        beardlower = null;
    };

    const build = () => {
        clearParts();
        const shape = look ? look.head : 'round';
        const colour = look ? new THREE.Color(COLOURS[look.color]) :
            new THREE.Color().setHSL(hueOf(seed) / 360, 0.55, 0.55);
        accentcss = look ? COLOURS[look.accent] : COLOURS.grey;
        const doubleside = THREE.DoubleSide;
        const bodymaterial = bm(new THREE.MeshStandardMaterial({color: colour, roughness: 0.6, metalness: 0}));
        // The head and hands: the skin tone, or the body colour ("match", and today's look).
        const tone = look && Object.prototype.hasOwnProperty.call(SKIN_TONES, look.skin) ? SKIN_TONES[look.skin] : '';
        // A skin tone glows a little in a warm tint of itself (the lighter, the more), so that light
        // tones stay warm and do not turn grey in the rooms' cool light; it is rougher than the body,
        // so the highlights do not wash it out.
        const skin = tone ? bm(new THREE.MeshStandardMaterial({color: new THREE.Color(tone), roughness: 0.75, metalness: 0,
            emissive: new THREE.Color(mixHex(tone, SKIN_GLOW, 0.4)), emissiveIntensity: 0.04 + 0.18 * lightness(tone)})) :
            bodymaterial;
        hands.forEach((hand) => {
            hand.material = skin;
        });

        if (look) {
            const headgeometry = shape === 'round' ? new THREE.SphereGeometry(HEAD_RADIUS, 20, 14) :
                shapeToHead(new THREE.SphereGeometry(1, 20, 14), 1, shape);
            const skull = new THREE.Mesh(bg(headgeometry), skin);
            skull.name = 'skull';
            headparts.add(skull);
            const accent = bm(new THREE.MeshStandardMaterial({color: new THREE.Color(accentcss), roughness: 0.7,
                metalness: 0, side: doubleside}));
            const hair = bm(new THREE.MeshStandardMaterial({color: new THREE.Color(HAIR_COLOURS[look.haircolor]),
                roughness: 0.9, metalness: 0, side: doubleside}));
            buildHair(look.hair, shape, hair, accent, {ears: look.ears && !hairCoversEars(look.hair), hat: look.hat});
            buildHat(look.hat, shape, accent, HAT_FIT[look.hair] || 1);
            if (look.glasses) {
                glasses = buildGlasses(shape);
            }
            buildFeatures(look, shape, skin, hair);
            buildBody(look.body, bodymaterial, accent);
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
        facescreen.geometry = bg(screenGeometry(shape, 0));
        videoscreen.geometry = bg(screenGeometry(shape, 0.002));
        inert(group);
        drawFace();
        applyPose();
        applyVideoState(now());
    };

    const loadPicture = () => {
        picturetoken++;
        picture = null;
        applyFaceParts(fadeValue(fade, now()));
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
                applyFaceParts(fadeValue(fade, now()));
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
        const grown = videoHeadScale(headgrow);
        head.position.set(h[0], h[1] + grown.lift, h[2]);
        head.scale.setScalar(grown.scale);
        quaternion.set(h[3], h[4], h[5], h[6]).normalize();
        head.quaternion.copy(quaternion);
        forward.set(0, 0, -1).applyQuaternion(quaternion);
        if (Math.hypot(forward.x, forward.z) > MIN_YAW_LENGTH) {
            yaw = Math.atan2(-forward.x, -forward.z);
        }
        body.position.set(h[0], h[1] - bodydrop, h[2]);
        body.rotation.set(0, yaw, 0);
        // The name tag and the speaking ring stay just above the head, however big it is.
        const above = grown.lift + (grown.scale - 1) * HEAD_RADIUS;
        label.position.set(h[0], h[1] + LABEL_HEIGHT + above, h[2]);
        ring.position.set(h[0], h[1] + RING_HEIGHT + above, h[2]);
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
     * Show or hide the parts in front of the face screen: the glasses, the nose and the beard hide
     * while the webcam shows; the nose and the moustache also while the profile picture shows.
     *
     * @param {number} value Video fade, 0 to 1.
     */
    function applyFaceParts(value) {
        const camera = value >= 0.5;
        const covered = camera || !!picture;
        if (glasses) {
            glasses.visible = !camera;
        }
        if (nose) {
            nose.visible = !covered;
        }
        if (beardface) {
            beardface.visible = !covered;
        }
        if (beardlower) {
            beardlower.visible = !camera;
        }
    }

    /**
     * Show the webcam layer as far as its fade has got.
     *
     * @param {number} at Now (ms).
     */
    function applyVideoState(at) {
        const value = fadeValue(fade, at);
        videomaterial.opacity = value;
        if (Math.abs(value - headgrow) > 1e-4) {
            headgrow = value;
            applyPose();
        }
        videoscreen.visible = value > 0.001 || fade.to > 0;
        applyFaceParts(value);
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
