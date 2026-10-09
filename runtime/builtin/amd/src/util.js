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
 * Small DOM and text helpers shared by the built-in viewer modules.
 *
 * @module     vrroomruntime_builtin/util
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Badge classes for objective states (BS5). */
export const BADGES = {
    // Dark text: in the dark colour mode the secondary background stays light.
    locked: 'bg-secondary text-dark',
    available: 'text-bg-info',
    completed: 'bg-success',
};

/**
 * Find a region inside the VR room root.
 *
 * @param {HTMLElement} root The VR room root element.
 * @param {string} name The data-region value.
 * @returns {HTMLElement|null}
 */
export const region = (root, name) => root.querySelector(`[data-region="${name}"]`);

/**
 * Turn a server string that may hold entities or tags (format_string output) into plain text.
 *
 * The result must only ever be placed with textContent or an escaping Mustache tag.
 * DOMParser documents are inert: no script runs and no resource loads.
 *
 * @param {*} value The value.
 * @returns {string}
 */
export const plainText = (value) => {
    if (value === null || value === undefined) {
        return '';
    }
    const str = String(value);
    if (!/[<&]/.test(str)) {
        return str;
    }
    const doc = new DOMParser().parseFromString(`<!DOCTYPE html><body>${str}`, 'text/html');
    return (doc.body.textContent || '').trim();
};

/**
 * Announce a message to screen readers through the live region.
 *
 * @param {HTMLElement} root The VR room root element.
 * @param {string} text Plain text.
 */
export const announce = (root, text) => {
    const live = region(root, 'vrroom-live');
    if (!live || !text) {
        return;
    }
    live.textContent = '';
    window.setTimeout(() => {
        live.textContent = text;
    }, 100);
};

/**
 * Show a message in the message region.
 *
 * @param {HTMLElement} root The VR room root element.
 * @param {string} text Plain text.
 * @param {string} level 'warning', 'info' or 'success'.
 */
export const showMessage = (root, text, level = 'warning') => {
    const message = region(root, 'vrroom-message');
    if (!message) {
        return;
    }
    message.classList.remove('alert-warning', 'alert-info', 'alert-success', 'd-none');
    message.classList.add(`alert-${level}`);
    message.textContent = text;
    message.dataset.vrroomMessage = text;
};

/**
 * Show a message with a link after it in the message region (for example back to the activity page).
 *
 * @param {HTMLElement} root The VR room root element.
 * @param {string} text Plain text.
 * @param {string} linktext Plain text of the link.
 * @param {string} url The link's URL.
 * @param {string} level 'warning', 'info' or 'success'.
 */
export const showMessageWithLink = (root, text, linktext, url, level = 'warning') => {
    const message = region(root, 'vrroom-message');
    if (!message) {
        return;
    }
    showMessage(root, text, level);
    if (!url || !linktext) {
        return;
    }
    const link = document.createElement('a');
    link.href = url;
    link.className = 'alert-link ms-1';
    link.textContent = linktext;
    message.append(' ', link);
};

/**
 * Error codes of web service calls that mean the attempt can no longer be worked on in this page:
 * finished (in another tab, or its time ran out), deleted by a teacher, or the activity is hidden,
 * restricted or the session has expired.
 */
export const CLOSED_ERRORS = [
    'errorattemptclosed',
    'errorattemptmissing',
    'errorattempttimeup',
    'invalidrecord',
    'requireloginerror',
    'servicerequireslogin',
];

/**
 * Whether a web service error means the attempt is closed for this page (see CLOSED_ERRORS).
 *
 * @param {*} error The rejection value of a core/ajax call.
 * @returns {boolean}
 */
export const isClosedError = (error) => !!(error && typeof error === 'object' && CLOSED_ERRORS.indexOf(error.errorcode) >= 0);

/**
 * Hide the message region, optionally only when it still shows a given text.
 *
 * @param {HTMLElement} root The VR room root element.
 * @param {string|null} onlyText Clear only when this text is shown.
 */
export const clearMessage = (root, onlyText = null) => {
    const message = region(root, 'vrroom-message');
    if (!message) {
        return;
    }
    if (onlyText !== null && message.dataset.vrroomMessage !== onlyText) {
        return;
    }
    message.classList.add('d-none');
    message.textContent = '';
    delete message.dataset.vrroomMessage;
};

/**
 * Format a number of points for display (at most two decimals).
 *
 * @param {number} value The value.
 * @returns {string}
 */
export const formatPoints = (value) => {
    const n = Number(value) || 0;
    return String(Math.round(n * 100) / 100);
};

/**
 * The scene the client starts in.
 *
 * @param {Object} config Launch config.
 * @returns {Object|null}
 */
export const startScene = (config) => {
    const scenes = Array.isArray(config.scenes) ? config.scenes : [];
    return scenes.find((s) => s.id === config.startscene) || scenes[0] || null;
};
