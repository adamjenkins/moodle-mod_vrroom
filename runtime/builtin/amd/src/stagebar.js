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
 * The bar under the 3D room: a fullscreen toggle, the reversed drag directions and the vertical lock.
 *
 * Fullscreen covers the whole viewport (the room and this bar), so the HUD buttons, panels and
 * the bar stay usable. The live room panel sits above the viewport on the page; while the viewport
 * is fullscreen it is moved in under the bar (a strip of at most a third of the screen that
 * scrolls), so leaving, the microphone, the camera and the people in the room stay in reach, and put back
 * when fullscreen ends. The drag settings are the user preferences mod_vrroom_invertx,
 * mod_vrroom_inverty and mod_vrroom_locky, so they apply in every VR room activity.
 *
 * @module     vrroomruntime_builtin/stagebar
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {getStrings} from 'core/str';
import {setUserPreference} from 'core_user/repository';
import {region} from 'vrroomruntime_builtin/util';

/** @var {string} Preference that reverses left/right dragging. */
const PREFX = 'mod_vrroom_invertx';
/** @var {string} Preference that reverses up/down dragging. */
const PREFY = 'mod_vrroom_inverty';
/** @var {string} Preference that stops up/down dragging. */
const PREFLOCK = 'mod_vrroom_locky';
/** @var {string[]} Bootstrap classes of the live room panel while it is inside the fullscreen viewport. */
const LIVE_FULLSCREEN_CLASSES = ['bg-body', 'text-body', 'overflow-auto', 'flex-shrink-0', 'rounded-0', 'm-0'];
/** @var {string} Most height of the live room panel inside the fullscreen viewport. */
const LIVE_FULLSCREEN_HEIGHT = '33vh';

/**
 * The element shown fullscreen right now, with the WebKit fallback.
 *
 * @returns {Element|null}
 */
const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;

/**
 * Whether this browser lets a page element go fullscreen.
 *
 * @param {HTMLElement} element The element.
 * @returns {boolean}
 */
const canFullscreen = (element) => !!(document.fullscreenEnabled || document.webkitFullscreenEnabled)
    && !!(element.requestFullscreen || element.webkitRequestFullscreen);

/**
 * Enter fullscreen on an element.
 *
 * @param {HTMLElement} element The element.
 * @returns {Promise}
 */
const enterFullscreen = (element) => Promise.resolve(
    element.requestFullscreen ? element.requestFullscreen() : element.webkitRequestFullscreen()
);

/**
 * Leave fullscreen.
 *
 * @returns {Promise}
 */
const exitFullscreen = () => Promise.resolve(
    document.exitFullscreen ? document.exitFullscreen() : document.webkitExitFullscreen()
);

/**
 * Wire the bar under the room.
 *
 * @param {HTMLElement} root The viewer root.
 * @param {Object} controls The controls from vrroomruntime_builtin/controls (setInvert()).
 * @param {Object} look {invertx, inverty, locky} from the launch config (the user's preferences).
 * @returns {Object} {dispose()}
 */
