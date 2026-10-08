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
 * One attempt (or preview) in the browser: local events evaluated with the client graph
 * evaluator for instant feedback, statements to the server, reconciliation with the server's
 * authoritative state, questions (with retries), hints, the attempt's deadline, rooms, resume
 * position and finishing.
 *
 * @module     mavrruntime_builtin/session
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {evaluate, classify, STATUS} from 'mavrruntime_builtin/graph';
import {createQueue, buildStatement} from 'mavrruntime_builtin/statements';
import {plainText} from 'mavrruntime_builtin/util';

/** Order of objective states, used to merge server and local state. */
const RANK = {locked: 0, available: 1, completed: 2};

/** Resume position is saved this often (ms). */
const RESUME_INTERVAL = 10000;

/** The deadline is checked this often (ms). */
const TIMER_INTERVAL = 1000;

/**
 * The hints of the launch config as a Map objectiveid => {total, used: [{id, text}]}.
 *
 * The server sends an object keyed by objective id (an empty PHP array arrives as a list).
 *
 * @param {*} hints config.hints
 * @returns {Map}
 */
const hintMap = (hints) => {
    const map = new Map();
    if (!hints || typeof hints !== 'object' || Array.isArray(hints)) {
        return map;
    }
    Object.keys(hints).forEach((id) => {
        const entry = hints[id] || {};
        const used = (Array.isArray(entry.used) ? entry.used : [])
            .filter((h) => h && h.id !== undefined)
            .map((h) => ({id: String(h.id), text: String(h.text || '')}));
        map.set(String(id), {total: Number(entry.total) || 0, used: used});
    });
    return map;
};

/**
 * Normalise an event to the evaluator's shape (contract §4.4.1).
 *
 * @param {Object} event Raw event.
 * @returns {Object}
 */
const normaliseEvent = (event) => ({
    verb: String(event.verb || ''),
    type: String(event.type || ''),
    id: event.id === null || event.id === undefined ? '' : String(event.id),
    scene: event.scene === undefined || event.scene === '' ? null : event.scene,
    fraction: event.fraction === undefined || event.fraction === null ? null : Number(event.fraction),
});

/**
 * Round like the evaluators do.
 *
 * @param {number} x Value.
 * @param {number} digits Decimals.
 * @returns {number}
 */
const round = (x, digits) => {
    const f = Math.pow(10, digits);
    return Math.round(x * f) / f;
};

/**
 * Call one web service and return a native promise.
 *
 * @param {string} methodname Function name.
 * @param {Object} args Arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Promise.resolve(Ajax.call([{methodname: methodname, args: args}])[0]);

/**
 * The client side of one attempt.
 */
class Session {
    /**
     * Constructor.
     *
     * @param {Object} config Launch config (contract §4.5).
     */
    constructor(config) {
        this.config = config;
        this.preview = !!config.preview;
        this.graph = config.graph || {schema: 'mavr-graph/1', hidden: [], objectives: [], rules: []};
        this.objectivedefs = Array.isArray(this.graph.objectives) ? this.graph.objectives : [];
        this.scenes = Array.isArray(config.scenes) ? config.scenes : [];
        this.confirmed = (Array.isArray(config.events) ? config.events : []).map(normaliseEvent);
        this.pendingevents = [];
        this.listeners = [];
        this.channel = 'builtin-3d';
        this.sceneid = null;
        this.active = false;
        this.closed = false;
        this.serverstate = null;
        this.serverfresh = false;
        this.clockoffset = 0;
        this.hints = hintMap(config.hints);
        this.timer = null;
        this.timeupsent = false;
        this.setServerState(config.state || null, !!config.state);
        this.local = evaluate(this.graph, this.allEvents());
        this.effectsseen = this.local.effects.length;
        this.view = this.buildView();
        this.reconciling = null;
        this.resumetimer = null;
        this.queue = this.preview ? null : createQueue(config, (result) => this.batchDone(result), {
            onFailure: (failing) => this.emit({type: 'failure', failing: failing}),
        });
        this.onPageHide = () => {
            if (this.active) {
                this.record('exited', 'scene', this.sceneid, this.sceneid);
                this.record('terminated', 'activity', null, null);
                this.active = false;
            }
            if (this.queue) {
                this.queue.flushBeacon();
            }
        };
        window.addEventListener('pagehide', this.onPageHide);
    }

