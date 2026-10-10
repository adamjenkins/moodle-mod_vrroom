// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests of the avatar builder (vrroomruntime_builtin/live/avatarmodel): the appearance document,
 * the palettes, the cover crop, the fade and the live video check, and (with the bundled three.js and
 * a stand-in canvas) the hair styles, head shapes, skin tones and face features it builds, and the
 * costumes (model extras, tint, device screens, the model hat and the fallback without a model).
 *
 * Run from the repository root: node --test tests/js/avatarmodel.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {SCHEMA, COLOURS, HAIR_COLOURS, SKIN_TONES, CHOICES, FLAGS, DEFAULT_APPEARANCE, APPEARANCE_MAX, FADE_MS,
    cleanAppearance, parseAppearance, cleanPictureUrl, coverCrop, fadeValue, hasLiveVideo, hueOf, videoHeadScale,
    hairCoversEars, createAvatar, cleanCostumeId, parseAssets, parseCostumeExtras, findCostumeExtras, costumeLayout,
    selectTintMaterials, fitScreen, devicePitch, lightPulse, shellScale, earCutBottom, loadCostume, clearCostumes,
    STUBBLE_STOPS, stubbleBand, MODEL_HATS, ASSET_NAMES, COSTUME_SCHEMA} from '../../runtime/builtin/amd/src/live/avatarmodel.js';

const DELTA = 1e-9;

/** A full, valid appearance that differs from the default everywhere. */
const FULL = {
    schema: SCHEMA,
    body: 'broad',
    head: 'square',
    color: 'teal',
    accent: 'pink',
    hair: 'bun',
    haircolor: 'auburn',
    glasses: true,
    hat: 'sunhat',
    picture: true,
    skin: 'tan',
    eyes: 'almond',
    brows: 'arched',
    nose: 'pointed',
    ears: true,
    beard: 'goatee',
    outfit: 'none',
};

/** A design saved before the hair and heads keys existed. */
const OLD = {
    schema: SCHEMA,
    body: 'slim',
    head: 'square',
    color: 'green',
    accent: 'orange',
    hair: 'long',
    haircolor: 'blonde',
    glasses: true,
    hat: 'cap',
    picture: false,
};

/**
 * A stand-in for a media stream with some video tracks.
 *
 * @param {Object[]} tracks Track stand-ins {readyState, muted, enabled}.
 * @returns {Object}
 */
const stream = (tracks) => ({getVideoTracks: () => tracks});

test('the palettes have the 14 colours, 10 hair colours and 8 skin tones of the contract, as hex', () => {
    assert.deepEqual(Object.keys(COLOURS), ['red', 'orange', 'yellow', 'green', 'teal', 'cyan', 'blue', 'indigo',
        'purple', 'pink', 'brown', 'grey', 'black', 'white']);
    assert.equal(COLOURS.black, '#1b1b1d', 'a black suit and tie can be made');
    assert.equal(COLOURS.white, '#f4f4f2');
    assert.deepEqual(Object.keys(HAIR_COLOURS), ['black', 'brown', 'blonde', 'auburn', 'grey', 'white', 'red', 'pink',
        'blue', 'purple']);
    assert.deepEqual(Object.keys(SKIN_TONES), ['porcelain', 'fair', 'light', 'medium', 'tan', 'brown', 'dark', 'deep']);
    [...Object.values(COLOURS), ...Object.values(HAIR_COLOURS), ...Object.values(SKIN_TONES)].forEach((hex) => {
        assert.match(hex, /^#[0-9a-f]{6}$/);
    });
    assert.equal(new Set(Object.values(COLOURS)).size, 14, 'colours are distinct');
    assert.equal(new Set(Object.values(HAIR_COLOURS)).size, 10, 'hair colours are distinct');
    assert.equal(new Set(Object.values(SKIN_TONES)).size, 8, 'skin tones are distinct');
    assert.ok(Object.isFrozen(SKIN_TONES));
});

test('the skin tones run from light to deep', () => {
    // Relative luminance (sRGB, roughly): each tone darker than the one before.
    const luminance = (hex) => {
        const value = parseInt(hex.slice(1), 16);
        return 0.2126 * ((value >> 16) & 255) + 0.7152 * ((value >> 8) & 255) + 0.0722 * (value & 255);
    };
    const tones = Object.values(SKIN_TONES).map(luminance);
    for (let i = 1; i < tones.length; i++) {
        assert.ok(tones[i] < tones[i - 1], Object.keys(SKIN_TONES)[i]);
    }
});

test('the choices are the allowed values of the contract', () => {
    assert.equal(SCHEMA, 'vrroom-avatar/1');
    assert.deepEqual([...CHOICES.body], ['capsule', 'slim', 'broad']);
    assert.deepEqual([...CHOICES.head], ['round', 'square', 'oval', 'heart']);
    assert.deepEqual([...CHOICES.hair], ['none', 'short', 'long', 'bun', 'curly', 'afro', 'ponytail', 'braids', 'buzz', 'bob',
        'mohawk', 'sidepart', 'pigtails', 'topknot']);
    assert.deepEqual([...CHOICES.hat], ['none', 'cap', 'beanie', 'sunhat', 'pirate']);
    assert.deepEqual([...CHOICES.color], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.accent], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.haircolor], Object.keys(HAIR_COLOURS));
    assert.deepEqual([...CHOICES.skin], ['match', 'porcelain', 'fair', 'light', 'medium', 'tan', 'brown', 'dark', 'deep']);
    assert.deepEqual([...CHOICES.eyes], ['dots', 'round', 'almond', 'sleepy']);
    assert.deepEqual([...CHOICES.brows], ['none', 'thin', 'thick', 'arched']);
    assert.deepEqual([...CHOICES.nose], ['none', 'small', 'round', 'pointed']);
    assert.deepEqual([...CHOICES.beard], ['none', 'stubble', 'moustache', 'goatee', 'full']);
    assert.deepEqual([...CHOICES.outfit], ['none'], 'the fixed outfit value; costume ids are checked by pattern');
    assert.deepEqual([...FLAGS], ['glasses', 'picture', 'ears']);
    // Every key of the document is a choice or a flag, and every choice is frozen.
    Object.keys(DEFAULT_APPEARANCE).filter((key) => key !== 'schema').forEach((key) => {
        assert.ok(CHOICES[key] || FLAGS.includes(key), key);
        if (CHOICES[key]) {
            assert.ok(Object.isFrozen(CHOICES[key]), key);
            assert.ok(CHOICES[key].includes(DEFAULT_APPEARANCE[key]), key + ' default is allowed');
        }
    });
});

test('the defaults are those of the contract and are frozen', () => {
    assert.deepEqual({...DEFAULT_APPEARANCE}, {
        schema: SCHEMA, body: 'capsule', head: 'round', color: 'blue', accent: 'grey', hair: 'none',
        haircolor: 'brown', glasses: false, hat: 'none', picture: true, skin: 'match', eyes: 'dots', brows: 'none',
        nose: 'none', ears: false, beard: 'none', outfit: 'none',
    });
    assert.ok(Object.isFrozen(DEFAULT_APPEARANCE));
    assert.ok(Object.isFrozen(COLOURS));
    assert.ok(Object.isFrozen(CHOICES.body));
});

test('a valid document is kept as it is, as a new object', () => {
    const clean = cleanAppearance(FULL);
    assert.deepEqual(clean, FULL);
    assert.notEqual(clean, FULL);
    assert.deepEqual(cleanAppearance(JSON.stringify(FULL)), FULL);
});

test('unknown keys are dropped and unknown values become the defaults', () => {
    const clean = cleanAppearance({
        schema: SCHEMA,
        body: 'huge',
        head: 'square',
        color: '#ff0000',
        accent: 'pink',
        hair: ['short'],
        haircolor: 'green',
        glasses: 'yes',
        hat: 'crown',
        picture: 1,
        skin: '#ffe0bd',
        eyes: 'laser',
        brows: true,
        nose: 'clown',
        ears: 'yes',
        beard: 'wizard',
        outfit: 'Not a costume!',
        script: '<script>alert(1)</script>',
        __proto__: {polluted: true},
    });
    assert.deepEqual(clean, {...DEFAULT_APPEARANCE, head: 'square', accent: 'pink'});
    assert.equal(Object.keys(clean).length, Object.keys(DEFAULT_APPEARANCE).length);
    assert.equal(clean.script, undefined);
});

test('a document with only the schema gets every default', () => {
    assert.deepEqual(cleanAppearance({schema: SCHEMA}), {...DEFAULT_APPEARANCE});
});

test('anything that is not a vrroom-avatar/1 document means no appearance', () => {
    [null, undefined, '', 'nonsense', '{', '[]', '42', 'null', 42, true, [], [FULL], {}, {...FULL, schema: 'vrroom-avatar/2'},
        {...FULL, schema: undefined}].forEach((input) => {
        assert.equal(cleanAppearance(input), null, JSON.stringify(input));
    });
});

test('an overlong document is refused', () => {
    const long = JSON.stringify({...FULL, padding: 'x'.repeat(APPEARANCE_MAX)});
    assert.equal(cleanAppearance(long), null);
});

test('the peer list appearance: JSON text, empty or invalid means none', () => {
    assert.deepEqual(parseAppearance(JSON.stringify(FULL)), FULL);
    assert.equal(parseAppearance(''), null);
    assert.equal(parseAppearance('not json'), null);
    assert.equal(parseAppearance(FULL), null, 'only text is accepted from the peer list');
    assert.equal(parseAppearance(undefined), null);
});

