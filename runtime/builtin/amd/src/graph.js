// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Client evaluator and validator for mavr-graph/1 task graphs.
 *
 * Twin of \mod_mavr\local\graph\evaluator and \mod_mavr\local\graph\graph_validator (PHP):
 * same semantics (contract §4.4.1, normative), same validation messages in the same order.
 * The golden fixtures in mod/mavr/tests/fixtures/graph are run against both; any change here
 * must be made there too.
 *
 * Dependency-free ES module (no imports) so that both grunt (AMD build) and node --test load it.
 *
 * @module     mavrruntime_builtin/graph
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Event statuses. */
export const STATUS = Object.freeze({
    APPLIED: 'applied',
    IGNORED: 'ignored',
    LOCKED: 'locked',
});

/** Objective statuses. */
export const OBJECTIVE = Object.freeze({
    LOCKED: 'locked',
    AVAILABLE: 'available',
    COMPLETED: 'completed',
});

/** Most rule triggers processed for one event; the rest are dropped. */
export const TRIGGER_CAP = 100;

const SCHEMA = 'mavr-graph/1';
const ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
const OBJECTIVE_TYPES = ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'];
const TRIGGERS = ['start', 'event', 'objective_completed', 'objective_unlocked', 'all_required_completed'];
const ACTIONS = ['show', 'hide', 'unlock', 'finish', 'teleport', 'play_media', 'open_question'];
const VERBS = ['launched', 'initialized', 'terminated', 'entered', 'exited', 'interacted', 'answered',
    'completed', 'passed', 'failed', 'requested'];
const OBJECT_TYPES = ['activity', 'scene', 'zone', 'object', 'question', 'objective', 'hint'];
const DIGITS_PATTERN = /^[0-9]+$/;

/** Longest time limit in seconds (seven days). */
export const MAX_TIMELIMIT = 604800;

/** Most hints on one objective. */
export const MAX_HINTS = 5;

/** Longest hint text, in Unicode code points. */
export const MAX_HINT_TEXT = 1000;

/**
 * Round half-up to 7 decimal places (PHP: round($x, 7)).
 *
 * @param {number} value
 * @returns {number}
 */
export const round7 = (value) => Math.round(value * 1e7) / 1e7;

/**
 * Round half-up to 5 decimal places (PHP: round($x, 5)).
 *
 * @param {number} value
 * @returns {number}
 */
export const round5 = (value) => Math.round(value * 1e5) / 1e5;

/**
 * Whether a value is a JSON number.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isNum = (value) => typeof value === 'number';

/**
 * Whether a value is a plain (non-array, non-null) object.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Values of an array if it is one, else an empty list.
 *
 * @param {*} value
 * @returns {Array}
 */
const asList = (value) => (Array.isArray(value) ? value.slice() : []);

/**
 * String form of a scalar id (null and non-scalars become '').
 *
 * @param {*} value
 * @returns {string}
 */
const str = (value) => {
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number') {
        return String(value);
    }
    return '';
};

/**
 * String form of a question slot number (truncated to an integer); '' when not a number.
 *
 * @param {*} slot
 * @returns {string}
 */
const slotString = (slot) => (isNum(slot) ? String(Math.trunc(slot)) : '');

/**
 * Normalise one event to {verb, type, id, scene, fraction} with string ids.
 *
 * @param {Object} event raw event
 * @returns {Object}
 */
const normaliseEvent = (event) => {
    const e = isObject(event) ? event : {};
    const scene = e.scene === undefined ? null : e.scene;
    return {
        verb: str(e.verb),
        type: str(e.type),
        id: str(e.id),
        scene: scene === null ? null : str(scene),
        fraction: isNum(e.fraction) ? e.fraction : null,
    };
};

/**
 * Normalise one objective, applying the §4.4 defaults.
 *
 * @param {Object} raw objective from the graph
 * @returns {Object}
 */
