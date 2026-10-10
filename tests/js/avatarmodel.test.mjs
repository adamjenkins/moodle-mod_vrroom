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
 * Tests of the pure parts of the avatar builder (vrroomruntime_builtin/live/avatarmodel): the
 * appearance document, the palette, the cover crop, the fade and the live video check.
 *
 * Run from the repository root: node --test tests/js/avatarmodel.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {SCHEMA, COLOURS, HAIR_COLOURS, CHOICES, FLAGS, DEFAULT_APPEARANCE, APPEARANCE_MAX, FADE_MS,
    cleanAppearance, parseAppearance, cleanPictureUrl, coverCrop, fadeValue, hasLiveVideo, hueOf, videoHeadScale}
    from '../../runtime/builtin/amd/src/live/avatarmodel.js';

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
};

/**
 * A stand-in for a media stream with some video tracks.
 *
 * @param {Object[]} tracks Track stand-ins {readyState, muted, enabled}.
 * @returns {Object}
 */
const stream = (tracks) => ({getVideoTracks: () => tracks});

test('the palette has the 12 colours and 6 hair colours of the contract, as hex', () => {
    assert.deepEqual(Object.keys(COLOURS), ['red', 'orange', 'yellow', 'green', 'teal', 'cyan', 'blue', 'indigo',
        'purple', 'pink', 'brown', 'grey']);
    assert.deepEqual(Object.keys(HAIR_COLOURS), ['black', 'brown', 'blonde', 'auburn', 'grey', 'white']);
    [...Object.values(COLOURS), ...Object.values(HAIR_COLOURS)].forEach((hex) => assert.match(hex, /^#[0-9a-f]{6}$/));
    assert.equal(new Set(Object.values(COLOURS)).size, 12, 'colours are distinct');
});

test('the choices are the allowed values of the contract', () => {
    assert.equal(SCHEMA, 'vrroom-avatar/1');
    assert.deepEqual([...CHOICES.body], ['capsule', 'slim', 'broad']);
    assert.deepEqual([...CHOICES.head], ['round', 'square']);
    assert.deepEqual([...CHOICES.hair], ['none', 'short', 'long', 'bun']);
    assert.deepEqual([...CHOICES.hat], ['none', 'cap', 'beanie', 'sunhat']);
    assert.deepEqual([...CHOICES.color], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.accent], Object.keys(COLOURS));
    assert.deepEqual([...CHOICES.haircolor], Object.keys(HAIR_COLOURS));
    assert.deepEqual([...FLAGS], ['glasses', 'picture']);
});

test('the defaults are those of the contract and are frozen', () => {
    assert.deepEqual({...DEFAULT_APPEARANCE}, {
        schema: SCHEMA, body: 'capsule', head: 'round', color: 'blue', accent: 'grey', hair: 'none',
        haircolor: 'brown', glasses: false, hat: 'none', picture: true,
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
