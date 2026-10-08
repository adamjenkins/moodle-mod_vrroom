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
 * Entry point of the built-in viewer (booted by mod/vrroom/view.php): renders the shell, starts
 * an attempt or a preview, and switches between the 3D room and the list view.
 *
 * @module     vrroomruntime_builtin/launch
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import {createSession} from 'vrroomruntime_builtin/session';
import * as Viewer from 'vrroomruntime_builtin/viewer';
import * as ListView from 'vrroomruntime_builtin/listview';
import {createProgress} from 'vrroomruntime_builtin/panels';
import {createLiveController} from 'vrroomruntime_builtin/live/controller';
import {region, announce, showMessage, clearMessage} from 'vrroomruntime_builtin/util';

/**
 * Call one web service and return a native promise.
 *
 * @param {string} methodname Function name.
 * @param {Object} args Arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Promise.resolve(Ajax.call([{methodname: methodname, args: args}])[0]);

/**
 * Drives one VR room root element.
 */
class Controller {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root The VR room root.
     * @param {Object} boot Boot data from view.php.
     * @param {Object} strings Strings used here.
     */
    constructor(root, boot, strings) {
        this.root = root;
        this.boot = boot;
        this.strings = strings;
        this.session = null;
        this.live = null;
        this.config = null;
        this.view = null;
        this.mode = null;
        this.progress = null;
        this.busy = false;
        this.announcedrequired = false;
        this.webgl = Viewer.isWebGLAvailable();
        root.addEventListener('click', (e) => this.onClick(e));
    }

    /**
     * Handle clicks on the shell's buttons.
     *
     * @param {Event} e Click event.
     */
    onClick(e) {
        const button = e.target.closest('[data-action]');
        if (!button || !this.root.contains(button)) {
            return;
        }
        switch (button.dataset.action) {
            case 'vrroom-enter-3d':
                e.preventDefault();
                this.launch('3d', false);
                break;
            case 'vrroom-enter-list':
                e.preventDefault();
                this.launch('list', false);
                break;
            case 'vrroom-preview-3d':
                e.preventDefault();
                this.launch('3d', true);
                break;
            case 'vrroom-preview-list':
                e.preventDefault();
                this.launch('list', true);
                break;
            case 'vrroom-switch-list':
                e.preventDefault();
                this.switchTo('list');
                break;
            case 'vrroom-toggle-progress':
                e.preventDefault();
                this.toggleProgress(button);
                break;
            default:
                break;
        }
    }

    /**
     * Enable or disable the launch buttons.
     *
     * @param {boolean} disabled Disabled.
     */
    setLaunchDisabled(disabled) {
        region(this.root, 'vrroom-launchbar').querySelectorAll('button').forEach((b) => {
            b.disabled = disabled;
        });
    }

    /**
     * Start (or resume) an attempt, or a preview, then open a view.
     *
     * @param {string} mode '3d' or 'list'.
     * @param {boolean} preview Preview instead of an attempt.
     * @returns {Promise}
     */
    launch(mode, preview) {
        if (this.busy || this.session) {
            return Promise.resolve();
        }
        this.busy = true;
        this.setLaunchDisabled(true);
        const pending = new Pending('vrroomruntime_builtin/launch:launch');
        const request = preview
            ? call('mod_vrroom_get_preview', {cmid: this.boot.cmid})
            : call('mod_vrroom_start_attempt', {cmid: this.boot.cmid});
        return request.then((result) => {
            const descriptor = JSON.parse(result.launchjson);
            if (descriptor.type === 'external' && descriptor.url) {
                window.location.href = descriptor.url;
                return undefined;
            }
            this.config = descriptor.config || descriptor;
            this.session = createSession(this.config);
            this.session.on((message) => this.onSessionMessage(message));
            this.live = createLiveController(this.root, this.config, this.session);
            region(this.root, 'vrroom-launchbar').classList.add('d-none');
            if (this.config.preview) {
                showMessage(this.root, this.strings.previewnotice, 'info');
            } else {
                clearMessage(this.root);
            }
            return this.showLiveAndView(mode);
        }).catch((error) => {
            this.setLaunchDisabled(false);
            Notification.exception(error);
        }).then(() => {
            this.busy = false;
            pending.resolve();
            return undefined;
        });
    }

