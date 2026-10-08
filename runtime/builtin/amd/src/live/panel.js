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
 * The live room panel: notice, group room choice, join and leave (or, with several rooms per
 * activity, the list of rooms with their occupancy), voice controls and the list of the people in
 * the room. It only shows state and reports clicks; live/controller decides.
 *
 * @module     vrroomruntime_builtin/live/panel
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
 * A whole number of at least 0 from a server value.
 *
 * @param {*} value The value.
 * @returns {number}
 */
const wholeNumber = (value) => {
    const number = Number(value);
    return isFinite(number) && number > 0 ? Math.floor(number) : 0;
};

/**
 * The live room panel.
 */
class Panel {
    /**
     * Constructor.
     *
     * @param {HTMLElement} container The vrroom-liveroom section.
     * @param {Object} options {cmid, live, secure, str(key, a), handlers: {join(room), leave, mic, mute,
     *     ptt, talk, mutepeer, groupchange}}
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
        this.roomlist = !!(options.live && options.live.roomlist === true);
        this.roomsseq = 0;
        this.canstart = false;
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
            roomlist: this.roomlist,
        };
        return Templates.renderForPromise('vrroomruntime_builtin/liveroom', context).then(({html, js}) => {
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
            if (name === 'vrroom-live-join') {
                e.preventDefault();
                this.call('join');
            } else if (name === 'vrroom-live-joinroom') {
                e.preventDefault();
                const room = wholeNumber(button.dataset.room);
                if (room > 0) {
                    this.call('join', room);
                }
            } else if (name === 'vrroom-live-start') {
                e.preventDefault();
                this.call('join', 0);
            } else if (name === 'vrroom-live-leave') {
                e.preventDefault();
                this.call('leave');
            } else if (name === 'vrroom-live-mic') {
                e.preventDefault();
                this.call('mic');
            } else if (name === 'vrroom-live-mute') {
                e.preventDefault();
                this.call('mute');
            } else if (name === 'vrroom-live-mutepeer') {
                e.preventDefault();
                const item = button.closest('[data-peerid]');
                if (item) {
                    this.call('mutepeer', item.dataset.peerid);
                }
            }
        });
        if (this.roomlist) {
            listen(part(this.container, 'vrroom-live-group'), 'change', () => {
                this.call('groupchange');
            });
        }
        listen(part(this.container, 'vrroom-live-ptt'), 'change', (e) => {
            this.call('ptt', !!e.target.checked);
        });
        const talk = action(this.container, 'vrroom-live-talk');
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
        const select = this.rendered ? part(this.container, 'vrroom-live-group') : null;
        return select ? (Number(select.value) || 0) : 0;
    }

    /**
     * Show the panel as joined (leave button, voice controls, people) or not.
     *
     * @param {boolean} joined Joined.
     */
    setJoined(joined) {
        const changed = this.joined !== !!joined;
        this.joined = !!joined;
        if (!this.rendered) {
            return;
        }
        // Move the focus only when it was in the panel (or nowhere): never out of the 3D room.
        const active = document.activeElement;
        const focushere = !active || active === document.body || this.container.contains(active);
        const join = action(this.container, 'vrroom-live-join');
        const leave = action(this.container, 'vrroom-live-leave');
        if (join) {
            join.classList.toggle('d-none', this.joined);
        }
        if (leave) {
            leave.classList.toggle('d-none', !this.joined);
        }
        const select = part(this.container, 'vrroom-live-group');
        if (select) {
            select.disabled = this.joined || this.busy;
        }
        part(this.container, 'vrroom-live-voice').classList.toggle('d-none', !this.joined || this.novoice);
        part(this.container, 'vrroom-live-people').classList.toggle('d-none', !this.joined);
        const rooms = part(this.container, 'vrroom-live-rooms');
        if (rooms) {
            rooms.classList.toggle('d-none', this.joined);
        }
        if (!this.joined) {
            this.setTalking(false);
            this.listseq++;
            part(this.container, 'vrroom-live-participants').textContent = '';
            this.setCurrentRoom('');
        }
        this.setBusy(this.busy);
        if (this.roomlist && changed && focushere) {
            const target = this.joined ? leave : part(this.container, 'vrroom-live-roomsheading');
            if (target) {
                target.focus();
            }
        }
    }

    /**
     * Show the room one is in ('' hides the line).
     *
     * @param {string} text Plain text, for example "You are in Room 2.".
     */
    setCurrentRoom(text) {
        if (!this.rendered) {
            return;
        }
        const current = part(this.container, 'vrroom-live-current');
        if (current) {
            current.textContent = text || '';
            current.classList.toggle('d-none', !text);
        }
    }