    /**
     * The launch config this session runs.
     *
     * @returns {Object}
     */
    getConfig() {
        return this.config;
    }

    /**
     * Take a server state; remember how far the browser clock is from the server's.
     *
     * @param {Object|null} state §4.6 attempt state.
     * @param {boolean} fresh Whether it is newer than every local event.
     */
    setServerState(state, fresh) {
        this.serverstate = state;
        this.serverfresh = fresh;
        if (state && Number(state.servertime) > 0) {
            this.clockoffset = Number(state.servertime) * 1000 - Date.now();
        }
    }

    /**
     * All events known locally, in server order (confirmed first, then unsent or in flight).
     *
     * @returns {Object[]}
     */
    allEvents() {
        return this.confirmed.concat(this.pendingevents.map((p) => p.event));
    }

    /**
     * Subscribe to session changes.
     *
     * Listeners receive {type: 'change', state, previous, unlocked, completed, effects},
     * {type: 'failure', failing} or, once, {type: 'timeup'} when the attempt's time has run out.
     * Listeners must ignore types they do not know.
     *
     * @param {Function} listener The listener.
     * @returns {Function} Unsubscribe.
     */
    on(listener) {
        this.listeners.push(listener);
        return () => {
            this.listeners = this.listeners.filter((l) => l !== listener);
        };
    }

    /**
     * Notify listeners.
     *
     * @param {Object} message The message.
     */
    emit(message) {
        this.listeners.slice().forEach((listener) => {
            try {
                listener(message);
            } catch (e) {
                Log.error(e);
            }
        });
    }

    /**
     * Merge the local evaluation with the server state into what the UI shows.
     *
     * While no local event is newer than the server state, the server state wins; otherwise an
     * objective takes whichever of the two states is further along.
     *
     * @returns {Object}
     */
    buildView() {
        const local = this.local;
        const server = this.serverstate;
        const serverobjectives = {};
        if (server && Array.isArray(server.objectives)) {
            server.objectives.forEach((o) => {
                serverobjectives[o.id] = o;
            });
        }
        const objectives = [];
        const byid = {};
        let earned = 0;
        let maxpoints = 0;
        let requiredtotal = 0;
        let requireddone = 0;
        this.objectivedefs.forEach((def) => {
            const loc = (local.objectives && local.objectives[def.id]) || {status: 'locked', earned: 0};
            let status = loc.status;
            let earnedhere = Number(loc.earned) || 0;
            const srv = serverobjectives[def.id];
            if (srv && (this.serverfresh || RANK[srv.status] > RANK[status])) {
                status = srv.status;
                earnedhere = Number(srv.earned) || 0;
            }
            const points = Number(def.points) || 0;
            const required = !!def.required;
            const hintdefs = Array.isArray(def.hints) ? def.hints.filter((h) => h && h.id !== undefined) : [];
            const hintsused = Math.max(Number(loc.hintsused) || 0, srv ? Number(srv.hintsused) || 0 : 0);
            const objective = {
                id: def.id,
                type: def.type,
                title: plainText(def.title),
                status: status,
                earned: earnedhere,
                points: points,
                required: required,
                zone: def.zone || null,
                slot: def.slot === undefined ? null : Number(def.slot),
                target: def.target || null,
                hintsused: hintsused,
                hintstotal: hintdefs.length,
                nexthintpenalty: hintsused < hintdefs.length ? Number(hintdefs[hintsused].penalty) || 0 : 0,
            };
            objectives.push(objective);
            byid[def.id] = objective;
            maxpoints += points;
            if (status === 'completed') {
                earned += earnedhere;
            }
            if (required) {
                requiredtotal++;
                if (status === 'completed') {
                    requireddone++;
                }
            }
        });
        maxpoints = round(maxpoints, 7);
        earned = round(earned, 7);
        let score = 0;
        if (maxpoints > 0) {
            score = round(earned / maxpoints, 5);
        } else if (requiredtotal > 0) {
            score = round(requireddone / requiredtotal, 5);
        }
        let hidden = Array.isArray(local.hidden) ? local.hidden : [];
        if (server && this.serverfresh && Array.isArray(server.hidden)) {
            hidden = server.hidden;
        }
        return {
            objectives: objectives,
            byid: byid,
            earnedpoints: earned,
            maxpoints: maxpoints,
            score: score,
            requiredtotal: requiredtotal,
            requireddone: requireddone,
            allrequiredcompleted: requiredtotal > 0 && requireddone === requiredtotal,
            finished: !!local.finished || !!(server && server.finished),
            hidden: new Set(hidden),
        };
    }