const normaliseObjective = (raw) => {
    const unlock = isObject(raw.unlock) ? raw.unlock : {};
    const scoreAtLeast = unlock.score_at_least;
    const hints = [];
    const penalties = new Map();
    asList(raw.hints).forEach((hint) => {
        if (!isObject(hint) || !has(hint, 'id')) {
            return;
        }
        const hintId = str(hint.id);
        if (penalties.has(hintId)) {
            return;
        }
        hints.push(hintId);
        penalties.set(hintId, isNum(hint.penalty) ? hint.penalty : 0);
    });
    return {
        id: str(raw.id),
        type: str(raw.type),
        points: isNum(raw.points) ? raw.points : 0,
        required: raw.required === true,
        after: asList(unlock.after).map(str),
        scoreAtLeast: isNum(scoreAtLeast) ? scoreAtLeast : null,
        zone: str(raw.zone),
        target: str(raw.target),
        slot: slotString(raw.slot),
        targets: asList(raw.targets).map(str),
        count: isNum(raw.count) ? Math.trunc(raw.count) : 0,
        steps: asList(raw.steps).map(str),
        hints,
        penalties,
        passFraction: isNum(raw.pass_fraction) ? raw.pass_fraction : null,
    };
};

/**
 * Whether an event is a hint request (verb requested, object type hint).
 *
 * @param {Object} event normalised event
 * @returns {boolean}
 */
const isHintEvent = (event) => event.verb === 'requested' && event.type === 'hint';

/**
 * Normalise a graph into objectives, an id index, rules and the initial hidden list.
 *
 * @param {Object} graph decoded, validated mavr-graph/1
 * @returns {Object}
 */
const compile = (graph) => {
    const g = isObject(graph) ? graph : {};
    const objectives = [];
    const index = new Map();
    asList(g.objectives).forEach((raw) => {
        if (!isObject(raw) || raw.id === undefined || raw.id === null) {
            return;
        }
        const objective = normaliseObjective(raw);
        if (index.has(objective.id)) {
            return;
        }
        index.set(objective.id, objectives.length);
        objectives.push(objective);
    });
    const rules = [];
    asList(g.rules).forEach((raw) => {
        if (!isObject(raw)) {
            return;
        }
        rules.push({
            id: str(raw.id),
            on: isObject(raw.on) ? raw.on : {},
            once: raw.once !== false,
            actions: asList(raw.actions).filter(isObject),
        });
    });
    const hidden = asList(g.hidden).map(str);
    return {objectives, index, rules, hidden};
};

/**
 * One evaluation run over a compiled graph (mirrors the PHP evaluator's run state).
 */
class Run {
    /**
     * Initialise the run state (§4.4.1 Initialisation).
     *
     * @param {Object} compiled result of compile()
     */
    constructor(compiled) {
        this.objectives = compiled.objectives;
        this.index = compiled.index;
        this.rules = compiled.rules;
        this.status = this.objectives.map(() => OBJECTIVE.LOCKED);
        this.earned = this.objectives.map(() => 0);
        this.collected = this.objectives.map(() => new Set());
        this.pointer = this.objectives.map(() => 0);
        this.hintsUsed = this.objectives.map(() => new Set());
        this.penalty = this.objectives.map(() => 0);
        this.hidden = new Set(compiled.hidden);
        this.finished = false;
        this.effects = [];
        this.allReqFired = false;
        this.fired = this.rules.map(() => false);
        this.queue = [];
        this.eventStatus = [];

        this.unlockPass(false);
        this.queue.push({on: 'start'});
        this.processQueue(-1);
    }