test('picture URLs: http(s) only', () => {
    assert.equal(cleanPictureUrl('https://example.org/pluginfile.php/5/user/icon/boost/f1'),
        'https://example.org/pluginfile.php/5/user/icon/boost/f1');
    assert.equal(cleanPictureUrl(' http://example.org/a.png '), 'http://example.org/a.png');
    ['', 'javascript:alert(1)', 'data:image/png;base64,AAAA', '//example.org/a.png', 42, null].forEach((url) => {
        assert.equal(cleanPictureUrl(url), '', String(url));
    });
    assert.equal(cleanPictureUrl('https://example.org/' + 'a'.repeat(3000)), '');
});

test('cover crop fills the screen without stretching', () => {
    // A wider image is cut at the sides.
    let crop = coverCrop(16 / 9, 4 / 3);
    assert.ok(Math.abs(crop.repeatx - (4 / 3) / (16 / 9)) < DELTA);
    assert.equal(crop.repeaty, 1);
    assert.ok(Math.abs(crop.offsetx - (1 - crop.repeatx) / 2) < DELTA);
    assert.equal(crop.offsety, 0);
    // A taller image is cut at the top and bottom.
    crop = coverCrop(3 / 4, 4 / 3);
    assert.equal(crop.repeatx, 1);
    assert.ok(Math.abs(crop.repeaty - (3 / 4) / (4 / 3)) < DELTA);
    assert.ok(Math.abs(crop.offsety - (1 - crop.repeaty) / 2) < DELTA);
    // The same shape is shown whole; nonsense sizes too.
    assert.deepEqual(coverCrop(1.5, 1.5), {repeatx: 1, repeaty: 1, offsetx: 0, offsety: 0});
    [[0, 1], [1, 0], [NaN, 1], [Infinity, 1], [-1, 1]].forEach(([s, t]) => {
        assert.deepEqual(coverCrop(s, t), {repeatx: 1, repeaty: 1, offsetx: 0, offsety: 0});
    });
});

test('the fade runs from its start value to its target in FADE_MS', () => {
    assert.equal(FADE_MS, 300);
    const fadein = {from: 0, to: 1, start: 1000};
    assert.equal(fadeValue(fadein, 900), 0);
    assert.equal(fadeValue(fadein, 1000), 0);
    assert.ok(Math.abs(fadeValue(fadein, 1150) - 0.5) < DELTA);
    assert.equal(fadeValue(fadein, 1300), 1);
    assert.equal(fadeValue(fadein, 5000), 1);
    const fadeout = {from: 0.6, to: 0, start: 0};
    assert.ok(Math.abs(fadeValue(fadeout, 150) - 0.3) < DELTA);
    assert.equal(fadeValue(fadeout, 300), 0);
    assert.equal(fadeValue(null, 10), 0);
});

test('video shows only for a live, unmuted, enabled video track', () => {
    assert.equal(hasLiveVideo(null), false);
    assert.equal(hasLiveVideo({}), false);
    assert.equal(hasLiveVideo(stream([])), false);
    assert.equal(hasLiveVideo(stream([{readyState: 'live', muted: false}])), true);
    assert.equal(hasLiveVideo(stream([{readyState: 'ended', muted: false}])), false);
    assert.equal(hasLiveVideo(stream([{readyState: 'live', muted: true}])), false);
    assert.equal(hasLiveVideo(stream([{readyState: 'live', muted: false, enabled: false}])), false);
    assert.equal(hasLiveVideo(stream([{readyState: 'ended', muted: false}, {readyState: 'live', muted: false}])), true);
});

test('the fallback hue is stable and in range', () => {
    const peerid = '0123456789abcdef0123456789abcdef';
    assert.equal(hueOf(peerid), hueOf(peerid));
    for (const id of ['', 'a', peerid, 'ffffffffffffffffffffffffffffffff']) {
        const hue = hueOf(id);
        assert.ok(Number.isInteger(hue) && hue >= 0 && hue < 360, id);
    }
});

test('the head doubles while the webcam shows, growing upwards from the neck', () => {
    assert.deepEqual(videoHeadScale(0), {scale: 1, lift: 0});
    const full = videoHeadScale(1);
    assert.equal(full.scale, 2);
    // Lifted by one head radius: the bottom of the doubled head stays where the bottom was.
    assert.ok(Math.abs(full.lift - 0.12) < DELTA);
    const half = videoHeadScale(0.5);
    assert.ok(half.scale > 1 && half.scale < 2);
    assert.deepEqual(videoHeadScale(-3), {scale: 1, lift: 0});
    assert.equal(videoHeadScale(7).scale, 2);
});

test('a design saved before the hair and heads keys existed keeps its look', () => {
    const clean = cleanAppearance(OLD);
    assert.deepEqual(clean, {...OLD, skin: 'match', eyes: 'dots', brows: 'none', nose: 'none', ears: false, beard: 'none',
        outfit: 'none'});
    assert.deepEqual(parseAppearance(JSON.stringify(OLD)), clean);
});

test('every value of every choice is kept; values of another key are not', () => {
    Object.keys(CHOICES).forEach((key) => {
        CHOICES[key].forEach((value) => {
            assert.equal(cleanAppearance({schema: SCHEMA, [key]: value})[key], value, key + ' ' + value);
        });
    });
    // A hair colour is not a skin tone, a head shape is not a hair style.
    assert.equal(cleanAppearance({schema: SCHEMA, skin: 'blonde'}).skin, 'match');
    assert.equal(cleanAppearance({schema: SCHEMA, hair: 'oval'}).hair, 'none');
    assert.equal(cleanAppearance({schema: SCHEMA, haircolor: 'deep'}).haircolor, 'brown');
    assert.equal(cleanAppearance({schema: SCHEMA, ears: true}).ears, true);
    assert.equal(cleanAppearance({schema: SCHEMA, ears: 'true'}).ears, false);
});

test('outfit is none or a costume id; anything else is none', () => {
    ['pirate-captain', 'suit-tie', 'blocky-a', 'mini-female-f', 'device-tv', 'lalobot', 'none'].forEach((value) => {
        assert.equal(cleanAppearance({schema: SCHEMA, outfit: value}).outfit, value, value);
        assert.equal(cleanCostumeId(value), value);
    });
    ['', 'None', 'Pirate', 'pirate_captain', '-pirate', 'pirate-', 'a--b', 'a b', '../x', 'x'.repeat(41), null, 1,
        ['none'], {}].forEach((value) => {
        assert.equal(cleanAppearance({schema: SCHEMA, outfit: value}).outfit, 'none', JSON.stringify(value));
    });
    assert.equal(cleanCostumeId('x'.repeat(40)), 'x'.repeat(40));
});

test('the pirate hat is a model hat, and the assets are the outfit and the hat', () => {
    assert.deepEqual([...MODEL_HATS], ['pirate']);
    assert.deepEqual([...ASSET_NAMES], ['outfit', 'hat']);
    assert.equal(cleanAppearance({schema: SCHEMA, hat: 'pirate'}).hat, 'pirate');
    assert.equal(COSTUME_SCHEMA, 'vrroom-costume/1');
});

test('a full document with every key fits well inside the size cap', () => {
    const longest = {schema: SCHEMA};
    Object.keys(CHOICES).forEach((key) => {
        longest[key] = CHOICES[key].reduce((a, b) => (b.length > a.length ? b : a));
    });
    FLAGS.forEach((key) => {
        longest[key] = false;
    });
    const text = JSON.stringify(longest);
    assert.ok(text.length < APPEARANCE_MAX / 4, String(text.length));
    assert.deepEqual(cleanAppearance(text), longest);
});

test('long hair styles cover the ears', () => {
    ['long', 'bob', 'afro', 'curly'].forEach((style) => assert.equal(hairCoversEars(style), true, style));
    ['none', 'short', 'bun', 'buzz', 'mohawk', 'ponytail', 'pigtails', 'braids', 'sidepart', 'topknot', 'x', undefined]
        .forEach((style) => assert.equal(hairCoversEars(style), false, String(style)));
});

// Built avatars: the bundled three.js and a stand-in canvas that logs what is drawn on it.

/** Every canvas the builder made: {width, height, log}. */
const canvases = [];

/** Prototype of the stand-in canvas gradients: their colour stops go to the log of their canvas. */
const GRADIENT = {
    addColorStop(...args) {
        this.log.push(['addColorStop', ...args]);
    },
};

/**
 * A stand-in 2D context that logs every call and every property set.
 *
 * @param {Array[]} log Where the calls go: [name, ...arguments].
 * @returns {Object}
 */
const context = (log) => new Proxy({}, {
    get: (target, key) => {
        if (key in target) {
            return target[key];
        }
        if (key === 'measureText') {
            return () => ({width: 10});
        }
        if (key === 'createRadialGradient') {
            return (...args) => {
                log.push([key, ...args]);
                const gradient = Object.create(GRADIENT);
                Object.defineProperty(gradient, 'log', {value: log, enumerable: false});
                return gradient;
            };
        }
        return (...args) => {
            log.push([key, ...args]);
        };
    },
    set: (target, key, value) => {
        target[key] = value;
        log.push(['set', key, value]);
        return true;
    },
});

globalThis.document = {
    createElement: () => {
        const canvas = {width: 0, height: 0, log: []};
        canvas.getContext = () => context(canvas.log);
        canvases.push(canvas);
        return canvas;
    },
};

const THREE = await import('../../runtime/builtin/amd/src/three.js');

