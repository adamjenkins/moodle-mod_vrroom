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
 * The live room panel: notice, group room choice, join and leave, voice controls and the list of
 * the people in the room. It only shows state and reports clicks; live/controller decides.
 *
 * @module     mavrruntime_builtin/live/panel
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Templates from 'core/templates';

/**
 * Find a data-region inside an element.
 *
 * @param {HTMLElement} el The element.
 * @param {string} name The data-region value.
 * @returns {HTMLElement|null}
 */
const part = (el, name) => el.querySelector(`[data-region="${name}"]`);

/**
 * Find a data-action button inside an element.
 *
 * @param {HTMLElement} el The element.
 * @param {string} name The data-action value.
 * @returns {HTMLElement|null}
 */
const action = (el, name) => el.querySelector(`[data-action="${name}"]`);

/**
 * The live room panel.
 */
class Panel {
    /**
     * Constructor.
     *
     * @param {HTMLElement} container The mavr-liveroom section.
     * @param {Object} options {cmid, live, secure, str(key, a), handlers: {join, leave, mic, mute, ptt,
     *     talk, mutepeer}}
     */
    constructor(container, options) {
        this.container = container;
        this.options = options;
        this.handlers = options.handlers || {};
        this.str = options.str;
        this.rendered = false;
        this.joined = false;
        this.novoice = false;
        this.busy = false;
        this.talking = false;
        this.listseq = 0;
        this.listeners = [];
    }

    /**
     * Render the panel and wire its controls.
     *
     * @returns {Promise}
     */
    render() {
        const live = this.options.live || {};
        const groups = Array.isArray(live.groups) ? live.groups : [];
        const defaultgroupid = Number(live.defaultgroupid) || 0;
        const context = {
            cmid: this.options.cmid,
            canjoin: !!live.canjoin,
            nogroup: live.reason === 'nogroup',
            secure: !!this.options.secure,
            hasgroups: !!live.canjoin && groups.length > 0,
            groups: groups.map((g) => ({
                id: Number(g.id) || 0,
                name: String(g.name || ''),
                selected: (Number(g.id) || 0) === defaultgroupid,
            })),
            maxpeers: Number(live.maxpeers) || 0,
            count: 0,
            notice: String(live.notice || ''),
        };
        return Templates.renderForPromise('mavrruntime_builtin/liveroom', context).then(({html, js}) => {
            Templates.replaceNodeContents(this.container, html, js);
            this.container.classList.remove('d-none');
            this.rendered = true;
            this.wire();
            return undefined;
        });
    }

    /**
     * Listen to the panel's controls.
     */
    wire() {
        const listen = (target, type, fn) => {
            if (!target) {
                return;
            }
            target.addEventListener(type, fn);
            this.listeners.push(() => target.removeEventListener(type, fn));
        };
        listen(this.container, 'click', (e) => {
            const button = e.target.closest('[data-action]');
            if (!button || !this.container.contains(button) || button.disabled) {
                return;
            }
            const name = button.dataset.action;
            if (name === 'mavr-live-join') {
                e.preventDefault();
                this.call('join');
            } else if (name === 'mavr-live-leave') {
                e.preventDefault();
                this.call('leave');
            } else if (name === 'mavr-live-mic') {
                e.preventDefault();
                this.call('mic');
            } else if (name === 'mavr-live-mute') {
                e.preventDefault();
                this.call('mute');
            } else if (name === 'mavr-live-mutepeer') {
                e.preventDefault();
                const item = button.closest('[data-peerid]');
                if (item) {
                    this.call('mutepeer', item.dataset.peerid);
                }
            }
        });
        listen(part(this.container, 'mavr-live-ptt'), 'change', (e) => {
            this.call('ptt', !!e.target.checked);
        });
        const talk = action(this.container, 'mavr-live-talk');
        const press = (e) => {
            if (e.type === 'keydown' && e.key !== ' ' && e.key !== 'Enter') {
                return;
            }
            if (e.type === 'keydown' || e.type === 'pointerdown') {
                e.preventDefault();
            }
            if (!this.talking) {
                this.call('talk', true);
            }
        };
        const release = (e) => {
            if (e.type === 'keyup' && e.key !== ' ' && e.key !== 'Enter') {
                return;
            }
            if (this.talking) {
                this.call('talk', false);
            }
        };
        listen(talk, 'pointerdown', press);
        listen(talk, 'keydown', press);
        ['pointerup', 'pointerleave', 'pointercancel', 'keyup', 'blur'].forEach((type) => listen(talk, type, release));
    }

    /**
     * Call a handler, if the controller gave one.
     *
     * @param {string} name Handler name.
     * @param {*} value Argument.
     */
    call(name, value) {
        const fn = this.handlers[name];
        if (typeof fn === 'function') {
            fn(value);
        }
    }

    /**
     * The group room chosen in the select, or 0 for the default room.
     *
     * @returns {number}
     */
    getGroupId() {
        const select = this.rendered ? part(this.container, 'mavr-live-group') : null;
        return select ? (Number(select.value) || 0) : 0;
    }

    /**
     * Show the panel as joined (leave button, voice controls, people) or not.
     *
     * @param {boolean} joined Joined.
     */
    setJoined(joined) {
        this.joined = !!joined;
        if (!this.rendered) {
            return;
        }
        const join = action(this.container, 'mavr-live-join');
        const leave = action(this.container, 'mavr-live-leave');
        if (join) {
            join.classList.toggle('d-none', this.joined);
        }
        if (leave) {
            leave.classList.toggle('d-none', !this.joined);
        }
        const select = part(this.container, 'mavr-live-group');
        if (select) {
            select.disabled = this.joined || this.busy;
        }
        part(this.container, 'mavr-live-voice').classList.toggle('d-none', !this.joined || this.novoice);
        part(this.container, 'mavr-live-people').classList.toggle('d-none', !this.joined);
        if (!this.joined) {
            this.setTalking(false);
            this.listseq++;
            part(this.container, 'mavr-live-participants').textContent = '';
        }
        this.setBusy(this.busy);
    }

