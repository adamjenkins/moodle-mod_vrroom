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
 * Reload the activity form with a template's fields as soon as another template is chosen.
 *
 * The form's no-submit button does the reload (nothing is saved); with JavaScript the button is
 * pressed on change and hidden, without it the teacher presses it.
 *
 * @module     mod_vrroom/template_select
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wire the template select to its reload button.
 *
 * @param {string} selectid Id of the template select.
 * @param {string} buttonid Id of the no-submit reload button.
 */
export const init = (selectid, buttonid) => {
    const select = document.getElementById(selectid);
    const button = document.getElementById(buttonid);
    if (!select || !button) {
        return;
    }
    button.classList.add('d-none');
    select.addEventListener('change', () => {
        button.click();
    });
};