/** Width of the face canvas (px), which tells it from the label and mask canvases. */
const FACE_CANVAS_WIDTH = 192;

/** Height of the face canvas (px). */
const FACE_CANVAS_HEIGHT = 141;

/**
 * Build an avatar and the calls of the last drawing of its face canvas.
 *
 * @param {Object|null} appearance Appearance.
 * @returns {{avatar: Object, ops: Array[]}}
 */
const build = (appearance) => {
    const before = canvases.length;
    const avatar = createAvatar(THREE, appearance, {name: 'Test person', seed: '0123456789abcdef'});
    const face = canvases.slice(before).find((canvas) => canvas.width === FACE_CANVAS_WIDTH);
    const last = face.log.map((op) => op[0]).lastIndexOf('clearRect');
    return {avatar, ops: face.log.slice(last)};
};

/**
 * A design: the defaults with some changes.
 *
 * @param {Object} changes Keys to change.
 * @returns {Object}
 */
const design = (changes) => ({...DEFAULT_APPEARANCE, ...changes});

/**
 * Triangles of every mesh in an object.
 *
 * @param {Object} object THREE.Object3D
 * @returns {number}
 */
const triangles = (object) => {
    let count = 0;
    object.traverse((child) => {
        if (child.isMesh && child.geometry) {
            const geometry = child.geometry;
            count += (geometry.index ? geometry.index.count : geometry.attributes.position.count) / 3;
        }
    });
    return count;
};

/**
 * The vertices of an object's meshes in the head's own space (before the head is posed and grown).
 *
 * @param {Object} avatar Avatar.
 * @param {Object} object THREE.Object3D inside the head.
 * @returns {Object[]} THREE.Vector3 list.
 */
const headVertices = (avatar, object) => {
    const head = avatar.group.getObjectByName('head');
    avatar.group.updateMatrixWorld(true);
    const inverse = head.matrixWorld.clone().invert();
    const out = [];
    object.traverse((child) => {
        if (!child.isMesh) {
            return;
        }
        const matrix = new THREE.Matrix4().multiplyMatrices(inverse, child.matrixWorld);
        const position = child.geometry.attributes.position;
        for (let i = 0; i < position.count; i++) {
            out.push(new THREE.Vector3().fromBufferAttribute(position, i).applyMatrix4(matrix));
        }
    });
    return out;
};

/**
 * Whether a vertex (head space) is in front of the face screen's middle: where the eyes, the
 * mouth, the picture badge and the webcam are.
 *
 * @param {Object} v THREE.Vector3
 * @returns {boolean}
 */
const onFace = (v) => Math.abs(v.x) < 0.065 && v.y > -0.055 && v.y < 0.045 && v.z < -0.05;

/** A stand-in for a playing video element. */
const playingVideo = () => ({readyState: 4, videoWidth: 320, videoHeight: 240, addEventListener() {
    // Nothing to listen to.
}, removeEventListener() {
    // Nothing was listened to.
}});

test('every hair style builds on every head shape, cheaply, and never covers the face', () => {
    CHOICES.head.forEach((head) => {
        CHOICES.hair.forEach((hair) => {
            const {avatar} = build(design({head, hair}));
            const group = avatar.group.getObjectByName('hair');
            assert.ok(group, `${head} ${hair}: hair group`);
            const count = triangles(group);
            if (hair === 'none') {
                assert.equal(count, 0, `${head}: no hair`);
            } else {
                assert.ok(count >= 50 && count <= 900, `${head} ${hair}: ${count} triangles`);
            }
            const covering = headVertices(avatar, group).filter(onFace);
            assert.equal(covering.length, 0, `${head} ${hair}: ${covering.length} hair vertices on the face`);
            avatar.dispose();
        });
    });
});

test('the hair styles differ from each other', () => {
    const shapes = new Map();
    CHOICES.hair.filter((hair) => hair !== 'none').forEach((hair) => {
        const {avatar} = build(design({hair}));
        const group = avatar.group.getObjectByName('hair');
        const box = new THREE.Box3().setFromObject(group);
        const key = [triangles(group), ...box.min.toArray(), ...box.max.toArray()].map((n) => n.toFixed(3)).join(',');
        assert.ok(!shapes.has(key), `${hair} looks like ${shapes.get(key)}`);
        shapes.set(key, hair);
        avatar.dispose();
    });
});

test('the head shapes: oval is taller than wide, heart is wider at the brow and narrower at the chin', () => {
    const widths = (head) => {
        const {avatar} = build(design({head}));
        const vertices = headVertices(avatar, avatar.group.getObjectByName('skull'));
        const width = (filter) => Math.max(...vertices.filter(filter).map((v) => Math.abs(v.x)));
        const box = new THREE.Box3().setFromPoints(vertices);
        avatar.dispose();
        return {brow: width((v) => v.y > 0.03), chin: width((v) => v.y < -0.07), box};
    };
    const round = widths('round');
    const oval = widths('oval');
    const heart = widths('heart');
    const size = oval.box.getSize(new THREE.Vector3());
    assert.ok(size.y > size.x * 1.15, 'oval is taller than wide');
    assert.ok(size.y > round.box.getSize(new THREE.Vector3()).y, 'oval is taller than round');
    assert.ok(heart.brow > round.brow, 'heart is wider at the brow');
    assert.ok(heart.chin < round.chin * 0.95, 'heart is narrower at the chin');
});

test('the face screen sits in front of every head shape and keeps its size', () => {
    CHOICES.head.forEach((head) => {
        const {avatar} = build(design({head}));
        const skull = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('skull')));
        const screen = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('facescreen')));
        assert.ok(screen.min.z < skull.min.z - 0.004, `${head}: screen in front of the face`);
        const size = screen.getSize(new THREE.Vector3());
        assert.ok(size.x > 0.12 && size.x <= 0.15 + 1e-6, `${head}: screen width ${size.x}`);
        assert.ok(Math.abs(size.y - 0.11) < 1e-6, `${head}: screen height ${size.y}`);
        avatar.dispose();
    });
});

test('skin tones colour the head and hands; match uses the body colour', () => {
    const colour = (hex) => new THREE.Color(hex).getHex();
    let {avatar} = build(design({color: 'teal', skin: 'deep'}));
    assert.equal(avatar.group.getObjectByName('skull').material.color.getHex(), colour(SKIN_TONES.deep));
    assert.equal(avatar.group.getObjectByName('lefthand').material.color.getHex(), colour(SKIN_TONES.deep));
    assert.equal(avatar.group.getObjectByName('body').children[0].material.color.getHex(), colour(COLOURS.teal));
    avatar.dispose();
    ({avatar} = build(design({color: 'teal'})));
    const skull = avatar.group.getObjectByName('skull');
    assert.equal(skull.material.color.getHex(), colour(COLOURS.teal));
    assert.equal(avatar.group.getObjectByName('body').children[0].material, skull.material, 'one material, as before');
    avatar.dispose();
});

test('the default design draws the first version: round head, no features, the same face', () => {
    const {avatar, ops} = build(design({}));
    const skull = avatar.group.getObjectByName('skull');
    assert.equal(skull.geometry.type, 'SphereGeometry');
    assert.equal(skull.geometry.parameters.radius, 0.12);
    assert.equal(skull.geometry.parameters.widthSegments, 20);
    ['nose', 'ears', 'beardface', 'beardlower', 'glasses'].forEach((name) => {
        assert.equal(avatar.group.getObjectByName(name), undefined, name);
    });
    assert.equal(avatar.group.getObjectByName('hair').children.length, 0);
    const names = ops.map((op) => op[0]);
    const ellipses = ops.filter((op) => op[0] === 'ellipse');
    assert.equal(ellipses.length, 2, 'two eyes');
    ellipses.forEach((op) => assert.deepEqual(op.slice(3, 5), [10, 13], 'dot eyes'));
    assert.equal(names.filter((name) => name === 'arc').length, 1, 'only the smile');
    assert.equal(names.filter((name) => name === 'stroke').length, 1, 'no brows');
    assert.equal(names.filter((name) => name === 'quadraticCurveTo').length, 4, 'only the screen corners');
    avatar.dispose();
    // A design saved before the new keys builds the same parts.
    const old = build(OLD).avatar;
    const same = build({...OLD, skin: 'match', eyes: 'dots', brows: 'none', nose: 'none', ears: false, beard: 'none'}).avatar;
    assert.equal(triangles(old.group), triangles(same.group));
    assert.equal(old.group.getObjectByName('hair').children.length, 3, 'long hair: crown, sides and fall');
    old.dispose();
    same.dispose();
});