    /**
     * Process one event (§4.4.1 Process, steps 1-6).
     *
     * @param {number} i event index
     * @param {Object} event normalised event
     */
    process(i, event) {
        const matches = this.matching(event);
        const status = this.classifyMatches(matches);
        this.eventStatus.push(status);
        if (status === STATUS.LOCKED) {
            return;
        }
        this.queue = [];
        this.queue.push({on: 'event', event});

        if (status === STATUS.APPLIED) {
            const hintEvent = isHintEvent(event);
            matches.forEach((pos) => {
                if (this.status[pos] !== OBJECTIVE.AVAILABLE) {
                    return;
                }
                const objective = this.objectives[pos];
                if (hintEvent) {
                    // A hint only costs points; it never completes anything.
                    if (!this.hintsUsed[pos].has(event.id)) {
                        this.hintsUsed[pos].add(event.id);
                        const cost = objective.penalties.has(event.id) ? objective.penalties.get(event.id) : 0;
                        this.penalty[pos] = round7(this.penalty[pos] + cost);
                    }
                    return;
                }
                switch (objective.type) {
                    case 'visit_zone':
                    case 'interact':
                        this.complete(pos, objective.points);
                        break;
                    case 'answer_question': {
                        const fraction = Math.max(0, Math.min(1, event.fraction === null ? 0 : event.fraction));
                        if (objective.passFraction !== null && fraction < objective.passFraction - 1e-9) {
                            // Not good enough: the objective stays available for another try.
                            break;
                        }
                        this.complete(pos, round7(objective.points * fraction));
                        break;
                    }
                    case 'collect':
                        this.collected[pos].add(event.id);
                        if (this.collected[pos].size >= objective.count) {
                            this.complete(pos, objective.points);
                        }
                        break;
                }
            });
        }

        this.unlockPass(true);

        if (!this.allReqFired && this.allRequiredCompleted()) {
            this.allReqFired = true;
            this.queue.push({on: 'all_required_completed'});
        }

        this.processQueue(i);
    }

    /**
     * Positions of the objectives the event matches, in graph order (§4.4.1 Matching).
     *
     * @param {Object} event normalised event
     * @returns {number[]}
     */
    matching(event) {
        const matches = [];
        this.objectives.forEach((objective, pos) => {
            let match = false;
            switch (objective.type) {
                case 'visit_zone':
                    match = (event.verb === 'entered' || event.verb === 'interacted')
                        && event.type === 'zone' && event.id === objective.zone;
                    break;
                case 'interact':
                    match = event.verb === 'interacted' && event.type === 'object'
                        && event.id === objective.target;
                    break;
                case 'answer_question':
                    match = event.verb === 'answered' && event.type === 'question'
                        && event.id === objective.slot;
                    break;
                case 'collect':
                    match = event.verb === 'interacted' && event.type === 'object'
                        && objective.targets.includes(event.id);
                    break;
            }
            if (!match && objective.type !== 'sequence' && isHintEvent(event)) {
                match = objective.hints.includes(event.id);
            }
            if (match) {
                matches.push(pos);
            }
        });
        return matches;
    }

    /**
     * Classify a set of matching objectives against the current state (§4.4.1 Classify).
     *
     * @param {number[]} matches objective positions
     * @returns {string} a STATUS value
     */
    classifyMatches(matches) {
        if (matches.length === 0) {
            return STATUS.IGNORED;
        }
        for (const pos of matches) {
            if (this.status[pos] === OBJECTIVE.AVAILABLE || this.status[pos] === OBJECTIVE.COMPLETED) {
                return STATUS.APPLIED;
            }
        }
        return STATUS.LOCKED;
    }

    /**
     * Complete an objective, then advance sequences that wait for it (depth-first).
     *
     * The points earned are the base points less the hint penalty, never below zero.
     *
     * @param {number} pos objective position
     * @param {number} base points earned before hint penalties
     */
    complete(pos, base) {
        this.status[pos] = OBJECTIVE.COMPLETED;
        this.earned[pos] = Math.max(0, round7(base - this.penalty[pos]));
        const id = this.objectives[pos].id;
        this.queue.push({on: 'objective_completed', objective: id});
        this.objectives.forEach((sequence, spos) => {
            if (sequence.type !== 'sequence' || this.status[spos] !== OBJECTIVE.AVAILABLE) {
                return;
            }
            const steps = sequence.steps;
            if (steps[this.pointer[spos]] !== id) {
                return;
            }
            this.pointer[spos]++;
            if (this.pointer[spos] === steps.length) {
                this.complete(spos, sequence.points);
            }
        });
    }

    /**
     * Sum of earned points of completed objectives, rounded to 7 places.
     *
     * @returns {number}
     */
    earnedPoints() {
        let sum = 0;
        this.objectives.forEach((objective, pos) => {
            if (this.status[pos] === OBJECTIVE.COMPLETED) {
                sum += this.earned[pos];
            }
        });
        return round7(sum);
    }

