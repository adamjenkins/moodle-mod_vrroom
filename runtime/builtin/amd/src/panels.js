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
 * Overlay panels of the 3D room (hotspot content and in-world questions) and the progress panel
 * with hints, the time left and the finish button.
 *
 * The hotspot panel follows the idea of mod_gear's hotspot popup (a Mustache-rendered card over
 * the scene, opened by clicking a hotspot, closed with a close button).
 *
 * @module     vrroomruntime_builtin/panels
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import {renderQuestion} from 'vrroomruntime_builtin/question';
import {BADGES, plainText, formatPoints, startScene, announce, showMessage} from 'vrroomruntime_builtin/util';

/** Counter for unique heading ids. */
let uidcounter = 0;

/** Seconds left at which the time left is announced to screen readers. */
const TIME_WARNINGS = [300, 60];

/** Per session: {lastleft, timeuphandled}, shared by every progress panel of the session. */
const timerstate = new WeakMap();

/**
 * Format seconds as m:ss, or h:mm:ss from one hour.
 *
 * @param {number} seconds Whole seconds.
 * @returns {string}
 */
export const formatTimeLeft = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const ss = String(s).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
};

/**
 * The shared timer state of a session.
 *
 * @param {Object} session The session.
 * @returns {Object} {lastleft, timeuphandled}
 */
const timerFor = (session) => {
    if (!timerstate.has(session)) {
        timerstate.set(session, {lastleft: Infinity, timeuphandled: false});
    }
    return timerstate.get(session);
};

/**
 * Create the overlay panels of a 3D stage.
 *
 * @param {HTMLElement} stageroot The element holding the vrroom-overlay region (the stage).
 * @param {Object} session The session.
 * @param {Object} options {onClose()} called after a panel closes (focus goes back to the canvas).
 * @returns {Object} {openInteractable(item), openQuestion(slot), close(), refresh()}
 */
export const createPanels = (stageroot, session, options = {}) => {
    const overlay = stageroot.querySelector('[data-region="vrroom-overlay"]');
    let current = null;

    const close = () => {
        if (!current) {
            return;
        }
        current = null;
        overlay.replaceChildren();
        if (options.onClose) {
            options.onClose();
        }
    };

    const questionHost = () => (current ? overlay.querySelector('[data-region="vrroom-question"]') : null);

    const show = (context, slot) => {
        const pending = new Pending('vrroomruntime_builtin/panels:open');
        uidcounter++;
        context.uid = `${session.config.cmid}-${uidcounter}`;
        return Templates.renderForPromise('vrroomruntime_builtin/panel', context)
            .then(({html, js}) => {
                Templates.replaceNodeContents(overlay, html, js);
                current = {id: context.id, slot: slot};
                const panel = overlay.querySelector('[data-region="vrroom-panel"]');
                panel.addEventListener('click', (e) => {
                    if (e.target.closest('[data-action="vrroom-close-panel"]')) {
                        e.preventDefault();
                        close();
                    }
                });
                panel.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        close();
                    }
                });
                const heading = panel.querySelector(`#vrroom-panel-title-${CSS.escape(context.uid)}`);
                if (heading) {
                    heading.focus({preventScroll: true});
                }
                const host = questionHost();
                if (host && slot !== null) {
                    return renderQuestion(host, session, slot);
                }
                return undefined;
            })
            .catch((error) => session.reportError(error))
            .then(() => pending.resolve());
    };

    /**
     * Open the panel of a hotspot (its content, media and question).
     *
     * @param {Object} item A hotspot interactable.
     * @returns {Promise}
     */
    const openInteractable = (item) => {
        const content = item.content || {};
        const slot = content.question === null || content.question === undefined ? null : Number(content.question);
        const title = plainText(content.title || item.name || '');
        const images = (Array.isArray(content.media) ? content.media : [])
            .filter((m) => m && m.url)
            .map((m) => ({url: m.url, alt: title}));
        return show({
            id: item.id,
            title: title,
            html: content.html || '',
            images: images,
            hasquestion: slot !== null,
            slot: slot,
        }, slot);
    };

    /**
     * Open a question by slot: the hotspot that carries it, or a bare question panel.
     *
     * @param {number|string} slot Slot number.
     * @returns {Promise}
     */
    const openQuestion = (slot) => {
        const n = Number(slot);
        if (current && current.slot === n) {
            const host = questionHost();
            return host ? renderQuestion(host, session, n) : Promise.resolve();
        }
        const scene = session.getScene(session.sceneid) || startScene(session.config);
        const items = scene && Array.isArray(scene.interactables) ? scene.interactables : [];
        const hotspot = items.find((i) => i.kind === 'hotspot' && i.content && Number(i.content.question) === n);
        if (hotspot) {
            return openInteractable(hotspot);
        }
        const objective = session.getObjectiveForSlot(n);
        return show({
            id: `slot-${n}`,
            title: objective ? objective.title : '',
            html: '',
            images: [],
            hasquestion: true,
            slot: n,
        }, n);
    };

    /**
     * Re-render a locked question that has become available.
     */
    const refresh = () => {
        const host = questionHost();
        if (!host || current.slot === null) {
            return;
        }
        if (host.dataset.vrroomQuestionState === 'locked' && session.isSlotAvailable(current.slot)) {
            renderQuestion(host, session, current.slot);
        }
    };

    return {
        openInteractable: openInteractable,
        openQuestion: openQuestion,
        close: close,
        refresh: refresh,
        /**
         * Whether a panel is open.
         *
         * @returns {boolean}
         */
        isOpen: () => !!current,
    };
};