export const createStagebar = (root, controls, look) => {
    const viewport = region(root, 'vrroom-viewport');
    const bar = region(root, 'vrroom-stagebar');
    if (!viewport || !bar) {
        return {dispose: () => null};
    }
    const button = bar.querySelector('[data-action="vrroom-fullscreen"]');
    const boxx = bar.querySelector('[data-action="vrroom-invertx"]');
    const boxy = bar.querySelector('[data-action="vrroom-inverty"]');
    const boxlock = bar.querySelector('[data-action="vrroom-locky"]');
    const labels = {enter: button ? button.textContent : '', exit: ''};
    const liveroom = region(root, 'vrroom-liveroom');
    // Where the live room panel goes back to after fullscreen.
    let liveplace = null;

    // Reversed and locked dragging: start from the stored preferences.
    const invert = {x: !!(look && look.invertx), y: !!(look && look.inverty)};
    let locky = !!(look && look.locky);
    controls.setInvert(invert.x, invert.y);
    controls.setLockY(locky);
    // Reversing up/down means nothing while up/down is locked.
    const showLock = () => {
        if (boxy) {
            boxy.disabled = locky;
        }
    };
    if (boxx) {
        boxx.checked = invert.x;
    }
    if (boxy) {
        boxy.checked = invert.y;
    }
    if (boxlock) {
        boxlock.checked = locky;
    }
    showLock();
    const save = (name, on) => {
        Promise.resolve(setUserPreference(name, on ? 1 : 0)).catch((error) => Log.debug(error));
    };
    const onChange = (e) => {
        if (e.target === boxlock) {
            locky = e.target.checked;
            controls.setLockY(locky);
            showLock();
            save(PREFLOCK, locky);
            return;
        }
        if (e.target !== boxx && e.target !== boxy) {
            return;
        }
        const axisx = e.target === boxx;
        invert[axisx ? 'x' : 'y'] = e.target.checked;
        controls.setInvert(invert.x, invert.y);
        save(axisx ? PREFX : PREFY, e.target.checked);
    };
    bar.addEventListener('change', onChange);

    // The live room panel: inside the viewport while it is fullscreen, in its place otherwise.
    const placeLive = (inside) => {
        if (!liveroom) {
            return;
        }
        if (inside && !liveplace && liveroom.parentNode) {
            liveplace = document.createComment('vrroom-liveroom');
            liveroom.parentNode.insertBefore(liveplace, liveroom);
            // After the bar: the tab order runs room, bar, live panel.
            viewport.appendChild(liveroom);
            liveroom.classList.add(...LIVE_FULLSCREEN_CLASSES);
            liveroom.style.maxHeight = LIVE_FULLSCREEN_HEIGHT;
        } else if (!inside && liveplace) {
            // Moving an element drops the focus inside it: give it back.
            const active = document.activeElement;
            const refocus = active && liveroom.contains(active) ? active : null;
            if (liveplace.parentNode) {
                liveplace.parentNode.insertBefore(liveroom, liveplace);
                liveplace.parentNode.removeChild(liveplace);
            }
            liveplace = null;
            liveroom.classList.remove(...LIVE_FULLSCREEN_CLASSES);
            liveroom.style.maxHeight = '';
            if (refocus && typeof refocus.focus === 'function') {
                refocus.focus();
            }
        }
    };

    // Fullscreen.
    const showLabel = () => {
        const on = fullscreenElement() === viewport;
        placeLive(on);
        if (!button) {
            return;
        }
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.textContent = on && labels.exit ? labels.exit : labels.enter;
    };
    const onClick = (e) => {
        if (!button || e.target.closest('[data-action="vrroom-fullscreen"]') !== button) {
            return;
        }
        e.preventDefault();
        const action = fullscreenElement() === viewport ? exitFullscreen() : enterFullscreen(viewport);
        action.catch((error) => Log.debug(error));
    };
    if (button && canFullscreen(viewport)) {
        button.classList.remove('d-none');
        bar.addEventListener('click', onClick);
        document.addEventListener('fullscreenchange', showLabel);
        document.addEventListener('webkitfullscreenchange', showLabel);
        getStrings([
            {key: 'fullscreen', component: 'vrroomruntime_builtin'},
            {key: 'exitfullscreen', component: 'vrroomruntime_builtin'},
        ]).then(([enter, exit]) => {
            labels.enter = enter;
            labels.exit = exit;
            showLabel();
            return undefined;
        }).catch((error) => Log.debug(error));
    }

    return {
        /** Remove the listeners and leave fullscreen if the room is shown that way. */
        dispose() {
            bar.removeEventListener('change', onChange);
            bar.removeEventListener('click', onClick);
            document.removeEventListener('fullscreenchange', showLabel);
            document.removeEventListener('webkitfullscreenchange', showLabel);
            if (fullscreenElement() === viewport) {
                exitFullscreen().catch((error) => Log.debug(error));
            }
            placeLive(false);
            if (button) {
                button.classList.add('d-none');
            }
        },
    };
};
