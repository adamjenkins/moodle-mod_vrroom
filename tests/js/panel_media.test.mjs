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
 * Tests of the media of hotspot panels by kind (vrroomruntime_builtin/media), which the overlay
 * panel, the in-world XR panel and the list view share.
 *
 * Run from the repository root: node --test tests/js/panel_media.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {MEDIA_KINDS, mediaKind, mediaItems} from '../../runtime/builtin/amd/src/media.js';

test('the kinds are image, audio and video', () => {
    assert.deepEqual(MEDIA_KINDS, ['image', 'audio', 'video']);
});

test('the kind comes from the entry, else its mimetype, else it is a picture', () => {
    assert.equal(mediaKind({kind: 'audio'}), 'audio');
    assert.equal(mediaKind({kind: 'video', mimetype: 'audio/mpeg'}), 'video', 'the given kind wins');
    assert.equal(mediaKind({mimetype: 'audio/ogg'}), 'audio');
    assert.equal(mediaKind({mimetype: 'VIDEO/MP4'}), 'video');
    assert.equal(mediaKind({mimetype: 'image/png'}), 'image');
    assert.equal(mediaKind({kind: 'model'}), 'image', 'an unknown kind is not trusted');
    assert.equal(mediaKind({url: 'x.jpg'}), 'image', 'older launch configs gave pictures only');
    assert.equal(mediaKind(null), 'image');
});

test('panel items keep their order, drop entries without a URL and carry the flags', () => {
    const items = mediaItems({media: [
        {url: 'https://example.com/a.jpg', kind: 'image'},
        {url: '', kind: 'audio'},
        {kind: 'video'},
        {url: 'https://example.com/b.mp3', kind: 'audio'},
        {url: 'https://example.com/c.mp4', mimetype: 'video/mp4'},
        null,
    ]}, 'The Vase');
    assert.deepEqual(items, [
        {url: 'https://example.com/a.jpg', alt: 'The Vase', kind: 'image', isimage: true, isaudio: false, isvideo: false},
        {url: 'https://example.com/b.mp3', alt: 'The Vase', kind: 'audio', isimage: false, isaudio: true, isvideo: false},
        {url: 'https://example.com/c.mp4', alt: 'The Vase', kind: 'video', isimage: false, isaudio: false, isvideo: true},
    ]);
});

test('no media, no items', () => {
    assert.deepEqual(mediaItems({}, 'x'), []);
    assert.deepEqual(mediaItems(null, 'x'), []);
    assert.deepEqual(mediaItems({media: 'nope'}, 'x'), []);
});
