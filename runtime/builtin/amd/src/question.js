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
 * A question-bank question inside a panel or a list-view station: fetched from the server,
 * rendered by the question engine, submitted and shown with its feedback. When the objective
 * needs a better answer (pass fraction) the feedback comes with Try again, which loads the
 * fresh question the server has started.
 *
 * @module     mavrruntime_builtin/question
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Fragment from 'core/fragment';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';

/**
 * Render a template into the host and run the question's JavaScript.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} context Template context.
 * @param {string} javascript Collected question JavaScript from the server.
 * @returns {Promise}
 */
const place = (host, context, javascript) => Templates.renderForPromise('mavrruntime_builtin/question', context)
    .then(({html, js}) => {
        Templates.replaceNodeContents(host, html, js);
        if (javascript) {
            Templates.runTemplateJS(Fragment.processCollectedJavascript(javascript));
        }
        return undefined;
    });

/**
 * Show a plain notice instead of the question.
 *
 * @param {HTMLElement} host The question region.
 * @param {number} slot Slot.
 * @param {string} key String key in mavrruntime_builtin.
 * @returns {Promise}
 */
const notice = (host, slot, key) => getString(key, 'mavrruntime_builtin')
    .then((text) => place(host, {slot: slot, html: '', answered: true, locked: true, lockedmessage: text, retry: false}, ''));

/**
 * Wire the Try again button shown after an answer that fell short.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} session The session.
 * @param {number} slot Slot.
 */
const wireRetry = (host, session, slot) => {
    const button = host.querySelector('[data-action="mavr-retry-question"]');
    if (!button) {
        return;
    }
    button.addEventListener('click', (e) => {
        e.preventDefault();
        button.disabled = true;
        renderQuestion(host, session, slot).then(() => {
            const html = host.querySelector('[data-region="mavr-question-html"]');
            if (html) {
                html.setAttribute('tabindex', '-1');
                html.focus({preventScroll: false});
            }
            return undefined;
        }).catch(Notification.exception);
    });
};

/**
 * Wire the submit handler of a rendered question form.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} session The session.
 * @param {number} slot Slot.
 */
const wire = (host, session, slot) => {
    const form = host.querySelector('[data-region="mavr-question-form"]');
    if (!form) {
        return;
    }
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const button = form.querySelector('[data-action="mavr-submit-question"]');
        if (button) {
            if (button.disabled) {
                return;
            }
            button.disabled = true;
        }
        const pending = new Pending('mavrruntime_builtin/question:submit');
        session.submitQuestion(slot, form)
            .then((result) => showSubmitted(host, session, slot, result))
            .catch((error) => {
                if (button) {
                    button.disabled = false;
                }
                Notification.exception(error);
            })
            .finally(() => pending.resolve());
    });
};

/**
 * Show the graded question after a submit and wire what it needs next.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} session The session.
 * @param {number} slot Slot.
 * @param {Object} result The submit_question result.
 * @returns {Promise}
 */
const showSubmitted = (host, session, slot, result) => place(host, {
    slot: slot,
    html: result.html,
    answered: result.answered,
    locked: false,
    lockedmessage: '',
    retry: !!result.retry,
}, result.javascript).then(() => {
    let state = 'open';
    if (result.retry) {
        state = 'retry';
    } else if (result.answered) {
        state = 'answered';
    }
    host.dataset.mavrQuestionState = state;
    if (!result.answered) {
        wire(host, session, slot);
    } else if (result.retry) {
        wireRetry(host, session, slot);
    }
    const heading = host.querySelector('[data-region="mavr-question-html"]');
    if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({preventScroll: false});
    }
    return undefined;
});

/**
 * Show a fetched question and wire its form while it is open.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} session The session.
 * @param {number} slot Slot.
 * @param {Object} result The get_question result.
 * @returns {Promise}
 */
const showFetched = (host, session, slot, result) => place(host, {
    slot: slot,
    html: result.html,
    answered: !!result.answered,
    locked: false,
    lockedmessage: '',
    retry: false,
}, result.javascript).then(() => {
    host.dataset.mavrQuestionState = result.answered ? 'answered' : 'open';
    if (!result.answered) {
        wire(host, session, slot);
    }
    return undefined;
});

/**
 * Render a slot's question into a host element.
 *
 * Preview: a notice only. Locked slot: the locked notice. Otherwise the server-rendered
 * question; once answered, the graded question with feedback and no submit button.
 *
 * @param {HTMLElement} host The question region.
 * @param {Object} session The session.
 * @param {number|string} slot Slot number.
 * @returns {Promise}
 */
export const renderQuestion = (host, session, slot) => {
    const n = Number(slot);
    host.dataset.slot = String(n);
    const pending = new Pending('mavrruntime_builtin/question:render');
    let promise;
    if (session.preview) {
        host.dataset.mavrQuestionState = 'preview';
        promise = notice(host, n, 'questionpreview');
    } else if (!session.isSlotAvailable(n)) {
        host.dataset.mavrQuestionState = 'locked';
        promise = notice(host, n, 'questionlocked');
    } else {
        host.dataset.mavrQuestionState = 'loading';
        promise = session.getQuestion(n)
            .then((result) => showFetched(host, session, n, result))
            .catch((error) => {
                if (error && error.errorcode === 'errorquestionlocked') {
                    host.dataset.mavrQuestionState = 'locked';
                    return notice(host, n, 'questionlocked');
                }
                host.dataset.mavrQuestionState = 'error';
                return Notification.exception(error);
            });
    }
    return promise.then(() => {
        pending.resolve();
        return undefined;
    }, (error) => {
        pending.resolve();
        throw error;
    });
};
