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
 * a stand-in canvas) the hair styles, head shapes, skin tones and face features it builds.
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
    hairCoversEars, createAvatar} from '../../runtime/builtin/amd/src/live/avatarmodel.js';

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

test('the palettes have the 12 colours, 10 hair colours and 8 skin tones of the contract, as hex', () => {
    assert.deepEqual(Object.keys(COLOURS), ['red', 'orange', 'yellow', 'green', 'teal', 'cyan', 'blue', 'indigo',
        'purple', 'pink', 'brown', 'grey']);
    assert.deepEqual(Object.keys(HAIR_COLOURS), ['black', 'brown', 'blonde', 'auburn', 'grey', 'white', 'red', 'pink',
        'blue', 'purple']);
    assert.deepEqual(Object.keys(SKIN_TONES), ['porcelain', 'fair', 'light', 'medium', 'tan', 'brown', 'dark', 'deep']);
    [...Object.values(COLOURS), ...Object.values(HAIR_COLOURS), ...Object.values(SKIN_TONES)].forEach((hex) => {
        assert.match(hex, /^#[0-9a-f]{6}$/);
    });
    assert.equal(new Set(Object.values(COLOURS)).size, 12, 'colours are distinct');
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
    assert.deepEqual([...CHOICES.hat], ['none', 'cap', 'beanie', 'sunhat']);
    assert.deepEqual([...CHOICES.color], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.accent], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.haircolor], Object.keys(HAIR_COLOURS));
    assert.deepEqual([...CHOICES.skin], ['match', 'porcelain', 'fair', 'light', 'medium', 'tan', 'brown', 'dark', 'deep']);
    assert.deepEqual([...CHOICES.eyes], ['dots', 'round', 'almond', 'sleepy']);
    assert.deepEqual([...CHOICES.brows], ['none', 'thin', 'thick', 'arched']);
    assert.deepEqual([...CHOICES.nose], ['none', 'small', 'round', 'pointed']);
    assert.deepEqual([...CHOICES.beard], ['none', 'stubble', 'moustache', 'goatee', 'full']);
    assert.deepEqual([...CHOICES.outfit], ['none'], 'outfit is reserved for the costumes');
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
        outfit: 'pirate',
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

test('outfit is reserved: only none is kept', () => {
    ['pirate', 'sailor', 'suit', '', 'None', null, 1, ['none'], {}].forEach((value) => {
        assert.equal(cleanAppearance({schema: SCHEMA, outfit: value}).outfit, 'none', JSON.stringify(value));
    });
    assert.equal(cleanAppearance({schema: SCHEMA, outfit: 'none'}).outfit, 'none');
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
    // A soft shadow: stacked see-through ellipses (beside the two eyes), no speckle of dots.
    assert.ok(count(stubble, 'ellipse') >= 2 + 5, 'stubble shadow');
    assert.equal(count(stubble, 'arc'), count(base, 'arc'), 'no stubble dots');
    assert.ok(stubble.some((op) => op[0] === 'set' && op[1] === 'globalAlpha' && op[2] > 0 && op[2] < 0.2),
        'each layer is faint');
    assert.ok(count(stubble, 'clip') >= 1, 'kept on the screen, below the nose');
    // The shadow is the hair colour, lightened so it shows on the dark screen.
    assert.ok(stubble.some((op) => op[0] === 'set' && op[1] === 'fillStyle' && op[2] === '#606060'));
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
    // Without ears the sides are whole, as before.
    const plain = build(design({hair: 'short'})).avatar;
    const cut = build(design({hair: 'short', ears: true})).avatar;
    assert.equal(plain.group.getObjectByName('hair').children.length, 2);
    assert.ok(cut.group.getObjectByName('hair').children.length > 2);
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
