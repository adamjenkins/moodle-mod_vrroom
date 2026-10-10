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
 * Entry point of the built-in viewer for other plugins (for example a virtual tour submission):
 * shows a set of rooms from a ready launch config, with no attempt, no tasks and no live room.
 *
 * The config comes from \mod_vrroom\local\launch\scene_formatter::standalone_config() and is run as
 * a preview session, so nothing is recorded or sent. The shell is rendered without its launch
 * buttons, progress panel and live room panel; the 3D room opens at once, or the list view when
 * the browser cannot show 3D (or the room fails to load). The same root.vrroomTest hooks as on the
 * activity page are there for Behat.
 *
 * @module     vrroomruntime_builtin/standalone
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import {createSession} from 'vrroomruntime_builtin/session';
import * as Viewer from 'vrroomruntime_builtin/viewer';
import * as ListView from 'vrroomruntime_builtin/listview';
import {region, showMessage, clearMessage, startScene} from 'vrroomruntime_builtin/util';

/** Strings this module shows, all from vrroomruntime_builtin; the caller may replace any of them. */
const STRINGKEYS = ['webglunavailable', 'loadfailed', 'standalonenoscenes'];

/**
 * Drives one standalone viewer root.
 */
class StandaloneViewer {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root The viewer root.
     * @param {Object} config Launch config (preview).
     * @param {Object} strings Strings by key.
     */
    constructor(root, config, strings) {
        this.root = root;
        this.config = config;
        this.strings = strings;
        this.session = createSession(config);
        this.view = null;
        this.mode = null;
        this.busy = false;
        this.webgl = Viewer.isWebGLAvailable();
        this.onClick = (e) => {
            const button = e.target.closest('[data-action="vrroom-switch-list"]');
            if (button && this.root.contains(button)) {
                e.preventDefault();
                this.switchTo('list');
            }
        };
        root.addEventListener('click', this.onClick);
    }

    /**
     * Open the first view: the 3D room, or the list view without WebGL.
     *
     * @returns {Promise}
     */
    begin() {
        if (!startScene(this.config)) {
            showMessage(this.root, this.strings.standalonenoscenes, 'info');
            return Promise.resolve();
        }
        return this.start3d();
    }

    /**
     * Open the 3D room, or the list view when WebGL is missing or the room fails to load.
     *
     * @returns {Promise}
     */
    start3d() {
        if (!this.webgl) {
            showMessage(this.root, this.strings.webglunavailable, 'warning');
            return this.startList();
        }
        this.mode = '3d';
        const shell = this.root.querySelector('[data-region="vrroom-shell"]');
        shell.classList.add('vrroom-mode-3d');
        return Viewer.start(this.root, this.session, this.config, {}).then((handle) => {
            this.view = handle;
            return undefined;
        }).catch((error) => {
            Log.debug(error);
            this.stopView();
            showMessage(this.root, this.strings.loadfailed, 'warning');
            this.webgl = false;
            return this.startList();
        });
    }

    /**
     * Open the list view.
     *
     * @returns {Promise}
     */
    startList() {
        this.mode = 'list';
        const shell = this.root.querySelector('[data-region="vrroom-shell"]');
        shell.classList.remove('vrroom-mode-3d');
        const listconfig = Object.assign({}, this.config, {
            standalone: true,
            canswitch3d: this.webgl,
            onFinish: null,
            onSwitch3d: () => this.switchTo('3d'),
        });
        return ListView.start(this.root, this.session, listconfig).then((handle) => {
            this.view = handle;
            return undefined;
        });
    }

    /**
     * Stop the current view.
     */
    stopView() {
        if (this.view) {
            this.view.stop();
            this.view = null;
        }
        this.mode = null;
    }

    /**
     * Switch between the 3D room and the list view (same session).
     *
     * @param {string} mode '3d' or 'list'.
     * @returns {Promise}
     */
    switchTo(mode) {
        if (this.mode === mode || this.busy) {
            return Promise.resolve();
        }
        this.busy = true;
        const pending = new Pending('vrroomruntime_builtin/standalone:switch');
        this.stopView();
        if (mode === '3d') {
            clearMessage(this.root, this.strings.webglunavailable);
            clearMessage(this.root, this.strings.loadfailed);
        }
        const promise = mode === '3d' ? this.start3d() : this.startList();
        return promise.catch(Notification.exception).then(() => {
            this.busy = false;
            pending.resolve();
            return undefined;
        });
    }
}

/**
 * Show rooms from a launch config in a root element of another plugin's page.
 *
 * @param {Object} args The arguments.
 * @param {string} args.rootid Id of the root element.
 * @param {Object} args.config Launch config from scene_formatter::standalone_config().
 * @param {Object} [args.strings] {key: text} to replace any of this module's messages
 *     (webglunavailable, loadfailed, standalonenoscenes).
 * @returns {Promise}
 */
export const init = ({rootid, config, strings}) => {
    const root = document.getElementById(rootid);
    if (!root || root.dataset.vrroomInit) {
        return Promise.resolve();
    }
    root.dataset.vrroomInit = '1';
    const pending = new Pending('vrroomruntime_builtin/standalone:init');
    // Always a preview: nothing is recorded and nothing is sent.
    const launch = Object.assign({}, config || {}, {preview: true, standalone: true});
    launch.cmid = Number(launch.cmid) || 0;
    if (!launch.live || typeof launch.live !== 'object') {
        launch.live = {available: false};
    }
    let loaded = null;
    return getStrings(STRINGKEYS.map((key) => ({key: key, component: 'vrroomruntime_builtin'}))).then((values) => {
        loaded = {};
        STRINGKEYS.forEach((key, index) => {
            const given = strings && typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : null;
            loaded[key] = given || values[index];
        });
        return Templates.renderForPromise('vrroomruntime_builtin/shell', {
            cmid: launch.cmid,
            standalone: true,
            canstart: false,
            canpreview: false,
            nolaunch: false,
        });
    }).then(({html, js}) => {
        Templates.replaceNodeContents(root, html, js);
        const viewer = new StandaloneViewer(root, launch, loaded);
        // The message region of the shell is under the root.
        if (!region(root, 'vrroom-message')) {
            throw new Error('The viewer shell did not render.');
        }
        return viewer.begin();
    }).catch(Notification.exception).then(() => {
        pending.resolve();
        return undefined;
    });
};