    /**
     * Show or hide the voice controls (a transport without voice hides them).
     *
     * @param {boolean} available Voice can be used in this room.
     */
    setVoiceAvailable(available) {
        this.novoice = !available;
        if (!this.rendered) {
            return;
        }
        part(this.container, 'mavr-live-voice').classList.toggle('d-none', !available || !this.joined);
    }

    /**
     * Disable the join and leave buttons while a join or leave runs.
     *
     * @param {boolean} busy Busy.
     */
    setBusy(busy) {
        this.busy = !!busy;
        if (!this.rendered) {
            return;
        }
        ['mavr-live-join', 'mavr-live-leave'].forEach((name) => {
            const button = action(this.container, name);
            if (button) {
                button.disabled = this.busy;
            }
        });
        const select = part(this.container, 'mavr-live-group');
        if (select) {
            select.disabled = this.joined || this.busy;
        }
    }

    /**
     * Show a status line (plain text; '' clears it).
     *
     * @param {string} text The text.
     */
    setStatus(text) {
        if (!this.rendered) {
            return;
        }
        part(this.container, 'mavr-live-status').textContent = text || '';
    }

    /**
     * Show the own voice state on the controls.
     *
     * @param {Object} state {micstate: 'off'|'on'|'muted', muted: bool, ptt: bool}
     */
    setVoiceState(state) {
        if (!this.rendered) {
            return;
        }
        const mic = action(this.container, 'mavr-live-mic');
        const mute = action(this.container, 'mavr-live-mute');
        const ptt = part(this.container, 'mavr-live-ptt');
        const talk = action(this.container, 'mavr-live-talk');
        const on = state.micstate !== 'off';
        if (mic) {
            mic.textContent = this.str(on ? 'livemicoff' : 'livemicon');
            mic.classList.toggle('btn-outline-primary', !on);
            mic.classList.toggle('btn-primary', on);
        }
        if (mute) {
            mute.disabled = !on;
            mute.setAttribute('aria-pressed', state.muted ? 'true' : 'false');
            mute.textContent = this.str(state.muted ? 'liveunmute' : 'livemute');
        }
        if (ptt) {
            ptt.checked = !!state.ptt;
        }
        if (talk) {
            talk.classList.toggle('d-none', !state.ptt);
            talk.disabled = !on || !!state.muted;
        }
    }

    /**
     * Show the talk button as pressed (push-to-talk) or not.
     *
     * @param {boolean} talking Pressed.
     */
    setTalking(talking) {
        this.talking = !!talking;
        if (!this.rendered) {
            return;
        }
        const talk = action(this.container, 'mavr-live-talk');
        if (talk) {
            talk.setAttribute('aria-pressed', this.talking ? 'true' : 'false');
            talk.classList.toggle('active', this.talking);
        }
    }

    /**
     * Show the people in the room.
     *
     * @param {Object[]} people Contexts of mavrruntime_builtin/liveparticipant, oneself first.
     * @returns {Promise}
     */
    setParticipants(people) {
        if (!this.rendered || !this.joined) {
            return Promise.resolve();
        }
        const seq = ++this.listseq;
        const count = part(this.container, 'mavr-live-count');
        if (count) {
            count.textContent = this.str('liveparticipants', people.length);
        }
        const pending = new Pending('mavrruntime_builtin/live/panel:participants');
        return Promise.all(people.map((person) => Templates.renderForPromise('mavrruntime_builtin/liveparticipant', person)))
            .then((results) => {
                if (seq !== this.listseq || !this.joined) {
                    return undefined;
                }
                const html = results.map((r) => r.html).join('');
                const js = results.map((r) => r.js).join(';');
                // Template output only: every value is escaped by Mustache.
                Templates.replaceNodeContents(part(this.container, 'mavr-live-participants'), html, js);
                return undefined;
            })
            .then(() => {
                pending.resolve();
                return undefined;
            }, (error) => {
                pending.resolve();
                throw error;
            });
    }

    /**
     * Switch the speaking indicators.
     *
     * @param {Function} isSpeaking (peerid) => boolean
     */
    setSpeaking(isSpeaking) {
        if (!this.rendered || !this.joined) {
            return;
        }
        part(this.container, 'mavr-live-participants').querySelectorAll('[data-peerid]').forEach((item) => {
            const indicator = item.querySelector('.mavr-live-speaking');
            if (indicator) {
                indicator.classList.toggle('mavr-live-speaking-on', !!isSpeaking(item.dataset.peerid));
            }
        });
    }

    /**
     * Stop listening and empty the panel.
     */
    destroy() {
        this.listeners.forEach((fn) => fn());
        this.listeners = [];
        this.listseq++;
        if (this.rendered) {
            this.container.textContent = '';
            this.container.classList.add('d-none');
        }
        this.rendered = false;
    }
}

/**
 * Create the live room panel.
 *
 * @param {HTMLElement} container The mavr-liveroom section.
 * @param {Object} options {cmid, live, secure, str(key, a), handlers}
 * @returns {Panel}
 */
export const createPanel = (container, options) => new Panel(container, options);
