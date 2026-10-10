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
 * A direction picker for forms: the flat 360° photo in a dialogue; a click (or the arrow keys)
 * chooses a direction, written into a yaw and a pitch field.
 *
 * The photo is equirectangular: its middle is straight ahead (yaw 0), its left and right edges are
 * behind, its top is straight up. So yaw = (0.5 - x / width) * 360 and pitch = (0.5 - y / height) * 180.
 *
 * The photo is a URL, or the file in a form's draft area (a filemanager not saved yet), found
 * through repository/draftfiles_ajax.php as Moodle's own file manager finds it.
 *
 * @module     mod_vrroom/panorama_picker
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';

/** Pitch limits (degrees), as for a placement. */
const PITCH = [-85, 85];

/** Image types the picker can show. */
const IMAGETYPES = /\.(jpe?g|png|webp)$/i;

/**
 * Round to one decimal.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const r1 = (value) => Math.round(value * 10) / 10;

/**
 * An angle in degrees brought into -180..180.
 *
 * @param {number} degrees Angle.
 * @returns {number}
 */
const wrap = (degrees) => {
    let a = ((Number(degrees) || 0) + 180) % 360;
    if (a < 0) {
        a += 360;
    }
    return a - 180;
};

/**
 * A direction made valid.
 *
 * @param {number} yaw Degrees.
 * @param {number} pitch Degrees.
 * @returns {Object} {yaw, pitch}
 */
const normalise = (yaw, pitch) => ({
    yaw: r1(wrap(yaw)),
    pitch: r1(Math.min(Math.max(Number(pitch) || 0, PITCH[0]), PITCH[1])),
});

/**
 * The direction of a point of the photo.
 *
 * @param {number} fx x / width, 0..1.
 * @param {number} fy y / height, 0..1.
 * @returns {Object} {yaw, pitch}
 */
export const directionAt = (fx, fy) => normalise((0.5 - fx) * 360, (0.5 - fy) * 180);

/**
 * Where a direction lies on the photo.
 *
 * @param {number} yaw Degrees.
 * @param {number} pitch Degrees.
 * @returns {Object} {fx, fy}, each 0..1.
 */
export const pointOf = (yaw, pitch) => ({fx: 0.5 - wrap(yaw) / 360, fy: 0.5 - pitch / 180});

/**
 * The value of a draft item id option: a number, or the id of the field that holds one (read when
 * the picker opens, as a form's filemanager can change).
 *
 * @param {number|string|Object} source draftitemid or {draftitemid}.
 * @returns {number}
 */
const draftItemid = (source) => {
    const value = source && typeof source === 'object' ? source.draftitemid : source;
    if (typeof value === 'string' && !/^\d+$/.test(value)) {
        const input = document.getElementById(value) || document.querySelector(`[name="${CSS.escape(value)}"]`);
        return input ? parseInt(input.value, 10) || 0 : 0;
    }
    return parseInt(value, 10) || 0;
};

/**
 * The URL of the first image in a draft area.
 *
 * @param {number} itemid Draft item id.
 * @returns {Promise<string|null>}
 */