    /**
     * Whether an objective's unlock conditions are met (§4.4.1 Conditions met).
     *
     * @param {Object} objective normalised objective
     * @param {number} earnedPoints current earned points
     * @returns {boolean}
     */
    conditionsMet(objective, earnedPoints) {
        for (const id of objective.after) {
            if (!this.index.has(id) || this.status[this.index.get(id)] !== OBJECTIVE.COMPLETED) {
                return false;
            }
        }
        return objective.scoreAtLeast === null || earnedPoints >= objective.scoreAtLeast;
    }

    /**
     * Unlock pass: make locked objectives with met conditions available until nothing changes.
     *
     * @param {boolean} triggers whether to enqueue objective_unlocked triggers
     */
    unlockPass(triggers) {
        let changed;
        do {
            changed = false;
            const earnedPoints = this.earnedPoints();
            this.objectives.forEach((objective, pos) => {
                if (this.status[pos] !== OBJECTIVE.LOCKED || !this.conditionsMet(objective, earnedPoints)) {
                    return;
                }
                this.status[pos] = OBJECTIVE.AVAILABLE;
                changed = true;
                if (triggers) {
                    this.queue.push({on: 'objective_unlocked', objective: objective.id});
                }
            });
        } while (changed);
    }

    /**
     * Counts of required objectives.
     *
     * @returns {{done: number, total: number}}
     */
    requiredCounts() {
        let done = 0;
        let total = 0;
        this.objectives.forEach((objective, pos) => {
            if (objective.required) {
                total++;
                if (this.status[pos] === OBJECTIVE.COMPLETED) {
                    done++;
                }
            }
        });
        return {done, total};
    }

    /**
     * Whether there is at least one required objective and all are completed.
     *
     * @returns {boolean}
     */
    allRequiredCompleted() {
        const {done, total} = this.requiredCounts();
        return total > 0 && done === total;
    }

    /**
     * Process the trigger queue FIFO, at most TRIGGER_CAP triggers; the rest are dropped.
     *
     * @param {number} i index of the event being processed (-1 during initialisation)
     */
    processQueue(i) {
        let processed = 0;
        while (this.queue.length > 0 && processed < TRIGGER_CAP) {
            const trigger = this.queue.shift();
            processed++;
            this.rules.forEach((rule, rpos) => {
                if (!triggerMatches(trigger, rule.on)) {
                    return;
                }
                if (rule.once && this.fired[rpos]) {
                    return;
                }
                this.fired[rpos] = true;
                rule.actions.forEach((action) => this.runAction(i, rule.id, action));
            });
        }
        this.queue = [];
    }

    /**
     * Run one rule action.
     *
     * @param {number} i event index
     * @param {string} ruleId rule id
     * @param {Object} action action definition
     */
    runAction(i, ruleId, action) {
        const what = str(action.do);
        switch (what) {
            case 'show':
                this.hidden.delete(str(action.target));
                break;
            case 'hide':
                this.hidden.add(str(action.target));
                break;
            case 'unlock': {
                const id = str(action.objective);
                if (this.index.has(id) && this.status[this.index.get(id)] === OBJECTIVE.LOCKED) {
                    this.status[this.index.get(id)] = OBJECTIVE.AVAILABLE;
                    this.queue.push({on: 'objective_unlocked', objective: id});
                }
                break;
            }
            case 'finish':
                this.finished = true;
                break;
            case 'teleport':
            case 'play_media':
                this.effects.push({event: i, rule: ruleId, do: what, target: str(action.target)});
                break;
            case 'open_question':
                this.effects.push({event: i, rule: ruleId, do: what, target: slotString(action.slot)});
                break;
        }
    }