    /**
     * Show the list of rooms (a live_rooms answer). Answers that arrive after a later call, or while
     * joined, are dropped.
     *
     * @param {Object} answer live_rooms result {roommode, maxrooms, rooms, canstart, startreason}.
     * @returns {Promise}
     */
    setRooms(answer) {
        if (!this.rendered || !this.roomlist) {
            return Promise.resolve();
        }
        const seq = ++this.roomsseq;
        const dynamic = answer && answer.roommode === 'dynamic';
        const list = answer && Array.isArray(answer.rooms) ? answer.rooms : [];
        const rooms = list.filter((room) => room && wholeNumber(room.number) > 0)
            .map((room) => ({
                number: wholeNumber(room.number),
                name: String(room.name || ''),
                count: wholeNumber(room.count),
                capacity: wholeNumber(room.capacity),
                joinable: !!room.joinable,
            }));
        this.canstart = dynamic && !!answer.canstart;
        const context = {
            cmid: this.options.cmid,
            rooms: rooms,
            hasrooms: rooms.length > 0,
            dynamic: dynamic,
            norooms: dynamic && rooms.length === 0,
            allfull: !dynamic && rooms.length > 0 && rooms.every((room) => !room.joinable),
            canstart: this.canstart,
            startlimit: dynamic && !this.canstart && answer.startreason === 'limit',
            maxrooms: wholeNumber(answer ? answer.maxrooms : 0),
            busy: this.busy,
        };
        const pending = new Pending('vrroomruntime_builtin/live/panel:rooms');
        return Templates.renderForPromise('vrroomruntime_builtin/liveroomlist', context).then(({html, js}) => {
            const body = this.rendered ? part(this.container, 'vrroom-live-roomsbody') : null;
            if (seq !== this.roomsseq || !body) {
                return undefined;
            }
            // Keep the keyboard focus on the same control when the list is drawn again.
            const active = document.activeElement;
            let refocus = null;
            if (active && body.contains(active) && active.dataset) {
                refocus = {action: active.dataset.action || '', room: active.dataset.room || ''};
            }
            // Template output only: every value is escaped by Mustache.
            Templates.replaceNodeContents(body, html, js);
            this.setBusy(this.busy);
            if (refocus) {
                let target = null;
                if (refocus.action === 'vrroom-live-joinroom') {
                    target = body.querySelector(`[data-action="vrroom-live-joinroom"][data-room="${wholeNumber(refocus.room)}"]`);
                } else if (refocus.action === 'vrroom-live-start') {
                    target = action(body, 'vrroom-live-start');
                }
                if (!target || target.disabled) {
                    target = part(this.container, 'vrroom-live-roomsheading');
                }
                if (target) {
                    target.focus();
                }
            }
            return undefined;
        }).then(() => {
            pending.resolve();
            return undefined;
        }, (error) => {
            pending.resolve();
            throw error;
        });
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
        part(this.container, 'vrroom-live-voice').classList.toggle('d-none', !available || !this.joined);
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
        ['vrroom-live-join', 'vrroom-live-leave'].forEach((name) => {
            const button = action(this.container, name);
            if (button) {
                button.disabled = this.busy;
            }
        });
        this.container.querySelectorAll('[data-action="vrroom-live-joinroom"]').forEach((button) => {
            button.disabled = this.busy;
        });
        const start = action(this.container, 'vrroom-live-start');
        if (start) {
            start.disabled = this.busy || !this.canstart;
        }
        const select = part(this.container, 'vrroom-live-group');
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
        part(this.container, 'vrroom-live-status').textContent = text || '';
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
        const mic = action(this.container, 'vrroom-live-mic');
        const mute = action(this.container, 'vrroom-live-mute');
        const ptt = part(this.container, 'vrroom-live-ptt');
        const talk = action(this.container, 'vrroom-live-talk');
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
        const talk = action(this.container, 'vrroom-live-talk');
        if (talk) {
            talk.setAttribute('aria-pressed', this.talking ? 'true' : 'false');
            talk.classList.toggle('active', this.talking);
        }
    }

    /**
     * Show the people in the room.
     *
     * @param {Object[]} people Contexts of vrroomruntime_builtin/liveparticipant, oneself first.
     * @returns {Promise}
     */
    setParticipants(people) {
        if (!this.rendered || !this.joined) {
            return Promise.resolve();
        }
        const seq = ++this.listseq;
        const count = part(this.container, 'vrroom-live-count');
        if (count) {
            count.textContent = this.str('liveparticipants', people.length);
        }
        const pending = new Pending('vrroomruntime_builtin/live/panel:participants');
        return Promise.all(people.map((person) => Templates.renderForPromise('vrroomruntime_builtin/liveparticipant', person)))
            .then((results) => {
                if (seq !== this.listseq || !this.joined) {
                    return undefined;
                }
                const html = results.map((r) => r.html).join('');
                const js = results.map((r) => r.js).join(';');
                // Template output only: every value is escaped by Mustache.
                Templates.replaceNodeContents(part(this.container, 'vrroom-live-participants'), html, js);
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
        part(this.container, 'vrroom-live-participants').querySelectorAll('[data-peerid]').forEach((item) => {
            const indicator = item.querySelector('.vrroom-live-speaking');
            if (indicator) {
                indicator.classList.toggle('vrroom-live-speaking-on', !!isSpeaking(item.dataset.peerid));
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
        this.roomsseq++;
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
 * @param {HTMLElement} container The vrroom-liveroom section.
 * @param {Object} options {cmid, live, secure, str(key, a), handlers}
 * @returns {Panel}
 */
export const createPanel = (container, options) => new Panel(container, options);