test('eyes, brows and stubble are drawn on the designed face', () => {
    const count = (ops, name) => ops.filter((op) => op[0] === name).length;
    const base = build(design({})).ops;
    const round = build(design({eyes: 'round'})).ops;
    assert.equal(count(round, 'arc'), 1 + 6, 'eye, pupil and highlight twice, and the smile');
    const almond = build(design({eyes: 'almond'})).ops.filter((op) => op[0] === 'ellipse');
    assert.equal(almond.length, 2);
    assert.ok(almond[0][5] !== 0 && almond[0][5] === -almond[1][5], 'almond eyes slant, mirrored');
    const sleepy = build(design({eyes: 'sleepy'})).ops;
    assert.equal(count(sleepy, 'stroke'), 1 + 2, 'two lids and the smile');
    ['thin', 'thick', 'arched'].forEach((brows) => {
        const ops = build(design({brows})).ops;
        assert.equal(count(ops, 'stroke'), count(base, 'stroke') + 2, brows);
    });
    const thick = build(design({brows: 'thick'})).ops;
    assert.ok(thick.some((op) => op[0] === 'set' && op[1] === 'lineWidth' && op[2] === 7));
    const arched = build(design({brows: 'arched'})).ops;
    assert.equal(count(arched, 'quadraticCurveTo'), 4 + 2, 'arched brows are curves');
    const stubble = build(design({beard: 'stubble', haircolor: 'black'})).ops;
    // One smooth gradient, no stacked see-through layers (rings) and no speckle of dots.
    assert.equal(count(stubble, 'createRadialGradient'), 1, 'one gradient');
    assert.equal(count(stubble, 'ellipse'), count(base, 'ellipse'), 'no stacked ellipses');
    assert.equal(count(stubble, 'arc'), count(base, 'arc'), 'no stubble dots');
    assert.equal(count(stubble, 'fillRect'), 1, 'one fill');
    assert.ok(count(stubble, 'clip') >= 1, 'kept on the screen, below the nose');
    // The shadow is the hair colour, lightened so it shows on the dark screen, faint at most.
    const stops = stubble.filter((op) => op[0] === 'addColorStop');
    assert.equal(stops.length, STUBBLE_STOPS.length);
    stops.forEach((op, i) => {
        assert.equal(op[1], STUBBLE_STOPS[i][0]);
        assert.equal(op[2], `rgba(96, 96, 96, ${STUBBLE_STOPS[i][1]})`);
        assert.ok(STUBBLE_STOPS[i][1] <= 0.45, 'faint');
    });
    // The mouth (the smile, its corners and its lowest point) is in the clear middle of the band.
    const at = stubble.find((op) => op[0] === 'translate');
    const squash = stubble.find((op) => op[0] === 'scale');
    const rx = stubble.find((op) => op[0] === 'createRadialGradient')[6];
    const ry = rx * squash[2];
    const smile = stubble.filter((op) => op[0] === 'arc').pop();
    const where = (x, y) => Math.hypot((x - at[1]) / rx, (y - at[2]) / ry);
    const clear = Math.min(...STUBBLE_STOPS.filter((stop) => stop[1] > 0).map((stop) => stop[0]));
    [smile[4], (smile[4] + smile[5]) / 2, smile[5]].forEach((angle) => {
        const x = smile[1] + (smile[3] + 3) * Math.cos(angle);
        const y = smile[2] + (smile[3] + 3) * Math.sin(angle);
        assert.ok(where(x, y) < clear - 0.05, `the mouth is clear (${where(x, y)})`);
    });
    // The band reaches the jaw: its coloured part lies below the mouth, inside the canvas.
    const band = stubbleBand(smile[1], smile[2] + 14);
    assert.ok(band.cy + band.ry * clear > smile[2] + smile[3] + 6);
    assert.ok(band.cy + band.ry * clear < FACE_CANVAS_HEIGHT);
    // The same shadow every time.
    assert.deepEqual(build(design({beard: 'stubble'})).ops, build(design({beard: 'stubble'})).ops);
});

test('ears show unless the hair covers them', () => {
    CHOICES.hair.forEach((hair) => {
        const {avatar} = build(design({hair, ears: true}));
        const ears = avatar.group.getObjectByName('ears');
        if (hairCoversEars(hair)) {
            assert.equal(ears, undefined, hair);
        } else {
            assert.equal(ears.children.length, 2, hair);
            assert.ok(triangles(ears) <= 200, hair);
        }
        avatar.dispose();
    });
    const {avatar} = build(design({hair: 'short'}));
    assert.equal(avatar.group.getObjectByName('ears'), undefined, 'no ears by default');
    avatar.dispose();
});

test('every nose stands in front of the face screen; beards are small and in the hair colour', () => {
    CHOICES.head.forEach((head) => {
        CHOICES.nose.filter((nose) => nose !== 'none').forEach((nose) => {
            const {avatar} = build(design({head, nose}));
            const screen = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('facescreen')));
            const part = avatar.group.getObjectByName('nose');
            const box = new THREE.Box3().setFromPoints(headVertices(avatar, part));
            assert.ok(box.min.z < screen.min.z, `${head} ${nose}: in front of the screen`);
            assert.ok(triangles(part) <= 200, `${head} ${nose}`);
            avatar.dispose();
        });
    });
    const parts = {moustache: ['beardface'], goatee: ['beardlower'], full: ['beardface', 'beardlower'], stubble: []};
    Object.keys(parts).forEach((beard) => {
        const {avatar} = build(design({beard, haircolor: 'red'}));
        ['beardface', 'beardlower'].forEach((name) => {
            const part = avatar.group.getObjectByName(name);
            assert.equal(!!part, parts[beard].includes(name), `${beard} ${name}`);
            if (part) {
                assert.ok(triangles(part) <= 300, `${beard} ${name}`);
                part.traverse((child) => {
                    if (child.isMesh && child.name !== 'moustacherim') {
                        assert.equal(child.material.color.getHex(), new THREE.Color(HAIR_COLOURS.red).getHex());
                    }
                });
            }
        });
        avatar.dispose();
    });
});

test('the nose, beard and glasses hide while the webcam shows and come back after', () => {
    const {avatar} = build(design({nose: 'round', beard: 'full', glasses: true, ears: true}));
    const visible = () => ['nose', 'beardface', 'beardlower', 'glasses', 'ears']
        .map((name) => avatar.group.getObjectByName(name).visible);
    assert.deepEqual(visible(), [true, true, true, true, true]);
    avatar.setVideo(playingVideo());
    avatar.update(performance.now() + 10 * FADE_MS);
    assert.deepEqual(visible(), [false, false, false, false, true], 'ears stay');
    avatar.setVideo(null);
    avatar.update(performance.now() + 10 * FADE_MS);
    assert.deepEqual(visible(), [true, true, true, true, true]);
    avatar.dispose();
});

test('every value of every key builds, also on the look without a design', () => {
    Object.keys(CHOICES).forEach((key) => {
        CHOICES[key].forEach((value) => {
            const {avatar} = build(design({[key]: value}));
            assert.ok(avatar.group.getObjectByName('skull'), `${key} ${value}`);
            avatar.dispose();
        });
    });
    const {avatar} = build(null);
    assert.equal(avatar.group.getObjectByName('skull'), undefined, 'the first look has its own head');
    assert.equal(avatar.group.getObjectByName('hair'), undefined);
    avatar.dispose();
});

/**
 * The largest distance of a set of vertices from the head centre (m).
 *
 * @param {Object[]} vertices THREE.Vector3 list.
 * @returns {number}
 */
const reach = (vertices) => Math.max(...vertices.map((v) => v.length()));

test('buzz is cropped: thinner and tighter than short, with a higher hairline and a duller colour', () => {
    const short = build(design({hair: 'short'})).avatar;
    const buzz = build(design({hair: 'buzz'})).avatar;
    const shortv = headVertices(short, short.group.getObjectByName('hair'));
    const buzzv = headVertices(buzz, buzz.group.getObjectByName('hair'));
    assert.ok(reach(buzzv) < reach(shortv) - 0.005, `buzz ${reach(buzzv)}, short ${reach(shortv)}`);
    assert.ok(reach(buzzv) < 0.125, 'tight to the head');
    // The hairline: the lowest hair in front of the forehead.
    const hairline = (vertices) => Math.min(...vertices.filter((v) => v.z < -0.06 && Math.abs(v.x) < 0.03).map((v) => v.y));
    assert.ok(hairline(buzzv) > hairline(shortv) + 0.005, 'higher hairline');
    const colour = (avatar) => avatar.group.getObjectByName('hair').children[0].material.color.getHSL({});
    assert.ok(colour(buzz).l < colour(short).l, 'darker');
    assert.ok(colour(buzz).s < colour(short).s, 'less saturated');
    short.dispose();
    buzz.dispose();
});

test('the back of long hair is a curved, thick shell hanging to the shoulders, not a board', () => {
    CHOICES.head.forEach((head) => {
        const {avatar} = build(design({head, hair: 'long'}));
        const fall = avatar.group.getObjectByName('fall');
        assert.ok(fall, head);
        const vertices = headVertices(avatar, fall);
        const box = new THREE.Box3().setFromPoints(vertices);
        // Curved round the head: from beside the head to well behind it.
        assert.ok(box.max.z - box.min.z > 0.12, `${head}: depth ${box.max.z - box.min.z}`);
        assert.ok(box.max.x - box.min.x > 0.2, `${head}: width ${box.max.x - box.min.x}`);
        // To the shoulders, longest in the middle (a rounded lower edge).
        assert.ok(box.min.y < -0.19, `${head}: length`);
        const lowest = (filter) => Math.min(...vertices.filter(filter).map((v) => v.y));
        assert.ok(lowest((v) => Math.abs(v.x) < 0.03) < lowest((v) => Math.abs(v.x) > 0.1) - 0.02, `${head}: rounded edge`);
        // Thick: behind the middle, hair at two depths.
        const middle = vertices.filter((v) => Math.abs(v.x) < 0.01 && v.y < -0.1 && v.z > 0).map((v) => v.z);
        assert.ok(Math.max(...middle) - Math.min(...middle) > 0.012, `${head}: thickness`);
        avatar.dispose();
    });
});