    /**
     * Messages from the session: completion announcements and statement failures.
     *
     * @param {Object} message Session message.
     */
    onSessionMessage(message) {
        if (message.type === 'failure') {
            if (message.failing) {
                showMessage(this.root, this.strings.statementsfailed, 'warning');
            } else {
                clearMessage(this.root, this.strings.statementsfailed);
            }
            return;
        }
        if (message.type !== 'change' || this.announcedrequired) {
            return;
        }
        const state = message.state;
        const previous = message.previous;
        if ((state.allrequiredcompleted && !previous.allrequiredcompleted) || (state.finished && !previous.finished)) {
            this.announcedrequired = true;
            announce(this.root, this.strings.allrequireddone);
            showMessage(this.root, this.strings.allrequireddone, 'success');
        }
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
        this.root.querySelector('[data-region="vrroom-shell"]').classList.add('vrroom-mode-3d');
        return Viewer.start(this.root, this.session, this.config, {live: this.live}).then((handle) => {
            this.view = handle;
            const aside = region(this.root, 'vrroom-progress');
            return createProgress(aside, this.session, {onFinish: () => this.finish()});
        }).then((progress) => {
            this.progress = progress;
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
        if (this.live) {
            this.live.setLocalMode('list');
        }
        this.root.querySelector('[data-region="vrroom-shell"]').classList.remove('vrroom-mode-3d');
        const listconfig = Object.assign({}, this.config, {
            canswitch3d: this.webgl,
            onFinish: () => this.finish(),
            onSwitch3d: () => this.switchTo('3d'),
        });
        return ListView.start(this.root, this.session, listconfig).then((handle) => {
            this.view = handle;
            return undefined;
        });
    }

    /**
     * Stop the current view and its progress panel.
     */
    stopView() {
        if (this.progress) {
            this.progress.destroy();
            this.progress = null;
        }
        const aside = region(this.root, 'vrroom-progress');
        aside.classList.add('d-none');
        const toggle = this.root.querySelector('[data-action="vrroom-toggle-progress"]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
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
        if (!this.session || this.mode === mode || this.busy) {
            return Promise.resolve();
        }
        this.busy = true;
        const pending = new Pending('vrroomruntime_builtin/launch:switch');
        this.stopView();
        const promise = mode === '3d' ? this.start3d() : this.startList();
        return promise.catch(Notification.exception).then(() => {
            this.busy = false;
            pending.resolve();
            return undefined;
        });
    }

    /**
     * Show or hide the progress panel over the 3D room.
     *
     * @param {HTMLElement} button The toggle button.
     */
    toggleProgress(button) {
        const aside = region(this.root, 'vrroom-progress');
        const show = aside.classList.contains('d-none');
        aside.classList.toggle('d-none', !show);
        button.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show && this.progress) {
            this.progress.refresh();
        }
    }

    /**
     * Finish the attempt and go back to the activity page.
     *
     * @returns {Promise}
     */
    finish() {
        const mode = this.mode;
        this.stopView();
        // Leave the live room first; a failure to leave does not stop the finish.
        const leaving = this.live ? this.live.destroy() : Promise.resolve();
        return leaving.catch(() => undefined).then(() => {
            this.live = null;
            return this.session.finish();
        }).then((result) => {
            window.location.href = (result && result.redirecturl) || this.config.finishurl;
            return undefined;
        }).catch((error) => {
            // The attempt is still open: bring the live room and the view back, then report the error.
            if (!this.live) {
                this.live = createLiveController(this.root, this.config, this.session);
            }
            return this.restoreAfterFailure(mode, error);
        });
    }

    /**
     * Render the live room panel (if any), then start the view in the given mode.
     *
     * The live room panel is optional: a failure to render it is reported and the view still starts.
     *
     * @param {string} mode '3d' or 'list'
     * @returns {Promise}
     */
    showLiveAndView(mode) {
        const rendering = this.live ? this.live.render().catch(Notification.exception) : Promise.resolve();
        return rendering.then(() => (mode === '3d' ? this.start3d() : this.startList()));
    }

    /**
     * Bring the live room and the view back after a failed finish, then reject with the error.
     *
     * @param {string} mode '3d' or 'list'
     * @param {*} error The finish error.
     * @returns {Promise} always rejected with error
     */
    restoreAfterFailure(mode, error) {
        return this.showLiveAndView(mode).then(() => {
            throw error;
        });
    }
}

/**
 * Boot the built-in viewer on view.php.
 *
 * @param {Object} boot {cmid, rootid, canattempt, canpreview, hasinprogress, canstart, attemptsleft}
 * @returns {Promise}
 */
export const init = (boot) => {
    const root = document.getElementById(boot.rootid);
    if (!root || root.dataset.vrroomInit) {
        return Promise.resolve();
    }
    root.dataset.vrroomInit = '1';
    const pending = new Pending('vrroomruntime_builtin/launch:init');
    const canstart = !!(boot.canattempt && boot.canstart);
    const canpreview = !!boot.canpreview;
    let strings = null;
    return getStrings([
        {key: 'previewnotice', component: 'vrroomruntime_builtin'},
        {key: 'webglunavailable', component: 'vrroomruntime_builtin'},
        {key: 'loadfailed', component: 'vrroomruntime_builtin'},
        {key: 'allrequireddone', component: 'vrroomruntime_builtin'},
        {key: 'statementsfailed', component: 'vrroomruntime_builtin'},
    ]).then(([previewnotice, webglunavailable, loadfailed, allrequireddone, statementsfailed]) => {
        strings = {previewnotice, webglunavailable, loadfailed, allrequireddone, statementsfailed};
        return Templates.renderForPromise('vrroomruntime_builtin/shell', {
            cmid: boot.cmid,
            canstart: canstart,
            canpreview: canpreview,
            nolaunch: !canstart && !canpreview,
        });
    }).then(({html, js}) => {
        Templates.replaceNodeContents(root, html, js);
        return new Controller(root, boot, strings);
    }).catch(Notification.exception).then(() => {
        pending.resolve();
        return undefined;
    });
};
