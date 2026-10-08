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
 * Files of the room editor: the list the server sent, lookups, and uploads through Moodle's file
 * picker in a modal form (mod_vrroom\form\asset_upload).
 *
 * @module     mod_vrroom/editor_assets
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import {getString} from 'core/str';

/** Upload kind => the areas whose files of that kind fit. */
const KIND_AREAS = Object.freeze({
    model: ['models', 'environment', 'assets'],
    image: ['media', 'assets'],
    audio: ['media', 'assets'],
    video: ['media', 'assets'],
});

/**
 * Whether two file references name the same file.
 *
 * @param {Object|null} a File reference.
 * @param {Object|null} b File reference.
 * @returns {boolean}
 */
export const sameRef = (a, b) => !!a && !!b && a.area === b.area && Number(a.itemid) === Number(b.itemid) &&
    (a.filepath || '/') === (b.filepath || '/') && a.filename === b.filename;

/**
 * A stable string key of a file reference (for select values).
 *
 * @param {Object} ref File reference.
 * @returns {string}
 */
export const refKey = (ref) => JSON.stringify([ref.area, Number(ref.itemid), ref.filepath || '/', ref.filename]);

/**
 * The file reference stored in a scene for an asset (no URL or other runtime keys).
 *
 * @param {Object} asset Asset from the list.
 * @returns {Object} {area, itemid, filepath, filename}
 */
export const toRef = (asset) => ({
    area: asset.area,
    itemid: Number(asset.itemid),
    filepath: asset.filepath || '/',
    filename: asset.filename,
});

/**
 * Parse a select value made by refKey().
 *
 * @param {string} key The key.
 * @returns {Object|null} File reference.
 */
export const parseKey = (key) => {
    try {
        const [area, itemid, filepath, filename] = JSON.parse(key);
        if (typeof area !== 'string' || typeof filename !== 'string') {
            return null;
        }
        return {area: area, itemid: Number(itemid), filepath: String(filepath || '/'), filename: filename};
    } catch (e) {
        return null;
    }
};

/**
 * The assets of a kind that may be used where these areas are allowed.
 *
 * @param {Array} assets The asset list.
 * @param {string} kind model, image, audio or video.
 * @param {string[]} areas Allowed areas.
 * @returns {Array}
 */
export const assetsOfKind = (assets, kind, areas) => assets.filter((a) => a.kind === kind &&
    areas.includes(a.area) && (KIND_AREAS[kind] || []).includes(a.area));

/**
 * The asset a file reference names.
 *
 * @param {Array} assets The asset list.
 * @param {Object|null} ref File reference.
 * @returns {Object|null}
 */
export const findAsset = (assets, ref) => assets.find((a) => sameRef(a, ref)) || null;

/**
 * Open the upload form and resolve with the new asset.
 *
 * @param {number} cmid Course module id.
 * @param {string} kind model, image, audio or video.
 * @param {Array} assets The asset list; the new asset is added to it.
 * @param {HTMLElement|null} returnfocus Element to focus when the form closes.
 * @returns {Promise<Object|null>} The asset, or null when the form was cancelled.
 */
export const upload = (cmid, kind, assets, returnfocus) => new Promise((resolve) => {
    const form = new ModalForm({
        formClass: 'mod_vrroom\\form\\asset_upload',
        args: {cmid: cmid, kind: kind},
        modalConfig: {title: getString('editoruploadtitle', 'mod_vrroom')},
        returnFocus: returnfocus || null,
    });
    let done = false;
    form.addEventListener(form.events.FORM_SUBMITTED, (event) => {
        const asset = event.detail;
        done = true;
        if (asset && asset.filename) {
            assets.push(asset);
            resolve(asset);
        } else {
            resolve(null);
        }
    });
    form.addEventListener(form.events.FORM_CANCELLED, () => {
        if (!done) {
            done = true;
            resolve(null);
        }
    });
    form.show().then(() => {
        // Closing the modal without Cancel ends the wait too. ModalEvents.destroyed bound on the
        // root never fires (the root is removed first), so watch whether the root is still in the page.
        const timer = window.setInterval(() => {
            const root = form.modal ? form.modal.getRoot() : null;
            if (done) {
                window.clearInterval(timer);
            } else if (form.modal && root && root[0] && !root[0].isConnected) {
                window.clearInterval(timer);
                done = true;
                resolve(null);
            }
        }, 500);
        return null;
    }).catch(() => {
        if (!done) {
            done = true;
            resolve(null);
        }
    });
});