test('the afro is a big round volume of clumps that stays clear of the name label', () => {
    CHOICES.head.forEach((head) => {
        const {avatar} = build(design({head, hair: 'afro'}));
        const hair = avatar.group.getObjectByName('hair');
        assert.ok(avatar.group.getObjectByName('clumps'), head);
        const box = new THREE.Box3().setFromPoints(headVertices(avatar, hair));
        const skull = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('skull')));
        assert.ok(box.max.x > skull.max.x * 1.35, `${head}: wide`);
        assert.ok(box.max.y > skull.max.y + 0.03, `${head}: tall`);
        // The label's lower edge is 0.3 - 0.0625 m above the head centre.
        assert.ok(box.max.y < 0.3 - 0.0625 - 0.02, `${head}: under the label (${box.max.y})`);
        avatar.dispose();
    });
});

test('hats sit on top of the hair and leave out what they would squash', () => {
    ['cap', 'beanie', 'sunhat'].forEach((hat) => {
        CHOICES.head.forEach((head) => {
            ['short', 'afro', 'mohawk', 'topknot', 'curly', 'bob', 'sidepart'].forEach((hair) => {
                const {avatar} = build(design({head, hair, hat}));
                const hatbox = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('hat')));
                const hairbox = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('hair')));
                assert.ok(hatbox.max.y > hairbox.max.y + 0.002, `${hat} ${head} ${hair}: the hat is on top`);
                avatar.dispose();
            });
        });
        const mohawk = build(design({hair: 'mohawk', hat})).avatar;
        assert.equal(mohawk.group.getObjectByName('spikes'), undefined, `${hat}: no spikes`);
        mohawk.dispose();
        const topknot = build(design({hair: 'topknot', hat})).avatar;
        assert.equal(topknot.group.getObjectByName('knot'), undefined, `${hat}: no knot`);
        topknot.dispose();
    });
    // Without a hat the spikes and the knot are there, and the afro is taller.
    const mohawk = build(design({hair: 'mohawk'})).avatar;
    assert.ok(mohawk.group.getObjectByName('spikes'));
    mohawk.dispose();
    const topknot = build(design({hair: 'topknot'})).avatar;
    assert.ok(topknot.group.getObjectByName('knot'));
    topknot.dispose();
    const top = (changes) => {
        const {avatar} = build(design(changes));
        const y = new THREE.Box3().setFromPoints(headVertices(avatar, avatar.group.getObjectByName('hair'))).max.y;
        avatar.dispose();
        return y;
    };
    assert.ok(top({hair: 'afro', hat: 'cap'}) < top({hair: 'afro'}) - 0.02, 'the afro is flatter under a hat');
    // A hat over hair that does not stand out keeps its own size.
    const {avatar} = build(design({hair: 'short', hat: 'cap'}));
    assert.equal(avatar.group.getObjectByName('hat').scale.x, 1);
    avatar.dispose();
});

test('hair that leaves the ears free is cut round them', () => {
    CHOICES.head.forEach((head) => {
        CHOICES.hair.filter((hair) => !hairCoversEars(hair)).forEach((hair) => {
            const {avatar} = build(design({head, hair, ears: true}));
            const hairv = headVertices(avatar, avatar.group.getObjectByName('hair'));
            avatar.group.getObjectByName('ears').children.forEach((ear) => {
                const box = new THREE.Box3().setFromPoints(headVertices(avatar, ear));
                const inside = hairv.filter((v) => box.containsPoint(v));
                assert.equal(inside.length, 0, `${head} ${hair}: ${inside.length} hair vertices over an ear`);
            });
            assert.ok(triangles(avatar.group.getObjectByName('hair')) <= 900, `${head} ${hair}`);
            avatar.dispose();
        });
    });
    // Without ears the sides are whole, as before; with them, one shell cut round the ears.
    const plain = build(design({hair: 'short'})).avatar;
    const cut = build(design({hair: 'short', ears: true})).avatar;
    assert.equal(plain.group.getObjectByName('hair').children.length, 2);
    assert.equal(plain.group.getObjectByName('earcut'), undefined);
    assert.equal(cut.group.getObjectByName('hair').children.length, 2);
    assert.ok(cut.group.getObjectByName('earcut'));
    plain.dispose();
    cut.dispose();
});

test('light skin stays warm, the nose is small and in the skin tone, the moustache has a light rim', () => {
    ['porcelain', 'fair', 'light'].forEach((skin) => {
        const {avatar} = build(design({skin}));
        const material = avatar.group.getObjectByName('skull').material;
        assert.equal(material.metalness, 0, skin);
        assert.ok(material.emissiveIntensity > 0.1, `${skin}: glows a little`);
        assert.ok(material.emissive.r > material.emissive.b * 1.2, `${skin}: in a warm tint`);
        avatar.dispose();
    });
    CHOICES.nose.filter((nose) => nose !== 'none').forEach((nose) => {
        const {avatar} = build(design({nose, skin: 'medium'}));
        const part = avatar.group.getObjectByName('nose');
        const size = new THREE.Box3().setFromPoints(headVertices(avatar, part)).getSize(new THREE.Vector3());
        assert.ok(Math.max(size.x, size.y, size.z) <= 0.02, `${nose}: small`);
        assert.equal(part.material.color.getHex(), new THREE.Color(SKIN_TONES.medium).getHex(), `${nose}: skin tone`);
        assert.ok(part.material.emissiveIntensity > 0, `${nose}: soft shading`);
        avatar.dispose();
    });
    Object.keys(HAIR_COLOURS).forEach((haircolor) => {
        const {avatar} = build(design({beard: 'moustache', haircolor}));
        const rims = [];
        avatar.group.getObjectByName('beardface').traverse((child) => {
            if (child.name === 'moustacherim') {
                rims.push(child);
            }
        });
        assert.equal(rims.length, 2, haircolor);
        assert.ok(rims[0].material.color.getHSL({}, THREE.SRGBColorSpace).l > 0.5, `${haircolor}: a light rim`);
        avatar.dispose();
    });
});

// Hair follow-ups: the buzz outline, the rounded ear cut, the side part's parting.

test('buzz has its own outline: tight, low, and its lower edge sinks into the head (no rim)', () => {
    const short = build(design({hair: 'short'})).avatar;
    const buzz = build(design({hair: 'buzz'})).avatar;
    const shortv = headVertices(short, short.group.getObjectByName('hair'));
    const buzzv = headVertices(buzz, buzz.group.getObjectByName('hair'));
    // At ear height, short hair stands out round the head; the buzz cut is inside the head's outline.
    const width = (vertices) => Math.max(...vertices.filter((v) => Math.abs(v.y) < 0.015).map((v) => Math.abs(v.x)));
    assert.ok(width(buzzv) < width(shortv) - 0.006, `buzz ${width(buzzv)}, short ${width(shortv)}`);
    assert.ok(width(buzzv) <= 0.1205, 'about the head itself');
    // The lowest buzz hair is under the skin (radius below the head's), so its edge has no rim; short
    // hair ends standing off the head.
    const lowest = (vertices) => vertices.filter((v) => v.y < Math.min(...vertices.map((p) => p.y)) + 0.002);
    lowest(buzzv).forEach((v) => assert.ok(v.length() < 0.12 * 0.99, `buzz edge at ${v.length()}`));
    assert.ok(lowest(shortv).every((v) => v.length() > 0.125), 'short hair has its edge');
    // The taper is smooth: the shell size never jumps.
    for (let t = 0; t < Math.PI; t += 0.01) {
        assert.ok(Math.abs(shellScale(1.008, t + 0.01, {from: 1.3, to: 1.9, floor: 0.98}) -
            shellScale(1.008, t, {from: 1.3, to: 1.9, floor: 0.98})) < 0.002);
    }
    short.dispose();
    buzz.dispose();
});

test('shell taper: the size, then a smooth step down to the floor', () => {
    assert.equal(shellScale(1.07, 1, null), 1.07);
    const taper = {from: 1, to: 2, floor: 0.98};
    assert.equal(shellScale(1.07, 0.5, taper), 1.07);
    assert.equal(shellScale(1.07, 1, taper), 1.07);
    assert.ok(Math.abs(shellScale(1.07, 2, taper) - 0.98) < DELTA);
    assert.ok(Math.abs(shellScale(1.07, 3, taper) - 0.98) < DELTA);
    assert.ok(Math.abs(shellScale(1.07, 1.5, taper) - 1.025) < DELTA, 'half way in the middle');
    assert.equal(shellScale(1.07, 1.5, {from: 2, to: 1, floor: 0.9}), 1.07, 'a reversed taper is none');
});

test('the cut round the ears is a rounded arch, not a rectangle', () => {
    const to = Math.PI * 0.6;
    const top = earCutBottom(0, to);
    assert.ok(top < to - 0.3, 'the arch rises over the ear');
    assert.equal(earCutBottom(0.1, to), top, 'flat over the ear');
    assert.equal(earCutBottom(1, to), to, 'full length away from the ear');
    assert.equal(earCutBottom(-1, to), to, 'on both sides');
    // Rounded: the edge passes through heights between the top and the full length, never rising.
    const samples = [];
    for (let d = 0; d <= 0.5; d += 0.005) {
        samples.push(earCutBottom(d, to));
    }
    for (let i = 1; i < samples.length; i++) {
        assert.ok(samples[i] >= samples[i - 1] - DELTA, 'never rises away from the ear');
    }
    const between = samples.filter((theta) => theta > top + (to - top) * 0.1 && theta < to - (to - top) * 0.1);
    assert.ok(between.length >= 4, `${between.length} points on the rounded part`);
    // Hair that ends above the cut is not cut.
    assert.equal(earCutBottom(0, Math.PI * 0.3), Math.PI * 0.3);
    // On the head: the shell's lower edge over an ear is curved (many heights), not two steps.
    const {avatar} = build(design({hair: 'short', ears: true}));
    const cut = headVertices(avatar, avatar.group.getObjectByName('earcut'));
    const heights = new Set(cut.filter((v) => v.x < -0.08 && v.y < 0.03 && v.y > -0.05)
        .map((v) => Math.round(v.y * 1000)));
    // Several heights (a curve), not the two of a rectangular cut; the mesh's own resolution sets how many.
    assert.ok(heights.size >= 5, `${heights.size} heights round the left ear`);
    avatar.dispose();
});

