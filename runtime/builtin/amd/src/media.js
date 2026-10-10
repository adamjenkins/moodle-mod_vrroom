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
 * The media of a hotspot's panel (content.media[]): pictures, audio and video.
 *
 * The launch config gives each entry a url and a kind ('image', 'audio' or 'video', from its
 * mimetype). An entry without a kind is a picture, as hotspot media were before audio and video.
 *
 * No three.js and no Moodle imports, so it runs in node tests too.
 *
 * @module     vrroomruntime_builtin/media
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The media kinds a panel shows. */
export const MEDIA_KINDS = ['image', 'audio', 'video'];

/**
 * The kind of one media entry: its kind, else from its mimetype, else a picture.
 *
 * @param {Object} entry content.media[] entry.
 * @returns {string} 'image', 'audio' or 'video'
 */
export const mediaKind = (entry) => {
    const e = entry && typeof entry === 'object' ? entry : {};
    if (MEDIA_KINDS.indexOf(e.kind) >= 0) {
        return e.kind;
    }
    const mime = typeof e.mimetype === 'string' ? e.mimetype.toLowerCase() : '';
    if (mime.startsWith('audio/')) {
        return 'audio';
    }
    if (mime.startsWith('video/')) {
        return 'video';
    }
    return 'image';
};

/**
 * The template context of a hotspot's media: entries that have a URL, in their order.
 *
 * @param {Object} content The hotspot's content.
 * @param {string} title Plain-text title, the label of each entry.
 * @returns {Object[]} [{url, alt, kind, isimage, isaudio, isvideo}]
 */
export const mediaItems = (content, title) => {
    const list = content && Array.isArray(content.media) ? content.media : [];
    return list.filter((m) => m && typeof m.url === 'string' && m.url !== '').map((m) => {
        const kind = mediaKind(m);
        return {
            url: m.url,
            alt: String(title || ''),
            kind: kind,
            isimage: kind === 'image',
            isaudio: kind === 'audio',
            isvideo: kind === 'video',
        };
    });
};