    /**
     * Re-evaluate and notify listeners about what changed.
     *
     * @param {boolean} fireeffects Whether new effects are passed to listeners.
     */
    update(fireeffects) {
        const previous = this.view;
        this.local = evaluate(this.graph, this.allEvents());
        let effects = [];
        if (fireeffects && this.local.effects.length > this.effectsseen) {
            effects = this.local.effects.slice(this.effectsseen);
        }
        this.effectsseen = this.local.effects.length;
        this.view = this.buildView();
        const unlocked = [];
        const completed = [];
        this.view.objectives.forEach((o) => {
            const before = previous.byid[o.id];
            const beforestatus = before ? before.status : 'locked';
            if (beforestatus === 'locked' && o.status !== 'locked') {
                unlocked.push(o);
            }
            if (beforestatus !== 'completed' && o.status === 'completed') {
                completed.push(o);
            }
        });
        this.emit({
            type: 'change',
            state: this.view,
            previous: previous,
            unlocked: unlocked,
            completed: completed,
            effects: effects,
        });
    }

    /**
     * The current merged state.
     *
     * @returns {Object}
     */
    getState() {
        return this.view;
    }

    /**
     * Find a scene by id.
     *
     * @param {string} sceneid Scene id.
     * @returns {Object|null}
     */
    getScene(sceneid) {
        return this.scenes.find((s) => s.id === sceneid) || null;
    }

    /**
     * Display name of a statement object, for the statement definition.
     *
     * @param {string} type Object type.
     * @param {string|null} sceneid Scene id.
     * @param {string|null} id Object id.
     * @returns {string}
     */
    nameFor(type, sceneid, id) {
        const scene = this.getScene(sceneid);
        if (!scene) {
            return '';
        }
        if (type === 'scene') {
            return plainText(scene.name);
        }
        const list = type === 'zone' ? scene.zones : scene.interactables;
        const item = (Array.isArray(list) ? list : []).find((x) => x.id === id);
        return item ? plainText(item.name || '') : '';
    }

    /**
     * Record a client event: classified locally first, a locked event is neither stored nor sent.
     *
     * @param {string} verb initialized, terminated, entered, exited or interacted.
     * @param {string} type activity, scene, zone or object.
     * @param {string|null} id Object id (the scene id for scene events).
     * @param {string|null} sceneid Scene id.
     * @returns {string} applied, ignored, locked or closed.
     */
    record(verb, type, id, sceneid) {
        if (this.closed) {
            return 'closed';
        }
        const event = normaliseEvent({verb: verb, type: type, id: id, scene: sceneid || null, fraction: null});
        const status = classify(this.graph, this.allEvents(), event);
        if (status === STATUS.LOCKED) {
            return status;
        }
        let statementid = null;
        if (this.queue) {
            const statement = buildStatement(this.config, verb, type, sceneid, id,
                this.nameFor(type, sceneid, id), this.channel);
            statementid = statement.id;
            this.queue.push(statement);
        }
        this.pendingevents.push({event: event, statementid: statementid});
        this.serverfresh = false;
        this.update(true);
        return status;
    }

    /**
     * Handle the result of one statement batch, then reconcile with the server.
     *
     * @param {Object} result {batch, accepted, error}
     * @returns {Promise}
     */
    batchDone(result) {
        result.batch.forEach((statement, index) => {
            const position = this.pendingevents.findIndex((p) => p.statementid === statement.id);
            if (position === -1) {
                return;
            }
            const pending = this.pendingevents.splice(position, 1)[0];
            if (result.accepted && result.accepted[index]) {
                this.confirmed.push(pending.event);
            }
        });
        if (result.error) {
            Log.debug(result.error);
        }
        return this.reconcile();
    }

    /**
     * Fetch the server state and merge it.
     *
     * @returns {Promise}
     */
    reconcile() {
        if (this.preview || this.closed || !this.config.attemptid) {
            this.update(false);
            return Promise.resolve();
        }
        const pendingbefore = this.pendingevents.length;
        this.reconciling = call('mod_mavr_get_attempt_state', {attemptid: this.config.attemptid})
            .then((state) => {
                this.setServerState(state, this.pendingevents.length === 0 && pendingbefore === 0);
                this.update(false);
                this.startTimer();
                return state;
            })
            .catch((e) => {
                Log.debug(e);
                this.update(false);
            })
            .then(() => {
                this.reconciling = null;
                return undefined;
            });
        return this.reconciling;
    }