test('the side part has a darker crown under its step, so no skin shows through the parting', () => {
    CHOICES.head.forEach((head) => {
        const {avatar} = build(design({head, hair: 'sidepart', haircolor: 'blonde'}));
        const parting = avatar.group.getObjectByName('parting');
        assert.ok(parting, head);
        const skull = avatar.group.getObjectByName('skull');
        // Outside the skull (beyond where a ray from the head centre to each vertex leaves the
        // skull), all round the crown.
        const vertices = headVertices(avatar, parting);
        const skullv = headVertices(avatar, skull);
        const index = skull.geometry.index;
        vertices.forEach((v) => {
            const ray = new THREE.Ray(new THREE.Vector3(), v.clone().normalize());
            const hit = new THREE.Vector3();
            let surface = 0;
            for (let i = 0; i < index.count; i += 3) {
                if (ray.intersectTriangle(skullv[index.getX(i)], skullv[index.getX(i + 1)], skullv[index.getX(i + 2)],
                    false, hit)) {
                    surface = Math.max(surface, hit.length());
                }
            }
            // A ray straight through one of the skull's own vertices can miss every triangle: nothing to compare.
            if (surface > 0) {
                assert.ok(v.length() > surface * 1.02, `${head}: above the skin (${v.length()}, ${surface})`);
            }
        });
        const xs = vertices.map((v) => v.x);
        assert.ok(Math.min(...xs) < -0.06 && Math.max(...xs) > 0.06, `${head}: a whole crown`);
        const hair = avatar.group.getObjectByName('hair').children.find((child) => child !== parting);
        assert.ok(parting.material.color.getHSL({}).l < hair.material.color.getHSL({}).l, `${head}: darker`);
        avatar.dispose();
    });
});

// Costumes: the pure parts.

test('assets: only the outfit and the hat, as clean http(s) URLs, from an object or JSON', () => {
    const url = 'https://example.org/local/avatar/costumes/suit-tie.glb';
    assert.deepEqual(parseAssets({outfit: url, hat: 'http://example.org/h.glb', other: url}),
        {outfit: url, hat: 'http://example.org/h.glb'});
    assert.deepEqual(parseAssets(JSON.stringify({outfit: url})), {outfit: url});
    ['', '{', '[]', 'null', '"x"', null, undefined, 3, [url]].forEach((value) => {
        assert.deepEqual(parseAssets(value), {}, JSON.stringify(value));
    });
    assert.deepEqual(parseAssets({outfit: 'javascript:alert(1)', hat: '/local/avatar/x.glb'}), {});
    assert.deepEqual(parseAssets({outfit: ' ' + url + ' '}), {outfit: url}, 'trimmed');
    assert.deepEqual(parseAssets({outfit: 'https://example.org/' + 'x'.repeat(2100)}), {}, 'too long');
});

test('costume extras are read and cleaned; unusable ones give null', () => {
    const body = {schema: COSTUME_SCHEMA, kind: 'body', neck: [0, 1.6, 0.05], headscale: 1.5, tint: ['suit', 'tie', 3],
        ownhands: true, lights: [[0, 1, 0], [1, 2], 'x', [0, 1.1, -0.1]], credit: 'Someone, CC0', licence: 'CC0-1.0'};
    const clean = parseCostumeExtras({vrroom: body});
    assert.deepEqual(clean, {kind: 'body', neck: [0, 1.6, 0.05], headscale: 1.5, screen: null, tint: ['suit', 'tie'],
        ownhands: true, lights: [[0, 1, 0], [0, 1.1, -0.1]], hat: null, credit: 'Someone, CC0', licence: 'CC0-1.0'});
    assert.deepEqual(parseCostumeExtras(body), clean, 'the inner object too');
    assert.equal(parseCostumeExtras({...body, schema: 'other'}), null);
    assert.equal(parseCostumeExtras({...body, kind: 'pet'}), null);
    assert.equal(parseCostumeExtras(null), null);
    assert.equal(parseCostumeExtras('x'), null);
    // Defaults and limits.
    const bare = parseCostumeExtras({schema: COSTUME_SCHEMA, kind: 'body', neck: [0, 'a', 0], headscale: 99});
    assert.deepEqual(bare.neck, [0, 1.6, 0]);
    assert.equal(bare.headscale, 3);
    assert.equal(parseCostumeExtras({schema: COSTUME_SCHEMA, kind: 'body', headscale: -1}).headscale, 1);
    assert.equal(bare.ownhands, false);
    assert.deepEqual(bare.tint, []);
    // A device needs a screen; its normal is made a unit vector.
    const device = {schema: COSTUME_SCHEMA, kind: 'device', neck: [0, 1.6, 0],
        screen: {center: [0, 1.6, -0.12], size: [0.45, 0.25], normal: [0, 0, -2]}};
    assert.deepEqual(parseCostumeExtras(device).screen, {center: [0, 1.6, -0.12], size: [0.45, 0.25], normal: [0, 0, -1]});
    assert.equal(parseCostumeExtras({...device, screen: null}), null);
    assert.equal(parseCostumeExtras({...device, screen: {center: [0, 1.6, -0.12], size: [0, 0.2]}}), null);
    assert.deepEqual(parseCostumeExtras({...device, screen: {center: [0, 1, 0], size: [1, 1], normal: [0, 0, 0]}}).screen.normal,
        [0, 0, -1]);
    // An accessory needs its hat offset.
    const hat = {schema: COSTUME_SCHEMA, kind: 'accessory', hat: {offset: [0, 0.045, 0]}};
    assert.deepEqual(parseCostumeExtras(hat).hat, {offset: [0, 0.045, 0]});
    assert.equal(parseCostumeExtras({...hat, hat: {}}), null);
    // Found on the scene, or on the first node that has them (the root node of the exported model).
    const scene = new THREE.Group();
    const node = new THREE.Group();
    node.userData = {vrroom: body};
    scene.add(node);
    assert.deepEqual(findCostumeExtras(scene), clean);
    scene.userData = {vrroom: hat};
    assert.equal(findCostumeExtras(scene).kind, 'accessory', 'the scene first');
    assert.equal(findCostumeExtras(new THREE.Group()), null);
    assert.equal(findCostumeExtras(null), null);
});

test('how a costume is worn: body, floating device, standing device, hat', () => {
    const extras = (more) => parseCostumeExtras({schema: COSTUME_SCHEMA, neck: [0, 1.6, 0],
        screen: {center: [0, 1.6, -0.12], size: [0.45, 0.25]}, hat: {offset: [0, 0.04, 0]}, ...more});
    assert.equal(costumeLayout(extras({kind: 'body'}), 0), 'body');
    assert.equal(costumeLayout(extras({kind: 'device'}), 1.29), 'device', 'a laptop floats');
    assert.equal(costumeLayout(extras({kind: 'device'}), 0), 'standing', 'a robot with a screen head stands');
    assert.equal(costumeLayout(extras({kind: 'accessory'}), -0.15), 'hat');
    assert.equal(costumeLayout(null, 0), '');
});

test('tint: only the materials named in the extras, each once', () => {
    const suit = {name: 'suit'};
    const tie = {name: 'tie'};
    const skin = {name: 'skin'};
    assert.deepEqual(selectTintMaterials([suit, skin, tie, suit, null, {}], ['suit', 'tie']), [suit, tie]);
    assert.deepEqual(selectTintMaterials([suit, skin], []), []);
    assert.deepEqual(selectTintMaterials([suit], undefined), []);
    assert.deepEqual(selectTintMaterials(null, ['suit']), []);
    assert.deepEqual(selectTintMaterials([{name: 'Suit'}], ['suit']), [], 'names match exactly');
});

test('the face fits a device screen: in front of it, and cropped to fill it', () => {
    const screen = {center: [0, 1.6, -0.12], size: [0.45, 0.25], normal: [0, 0, -1]};
    const fit = fitScreen(screen, [0, 1.6, 0], 192 / 141);
    assert.ok(Math.abs(fit.position[0]) < DELTA && Math.abs(fit.position[1]) < DELTA);
    assert.ok(fit.position[2] < -0.12 && fit.position[2] > -0.13, 'a few mm in front of the screen');
    assert.equal(fit.width, 0.45);
    assert.equal(fit.height, 0.25);
    assert.deepEqual(fit.crop, coverCrop(192 / 141, 0.45 / 0.25));
    assert.ok(fit.crop.repeatx === 1 && fit.crop.repeaty < 1, 'a wide screen crops the top and bottom');
    // Relative to the neck point, wherever the screen is.
    const robot = fitScreen({center: [0, 1.589, -0.24], size: [0.6, 0.288], normal: [0, 0, -1]}, [0, 1.599, 0.066], 4 / 3);
    assert.ok(Math.abs(robot.position[1] + 0.01) < 1e-6);
    assert.ok(Math.abs(robot.position[2] + 0.306 + 0.003) < 1e-6);
    // A tall screen crops the sides.
    assert.ok(fitScreen({center: [0, 0, 0], size: [0.2, 0.4], normal: [0, 0, -1]}, [0, 0, 0], 4 / 3).crop.repeatx < 1);
});

