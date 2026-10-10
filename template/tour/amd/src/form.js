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
 * "Pick on the photo" buttons of the Virtual tour form.
 *
 * Each stop row has a photo select, yaw and pitch fields and a button. The photo a stop is on can
 * change while the form is open, so the button looks up the chosen photo row's file manager draft
 * area when it is pressed, then opens mod_vrroom's direction picker on that draft. The picker is
 * given its own hidden trigger per (stop, draft) pair, as its init() binds to one button and one
 * image.
 *
 * @module     vrroomtemplate_tour/form
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {init as initPicker} from 'mod_vrroom/panorama_picker';

/** @type {RegExp} Id of a stop row's "Pick on the photo" button. */
const BUTTON_ID = /^id_tour_pick_(\d+)$/;

/** @type {Set<string>} Picker triggers already made, keyed "stop:draftitemid". */
const triggers = new Set();

/** @type {boolean} Whether the click handler is installed. */
let installed = false;

/**
 * A form field of a repeated row, by its posted name.
 *
 * @param {HTMLElement} scope Element to search in (the form).
 * @param {string} name Field name, e.g. "tour_yaw".
 * @param {number|string} index Row index.
 * @returns {HTMLElement|null}
 */
const field = (scope, name, index) => scope.querySelector(`[name="${name}[${index}]"]`);

/**
 * Open the direction picker for one stop row.
 *
 * @param {HTMLElement} button The row's "Pick on the photo" button.
 * @param {number} index Stop row index.
 */
const pick = async(button, index) => {
    const form = button.closest('form') || document;
    const select = field(form, 'tour_stopphoto', index);
    const yaw = field(form, 'tour_yaw', index);
    const pitch = field(form, 'tour_pitch', index);
    if (!select || !yaw || !pitch || !yaw.id || !pitch.id) {
        return;
    }
    const draft = field(form, 'tour_photofile', select.value);
    const draftitemid = draft ? parseInt(draft.value, 10) : 0;
    if (!draftitemid) {
        return;
    }

    const key = `${index}:${draftitemid}`;
    const triggerid = `vrroomtemplate-tour-pick-${index}-${draftitemid}`;
    if (!triggers.has(key)) {
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.id = triggerid;
        trigger.hidden = true;
        trigger.tabIndex = -1;
        button.after(trigger);
        triggers.add(key);
        await Promise.resolve(initPicker({
            inputyaw: yaw.id,
            inputpitch: pitch.id,
            imagefromdraft: {draftitemid: draftitemid},
            button: triggerid,
        }));
    }
    document.getElementById(triggerid)?.click();
};

/**
 * Install the click handler for every "Pick on the photo" button (rows added later included).
 */
export const init = () => {
    if (installed) {
        return;
    }
    installed = true;
    document.addEventListener('click', (e) => {
        const button = e.target instanceof Element ? e.target.closest('[id^="id_tour_pick_"]') : null;
        const match = button ? BUTTON_ID.exec(button.id) : null;
        if (!match) {
            return;
        }
        e.preventDefault();
        pick(button, parseInt(match[1], 10)).catch(Notification.exception);
    });
};