const draftImageUrl = (itemid) => {
    if (!itemid) {
        return Promise.resolve(null);
    }
    const params = new URLSearchParams({action: 'list', itemid: String(itemid), filepath: '/', sesskey: Config.sesskey});
    return fetch(`${Config.wwwroot}/repository/draftfiles_ajax.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString(),
    }).then((response) => (response.ok ? response.json() : null)).then((data) => {
        const list = data && Array.isArray(data.list) ? data.list : [];
        const file = list.find((f) => f && f.type !== 'folder' && IMAGETYPES.test(String(f.filename || '')) && f.url);
        return file ? String(file.url) : null;
    });
};

/**
 * Open the picker for one pair of fields.
 *
 * @param {HTMLInputElement} yawinput Yaw field.
 * @param {HTMLInputElement} pitchinput Pitch field.
 * @param {string} url Photo URL.
 * @param {HTMLElement} button The button that opened it (focused again on close).
 * @returns {Promise}
 */
const openPicker = (yawinput, pitchinput, url, button) => {
    const id = `vrroom-pick-${Date.now()}`;
    let current = normalise(yawinput.value, pitchinput.value);
    return ModalSaveCancel.create({
        title: getString('panoramapickertitle', 'mod_vrroom'),
        body: Templates.render('mod_vrroom/panorama_picker', {id: id, url: url}),
        buttons: {save: getString('panoramapickeruse', 'mod_vrroom')},
        large: true,
        removeOnClose: true,
        show: true,
        returnElement: button,
    }).then((modal) => {
        const root = modal.getRoot();
        const body = root[0];
        const area = () => body.querySelector(`#${id}`);
        const mark = () => body.querySelector(`#${id}-mark`);
        const status = () => body.querySelector(`#${id}-value`);

        /** Show the current direction: the mark on the photo and the numbers under it. */
        const draw = () => {
            const where = pointOf(current.yaw, current.pitch);
            const m = mark();
            if (m) {
                m.style.left = `${where.fx * 100}%`;
                m.style.top = `${where.fy * 100}%`;
            }
            // Drawn from inside the modal's promise chain; the string load is independent of it.
            // eslint-disable-next-line promise/no-nesting
            getString('panoramapickervalue', 'mod_vrroom', {yaw: current.yaw, pitch: current.pitch}).then((text) => {
                const s = status();
                if (s) {
                    s.textContent = text;
                }
                return null;
            }).catch(Notification.exception);
        };

        /** Write the chosen direction into the form. */
        const accept = () => {
            yawinput.value = String(current.yaw);
            pitchinput.value = String(current.pitch);
            [yawinput, pitchinput].forEach((input) => {
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
            });
        };

        root.on(ModalEvents.save, accept);
        root.on(ModalEvents.shown, () => {
            draw();
            const a = area();
            if (a) {
                a.focus();
            }
        });
        root.on('click', `#${id}`, (e) => {
            const a = area();
            const image = a ? a.querySelector('img') : null;
            if (!image) {
                return;
            }
            const rect = image.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) {
                return;
            }
            const fx = Math.min(Math.max((e.clientX - rect.left) / rect.width, 0), 1);
            const fy = Math.min(Math.max((e.clientY - rect.top) / rect.height, 0), 1);
            current = directionAt(fx, fy);
            draw();
        });
        root.on('keydown', `#${id}`, (e) => {
            const step = e.shiftKey ? 5 : 1;
            let handled = true;
            switch (e.key) {
                case 'ArrowLeft':
                    current = normalise(current.yaw + step, current.pitch);
                    break;
                case 'ArrowRight':
                    current = normalise(current.yaw - step, current.pitch);
                    break;
                case 'ArrowUp':
                    current = normalise(current.yaw, current.pitch + step);
                    break;
                case 'ArrowDown':
                    current = normalise(current.yaw, current.pitch - step);
                    break;
                case 'Enter':
                    accept();
                    modal.destroy();
                    break;
                default:
                    handled = false;
            }
            if (handled) {
                e.preventDefault();
                if (e.key !== 'Enter') {
                    draw();
                }
            }
        });
        draw();
        return null;
    });
};

/**
 * Wire a "Pick on the photo" button to a yaw and a pitch field.
 *
 * @param {Object} options
 * @param {string} options.inputyaw Id of the yaw field.
 * @param {string} options.inputpitch Id of the pitch field.
 * @param {string} [options.imageurl] URL of the photo.
 * @param {Object} [options.imagefromdraft] {draftitemid}: the photo is the first image of this draft
 *     area; draftitemid is a number or the id (or name) of the field holding it.
 * @param {string} options.button Id of the button.
 */
export const init = ({inputyaw, inputpitch, imageurl, imagefromdraft, button}) => {
    const trigger = document.getElementById(button);
    const yawinput = document.getElementById(inputyaw);
    const pitchinput = document.getElementById(inputpitch);
    if (!trigger || !yawinput || !pitchinput || trigger.dataset.vrroomPicker) {
        return;
    }
    trigger.dataset.vrroomPicker = '1';
    trigger.addEventListener('click', (e) => {
        e.preventDefault();
        const pending = new Pending('mod_vrroom/panorama_picker:open');
        const source = imageurl ? Promise.resolve(String(imageurl)) : draftImageUrl(draftItemid(imagefromdraft));
        source.then((url) => {
            if (!url) {
                // eslint-disable-next-line promise/no-nesting
                return getString('panoramapickernoimage', 'mod_vrroom').then((text) => Notification.alert(
                    getString('panoramapickertitle', 'mod_vrroom'), text));
            }
            return openPicker(yawinput, pitchinput, url, trigger);
        }).catch(Notification.exception).finally(() => pending.resolve());
    });
};