test('a device tilts with half the head pitch, never more than 0.3 rad', () => {
    assert.equal(devicePitch([0, 0, -1]), 0);
    const up = devicePitch([0, Math.sin(0.4), -Math.cos(0.4)]);
    assert.ok(Math.abs(up - 0.2) < 1e-9, String(up));
    assert.ok(Math.abs(devicePitch([0, -Math.sin(0.4), -Math.cos(0.4)]) + 0.2) < 1e-9);
    assert.equal(devicePitch([0, 1, 0]), 0.3);
    assert.equal(devicePitch([0, -1, 0]), -0.3);
});

test('costume lamps pulse gently, and stay steady with reduced motion', () => {
    const values = [];
    for (let t = 0; t < 2000; t += 50) {
        values.push(lightPulse(0, t, false));
        assert.equal(lightPulse(2, t, true), 1);
    }
    assert.ok(Math.min(...values) >= 0.3 && Math.max(...values) <= 1);
    assert.ok(Math.max(...values) - Math.min(...values) > 0.4, 'it pulses');
    assert.notEqual(lightPulse(0, 100, false), lightPulse(1, 100, false), 'out of step');
});

// Costumes on avatars, with stand-in models and a stand-in loader.

/** Shared material of the stand-in models (never changed by tinting). */
const MATERIALS = {
    suit: new THREE.MeshStandardMaterial({name: 'suit', color: 0x222222}),
    skin: new THREE.MeshStandardMaterial({name: 'skin', color: 0xe0b090}),
    case: new THREE.MeshStandardMaterial({name: 'case', color: 0x888888}),
    screen: new THREE.MeshStandardMaterial({name: 'screen', color: 0x111111}),
    hat: new THREE.MeshStandardMaterial({name: 'hat', color: 0x111111}),
};

/**
 * A stand-in costume model: boxes (with materials) under a scene that carries the extras.
 *
 * @param {Object} extras The "vrroom" extras.
 * @param {Array[]} boxes [material name, [w, h, d], [x, y, z]]
 * @returns {Object} THREE.Group
 */
const model = (extras, boxes) => {
    const scene = new THREE.Group();
    scene.userData = {vrroom: {schema: COSTUME_SCHEMA, ...extras}};
    boxes.forEach(([material, size, at]) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(size[0], size[1], size[2]), MATERIALS[material]);
        mesh.position.set(at[0], at[1], at[2]);
        scene.add(mesh);
    });
    return scene;
};

const URLS = {
    suit: 'https://example.org/costumes/suit.glb',
    laptop: 'https://example.org/costumes/laptop.glb',
    robot: 'https://example.org/costumes/robot.glb',
    hat: 'https://example.org/costumes/hat.glb',
    missing: 'https://example.org/costumes/missing.glb',
};

/** The stand-in models by URL. */
const MODELS = {
    [URLS.suit]: model({kind: 'body', neck: [0, 1.6, 0.05], headscale: 1.5, tint: ['suit'], ownhands: true,
        lights: [[-0.04, 1.2, -0.12], [0.04, 1.2, -0.12], [0, 1.1, -0.12]]},
    [['suit', [0.5, 1.2, 0.3], [0, 0.9, 0]], ['skin', [0.1, 0.1, 0.1], [0, 1.5, 0]]]),
    [URLS.laptop]: model({kind: 'device', neck: [0, 1.6, 0], screen: {center: [0, 1.6, -0.12], size: [0.45, 0.25],
        normal: [0, 0, -1]}}, [['case', [0.5, 0.3, 0.02], [0, 1.6, -0.11]], ['screen', [0.45, 0.25, 0.005], [0, 1.6, -0.118]]]),
    [URLS.robot]: model({kind: 'device', neck: [0, 1.6, 0.06], ownhands: true, screen: {center: [0, 1.59, -0.24],
        size: [0.6, 0.29], normal: [0, 0, -1]}}, [['case', [0.6, 1.95, 0.4], [0, 0.975, 0]]]),
    [URLS.hat]: model({kind: 'accessory', hat: {offset: [0, 0.045, 0]}}, [['hat', [0.4, 0.2, 0.4], [0, 0.1, 0]]]),
};

/**
 * A stand-in glTF loader that counts its loads.
 *
 * @returns {Object} {loads: string[], loadAsync(url)}
 */
const standInLoader = () => {
    const loader = {loads: []};
    loader.loadAsync = (url) => {
        loader.loads.push(url);
        return MODELS[url] ? Promise.resolve({scene: MODELS[url]}) : Promise.reject(new Error('404 ' + url));
    };
    return loader;
};

/**
 * Build an avatar that wears costumes, and wait until it is dressed.
 *
 * @param {Object} changes Design changes.
 * @param {Object} assets {outfit, hat}
 * @param {Object} more More options (loader, onError).
 * @returns {Promise<Object>} The avatar.
 */
const dressed = async(changes, assets, more = {}) => {
    const avatar = createAvatar(THREE, design(changes), {name: 'Costume', seed: 'abc', assets: assets,
        loader: more.loader || standInLoader(), onError: more.onError});
    await avatar.whenDressed();
    return avatar;
};

/** A time when the first lamp is at its brightest (ms): a quarter of its period. */
const LIGHTS_PEAK = 1800 / 4;

/** A pose at (1, 1.5, 2), turned by yaw and pitch. */
const turned = (yaw, pitch = 0) => {
    const q = new THREE.Quaternion().setFromEuler(new THREE.Euler(pitch, yaw, 0, 'YXZ'));
    return {h: [1, 1.5, 2, q.x, q.y, q.z, q.w], l: null, r: null};
};

/**
 * World position of an object.
 *
 * @param {Object} object THREE.Object3D
 * @returns {Object} THREE.Vector3
 */
const worldOf = (object) => {
    object.updateWorldMatrix(true, false);
    return new THREE.Vector3().setFromMatrixPosition(object.matrixWorld);
};

test('a body costume replaces the body, carries the head at its neck and takes the accent colour', async() => {
    clearCostumes();
    const loader = standInLoader();
    const avatar = await dressed({outfit: 'suit-test', accent: 'pink'}, {outfit: URLS.suit}, {loader});
    const g = avatar.group;
    assert.equal(g.getObjectByName('body').visible, false, 'the built body is hidden');
    assert.equal(g.getObjectByName('head').visible, true, 'our head stays');
    const costume = g.getObjectByName('costume');
    assert.ok(costume, 'worn');
    assert.notEqual(costume, MODELS[URLS.suit], 'a clone of the shared model');
    // Tint: the suit in the accent colour, as a copy; the skin is the shared material.
    const meshes = costume.children.filter((child) => child.isMesh && child.name !== 'costumelight');
    const suit = meshes.find((mesh) => mesh.material.name === 'suit');
    const skin = meshes.find((mesh) => mesh.material.name === 'skin');
    assert.notEqual(suit.material, MATERIALS.suit);
    assert.equal(suit.material.color.getHex(), new THREE.Color(COLOURS.pink).getHex());
    assert.equal(MATERIALS.suit.color.getHex(), 0x222222, 'the shared model keeps its colour');
    assert.equal(skin.material, MATERIALS.skin);
    // The head (scaled by headscale) sits at the neck point, and the costume turns with the head's yaw.
    avatar.setPose(turned(Math.PI / 2), '3d');
    g.updateMatrixWorld(true);
    const head = g.getObjectByName('head');
    assert.ok(Math.abs(head.scale.x - 1.5) < 1e-9, 'headscale');
    const neck = costume.localToWorld(new THREE.Vector3(0, 1.6, 0.05));
    assert.ok(neck.distanceTo(worldOf(head)) < 1e-6, 'the neck point is at the head centre');
    const feet = costume.localToWorld(new THREE.Vector3(0, 0, 0));
    assert.ok(Math.abs(feet.y - (1.5 - 1.6)) < 1e-6, 'hangs from the head like the body');
    // One metre in front of the neck point (which sits 5 cm behind the costume's centre line).
    const front = costume.localToWorld(new THREE.Vector3(0, 1.6, 0.05 - 1)).sub(neck);
    assert.ok(Math.abs(front.x + 1) < 1e-6, 'turned with the yaw (facing -X)');
    // The webcam still doubles the head (on top of headscale).
    avatar.setVideo(playingVideo());
    avatar.update(performance.now() + 10 * FADE_MS);
    assert.ok(Math.abs(head.scale.x - 3) < 1e-9);
    // Lamps in the accent colour and two fixed colours, pulsing.
    const lights = [];
    costume.traverse((child) => {
        if (child.name === 'costumelight') {
            lights.push(child);
        }
    });
    assert.equal(lights.length, 3);
    // Big enough to see: a bead of about 2.5-3 cm with a soft halo around it, never picked.
    lights.forEach((light) => {
        light.geometry.computeBoundingSphere();
        const radius = light.geometry.boundingSphere.radius;
        assert.ok(radius >= 0.025 && radius <= 0.03, 'lamp radius ' + radius);
        const halo = light.getObjectByName('costumelighthalo');
        assert.ok(halo && halo.isSprite, 'a halo');
        assert.ok(halo.scale.x > 2 * radius, 'the halo is wider than the bead');
        assert.equal(halo.material.blending, THREE.AdditiveBlending);
        assert.equal(halo.material.depthWrite, false);
        assert.equal(halo.raycast(), undefined);
    });
    assert.equal(lights[0].getObjectByName('costumelighthalo').material.map,
        lights[1].getObjectByName('costumelighthalo').material.map, 'one halo texture');
    avatar.update(0);
    const glow = lights[0].getObjectByName('costumelighthalo').material.opacity;
    avatar.update(450);
    assert.notEqual(lights[0].getObjectByName('costumelighthalo').material.opacity, glow, 'the halo pulses too');
    avatar.update(0);
    const first = lights[0].material.color.clone();
    avatar.update(450);
    assert.notEqual(lights[0].material.color.getHex(), first.getHex(), 'pulses');
    avatar.update(LIGHTS_PEAK);
    const hsl = lights[0].material.color.getHSL({});
    assert.ok(Math.abs(hsl.h - new THREE.Color(COLOURS.pink).getHSL({}).h) < 0.02, 'the first lamp is in the accent');
    // One load for every avatar that wears it.
    const second = await dressed({outfit: 'suit-test'}, {outfit: URLS.suit}, {loader});
    assert.equal(loader.loads.length, 1, 'loaded once');
    assert.notEqual(second.group.getObjectByName('costume'), costume);
    avatar.dispose();
    second.dispose();
});