    /**
     * Send everything queued and wait for the reconcile that follows.
     *
     * @returns {Promise<boolean>}
     */
    flush() {
        if (!this.queue) {
            return Promise.resolve(true);
        }
        return this.queue.flush().then((ok) => {
            if (this.reconciling) {
                return this.reconciling.then(() => ok);
            }
            return ok;
        });
    }

    /**
     * Start a view: initialized (activity) then entered (scene).
     *
     * @param {string} channel builtin-3d or builtin-list.
     * @param {string} sceneid Scene id.
     */
    start(channel, sceneid) {
        if (this.active) {
            this.stop();
        }
        this.channel = channel;
        this.sceneid = sceneid;
        this.active = true;
        this.record('initialized', 'activity', null, null);
        this.record('entered', 'scene', sceneid, sceneid);
        this.startTimer();
    }

    /**
     * Move to another room of the activity: exited (old room), entered (new room).
     *
     * Does nothing while no view runs or when the room does not change.
     *
     * @param {string} sceneid Scene id of the new room.
     */
    setScene(sceneid) {
        if (!this.active || !sceneid || sceneid === this.sceneid) {
            return;
        }
        const old = this.sceneid;
        if (old) {
            this.record('exited', 'scene', old, old);
        }
        this.sceneid = sceneid;
        this.record('entered', 'scene', sceneid, sceneid);
    }

    /**
     * Set the channel later statements report (builtin-3d, builtin-list, builtin-vr, builtin-ar, builtin-mr).
     *
     * @param {string} channel Channel name.
     */
    setChannel(channel) {
        if (channel) {
            this.channel = String(channel);
        }
    }

    /**
     * When the attempt's time runs out, in browser-clock milliseconds.
     *
     * @returns {number|null} null when the attempt has no time limit (and always in a preview).
     */
    getDeadline() {
        const timedue = this.serverstate ? Number(this.serverstate.timedue) || 0 : 0;
        if (this.preview || timedue <= 0) {
            return null;
        }
        return timedue * 1000 - this.clockoffset;
    }

    /**
     * Whole seconds left until the deadline.
     *
     * @returns {number|null} null when there is no deadline; never below 0.
     */
    getTimeLeft() {
        const deadline = this.getDeadline();
        if (deadline === null) {
            return null;
        }
        return Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    }

    /**
     * Start the deadline timer when a view runs and the attempt has a deadline.
     */
    startTimer() {
        if (this.timer || this.timeupsent || !this.active || this.closed || this.getDeadline() === null) {
            return;
        }
        this.timer = window.setInterval(() => this.checkTime(), TIMER_INTERVAL);
        this.checkTime();
    }

    /**
     * Stop the deadline timer.
     */
    stopTimer() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    /**
     * Emit timeup once when the time left reaches 0.
     */
    checkTime() {
        if (this.timeupsent || this.closed) {
            this.stopTimer();
            return;
        }
        const left = this.getTimeLeft();
        if (left === null || left > 0) {
            return;
        }
        this.timeupsent = true;
        this.stopTimer();
        this.emit({type: 'timeup'});
    }

    /**
     * Stop a view: exited (scene) then terminated.
     */
    stop() {
        if (!this.active) {
            return;
        }
        this.record('exited', 'scene', this.sceneid, this.sceneid);
        this.record('terminated', 'activity', null, null);
        this.active = false;
        this.stopResume();
        this.stopTimer();
        if (this.queue) {
            this.queue.flush();
        }
    }

    /**
     * The visit objective of a zone.
     *
     * @param {string} zoneid Zone id.
     * @returns {Object|null}
     */
    getObjectiveForZone(zoneid) {
        return this.view.objectives.find((o) => o.type === 'visit_zone' && o.zone === zoneid) || null;
    }

    /**
     * The answer_question objective of a slot.
     *
     * @param {number|string} slot Slot number.
     * @returns {Object|null}
     */
    getObjectiveForSlot(slot) {
        const n = Number(slot);
        return this.view.objectives.find((o) => o.type === 'answer_question' && o.slot === n) || null;
    }