    /**
     * Build the result from the current run state (§4.4.1 Outputs).
     *
     * @returns {Object} the shape of evaluation_result::to_array()
     */
    result() {
        const objectives = {};
        let maxPoints = 0;
        this.objectives.forEach((objective, pos) => {
            maxPoints += objective.points;
            objectives[objective.id] = {
                status: this.status[pos],
                earned: this.status[pos] === OBJECTIVE.COMPLETED ? this.earned[pos] : 0,
                points: objective.points,
                required: objective.required,
                hintsused: this.hintsUsed[pos].size,
            };
        });
        maxPoints = round7(maxPoints);
        const earnedPoints = this.earnedPoints();
        const {done, total} = this.requiredCounts();
        let score = 0;
        if (maxPoints > 0) {
            score = round5(earnedPoints / maxPoints);
        } else if (total > 0) {
            score = round5(done / total);
        }
        const hidden = Array.from(this.hidden);
        hidden.sort((a, b) => (a < b ? -1 : (a > b ? 1 : 0)));
        return {
            eventstatus: this.eventStatus.slice(),
            objectives,
            earnedpoints: earnedPoints,
            maxpoints: maxPoints,
            score,
            requireddone: done,
            requiredtotal: total,
            finished: this.finished,
            allrequiredcompleted: total > 0 && done === total,
            hidden,
            effects: this.effects.slice(),
        };
    }
}

/**
 * Whether a trigger matches a rule's "on" definition.
 *
 * @param {Object} trigger queued trigger
 * @param {Object} on rule trigger definition
 * @returns {boolean}
 */
const triggerMatches = (trigger, on) => {
    const kind = on.on;
    if (kind !== trigger.on) {
        return false;
    }
    switch (kind) {
        case 'event':
            for (const field of ['verb', 'type', 'id']) {
                if (on[field] !== undefined && on[field] !== null && str(on[field]) !== trigger.event[field]) {
                    return false;
                }
            }
            return true;
        case 'objective_completed':
        case 'objective_unlocked':
            return str(on.objective) === trigger.objective;
        case 'start':
        case 'all_required_completed':
            return true;
    }
    return false;
};

/**
 * Run a graph over a list of events.
 *
 * @param {Object} graph decoded mavr-graph/1
 * @param {Object[]} events §4.4.1 events in order
 * @returns {Run}
 */
const runEvents = (graph, events) => {
    const run = new Run(compile(graph));
    asList(events).forEach((event, i) => run.process(i, normaliseEvent(event)));
    return run;
};

/**
 * Evaluate a graph against an ordered event list.
 *
 * @param {Object} graph decoded, validated mavr-graph/1
 * @param {Object[]} events §4.4.1 events {verb, type, id, scene, fraction} in order
 * @returns {Object} same shape as \mod_mavr\local\graph\evaluation_result::to_array()
 */
export const evaluate = (graph, events) => runEvents(graph, events).result();

/**
 * Status a candidate event would get after the given events (§4.4.1 Classify).
 *
 * @param {Object} graph decoded, validated mavr-graph/1
 * @param {Object[]} events events already accepted, in order
 * @param {Object} event the candidate event
 * @returns {string} a STATUS value
 */
export const classify = (graph, events, event) => {
    const run = runEvents(graph, events);
    return run.classifyMatches(run.matching(normaliseEvent(event)));
};

/**
 * Whether a value is a valid id string.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isId = (value) => typeof value === 'string' && ID_PATTERN.test(value);

/**
 * Whether a value is a list of distinct valid ids.
 *
 * @param {*} value
 * @param {boolean} allowEmpty whether an empty list is accepted
 * @returns {boolean}
 */
const isIdList = (value, allowEmpty) => {
    if (!Array.isArray(value) || (!allowEmpty && value.length === 0)) {
        return false;
    }
    const seen = new Set();
    for (const id of value) {
        if (!isId(id) || seen.has(id)) {
            return false;
        }
        seen.add(id);
    }
    return true;
};

/**
 * Whether a value is a finite JSON number.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isFiniteNumber = (value) => isNum(value) && Number.isFinite(value);

/**
 * Whether an object has an own property.
 *
 * @param {Object} object
 * @param {string} key
 * @returns {boolean}
 */
const has = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

/**
 * Validate an objective's hints list.
 *
 * @param {*} hints the objective's hints value
 * @param {*} type the objective's type value
 * @param {string} where path for messages
 * @param {Set} hintIds hint ids over all objectives seen so far, added to
 * @param {string[]} errors errors, appended to
 */