/**
 * Template context of one objective, with its hints.
 *
 * @param {Object} o Objective of the session view.
 * @param {Object} session The session.
 * @param {Object} labels Status labels.
 * @returns {Promise<Object>}
 */
const objectiveContext = (o, session, labels) => {
    const context = {
        id: o.id,
        status: o.status,
        badgeclass: BADGES[o.status] || BADGES.locked,
        statuslabel: labels[o.status] || o.status,
        title: o.title,
        hashints: false,
        canhint: false,
        hintsusedtext: '',
        hintcosttext: '',
        hints: [],
    };
    const hints = session.getHints(o.id);
    const total = hints ? hints.total : 0;
    if (total <= 0 || o.status === 'locked') {
        return Promise.resolve(context);
    }
    const used = Math.max(o.hintsused || 0, hints.used.length);
    context.hashints = true;
    context.canhint = !session.preview && !session.closed && o.status === 'available' && used < total;
    context.hints = hints.used.map((h) => ({text: h.text}));
    const requests = [getString('hintsused', 'vrroomruntime_builtin', {used: used, total: total})];
    if (context.canhint && o.nexthintpenalty > 0) {
        requests.push(getString('hintcost', 'vrroomruntime_builtin', formatPoints(o.nexthintpenalty)));
    }
    return Promise.all(requests).then(([usedtext, costtext]) => {
        context.hintsusedtext = usedtext;
        context.hintcosttext = costtext || '';
        return context;
    });
};