    /**
     * Whether entering the zone would be refused (its objective is still locked).
     *
     * @param {string} zoneid Zone id.
     * @returns {boolean}
     */
    isZoneLocked(zoneid) {
        const event = normaliseEvent({verb: 'entered', type: 'zone', id: zoneid, scene: this.sceneid});
        return classify(this.graph, this.allEvents(), event) === STATUS.LOCKED;
    }

    /**
     * Whether interacting with an object would be refused.
     *
     * @param {string} objectid Interactable id.
     * @returns {boolean}
     */
    isObjectLocked(objectid) {
        const event = normaliseEvent({verb: 'interacted', type: 'object', id: objectid, scene: this.sceneid});
        return classify(this.graph, this.allEvents(), event) === STATUS.LOCKED;
    }

    /**
     * Whether a question slot can be shown now.
     *
     * @param {number|string} slot Slot number.
     * @returns {boolean}
     */
    isSlotAvailable(slot) {
        if (this.preview) {
            return false;
        }
        const objective = this.getObjectiveForSlot(slot);
        if (objective) {
            return objective.status !== 'locked';
        }
        const question = (this.config.questions || []).find((q) => Number(q.slot) === Number(slot));
        return !!(question && question.available);
    }

    /**
     * Whether a slot's question has been answered.
     *
     * @param {number|string} slot Slot number.
     * @returns {boolean}
     */
    isSlotAnswered(slot) {
        const objective = this.getObjectiveForSlot(slot);
        return !!(objective && objective.status === 'completed');
    }

    /**
     * Fetch a question's HTML. Queued statements are sent first so the server has seen the visit
     * that unlocks the question.
     *
     * @param {number} slot Slot number.
     * @returns {Promise<Object>} {slot, html, javascript, answered}
     */
    getQuestion(slot) {
        return this.flush().then(() => call('mod_mavr_get_question', {
            attemptid: this.config.attemptid,
            slot: Number(slot),
        }));
    }

    /**
     * Submit a question form.
     *
     * @param {number} slot Slot number.
     * @param {HTMLFormElement} form The question form.
     * @returns {Promise<Object>} the web service result plus `answered` and `retry`.
     */
    submitQuestion(slot, form) {
        const responses = [];
        new FormData(form).forEach((value, name) => {
            if (typeof value === 'string') {
                responses.push({name: name, value: value});
            }
        });
        return this.submitResponses(slot, responses);
    }

    /**
     * Submit responses to a question (field names as the question engine posts them).
     *
     * A graded answer is added to the local events with the fraction the server returned; when
     * it falls short of the objective's pass fraction the server has restarted the question
     * (`retry` true) and the local evaluator keeps the objective available, as the server does.
     *
     * @param {number} slot Slot number.
     * @param {Object[]} responses [{name, value}] with string values.
     * @returns {Promise<Object>} the web service result plus `answered` and `retry`.
     */
    submitResponses(slot, responses) {
        const list = (Array.isArray(responses) ? responses : [])
            .filter((r) => r && typeof r.name === 'string')
            .map((r) => ({name: r.name, value: r.value === undefined || r.value === null ? '' : String(r.value)}));
        return this.flush()
            .then(() => call('mod_mavr_submit_question', {
                attemptid: this.config.attemptid,
                slot: Number(slot),
                responses: list,
            }))
            .then((result) => {
                const answered = result.fraction !== null && result.fraction !== undefined;
                if (answered) {
                    this.confirmed.push(normaliseEvent({
                        verb: 'answered', type: 'question', id: String(slot), scene: null, fraction: result.fraction,
                    }));
                }
                if (result.state) {
                    this.setServerState(result.state, this.pendingevents.length === 0);
                }
                this.update(true);
                return Object.assign({}, result, {answered: answered, retry: !!result.retry});
            });
    }

    /**
     * The hints of an objective released so far.
     *
     * @param {string} objectiveid Objective id.
     * @returns {Object|null} {total, used: [{id, text}]}, or null when the objective has no hints.
     */
    getHints(objectiveid) {
        const id = String(objectiveid);
        if (this.hints.has(id)) {
            const entry = this.hints.get(id);
            return {total: entry.total, used: entry.used.slice()};
        }
        const def = this.objectivedefs.find((o) => o && String(o.id) === id);
        const total = def && Array.isArray(def.hints) ? def.hints.length : 0;
        return total > 0 ? {total: total, used: []} : null;
    }