test('a device replaces the head and the body and shows the face on its screen', async() => {
    clearCostumes();
    const avatar = await dressed({outfit: 'laptop-test'}, {outfit: URLS.laptop});
    const g = avatar.group;
    assert.equal(g.getObjectByName('body').visible, false);
    assert.equal(g.getObjectByName('head').visible, false);
    const holder = g.getObjectByName('outfit');
    const face = g.getObjectByName('facescreen');
    const video = g.getObjectByName('videoscreen');
    assert.equal(face.parent, holder, 'the face screen is on the device');
    assert.equal(video.parent, holder);
    assert.ok(face.visible);
    face.geometry.computeBoundingBox();
    const size = face.geometry.boundingBox.getSize(new THREE.Vector3());
    assert.ok(Math.abs(size.x - 0.45) < 1e-6 && Math.abs(size.y - 0.25) < 1e-6, 'fitted to the screen');
    assert.ok(face.position.z < -0.12 && video.position.z < face.position.z, 'in front of the screen, the video in front');
    // Facing -Z (towards whoever looks at the front of the device).
    const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(face.quaternion);
    assert.ok(normal.z < -0.999);
    // The face is cropped to fill the wide screen.
    const map = face.material.map;
    assert.ok(map.repeat.y < 1 && map.repeat.x === 1);
    // The head does not grow for the webcam, and the video is cropped to the screen.
    avatar.setPose(turned(0, 0.5), '3d');
    avatar.setVideo(playingVideo());
    avatar.update(performance.now() + 10 * FADE_MS);
    assert.equal(g.getObjectByName('head').scale.x, 1);
    assert.ok(video.visible && video.material.opacity > 0.99);
    assert.deepEqual([video.material.map.repeat.x, video.material.map.repeat.y],
        [coverCrop(320 / 240, 0.45 / 0.25).repeatx, coverCrop(320 / 240, 0.45 / 0.25).repeaty]);
    // It turns with the head, and tilts a little with its pitch.
    assert.ok(Math.abs(holder.rotation.x - devicePitch(new THREE.Vector3(0, 0, -1)
        .applyQuaternion(new THREE.Quaternion().setFromEuler(new THREE.Euler(0.5, 0, 0, 'YXZ'))).toArray())) < 1e-6);
    assert.ok(holder.rotation.x > 0 && holder.rotation.x <= 0.3);
    // The name tag is above the device.
    const label = g.getObjectByName('label');
    assert.ok(label.position.y - 1.5 >= 0.3 - 1e-9);
    avatar.dispose();
});

test('a standing device is worn like a body, with the face on its screen and no tilt', async() => {
    clearCostumes();
    const avatar = await dressed({outfit: 'robot-test'}, {outfit: URLS.robot});
    const g = avatar.group;
    assert.equal(g.getObjectByName('head').visible, false);
    assert.equal(g.getObjectByName('body').visible, false);
    assert.equal(g.getObjectByName('facescreen').parent, g.getObjectByName('outfit'));
    avatar.setPose(turned(0, 0.5), '3d');
    assert.equal(g.getObjectByName('outfit').rotation.x, 0, 'no tilt: it stands');
    // The tag clears the top of the robot (1.95 m, its neck at 1.6 m).
    assert.ok(g.getObjectByName('label').position.y - 1.5 >= 1.95 - 1.6 + 0.05);
    avatar.dispose();
});

test('the pirate hat sits on the head, leaves out what a hat would squash, and the tag clears it', async() => {
    clearCostumes();
    const avatar = await dressed({hat: 'pirate', hair: 'mohawk'}, {hat: URLS.hat});
    const g = avatar.group;
    const hat = g.getObjectByName('hat');
    assert.ok(hat && hat.userData.vrroomCostume, 'the model hat');
    assert.equal(hat.parent.name, 'headparts', 'on the head (grows with it)');
    assert.equal(g.getObjectByName('spikes'), undefined, 'no spikes under it');
    const box = new THREE.Box3().setFromPoints(headVertices(avatar, hat));
    assert.ok(Math.abs(box.min.y - 0.045) < 1e-6, 'at its offset above the head centre');
    avatar.setPose(turned(0), '3d');
    assert.ok(g.getObjectByName('label').position.y - 1.5 > box.max.y + 0.05, 'the tag is above the hat');
    // Over an afro it is drawn bigger, like the other hats.
    const afro = await dressed({hat: 'pirate', hair: 'afro'}, {hat: URLS.hat});
    assert.ok(afro.group.getObjectByName('hat').scale.x > 1);
    afro.dispose();
    // A hat model is not an outfit, and an outfit model is not a hat.
    const errors = [];
    const wrong = await dressed({outfit: 'hat-test', hat: 'pirate', hair: 'mohawk'}, {outfit: URLS.hat, hat: URLS.suit},
        {onError: (error) => errors.push(error)});
    assert.equal(wrong.group.getObjectByName('costume'), undefined);
    assert.equal(wrong.group.getObjectByName('body').visible, true);
    assert.ok(wrong.group.getObjectByName('spikes'), 'no hat: the spikes are back');
    assert.equal(errors.length, 2);
    avatar.dispose();
    wrong.dispose();
});

test('without a model, or when it fails to load, the avatar looks as it does without a costume', async() => {
    clearCostumes();
    const plain = build(design({hair: 'mohawk'})).avatar;
    const count = triangles(plain.group);
    // No assets: an outfit id and the pirate hat alone change nothing.
    const bare = await dressed({hair: 'mohawk', outfit: 'suit-tie', hat: 'pirate'}, {});
    assert.equal(triangles(bare.group), count);
    assert.equal(bare.group.getObjectByName('body').visible, true);
    assert.ok(bare.group.getObjectByName('spikes'));
    // A model that does not load: told once, the look without it.
    const errors = [];
    const loader = standInLoader();
    const failed = await dressed({hair: 'mohawk', outfit: 'missing', hat: 'pirate'}, {outfit: URLS.missing, hat: URLS.missing},
        {loader, onError: (error) => errors.push(error)});
    assert.equal(triangles(failed.group), count);
    assert.equal(failed.group.getObjectByName('body').visible, true);
    assert.equal(failed.group.getObjectByName('head').visible, true);
    assert.ok(failed.group.getObjectByName('spikes'));
    assert.equal(errors.length, 1, 'told once');
    assert.equal(loader.loads.length, 1, 'not loaded again');
    // An outfit of "none" ignores the assets.
    const none = await dressed({hair: 'mohawk'}, {outfit: URLS.suit});
    assert.equal(triangles(none.group), count);
    assert.equal(none.group.getObjectByName('costume'), undefined);
    // A loader that is missing altogether fails the same way.
    const entry = loadCostume({Box3: THREE.Box3}, 'https://example.org/costumes/none.glb');
    assert.equal(await entry.promise, null);
    assert.equal(entry.failed, true);
    plain.dispose();
    bare.dispose();
    failed.dispose();
    none.dispose();
});

test('changing or taking off a costume puts the face screen back on the head', async() => {
    clearCostumes();
    const loader = standInLoader();
    const avatar = await dressed({outfit: 'laptop-test'}, {outfit: URLS.laptop}, {loader});
    const g = avatar.group;
    // Another costume (the assets change with the appearance).
    avatar.setAppearance(design({outfit: 'suit-test'}), '', {outfit: URLS.suit});
    await avatar.whenDressed();
    assert.equal(g.getObjectByName('facescreen').parent, g.getObjectByName('head'));
    assert.equal(g.getObjectByName('head').visible, true);
    assert.equal(g.getObjectByName('facescreen').material.map.repeat.y, 1, 'the face is no longer cropped');
    assert.ok(g.getObjectByName('costume'));
    // Leaving out the assets keeps them.
    avatar.setAppearance(design({outfit: 'suit-test', accent: 'teal'}), '');
    await avatar.whenDressed();
    assert.ok(g.getObjectByName('costume'));
    // Off again.
    avatar.setAppearance(design({}), '', {});
    assert.equal(g.getObjectByName('costume'), undefined);
    assert.equal(g.getObjectByName('body').visible, true);
    assert.equal(g.getObjectByName('outfit').children.length, 0);
    avatar.dispose();
});
