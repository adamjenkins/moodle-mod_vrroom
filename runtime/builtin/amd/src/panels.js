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
 * with the finish button.
 *
 * The hotspot panel follows the idea of mod_gear's hotspot popup (a Mustache-rendered card over
 * the scene, opened by clicking a hotspot, closed with a close button).
 *
 * @module     mavrruntime_builtin/panels
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import {renderQuestion} from 'mavrruntime_builtin/question';
import {BADGES, plainText, formatPoints, startScene} from 'mavrruntime_builtin/util';

/** Counter for unique heading ids. */
let uidcounter = 0;

/**
 * Create the overlay panels of a 3D stage.
 *
 * @param {HTMLElement} stageroot The element holding the mavr-overlay region (the stage).
 * @param {Object} session The session.
 * @param {Object} options {onClose()} called after a panel closes (focus goes back to the canvas).
 * @returns {Object} {openInteractable(item), openQuestion(slot), close(), refresh()}
 */
export const createPanels = (stageroot, session, options = {}) => {
    const overlay = stageroot.querySelector('[data-region="mavr-overlay"]');
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

    const questionHost = () => (current ? overlay.querySelector('[data-region="mavr-question"]') : null);

    const show = (context, slot) => {
        const pending = new Pending('mavrruntime_builtin/panels:open');
        uidcounter++;
        context.uid = `${session.config.cmid}-${uidcounter}`;
        return Templates.renderForPromise('mavrruntime_builtin/panel', context)
            .then(({html, js}) => {
                Templates.replaceNodeContents(overlay, html, js);
                current = {id: context.id, slot: slot};
                const panel = overlay.querySelector('[data-region="mavr-panel"]');
                panel.addEventListener('click', (e) => {
                    if (e.target.closest('[data-action="mavr-close-panel"]')) {
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
                const heading = panel.querySelector(`#mavr-panel-title-${CSS.escape(context.uid)}`);
                if (heading) {
                    heading.focus({preventScroll: true});
                }
                const host = questionHost();
                if (host && slot !== null) {
                    return renderQuestion(host, session, slot);
                }
                return undefined;
            })
            .catch(Notification.exception)
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
        if (host.dataset.mavrQuestionState === 'locked' && session.isSlotAvailable(current.slot)) {
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
 * Create the progress panel (objectives, points, finish button) inside a host element.
 *
 * Options: root (the MAVR root, for the live and message regions); onFinish() → Promise that
 * finishes the attempt; announce(text); message(text).
 *
 * @param {HTMLElement} host Where the progress is rendered.
 * @param {Object} session The session.
 * @param {Object} options Options.
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
export const createProgress = (host, session, options = {}) => getStrings([
    {key: 'objectivestatus_locked', component: 'mavrruntime_builtin'},
    {key: 'objectivestatus_available', component: 'mavrruntime_builtin'},
    {key: 'objectivestatus_completed', component: 'mavrruntime_builtin'},
    {key: 'finishconfirmtitle', component: 'mavrruntime_builtin'},
    {key: 'finishconfirm', component: 'mavrruntime_builtin'},
    {key: 'finishattempt', component: 'mavrruntime_builtin'},
]).then(([locked, available, completed, confirmtitle, confirmtext, finishlabel]) => {
    const labels = {locked: locked, available: available, completed: completed};
    let destroyed = false;
    let sequence = 0;

    const render = () => {
        sequence++;
        const mine = sequence;
        const state = session.getState();
        return getString('scoreprogress', 'mavrruntime_builtin', {
            earned: formatPoints(state.earnedpoints),
            max: formatPoints(state.maxpoints),
        }).then((scoretext) => {
            const context = {
                scoretext: scoretext,
                objectives: state.objectives
                    .filter((o) => !state.hidden.has(o.id))
                    .map((o) => ({
                        id: o.id,
                        status: o.status,
                        badgeclass: BADGES[o.status] || BADGES.locked,
                        statuslabel: labels[o.status] || o.status,
                        title: o.title,
                    })),
                canfinish: !session.preview && !session.closed,
                preview: session.preview,
            };
            return Templates.renderForPromise('mavrruntime_builtin/progress', context);
        }).then(({html, js}) => {
            if (!destroyed && mine === sequence) {
                Templates.replaceNodeContents(host, html, js);
            }
            return undefined;
        }).catch(Notification.exception);
    };

    const onClick = (e) => {
        const button = e.target.closest('[data-action="mavr-finish-attempt"]');
        if (!button || button.disabled) {
            return;
        }
        e.preventDefault();
        Notification.saveCancelPromise(confirmtitle, confirmtext, finishlabel, {triggerElement: button})
            .then(() => {
                button.disabled = true;
                const pending = new Pending('mavrruntime_builtin/panels:finish');
                return Promise.resolve(options.onFinish ? options.onFinish() : null)
                    .catch((error) => {
                        button.disabled = false;
                        Notification.exception(error);
                    })
                    .then(() => pending.resolve());
            })
            .catch(() => {
                // Cancelled.
            });
    };
    host.addEventListener('click', onClick);

    const unsubscribe = session.on((message) => {
        if (message.type === 'change') {
            render();
        }
    });

    return render().then(() => ({
        refresh: render,
        destroy: () => {
            destroyed = true;
            unsubscribe();
            host.removeEventListener('click', onClick);
            host.replaceChildren();
        },
    }));
});

/**
 * Plain-text status label of an objective state.
 *
 * @param {string} status locked, available or completed.
 * @returns {Promise<string>}
 */
export const statusLabel = (status) => getString(`objectivestatus_${status}`, 'mavrruntime_builtin');