const validateHints = (hints, type, where, hintIds, errors) => {
    if (!Array.isArray(hints) || hints.length > MAX_HINTS) {
        errors.push(`${where}.hints must be an array of at most ${MAX_HINTS} hints`);
        return;
    }
    if (type === 'sequence' && hints.length > 0) {
        errors.push(`${where}.hints are not allowed on a sequence`);
        return;
    }
    hints.forEach((hint, j) => {
        if (!isObject(hint)) {
            errors.push(`${where}.hints[${j}] must be an object`);
            return;
        }
        const hintId = hint.id;
        if (!isId(hintId)) {
            errors.push(`${where}.hints[${j}].id is not a valid id`);
        } else if (hintIds.has(hintId)) {
            errors.push(`duplicate hint id "${hintId}"`);
        } else {
            hintIds.add(hintId);
        }
        const text = hint.text;
        if (typeof text !== 'string' || text === '' || [...text].length > MAX_HINT_TEXT) {
            errors.push(`${where}.hints[${j}].text must be a non-empty string of at most ${MAX_HINT_TEXT} characters`);
        }
        if (has(hint, 'penalty') && (!isFiniteNumber(hint.penalty) || hint.penalty < 0)) {
            errors.push(`${where}.hints[${j}].penalty must be a number >= 0`);
        }
    });
};

/**
 * Validate a rule trigger.
 *
 * @param {*} on trigger definition
 * @param {string} where path for messages
 * @param {Map} types objective id => type
 * @param {string[]} errors errors, appended to
 */
const validateTrigger = (on, where, types, errors) => {
    if (!isObject(on)) {
        errors.push(`${where} must be an object`);
        return;
    }
    const kind = on.on;
    if (typeof kind !== 'string' || !TRIGGERS.includes(kind)) {
        errors.push(`${where}.on is an unknown trigger`);
        return;
    }
    switch (kind) {
        case 'event':
            if (has(on, 'verb') && on.verb !== null && (typeof on.verb !== 'string' || !VERBS.includes(on.verb))) {
                errors.push(`${where}.verb is unknown`);
            }
            if (has(on, 'type') && on.type !== null
                    && (typeof on.type !== 'string' || !OBJECT_TYPES.includes(on.type))) {
                errors.push(`${where}.type is unknown`);
            }
            if (has(on, 'id') && on.id !== null && !isId(on.id)) {
                errors.push(`${where}.id is not a valid id`);
            }
            break;
        case 'objective_completed':
        case 'objective_unlocked': {
            const ref = on.objective;
            if (!isId(ref)) {
                errors.push(`${where}.objective is not a valid id`);
            } else if (!types.has(ref)) {
                errors.push(`${where}.objective refers to unknown objective "${ref}"`);
            }
            break;
        }
    }
};

/**
 * Validate a rule action.
 *
 * @param {*} action action definition
 * @param {string} where path for messages
 * @param {Map} types objective id => type
 * @param {Set} slots question slots in use
 * @param {string[]} errors errors, appended to
 */
const validateAction = (action, where, types, slots, errors) => {
    if (!isObject(action)) {
        errors.push(`${where} must be an object`);
        return;
    }
    const what = action.do;
    if (typeof what !== 'string' || !ACTIONS.includes(what)) {
        errors.push(`${where}.do is an unknown action`);
        return;
    }
    switch (what) {
        case 'show':
        case 'hide':
        case 'teleport':
        case 'play_media':
            if (!isId(action.target)) {
                errors.push(`${where}.target is not a valid id`);
            }
            break;
        case 'unlock': {
            const ref = action.objective;
            if (!isId(ref)) {
                errors.push(`${where}.objective is not a valid id`);
            } else if (!types.has(ref)) {
                errors.push(`${where}.objective refers to unknown objective "${ref}"`);
            }
            break;
        }
        case 'open_question': {
            const slot = action.slot;
            if (!Number.isInteger(slot) || slot < 1) {
                errors.push(`${where}.slot must be an integer >= 1`);
            } else if (!slots.has(slot)) {
                errors.push(`${where}.slot ${slot} has no answer_question objective`);
            }
            break;
        }
    }
};

/**
 * Depth-first search for cycles in the unlock.after graph; one error per back edge.
 *
 * @param {string} node objective id
 * @param {Map} after objective id => ids it waits for
 * @param {Map} state objective id => 1 (on the stack) or 2 (done)
 * @param {string[]} errors errors, appended to
 */
