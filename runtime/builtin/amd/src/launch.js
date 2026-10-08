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
 * Entry point of the built-in viewer (booted by mod/mavr/view.php): renders the shell, starts
 * an attempt or a preview, and switches between the 3D room and the list view.
 *
 * @module     mavrruntime_builtin/launch
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import {createSession} from 'mavrruntime_builtin/session';
import * as Viewer from 'mavrruntime_builtin/viewer';
import * as ListView from 'mavrruntime_builtin/listview';
import {createProgress} from 'mavrruntime_builtin/panels';
import {region, announce, showMessage, clearMessage} from 'mavrruntime_builtin/util';

/**
 * Call one web service and return a native promise.
 *
 * @param {string} methodname Function name.
 * @param {Object} args Arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Promise.resolve(Ajax.call([{methodname: methodname, args: args}])[0]);

/**
 * Drives one MAVR root element.
 */
class Controller {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root The MAVR root.
     * @param {Object} boot Boot data from view.php.
     * @param {Object} strings Strings used here.
     */
    constructor(root, boot, strings) {
        this.root = root;
        this.boot = boot;
        this.strings = strings;
        this.session = null;
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
            case 'mavr-enter-3d':
                e.preventDefault();
                this.launch('3d', false);
                break;
            case 'mavr-enter-list':
                e.preventDefault();
                this.launch('list', false);
                break;
            case 'mavr-preview-3d':
                e.preventDefault();
                this.launch('3d', true);
                break;
            case 'mavr-preview-list':
                e.preventDefault();
                this.launch('list', true);
                break;
            case 'mavr-switch-list':
                e.preventDefault();
                this.switchTo('list');
                break;
            case 'mavr-toggle-progress':
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
        region(this.root, 'mavr-launchbar').querySelectorAll('button').forEach((b) => {
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
        const pending = new Pending('mavrruntime_builtin/launch:launch');
        const request = preview
            ? call('mod_mavr_get_preview', {cmid: this.boot.cmid})
            : call('mod_mavr_start_attempt', {cmid: this.boot.cmid});
        return request.then((result) => {
            const descriptor = JSON.parse(result.launchjson);
            if (descriptor.type === 'external' && descriptor.url) {
                window.location.href = descriptor.url;
                return undefined;
            }
            this.config = descriptor.config || descriptor;
            this.session = createSession(this.config);
            this.session.on((message) => this.onSessionMessage(message));
            region(this.root, 'mavr-launchbar').classList.add('d-none');
            if (this.config.preview) {
                showMessage(this.root, this.strings.previewnotice, 'info');
            } else {
                clearMessage(this.root);
            }
            return mode === '3d' ? this.start3d() : this.startList();
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
        this.root.querySelector('[data-region="mavr-shell"]').classList.add('mavr-mode-3d');
        return Viewer.start(this.root, this.session, this.config).then((handle) => {
            this.view = handle;
            const aside = region(this.root, 'mavr-progress');
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
        this.root.querySelector('[data-region="mavr-shell"]').classList.remove('mavr-mode-3d');
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
        const aside = region(this.root, 'mavr-progress');
        aside.classList.add('d-none');
        const toggle = this.root.querySelector('[data-action="mavr-toggle-progress"]');
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
        const pending = new Pending('mavrruntime_builtin/launch:switch');
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
        const aside = region(this.root, 'mavr-progress');
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
        return this.session.finish().then((result) => {
            window.location.href = (result && result.redirecturl) || this.config.finishurl;
            return undefined;
        }).catch((error) => {
            // The attempt is still open: bring the view back, then report the error.
            const restart = mode === '3d' ? this.start3d() : this.startList();
            return restart.then(() => {
                throw error;
            });
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
    if (!root || root.dataset.mavrInit) {
        return Promise.resolve();
    }
    root.dataset.mavrInit = '1';
    const pending = new Pending('mavrruntime_builtin/launch:init');
    const canstart = !!(boot.canattempt && boot.canstart);
    const canpreview = !!boot.canpreview;
    return getStrings([
        {key: 'previewnotice', component: 'mavrruntime_builtin'},
        {key: 'webglunavailable', component: 'mavrruntime_builtin'},
        {key: 'loadfailed', component: 'mavrruntime_builtin'},
        {key: 'allrequireddone', component: 'mavrruntime_builtin'},
        {key: 'statementsfailed', component: 'mavrruntime_builtin'},
    ]).then(([previewnotice, webglunavailable, loadfailed, allrequireddone, statementsfailed]) => {
        const strings = {previewnotice, webglunavailable, loadfailed, allrequireddone, statementsfailed};
        return Templates.renderForPromise('mavrruntime_builtin/shell', {
            cmid: boot.cmid,
            canstart: canstart,
            canpreview: canpreview,
            nolaunch: !canstart && !canpreview,
        }).then(({html, js}) => {
            Templates.replaceNodeContents(root, html, js);
            return new Controller(root, boot, strings);
        });
    }).catch(Notification.exception).then(() => {
        pending.resolve();
        return undefined;
    });
};