    /**
     * Ask the server for the next hint of an objective (it costs the hint's penalty).
     *
     * Queued statements are sent first, so the server has seen what unlocked the objective.
     *
     * @param {string} objectiveid Objective id.
     * @returns {Promise<Object>} {hintid, text, hintsused, hintstotal}; text is plain text (use textContent).
     */
    requestHint(objectiveid) {
        if (this.preview || this.closed || !this.config.attemptid) {
            return Promise.reject(new Error('Hints are only available in an attempt.'));
        }
        const id = String(objectiveid);
        return this.flush()
            .then(() => call('mod_mavr_request_hint', {attemptid: this.config.attemptid, objectiveid: id}))
            .then((result) => {
                const hintid = String(result.hintid);
                this.confirmed.push(normaliseEvent({
                    verb: 'requested', type: 'hint', id: hintid, scene: null, fraction: null,
                }));
                const entry = this.getHints(id) || {total: Number(result.hintstotal) || 0, used: []};
                if (!entry.used.some((h) => h.id === hintid)) {
                    entry.used.push({id: hintid, text: String(result.text || '')});
                }
                entry.total = Number(result.hintstotal) || entry.total;
                this.hints.set(id, entry);
                if (result.state) {
                    this.setServerState(result.state, this.pendingevents.length === 0);
                }
                this.update(true);
                return {
                    hintid: hintid,
                    text: String(result.text || ''),
                    hintsused: Number(result.hintsused) || entry.used.length,
                    hintstotal: entry.total,
                };
            });
    }

    /**
     * Load the saved position (3D only).
     *
     * @returns {Promise<Object|null>} {scene, position, yaw} or null.
     */
    loadResume() {
        if (this.preview || !this.config.resume || !this.config.attemptid) {
            return Promise.resolve(null);
        }
        return call('core_xapi_get_state', {
            component: 'mod_mavr',
            activityId: this.config.resume.activityid,
            agent: JSON.stringify(this.config.actor),
            stateId: this.config.resume.stateid,
        }).then((json) => {
            if (!json) {
                return null;
            }
            const data = typeof json === 'string' ? JSON.parse(json) : json;
            if (!data || typeof data.scene !== 'string' || !Array.isArray(data.position) || data.position.length !== 3) {
                return null;
            }
            const position = data.position.map(Number);
            if (position.some((v) => !Number.isFinite(v))) {
                return null;
            }
            return {scene: data.scene, position: position, yaw: Number(data.yaw) || 0};
        }).catch(() => null);
    }

    /**
     * Save the position every 10 s while a 3D view runs.
     *
     * @param {Function} getter Returns {scene, position, yaw}.
     */
    startResume(getter) {
        this.stopResume();
        if (this.preview || !this.config.resume || !this.config.attemptid) {
            return;
        }
        this.resumetimer = window.setInterval(() => {
            if (this.closed || document.visibilityState === 'hidden') {
                return;
            }
            const data = getter();
            if (!data) {
                return;
            }
            call('core_xapi_post_state', {
                component: 'mod_mavr',
                activityId: this.config.resume.activityid,
                agent: JSON.stringify(this.config.actor),
                stateId: this.config.resume.stateid,
                stateData: JSON.stringify(data),
            }).catch(() => null);
        }, RESUME_INTERVAL);
    }

    /**
     * Stop saving the position.
     */
    stopResume() {
        if (this.resumetimer) {
            window.clearInterval(this.resumetimer);
            this.resumetimer = null;
        }
    }

    /**
     * Finish the attempt: stop, send everything, then ask the server to finish.
     *
     * @returns {Promise<Object>} {attemptid, state, score, redirecturl}
     */
    finish() {
        if (this.preview || !this.config.attemptid) {
            return Promise.resolve({redirecturl: this.config.finishurl});
        }
        this.stop();
        return this.flush()
            .then(() => call('mod_mavr_finish_attempt', {attemptid: this.config.attemptid}))
            .then((result) => {
                this.closed = true;
                this.destroy();
                return result;
            });
    }

    /**
     * Release timers and listeners.
     */
    destroy() {
        this.stopResume();
        this.stopTimer();
        window.removeEventListener('pagehide', this.onPageHide);
        if (this.queue) {
            this.queue.stop();
        }
    }
}

/**
 * Create a session for a launch config.
 *
 * @param {Object} config Launch config.
 * @returns {Session}
 */
export const createSession = (config) => new Session(config);