const findCycles = (node, after, state, errors) => {
    if (state.has(node)) {
        return;
    }
    state.set(node, 1);
    (after.get(node) || []).forEach((next) => {
        const seen = state.get(next) || 0;
        if (seen === 1) {
            errors.push(`unlock.after cycle: "${node}" waits for "${next}"`);
        } else if (seen === 0) {
            findCycles(next, after, state, errors);
        }
    });
    state.set(node, 2);
};

/**
 * Validate a decoded graph (same checks, order and messages as the PHP graph_validator).
 *
 * @param {Object} graph decoded mavr-graph/1 document
 * @returns {string[]} developer-facing English errors; [] = valid
 */
export const validate = (graph) => {
    const errors = [];
    if (!isObject(graph)) {
        return [`schema must be "${SCHEMA}"`, 'objectives must be an array'];
    }

    if (graph.schema !== SCHEMA) {
        errors.push(`schema must be "${SCHEMA}"`);
    }

    if (has(graph, 'hidden')) {
        if (!Array.isArray(graph.hidden)) {
            errors.push('hidden must be an array');
        } else {
            graph.hidden.forEach((id, k) => {
                if (!isId(id)) {
                    errors.push(`hidden[${k}] is not a valid id`);
                }
            });
        }
    }

    if (has(graph, 'timelimit')) {
        const timelimit = graph.timelimit;
        if (!Number.isInteger(timelimit) || timelimit < 0 || timelimit > MAX_TIMELIMIT) {
            errors.push(`timelimit must be an integer from 0 to ${MAX_TIMELIMIT}`);
        }
    }

    let objectives = [];
    if (!Array.isArray(graph.objectives)) {
        errors.push('objectives must be an array');
    } else {
        objectives = graph.objectives;
    }

    let rules = [];
    if (has(graph, 'rules')) {
        if (!Array.isArray(graph.rules)) {
            errors.push('rules must be an array');
        } else {
            rules = graph.rules;
        }
    }

    // Pass 1: objective ids, types and fields.
    const types = new Map();
    const slots = new Set();
    const hintIds = new Set();
    objectives.forEach((objective, k) => {
        const where = `objectives[${k}]`;
        if (!isObject(objective)) {
            errors.push(`${where} must be an object`);
            return;
        }
        const id = objective.id;
        if (!isId(id)) {
            errors.push(`${where}.id is not a valid id`);
        } else if (DIGITS_PATTERN.test(id)) {
            // An all-digit id would turn the objectives map into a JSON list in PHP.
            errors.push(`${where}.id must not be all digits`);
        } else if (types.has(id)) {
            errors.push(`duplicate objective id "${id}"`);
        } else {
            types.set(id, typeof objective.type === 'string' ? objective.type : '');
        }
        const type = objective.type;
        if (typeof type !== 'string' || !OBJECTIVE_TYPES.includes(type)) {
            errors.push(`${where}.type is unknown`);
        }
        if (typeof objective.title !== 'string') {
            errors.push(`${where}.title must be a string`);
        }
        if (has(objective, 'points') && (!isFiniteNumber(objective.points) || objective.points < 0)) {
            errors.push(`${where}.points must be a number >= 0`);
        }
        if (has(objective, 'required') && typeof objective.required !== 'boolean') {
            errors.push(`${where}.required must be true or false`);
        }
        if (has(objective, 'unlock')) {
            const unlock = objective.unlock;
            if (!isObject(unlock)) {
                errors.push(`${where}.unlock must be an object`);
            } else {
                if (has(unlock, 'after') && !isIdList(unlock.after, true)) {
                    errors.push(`${where}.unlock.after must be an array of ids`);
                }
                if (has(unlock, 'score_at_least') && unlock.score_at_least !== null
                        && !isFiniteNumber(unlock.score_at_least)) {
                    errors.push(`${where}.unlock.score_at_least must be a number or null`);
                }
            }
        }
        if (has(objective, 'hints')) {
            validateHints(objective.hints, type, where, hintIds, errors);
        }
        if (has(objective, 'pass_fraction') && objective.pass_fraction !== null) {
            const passFraction = objective.pass_fraction;
            if (type !== 'answer_question') {
                errors.push(`${where}.pass_fraction is only allowed on answer_question`);
            } else if (!isFiniteNumber(passFraction) || passFraction <= 0 || passFraction > 1) {
                errors.push(`${where}.pass_fraction must be a number greater than 0 and at most 1`);
            }
        }
        switch (type) {
            case 'visit_zone':
                if (!isId(objective.zone)) {
                    errors.push(`${where}.zone is not a valid id`);
                }
                if (has(objective, 'scene') && objective.scene !== null && !isId(objective.scene)) {
                    errors.push(`${where}.scene is not a valid id`);
                }
                break;
            case 'interact':
                if (!isId(objective.target)) {
                    errors.push(`${where}.target is not a valid id`);
                }
                break;
            case 'answer_question': {
                const slot = objective.slot;
                if (!Number.isInteger(slot) || slot < 1) {
                    errors.push(`${where}.slot must be an integer >= 1`);
                } else if (slots.has(slot)) {
                    errors.push(`duplicate question slot ${slot}`);
                } else {
                    slots.add(slot);
                }
                break;
            }
            case 'collect': {
                const targets = objective.targets;
                if (!isIdList(targets, false)) {
                    errors.push(`${where}.targets must be a non-empty array of distinct ids`);
                    break;
                }
                const count = objective.count;
                if (!Number.isInteger(count) || count < 1 || count > targets.length) {
                    errors.push(`${where}.count must be an integer from 1 to the number of targets`);
                }
                break;
            }
            case 'sequence':
                if (!isIdList(objective.steps, false)) {
                    errors.push(`${where}.steps must be a non-empty array of distinct ids`);
                }
                break;
        }
    });

    // Pass 2: objective references.
    const after = new Map();
    objectives.forEach((objective, k) => {
        if (!isObject(objective) || !isId(objective.id)) {
            return;
        }
        const where = `objectives[${k}]`;
        const id = objective.id;
        const edges = [];
        const unlock = objective.unlock;
        if (isObject(unlock) && isIdList(unlock.after === undefined ? [] : unlock.after, true)) {
            asList(unlock.after).forEach((ref) => {
                if (!types.has(ref)) {
                    errors.push(`${where}.unlock.after refers to unknown objective "${ref}"`);
                } else {
                    edges.push(ref);
                }
            });
        }
        if (!after.has(id)) {
            after.set(id, edges);
        }
        if (objective.type === 'sequence' && isIdList(objective.steps, false)) {
            objective.steps.forEach((ref) => {
                if (ref === id) {
                    errors.push(`${where}.steps cannot contain the sequence itself`);
                } else if (!types.has(ref)) {
                    errors.push(`${where}.steps refers to unknown objective "${ref}"`);
                } else if (types.get(ref) === 'sequence') {
                    errors.push(`${where}.steps cannot contain another sequence "${ref}"`);
                }
            });
        }
    });

    // Pass 3: no cycle in the unlock.after graph (depth-first search in graph order).
    const state = new Map();
    after.forEach((edges, start) => findCycles(start, after, state, errors));

    // Rules.
    const ruleIds = new Set();
    rules.forEach((rule, k) => {
        const where = `rules[${k}]`;
        if (!isObject(rule)) {
            errors.push(`${where} must be an object`);
            return;
        }
        const id = rule.id;
        if (!isId(id)) {
            errors.push(`${where}.id is not a valid id`);
        } else if (ruleIds.has(id)) {
            errors.push(`duplicate rule id "${id}"`);
        } else {
            ruleIds.add(id);
        }
        if (has(rule, 'once') && typeof rule.once !== 'boolean') {
            errors.push(`${where}.once must be true or false`);
        }
        validateTrigger(rule.on, `${where}.on`, types, errors);
        const actions = rule.actions;
        if (!Array.isArray(actions) || actions.length === 0) {
            errors.push(`${where}.actions must be a non-empty array`);
            return;
        }
        actions.forEach((action, a) => validateAction(action, `${where}.actions[${a}]`, types, slots, errors));
    });

    return errors;
};
