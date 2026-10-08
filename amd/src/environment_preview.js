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
 * Swap the room preview under an environment selector when the choice changes (before saving).
 *
 * Everything shown comes from the figure's data-previews map, written by the server
 * (\mod_vrroom\form\environment_preview); texts are set with textContent only.
 *
 * @module     mod_vrroom/environment_preview
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Set a caption line's text, hiding it when empty.
 *
 * @param {HTMLElement|null} node the line
 * @param {string} text plain text
 */
const setLine = (node, text) => {
    if (!node) {
        return;
    }
    node.textContent = text || '';
    node.classList.toggle('d-none', !text);
};

/**
 * Start the preview of one environment selector.
 *
 * @param {string} selectid id of the select element
 * @param {string} regionid id of the preview figure
 */
export const init = (selectid, regionid) => {
    const select = document.getElementById(selectid);
    const region = document.getElementById(regionid);
    if (!select || !region) {
        return;
    }
    let map = {};
    try {
        map = JSON.parse(region.dataset.previews || '{}') || {};
    } catch (e) {
        map = {};
    }
    const img = region.querySelector('[data-region="image"]');
    const placeholder = region.querySelector('[data-region="placeholder"]');
    const placeholdertext = region.querySelector('[data-region="placeholdertext"]');
    const name = region.querySelector('[data-region="name"]');
    const credit = region.querySelector('[data-region="credit"]');
    const places = region.querySelector('[data-region="places"]');

    const showPlaceholder = (text) => {
        if (placeholdertext) {
            placeholdertext.textContent = text || region.dataset.none || '';
        }
        if (img) {
            img.classList.add('d-none');
            img.removeAttribute('src');
        }
        if (placeholder) {
            placeholder.classList.remove('d-none');
        }
    };

    const show = () => {
        const entry = Object.prototype.hasOwnProperty.call(map, select.value) ? map[select.value] : null;
        if (img) {
            // Even a hidden image keeps the chosen room's name as its alt text.
            img.alt = (entry && entry.name) || '';
        }
        if (entry && entry.url && img) {
            if (img.getAttribute('src') !== entry.url) {
                img.src = entry.url;
            }
            img.classList.remove('d-none');
            if (placeholder) {
                placeholder.classList.add('d-none');
            }
        } else {
            showPlaceholder(entry && entry.placeholder);
        }
        setLine(name, entry ? entry.name : '');
        setLine(credit, entry ? entry.credit : '');
        setLine(places, entry ? entry.places : '');
    };

    if (img) {
        img.addEventListener('error', () => {
            if (img.getAttribute('src')) {
                showPlaceholder(region.dataset.none);
            }
        });
    }
    select.addEventListener('change', show);
    show();
    // An image that already failed before this module started never fires its error event again.
    if (img && img.getAttribute('src') && img.complete && img.naturalWidth === 0) {
        showPlaceholder(region.dataset.none);
    }
};