/**
 * Create the progress panel (objectives, hints, points, time left, finish button) inside a host
 * element.
 *
 * Options: root (the element holding the vrroom-live and vrroom-message regions; default the shell
 * around the host); onFinish() → Promise that finishes the attempt and leaves the page.
 *
 * When the attempt's time runs out the panel says so and finishes the attempt (once per session,
 * whichever panel is showing).
 *
 * @param {HTMLElement} host Where the progress is rendered.
 * @param {Object} session The session.
 * @param {Object} options Options.
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
export const createProgress = (host, session, options = {}) => getStrings([
    {key: 'objectivestatus_locked', component: 'vrroomruntime_builtin'},
    {key: 'objectivestatus_available', component: 'vrroomruntime_builtin'},
    {key: 'objectivestatus_completed', component: 'vrroomruntime_builtin'},
    {key: 'finishconfirmtitle', component: 'vrroomruntime_builtin'},
    {key: 'finishconfirm', component: 'vrroomruntime_builtin'},
    {key: 'finishattempt', component: 'vrroomruntime_builtin'},
    {key: 'timeup', component: 'vrroomruntime_builtin'},
    {key: 'nomorehints', component: 'vrroomruntime_builtin'},
]).then((strings) => buildProgress(host, session, options, strings));

/**
 * Build the progress panel once its strings are loaded (see createProgress).
 *
 * @param {HTMLElement} host Where the progress is rendered.
 * @param {Object} session The session.
 * @param {Object} options Options.
 * @param {string[]} strings The strings createProgress loaded, in its order.
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
const buildProgress = (host, session, options, strings) => {
    const [locked, available, completed, confirmtitle, confirmtext, finishlabel, timeuptext, nomorehints] = strings;
    const labels = {locked: locked, available: available, completed: completed};
    const root = options.root || host.closest('[data-region="vrroom-shell"]') || host;
    const shared = timerFor(session);
    let destroyed = false;
    let sequence = 0;
    let ticker = null;

    /**
     * Show the time left in the timer region and announce the warnings.
     */
    const tick = () => {
        const timer = host.querySelector('[data-region="vrroom-timer"]');
        const left = session.getTimeLeft();
        if (!timer) {
            return;
        }
        if (left === null) {
            timer.hidden = true;
            return;
        }
        const formatted = formatTimeLeft(left);
        getString('timeleft', 'vrroomruntime_builtin', formatted).then((text) => {
            if (!destroyed) {
                timer.textContent = text;
                timer.hidden = false;
            }
            return undefined;
        }).catch(() => null);
        const crossed = TIME_WARNINGS.filter((t) => shared.lastleft > t && left <= t && left > 0);
        shared.lastleft = left;
        if (crossed.length > 0) {
            getString('timewarning', 'vrroomruntime_builtin', formatted)
                .then((text) => announce(root, text))
                .catch(() => null);
        }
    };

    const render = () => {
        sequence++;
        const mine = sequence;
        const state = session.getState();
        let scoretext = '';
        return getString('scoreprogress', 'vrroomruntime_builtin', {
            earned: formatPoints(state.earnedpoints),
            max: formatPoints(state.maxpoints),
        }).then((text) => {
            scoretext = text;
            return Promise.all(state.objectives
                .filter((o) => !state.hidden.has(o.id))
                .map((o) => objectiveContext(o, session, labels)));
        }).then((objectives) => Templates.renderForPromise('vrroomruntime_builtin/progress', {
            scoretext: scoretext,
            objectives: objectives,
            canfinish: !session.preview && !session.closed,
            preview: session.preview,
        })).then(({html, js}) => {
            if (!destroyed && mine === sequence) {
                Templates.replaceNodeContents(host, html, js);
                tick();
            }
            return undefined;
        }).catch(Notification.exception);
    };

    /**
     * The time is up: say so, finish the attempt and leave the room.
     */
    const onTimeUp = () => {
        if (shared.timeuphandled) {
            return;
        }
        shared.timeuphandled = true;
        showMessage(root, timeuptext, 'warning');
        announce(root, timeuptext);
        const finishurl = session.getConfig().finishurl;
        const pending = new Pending('vrroomruntime_builtin/panels:timeup');
        const finishing = options.onFinish
            ? Promise.resolve(options.onFinish()).then(() => null)
            : session.finish().then((result) => (result && result.redirecturl) || finishurl);
        finishing.catch(() => finishurl).then((url) => {
            pending.resolve();
            if (url) {
                window.location.href = url;
            }
            return undefined;
        }).catch(() => null);
    };

    /**
     * Ask for the next hint of an objective.
     *
     * @param {HTMLElement} button The hint button.
     */
    const getHint = (button) => {
        button.disabled = true;
        const pending = new Pending('vrroomruntime_builtin/panels:hint');
        session.requestHint(button.dataset.objective)
            .then((hint) => {
                announce(root, hint.text);
                return undefined;
            })
            .catch((error) => {
                button.disabled = false;
                if (error && error.errorcode === 'errornomorehints') {
                    announce(root, nomorehints);
                    return render();
                }
                return session.reportError(error);
            })
            .then(() => pending.resolve())
            .catch(() => pending.resolve());
    };

    /**
     * Finish the attempt from the Finish button (after the confirmation).
     *
     * @param {HTMLButtonElement} button The Finish button.
     * @returns {Promise}
     */
    const runFinish = (button) => {
        button.disabled = true;
        const pending = new Pending('vrroomruntime_builtin/panels:finish');
        return Promise.resolve(options.onFinish ? options.onFinish() : null)
            .catch((error) => {
                button.disabled = false;
                return session.reportError(error);
            })
            .then(() => pending.resolve());
    };

    const onClick = (e) => {
        const hintbutton = e.target.closest('[data-action="vrroom-get-hint"]');
        if (hintbutton) {
            e.preventDefault();
            if (!hintbutton.disabled) {
                getHint(hintbutton);
            }
            return;
        }
        const button = e.target.closest('[data-action="vrroom-finish-attempt"]');
        if (!button || button.disabled) {
            return;
        }
        e.preventDefault();
        Notification.saveCancelPromise(confirmtitle, confirmtext, finishlabel, {triggerElement: button})
            .then(() => runFinish(button))
            .catch(() => {
                // Cancelled.
            });
    };
    host.addEventListener('click', onClick);

    const unsubscribe = session.on((message) => {
        if (destroyed) {
            return;
        }
        if (message.type === 'change') {
            render();
        } else if (message.type === 'timeup') {
            onTimeUp();
        }
    });

    if (session.getDeadline() !== null) {
        ticker = window.setInterval(tick, 1000);
    }

    return render().then(() => ({
        refresh: render,
        destroy: () => {
            destroyed = true;
            unsubscribe();
            if (ticker) {
                window.clearInterval(ticker);
                ticker = null;
            }
            host.removeEventListener('click', onClick);
            host.replaceChildren();
        },
    }));
};

/**
 * Plain-text status label of an objective state.
 *
 * @param {string} status locked, available or completed.
 * @returns {Promise<string>}
 */
export const statusLabel = (status) => getString(`objectivestatus_${status}`, 'vrroomruntime_builtin');
