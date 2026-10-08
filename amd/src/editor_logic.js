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
 * The logic editor on the Tasks tab of the room editor: settings, objectives (with hints and
 * question-bank questions) and rules of the activity's mavr-graph/1 task graph.
 *
 * It edits the shared editor document only through model.transact(), so the room editor's undo
 * history covers it, and it keeps the question bindings in step with the answer_question
 * objectives. Every select lists names from the document; only titles and hint texts are free
 * text. The server validates everything again on save.
 *
 * @module     mod_mavr/editor_logic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import {validate} from 'mavrruntime_builtin/graph';

/** Objective types, in menu order. */
const TYPES = ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'];

/** Rule triggers, in menu order. */
const TRIGGERS = ['start', 'event', 'objective_completed', 'objective_unlocked', 'all_required_completed'];

/** Rule actions, in menu order. */
const ACTIONS = ['show', 'hide', 'unlock', 'finish', 'teleport', 'play_media', 'open_question'];

/** Verbs an event trigger can name (the derived completed/passed/failed are never evaluated). */
const EVENT_VERBS = ['entered', 'exited', 'interacted', 'answered', 'requested', 'initialized', 'terminated', 'launched'];

/** Object types an event trigger can name. */
const EVENT_TYPES = ['zone', 'object', 'scene', 'question', 'hint', 'activity'];

/** Most hints on one objective. */
const MAX_HINTS = 5;

/** Longest time limit, in minutes (seven days). */
const MAX_TIMELIMIT_MINUTES = 10080;

/** Most problems listed at the top of the panel. */
const MAX_PROBLEMS = 10;

/**
 * Replace {$a} in a raw language string.
 *
 * @param {string} raw The string as loaded without a parameter.
 * @param {string|number} value The value.
 * @returns {string}
 */
const fill = (raw, value) => String(raw).split('{$a}').join(String(value));

/**
 * Replace {$a->name} placeholders in a raw language string.
 *
 * @param {string} raw The string as loaded without a parameter.
 * @param {Object} values name => value.
 * @returns {string}
 */
const fillAll = (raw, values) => Object.keys(values).reduce(
    (text, key) => text.split(`{$a->${key}}`).join(String(values[key])), String(raw));

/**
 * Whether a value is a plain object.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A list, or [] for anything else.
 *
 * @param {*} value
 * @returns {Array}
 */
const list = (value) => (Array.isArray(value) ? value : []);

/**
 * A finite number from a form value, or the fallback.
 *
 * @param {string} value Field value.
 * @param {number} fallback Returned for an empty or invalid value.
 * @returns {number}
 */
const numberFrom = (value, fallback) => {
    if (value === '' || value === null || value === undefined) {
        return fallback;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
};

/**
 * Round to 7 decimals (as the evaluators do).
 *
 * @param {number} value
 * @returns {number}
 */
const round7 = (value) => Math.round(value * 1e7) / 1e7;

/**
 * The max mark of a question binding for an objective's points (the gallery rule).
 *
 * @param {*} points Objective points.
 * @returns {number}
 */
const maxmarkFor = (points) => {
    const n = Number(points);
    return Number.isFinite(n) && n > 0 ? n : 1;
};

/**
 * Make sure a draft document has a graph with the lists the editor writes to.
 *
 * @param {Object} draft The draft document.
 * @returns {Object} the draft graph
 */
const ensureGraph = (draft) => {
    if (!isObject(draft.graph)) {
        draft.graph = {schema: 'mavr-graph/1'};
    }
    const graph = draft.graph;
    graph.schema = graph.schema || 'mavr-graph/1';
    ['hidden', 'objectives', 'rules'].forEach((key) => {
        if (!Array.isArray(graph[key])) {
            graph[key] = [];
        }
    });
    if (!Array.isArray(draft.bindings)) {
        draft.bindings = [];
    }
    return graph;
};

/**
 * Every question slot in use (answer_question objectives and bindings).
 *
 * @param {Object} doc The document.
 * @returns {number[]}
 */
const usedSlots = (doc) => {
    const slots = [];
    list(doc.graph && doc.graph.objectives).forEach((o) => {
        if (isObject(o) && o.type === 'answer_question' && Number.isInteger(o.slot)) {
            slots.push(o.slot);
        }
    });
    list(doc.bindings).forEach((b) => {
        if (isObject(b) && Number.isInteger(b.slot)) {
            slots.push(b.slot);
        }
    });
    return slots;
};

/**
 * Remove everything that refers to a question slot: its binding, open_question actions (and
 * rules left without actions) and hotspot questions in every room.
 *
 * @param {Object} draft The draft document.
 * @param {number} slot The slot.
 */
const removeSlot = (draft, slot) => {
    const graph = ensureGraph(draft);
    draft.bindings = draft.bindings.filter((b) => !(isObject(b) && b.slot === slot));
    graph.rules = graph.rules.filter((rule) => {
        if (!isObject(rule)) {
            return true;
        }
        const actions = list(rule.actions);
        const kept = actions.filter((a) => !(isObject(a) && a.do === 'open_question' && Number(a.slot) === slot));
        rule.actions = kept;
        return kept.length > 0 || actions.length === 0;
    });
    list(draft.scenes).forEach((scene) => {
        list(scene && scene.interactables).forEach((item) => {
            if (isObject(item) && item.kind === 'hotspot' && isObject(item.content)
                    && item.content.question !== null && Number(item.content.question) === slot) {
                item.content.question = null;
            }
        });
    });
};

/**
 * Remove every reference to an objective: unlock.after entries, sequence steps, unlock actions
 * and rules triggered by it.
 *
 * @param {Object} graph The draft graph.
 * @param {string} id Objective id.
 */
const removeObjectiveRefs = (graph, id) => {
    graph.objectives.forEach((o) => {
        if (!isObject(o)) {
            return;
        }
        if (isObject(o.unlock) && Array.isArray(o.unlock.after)) {
            o.unlock.after = o.unlock.after.filter((ref) => ref !== id);
            if (o.unlock.after.length === 0) {
                delete o.unlock.after;
            }
            if (Object.keys(o.unlock).length === 0) {
                delete o.unlock;
            }
        }
        if (Array.isArray(o.steps)) {
            o.steps = o.steps.filter((ref) => ref !== id);
        }
    });
    graph.rules = graph.rules.filter((rule) => {
        if (!isObject(rule)) {
            return true;
        }
        const on = isObject(rule.on) ? rule.on : {};
        if ((on.on === 'objective_completed' || on.on === 'objective_unlocked') && on.objective === id) {
            return false;
        }
        const actions = list(rule.actions);
        const kept = actions.filter((a) => !(isObject(a) && a.do === 'unlock' && a.objective === id));
        rule.actions = kept;
        return kept.length > 0 || actions.length === 0;
    });
};

/**
 * Ids of the objectives that wait for an objective, directly or through others (unlock.after).
 *
 * @param {Object[]} objectives Graph objectives.
 * @param {string} id Objective id.
 * @returns {Set<string>}
 */
const dependentsOf = (objectives, id) => {
    const waitsfor = new Map();
    objectives.forEach((o) => {
        if (isObject(o) && typeof o.id === 'string') {
            waitsfor.set(o.id, isObject(o.unlock) ? list(o.unlock.after) : []);
        }
    });
    const found = new Set();
    let changed = true;
    while (changed) {
        changed = false;
        for (const [candidate, after] of waitsfor) {
            if (found.has(candidate) || candidate === id) {
                continue;
            }
            if (after.some((ref) => ref === id || found.has(ref))) {
                found.add(candidate);
                changed = true;
            }
        }
    }
    return found;
};

/**
 * Render the panel and wire its events, once the strings are loaded.
 *
 * @param {HTMLElement} container The data-region="mavr-editor-logic" element.
 * @param {Object} model The editor model (mod_mavr/editor_model).
 * @param {Object} str Strings by key.
 * @param {Object} options {questions, maxhints, uid, pending}
 * @param {Object[]} options.questions [{id, label, available}]
 * @param {number} options.maxhints Most hints per objective.
 * @param {string} options.uid Prefix for element ids.
 * @param {Object} options.pending The init Pending, resolved once the panel is drawn.
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
const build = (container, model, str, {questions, maxhints, uid, pending}) => {
    let destroyed = false;
    let sequence = 0;

    // Names from the document --------------------------------------------------------------

    const scenesOf = (doc) => list(doc.scenes).filter(isObject);
    const roomLabel = (doc, scene, i) => (scenesOf(doc).length > 1 ? fill(str.logicroom, scene.name || i + 1) : '');
    const objectiveLabel = (o, i) => (o.title && String(o.title).trim() !== ''
        ? String(o.title) : fill(str.logicobjective, i + 1));

    /**
     * Option groups (one per room) of the things a picker lists.
     *
     * @param {Object} doc The document.
     * @param {Function} pick (scene) => [{value, label}]
     * @param {Set<string>|string|null} selected Selected value(s).
     * @param {boolean} placeholder Whether a "None" option comes first (single selects).
     * @returns {Object[]}
     */
    const groups = (doc, pick, selected, placeholder) => {
        const isSelected = (value) => (selected instanceof Set ? selected.has(value) : selected === value);
        const result = [];
        const known = new Set();
        scenesOf(doc).forEach((scene, i) => {
            const options = pick(scene).map((o) => {
                known.add(o.value);
                return {value: o.value, label: o.label, selected: isSelected(o.value)};
            });
            if (options.length > 0) {
                result.push({label: roomLabel(doc, scene, i), options: options});
            }
        });
        const extra = [];
        if (placeholder) {
            extra.push({value: '', label: str.logicnone, selected: !selected});
        }
        // A reference to something that no longer exists stays visible (the save reports it).
        let missing = selected ? [selected] : [];
        if (selected instanceof Set) {
            missing = Array.from(selected);
        }
        missing.filter((value) => !known.has(value)).forEach((value) => {
            extra.push({value: value, label: value, selected: true});
        });
        if (extra.length > 0) {
            result.unshift({label: '', options: extra});
        }
        return result;
    };

    const zonesOf = (scene) => list(scene.zones).filter(isObject)
        .map((z) => ({value: String(z.id), label: String(z.name || z.id)}));
    const interactablesOf = (scene) => list(scene.interactables).filter(isObject)
        .map((item) => ({value: String(item.id), label: String(item.name || item.id)}));
    const hideablesOf = (scene) => interactablesOf(scene).concat(list(scene.props).filter(isObject)
        .map((p) => ({value: String(p.id), label: String(p.name || p.id)})));
    const mediaOf = (scene) => list(scene.interactables).filter((i) => isObject(i) && i.kind === 'media')
        .map((item) => ({value: String(item.id), label: String(item.name || item.id)}));
    const spawnsOf = (scene) => list(scene.spawn).filter(isObject)
        .map((s) => ({value: String(s.id), label: String(s.name || s.id)}));
    const scenesAsOptions = (doc) => scenesOf(doc).map((s, i) => ({value: String(s.id), label: String(s.name || i + 1)}));

    const firstOf = (doc, pick) => {
        for (const scene of scenesOf(doc)) {
            const options = pick(scene);
            if (options.length > 0) {
                return options[0].value;
            }
        }
        return '';
    };

    const sceneOfZone = (doc, zoneid) => {
        const scene = scenesOf(doc).find((s) => list(s.zones).some((z) => isObject(z) && z.id === zoneid));
        return scene ? String(scene.id) : null;
    };

    const objectivesOf = (doc) => list(doc.graph && doc.graph.objectives).filter(isObject);

    const slotOptions = (doc, selected) => {
        const objectives = objectivesOf(doc);
        return objectives.filter((o) => o.type === 'answer_question' && Number.isInteger(o.slot))
            .map((o) => ({
                value: String(o.slot),
                label: fillAll(str.logicslotoption, {title: objectiveLabel(o, objectives.indexOf(o)), slot: o.slot}),
                selected: String(o.slot) === String(selected),
            }));
    };

    const hintOptions = (doc, selected) => {
        const options = [];
        objectivesOf(doc).forEach((o, i) => {
            list(o.hints).forEach((h, j) => {
                if (isObject(h) && typeof h.id === 'string') {
                    options.push({
                        value: h.id,
                        label: `${objectiveLabel(o, i)}: ${fill(str.logichint, j + 1)}`,
                        selected: h.id === selected,
                    });
                }
            });
        });
        return options;
    };

    // Template context ----------------------------------------------------------------------

    const bindingFor = (doc, slot) => list(doc.bindings).find((b) => isObject(b) && b.slot === slot) || null;

    const objectiveContext = (doc, o, index, count) => { // eslint-disable-line complexity
        const objectives = objectivesOf(doc);
        const type = String(o.type || '');
        const unlock = isObject(o.unlock) ? o.unlock : {};
        const after = new Set(list(unlock.after).map(String));
        const excluded = dependentsOf(objectives, String(o.id));
        excluded.add(String(o.id));
        const afteroptions = objectives.filter((other) => !excluded.has(String(other.id)))
            .map((other) => ({value: String(other.id), label: objectiveLabel(other, objectives.indexOf(other)),
                selected: after.has(String(other.id))}));
        list(unlock.after).map(String).filter((ref) => !objectives.some((other) => String(other.id) === ref))
            .forEach((ref) => afteroptions.push({value: ref, label: ref, selected: true}));
        const ctx = {
            uid: uid,
            index: index,
            heading: fill(str.logicobjective, index + 1),
            title: typeof o.title === 'string' ? o.title : '',
            typeoptions: TYPES.map((t) => ({value: t, label: str[`logictype_${t}`], selected: t === type})),
            points: Number.isFinite(Number(o.points)) ? Number(o.points) : 0,
            required: o.required === true,
            isfirst: index === 0,
            islast: index === count - 1,
            isvisit: type === 'visit_zone',
            isinteract: type === 'interact',
            iscollect: type === 'collect',
            isquestion: type === 'answer_question',
            issequence: type === 'sequence',
            aftergroups: afteroptions.length > 0 ? [{label: '', options: afteroptions}] : [],
            score: Number.isFinite(unlock.score_at_least) ? unlock.score_at_least : '',
            unlockalways: after.size === 0 && !Number.isFinite(unlock.score_at_least),
            canhints: type !== 'sequence',
            hints: [],
            canaddhint: false,
        };
        if (ctx.isvisit) {
            ctx.zonegroups = groups(doc, zonesOf, o.zone ? String(o.zone) : null, true);
        }
        if (ctx.isinteract) {
            ctx.targetgroups = groups(doc, interactablesOf, o.target ? String(o.target) : null, true);
        }
        if (ctx.iscollect) {
            const targets = list(o.targets).map(String);
            ctx.targetsgroups = groups(doc, interactablesOf, new Set(targets), false);
            ctx.count = Number.isInteger(o.count) ? o.count : 1;
            ctx.countmax = Math.max(1, targets.length);
        }
        if (ctx.isquestion) {
            const binding = Number.isInteger(o.slot) ? bindingFor(doc, o.slot) : null;
            const qbeid = binding ? Number(binding.questionbankentryid) || 0 : 0;
            ctx.questionoptions = [{value: '0', label: str.logicnone, selected: qbeid === 0}]
                .concat(questions.map((q) => ({
                    value: String(q.id), label: String(q.label), selected: Number(q.id) === qbeid,
                })));
            if (qbeid > 0 && !questions.some((q) => Number(q.id) === qbeid)) {
                ctx.questionoptions.push({value: String(qbeid), label: String(qbeid), selected: true});
            }
            ctx.passfraction = o.pass_fraction !== null && o.pass_fraction !== undefined;
        }
        if (ctx.issequence) {
            const candidates = objectives.filter((other) => other.type !== 'sequence' && other.id !== o.id);
            ctx.steps = list(o.steps).map((step, s) => {
                const options = candidates.map((other) => ({value: String(other.id),
                    label: objectiveLabel(other, objectives.indexOf(other)), selected: String(other.id) === String(step)}));
                if (!candidates.some((other) => String(other.id) === String(step))) {
                    options.unshift({value: String(step), label: String(step), selected: true});
                }
                return {index: s, objectiveindex: index, number: s + 1, options: options};
            });
            ctx.canaddstep = candidates.length > ctx.steps.length;
        }
        if (ctx.canhints) {
            ctx.hints = list(o.hints).filter(isObject).map((h, j) => ({
                index: j,
                objectiveindex: index,
                number: j + 1,
                text: typeof h.text === 'string' ? h.text : '',
                penalty: Number.isFinite(Number(h.penalty)) ? Number(h.penalty) : 0,
            }));
            ctx.canaddhint = ctx.hints.length < maxhints;
        }
        return ctx;
    };

    const actionContext = (doc, action, index, ruleindex) => {
        const what = isObject(action) ? String(action.do || '') : '';
        const ctx = {
            index: index,
            ruleindex: ruleindex,
            number: index + 1,
            uid: uid,
            dooptions: ACTIONS.map((a) => ({value: a, label: str[`logicaction_${a}`], selected: a === what})),
            hastarget: what !== 'finish' && what !== '',
            targetgroups: [],
        };
        switch (what) {
            case 'show':
            case 'hide':
                ctx.targetgroups = groups(doc, hideablesOf, action.target ? String(action.target) : null, true);
                break;
            case 'teleport':
                ctx.targetgroups = groups(doc, spawnsOf, action.target ? String(action.target) : null, true);
                break;
            case 'play_media':
                ctx.targetgroups = groups(doc, mediaOf, action.target ? String(action.target) : null, true);
                break;
            case 'unlock': {
                const objectives = objectivesOf(doc);
                const options = [{value: '', label: str.logicnone, selected: !action.objective}]
                    .concat(objectives.map((o, i) => ({value: String(o.id), label: objectiveLabel(o, i),
                        selected: String(o.id) === String(action.objective)})));
                ctx.targetgroups = [{label: '', options: options}];
                break;
            }
            case 'open_question': {
                const options = slotOptions(doc, action.slot);
                options.unshift({value: '', label: str.logicnone, selected: !options.some((o) => o.selected)});
                ctx.targetgroups = [{label: '', options: options}];
                break;
            }
        }
        return ctx;
    };

    const ruleContext = (doc, rule, index, count) => {
        const on = isObject(rule.on) ? rule.on : {};
        const kind = String(on.on || '');
        const ctx = {
            uid: uid,
            index: index,
            heading: fill(str.logicrule, index + 1),
            isfirst: index === 0,
            islast: index === count - 1,
            once: rule.once !== false,
            triggeroptions: TRIGGERS.map((t) => ({value: t, label: str[`logictrigger_${t}`], selected: t === kind})),
            isevent: kind === 'event',
            isobjectivetrigger: kind === 'objective_completed' || kind === 'objective_unlocked',
            actions: list(rule.actions).map((a, i) => actionContext(doc, a, i, index)),
        };
        if (ctx.isobjectivetrigger) {
            const objectives = objectivesOf(doc);
            ctx.objectiveoptions = [{value: '', label: str.logicnone, selected: !on.objective}]
                .concat(objectives.map((o, i) => ({value: String(o.id), label: objectiveLabel(o, i),
                    selected: String(o.id) === String(on.objective)})));
        }
        if (ctx.isevent) {
            const verb = on.verb ? String(on.verb) : '';
            const type = on.type ? String(on.type) : '';
            const id = on.id === null || on.id === undefined ? '' : String(on.id);
            ctx.verboptions = [{value: '', label: str.logicanything, selected: verb === ''}]
                .concat(EVENT_VERBS.map((v) => ({value: v, label: str[`verb_${v}`], selected: v === verb})));
            ctx.kindoptions = [{value: '', label: str.logicanything, selected: type === ''}]
                .concat(EVENT_TYPES.map((t) => ({value: t, label: str[`objecttype_${t}`], selected: t === type})));
            ctx.hasid = type !== '' && type !== 'activity';
            if (ctx.hasid) {
                const anything = {value: '', label: str.logicanything, selected: id === ''};
                let options = [];
                switch (type) {
                    case 'zone':
                        ctx.idgroups = groups(doc, zonesOf, id || null, false);
                        break;
                    case 'object':
                        ctx.idgroups = groups(doc, interactablesOf, id || null, false);
                        break;
                    case 'scene':
                        options = scenesAsOptions(doc).map((o) => Object.assign(o, {selected: o.value === id}));
                        break;
                    case 'question':
                        options = slotOptions(doc, id);
                        break;
                    case 'hint':
                        options = hintOptions(doc, id);
                        break;
                }
                if (!ctx.idgroups) {
                    ctx.idgroups = [{label: '', options: options}];
                }
                ctx.idgroups.unshift({label: '', options: [anything]});
            }
        }
        return ctx;
    };

    const problemsOf = (doc) => {
        const problems = [];
        const graph = isObject(doc.graph) ? doc.graph : {};
        validate(graph).slice(0, MAX_PROBLEMS).forEach((error) => {
            problems.push({text: fill(str.logicinvalid, error)});
        });
        objectivesOf(doc).forEach((o, i) => {
            if (o.type !== 'answer_question') {
                return;
            }
            const binding = Number.isInteger(o.slot) ? bindingFor(doc, o.slot) : null;
            if (!binding || !(Number(binding.questionbankentryid) > 0)) {
                problems.push({text: fill(str.logicnoquestion, objectiveLabel(o, i))});
            }
        });
        return problems;
    };

    const buildContext = (doc) => {
        const graph = isObject(doc.graph) ? doc.graph : {};
        const objectives = list(graph.objectives).filter(isObject);
        const rules = list(graph.rules).filter(isObject);
        const timelimit = Number.isFinite(graph.timelimit) ? graph.timelimit : 0;
        const problems = problemsOf(doc);
        return {
            uid: uid,
            hasproblems: problems.length > 0,
            problems: problems,
            timelimit: Math.round((timelimit / 60) * 100) / 100,
            hiddengroups: groups(doc, hideablesOf, new Set(list(graph.hidden).map(String)), false),
            hasobjectives: objectives.length > 0,
            objectives: objectives.map((o, i) => objectiveContext(doc, o, i, objectives.length)),
            hasrules: rules.length > 0,
            rules: rules.map((r, i) => ruleContext(doc, r, i, rules.length)),
        };
    };

    const render = () => {
        sequence++;
        const mine = sequence;
        const renderpending = new Pending('mod_mavr/editor_logic:render');
        const active = document.activeElement;
        const focusid = active && container.contains(active) ? active.id : '';
        return Templates.renderForPromise('mod_mavr/editor_logic', buildContext(model.getDocument()))
            .then(({html, js}) => {
                if (destroyed || mine !== sequence) {
                    return undefined;
                }
                Templates.replaceNodeContents(container, html, js);
                if (focusid) {
                    const target = document.getElementById(focusid);
                    if (target && container.contains(target)) {
                        target.focus({preventScroll: true});
                    }
                }
                return undefined;
            })
            .catch(Notification.exception)
            .then(() => renderpending.resolve());
    };

    // Edits -----------------------------------------------------------------------------------

    const edit = (key, what, mutate) => {
        model.transact(fill(str[key], what), (draft) => {
            mutate(draft, ensureGraph(draft));
        });
    };

    const objectiveName = (index) => {
        const o = objectivesOf(model.getDocument())[index];
        return o ? objectiveLabel(o, index) : fill(str.logicobjective, index + 1);
    };

    const newSlot = (draft) => {
        const slots = usedSlots(draft);
        return (slots.length > 0 ? Math.max(...slots) : 0) + 1;
    };

    const setType = (draft, o, type) => {
        const doc = draft;
        if (o.type === 'answer_question' && type !== 'answer_question' && Number.isInteger(o.slot)) {
            removeSlot(draft, o.slot);
        }
        ['zone', 'scene', 'target', 'targets', 'count', 'steps', 'slot', 'pass_fraction'].forEach((key) => {
            delete o[key];
        });
        o.type = type;
        switch (type) {
            case 'visit_zone':
                o.zone = firstOf(doc, zonesOf);
                break;
            case 'interact':
                o.target = firstOf(doc, interactablesOf);
                break;
            case 'collect':
                o.targets = [];
                o.count = 1;
                break;
            case 'sequence':
                o.steps = [];
                delete o.hints;
                break;
            case 'answer_question': {
                const slot = newSlot(draft);
                o.slot = slot;
                draft.bindings.push({slot: slot, questionbankentryid: 0, maxmark: maxmarkFor(o.points)});
                break;
            }
        }
    };

    const selectedValues = (select) => Array.from(select.selectedOptions).map((option) => option.value)
        .filter((value) => value !== '');

    const onObjectiveChange = (field, target, index) => {
        const name = objectiveName(index);
        edit('editorchange_edit', name, (draft, graph) => { // eslint-disable-line complexity
            const o = graph.objectives.filter(isObject)[index];
            if (!o) {
                return;
            }
            const value = target.value;
            switch (field) {
                case 'title':
                    o.title = value.trim();
                    break;
                case 'type':
                    if (TYPES.includes(value) && value !== o.type) {
                        setType(draft, o, value);
                    }
                    break;
                case 'zone':
                    o.zone = value;
                    if (Object.prototype.hasOwnProperty.call(o, 'scene')) {
                        o.scene = sceneOfZone(draft, value);
                    }
                    break;
                case 'target':
                    o.target = value;
                    break;
                case 'targets':
                    o.targets = selectedValues(target);
                    o.count = Math.min(Math.max(1, Number.isInteger(o.count) ? o.count : 1), Math.max(1, o.targets.length));
                    break;
                case 'count':
                    o.count = Math.max(1, Math.round(numberFrom(value, 1)));
                    break;
                case 'question': {
                    if (!Number.isInteger(o.slot)) {
                        o.slot = newSlot(draft);
                    }
                    const qbeid = Math.max(0, Math.round(numberFrom(value, 0)));
                    let binding = draft.bindings.find((b) => isObject(b) && b.slot === o.slot);
                    if (!binding) {
                        binding = {slot: o.slot, questionbankentryid: 0, maxmark: maxmarkFor(o.points)};
                        draft.bindings.push(binding);
                    }
                    binding.questionbankentryid = qbeid;
                    break;
                }
                case 'passfraction':
                    if (target.checked) {
                        o.pass_fraction = 1; // eslint-disable-line camelcase
                    } else {
                        delete o.pass_fraction;
                    }
                    break;
                case 'step': {
                    const step = Number(target.closest('[data-step-index]').dataset.stepIndex);
                    if (Array.isArray(o.steps) && step >= 0 && step < o.steps.length) {
                        o.steps[step] = value;
                    }
                    break;
                }
                case 'points':
                    o.points = round7(Math.max(0, numberFrom(value, 0)));
                    if (o.type === 'answer_question' && Number.isInteger(o.slot)) {
                        draft.bindings.filter((b) => isObject(b) && b.slot === o.slot).forEach((b) => {
                            b.maxmark = maxmarkFor(o.points);
                        });
                    }
                    break;
                case 'required':
                    o.required = target.checked;
                    break;
                case 'after': {
                    const unlock = isObject(o.unlock) ? o.unlock : {};
                    const after = selectedValues(target);
                    if (after.length > 0) {
                        unlock.after = after;
                    } else {
                        delete unlock.after;
                    }
                    o.unlock = unlock;
                    if (Object.keys(unlock).length === 0) {
                        delete o.unlock;
                    }
                    break;
                }
                case 'score': {
                    const unlock = isObject(o.unlock) ? o.unlock : {};
                    if (value === '') {
                        delete unlock.score_at_least;
                    } else {
                        unlock.score_at_least = round7(Math.max(0, numberFrom(value, 0))); // eslint-disable-line camelcase
                    }
                    o.unlock = unlock;
                    if (Object.keys(unlock).length === 0) {
                        delete o.unlock;
                    }
                    break;
                }
                case 'hinttext':
                case 'hintpenalty': {
                    const hint = Number(target.closest('[data-hint-index]').dataset.hintIndex);
                    const hints = list(o.hints);
                    if (!isObject(hints[hint])) {
                        break;
                    }
                    if (field === 'hinttext') {
                        hints[hint].text = value.trim();
                    } else {
                        hints[hint].penalty = round7(Math.max(0, numberFrom(value, 0)));
                    }
                    break;
                }
            }
        });
    };

    const onRuleChange = (field, target, index) => {
        edit('editorchange_edit', fill(str.logicrule, index + 1), (draft, graph) => { // eslint-disable-line complexity
            const rule = graph.rules.filter(isObject)[index];
            if (!rule) {
                return;
            }
            const value = target.value;
            const on = isObject(rule.on) ? rule.on : {};
            rule.on = on;
            switch (field) {
                case 'trigger':
                    if (!TRIGGERS.includes(value)) {
                        break;
                    }
                    rule.on = {on: value};
                    if (value === 'objective_completed' || value === 'objective_unlocked') {
                        const first = graph.objectives.find((o) => isObject(o) && typeof o.id === 'string');
                        rule.on.objective = first ? first.id : '';
                    }
                    break;
                case 'triggerobjective':
                    on.objective = value;
                    break;
                case 'verb':
                    if (value === '') {
                        delete on.verb;
                    } else {
                        on.verb = value;
                    }
                    break;
                case 'kind':
                    delete on.id;
                    if (value === '') {
                        delete on.type;
                    } else {
                        on.type = value;
                    }
                    break;
                case 'eventid':
                    if (value === '') {
                        delete on.id;
                    } else {
                        on.id = value;
                    }
                    break;
                case 'once':
                    rule.once = target.checked;
                    break;
                case 'do':
                case 'actiontarget': {
                    const a = Number(target.closest('[data-action-index]').dataset.actionIndex);
                    const actions = list(rule.actions);
                    if (a < 0 || a >= actions.length) {
                        break;
                    }
                    if (field === 'do') {
                        actions[a] = newAction(draft, value);
                        break;
                    }
                    const action = isObject(actions[a]) ? actions[a] : {};
                    if (action.do === 'unlock') {
                        action.objective = value;
                    } else if (action.do === 'open_question') {
                        action.slot = Math.round(numberFrom(value, 0));
                    } else {
                        action.target = value;
                    }
                    actions[a] = action;
                    break;
                }
            }
        });
    };

    const newAction = (doc, what) => {
        const action = {'do': ACTIONS.includes(what) ? what : 'show'};
        switch (action.do) {
            case 'show':
            case 'hide':
                action.target = firstOf(doc, hideablesOf);
                break;
            case 'teleport':
                action.target = firstOf(doc, spawnsOf);
                break;
            case 'play_media':
                action.target = firstOf(doc, mediaOf);
                break;
            case 'unlock': {
                const first = list(doc.graph && doc.graph.objectives).find((o) => isObject(o) && typeof o.id === 'string');
                action.objective = first ? first.id : '';
                break;
            }
            case 'open_question': {
                const first = list(doc.graph && doc.graph.objectives)
                    .find((o) => isObject(o) && o.type === 'answer_question' && Number.isInteger(o.slot));
                action.slot = first ? first.slot : 0;
                break;
            }
        }
        return action;
    };

    const onChange = (e) => {
        const target = e.target;
        const field = target && target.dataset ? target.dataset.field : null;
        if (!field) {
            return;
        }
        const objectivecard = target.closest('[data-objective-index]');
        const rulecard = target.closest('[data-rule-index]');
        if (objectivecard) {
            onObjectiveChange(field, target, Number(objectivecard.dataset.objectiveIndex));
            return;
        }
        if (rulecard) {
            onRuleChange(field, target, Number(rulecard.dataset.ruleIndex));
            return;
        }
        if (field === 'timelimit') {
            const minutes = Math.min(MAX_TIMELIMIT_MINUTES, Math.max(0, numberFrom(target.value, 0)));
            edit('editorchange_edit', str.logicsettings, (draft, graph) => {
                const seconds = Math.round(minutes * 60);
                if (seconds > 0) {
                    graph.timelimit = seconds;
                } else {
                    delete graph.timelimit;
                }
            });
        } else if (field === 'hidden') {
            const hidden = selectedValues(target);
            edit('editorchange_edit', str.logicsettings, (draft, graph) => {
                graph.hidden = hidden;
            });
        }
    };

    const move = (items, index, delta) => {
        const other = index + delta;
        if (index < 0 || other < 0 || index >= items.length || other >= items.length) {
            return;
        }
        const item = items[index];
        items[index] = items[other];
        items[other] = item;
    };

    const onObjectiveClick = (action, index, button) => {
        const name = objectiveName(index);
        switch (action) {
            case 'mavr-logic-objective-up':
            case 'mavr-logic-objective-down':
                edit('editorchange_edit', name, (draft, graph) => {
                    graph.objectives = graph.objectives.filter(isObject);
                    move(graph.objectives, index, action === 'mavr-logic-objective-up' ? -1 : 1);
                });
                break;
            case 'mavr-logic-remove-objective':
                edit('editorchange_delete', name, (draft, graph) => {
                    graph.objectives = graph.objectives.filter(isObject);
                    const o = graph.objectives[index];
                    if (!o) {
                        return;
                    }
                    graph.objectives.splice(index, 1);
                    if (o.type === 'answer_question' && Number.isInteger(o.slot)) {
                        removeSlot(draft, o.slot);
                    }
                    if (typeof o.id === 'string') {
                        removeObjectiveRefs(graph, o.id);
                    }
                });
                break;
            case 'mavr-logic-add-hint':
                edit('editorchange_edit', name, (draft, graph) => {
                    const o = graph.objectives.filter(isObject)[index];
                    if (!o || o.type === 'sequence') {
                        return;
                    }
                    o.hints = list(o.hints);
                    if (o.hints.length < maxhints) {
                        o.hints.push({id: model.newId('hn'), text: '', penalty: 0});
                    }
                });
                break;
            case 'mavr-logic-remove-hint': {
                const hint = Number(button.closest('[data-hint-index]').dataset.hintIndex);
                edit('editorchange_edit', name, (draft, graph) => {
                    const o = graph.objectives.filter(isObject)[index];
                    if (!o || !Array.isArray(o.hints)) {
                        return;
                    }
                    const removed = o.hints.splice(hint, 1)[0];
                    if (o.hints.length === 0) {
                        delete o.hints;
                    }
                    // A rule waiting for that hint would never fire again.
                    if (isObject(removed) && typeof removed.id === 'string') {
                        graph.rules.forEach((rule) => {
                            if (isObject(rule) && isObject(rule.on) && rule.on.type === 'hint' && rule.on.id === removed.id) {
                                delete rule.on.id;
                            }
                        });
                    }
                });
                break;
            }
            case 'mavr-logic-add-step':
                edit('editorchange_edit', name, (draft, graph) => {
                    const o = graph.objectives.filter(isObject)[index];
                    if (!o || o.type !== 'sequence') {
                        return;
                    }
                    o.steps = list(o.steps);
                    const candidate = graph.objectives.find((other) => isObject(other) && other.type !== 'sequence'
                        && other.id !== o.id && !o.steps.includes(other.id));
                    if (candidate) {
                        o.steps.push(candidate.id);
                    }
                });
                break;
            case 'mavr-logic-remove-step': {
                const step = Number(button.closest('[data-step-index]').dataset.stepIndex);
                edit('editorchange_edit', name, (draft, graph) => {
                    const o = graph.objectives.filter(isObject)[index];
                    if (o && Array.isArray(o.steps)) {
                        o.steps.splice(step, 1);
                    }
                });
                break;
            }
        }
    };

    const onRuleClick = (action, index, button) => {
        const name = fill(str.logicrule, index + 1);
        switch (action) {
            case 'mavr-logic-rule-up':
            case 'mavr-logic-rule-down':
                edit('editorchange_edit', name, (draft, graph) => {
                    graph.rules = graph.rules.filter(isObject);
                    move(graph.rules, index, action === 'mavr-logic-rule-up' ? -1 : 1);
                });
                break;
            case 'mavr-logic-remove-rule':
                edit('editorchange_delete', name, (draft, graph) => {
                    graph.rules = graph.rules.filter(isObject);
                    graph.rules.splice(index, 1);
                });
                break;
            case 'mavr-logic-add-action':
                edit('editorchange_edit', name, (draft, graph) => {
                    const rule = graph.rules.filter(isObject)[index];
                    if (rule) {
                        rule.actions = list(rule.actions);
                        rule.actions.push(newAction(draft, 'show'));
                    }
                });
                break;
            case 'mavr-logic-remove-action': {
                const a = Number(button.closest('[data-action-index]').dataset.actionIndex);
                edit('editorchange_edit', name, (draft, graph) => {
                    const rule = graph.rules.filter(isObject)[index];
                    if (rule && Array.isArray(rule.actions)) {
                        rule.actions.splice(a, 1);
                    }
                });
                break;
            }
        }
    };

    const onClick = (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !container.contains(button) || button.disabled) {
            return;
        }
        const action = button.dataset.action;
        if (!action.startsWith('mavr-logic-')) {
            return;
        }
        e.preventDefault();
        const objectivecard = button.closest('[data-objective-index]');
        const rulecard = button.closest('[data-rule-index]');
        if (objectivecard) {
            onObjectiveClick(action, Number(objectivecard.dataset.objectiveIndex), button);
        } else if (rulecard) {
            onRuleClick(action, Number(rulecard.dataset.ruleIndex), button);
        } else if (action === 'mavr-logic-add-objective') {
            const number = objectivesOf(model.getDocument()).length + 1;
            const title = fill(str.logicobjective, number);
            edit('editorchange_add', title, (draft, graph) => {
                graph.objectives.push({
                    id: model.newId('o'),
                    type: 'visit_zone',
                    title: title,
                    zone: firstOf(draft, zonesOf),
                    points: 1,
                    required: true,
                });
            });
        } else if (action === 'mavr-logic-add-rule') {
            const number = list(model.getDocument().graph && model.getDocument().graph.rules).length + 1;
            edit('editorchange_add', fill(str.logicrule, number), (draft, graph) => {
                graph.rules.push({id: model.newId('r'), on: {on: 'start'}, once: true, actions: [newAction(draft, 'show')]});
            });
        }
    };

    container.addEventListener('change', onChange);
    container.addEventListener('click', onClick);
    const unsubscribe = model.on('change', () => {
        if (!destroyed) {
            render();
        }
    });

    return render().then(() => {
        pending.resolve();
        return {
            refresh: render,
            destroy: () => {
                destroyed = true;
                unsubscribe();
                container.removeEventListener('change', onChange);
                container.removeEventListener('click', onClick);
                container.replaceChildren();
            },
        };
    });
};

/**
 * Load the strings, render the panel and wire its events.
 *
 * @param {HTMLElement} container The data-region="mavr-editor-logic" element.
 * @param {Object} model The editor model (mod_mavr/editor_model).
 * @param {Object} context {questions: [{id, label, available}], presets, info}
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
const setup = (container, model, context) => {
    const questions = list(context && context.questions);
    const info = (context && context.info) || {};
    const maxhints = Math.min(MAX_HINTS, Number(info.maxhints) || MAX_HINTS);
    const uid = String(info.cmid || 'editor');

    const keys = [
        ...TYPES.map((t) => `logictype_${t}`),
        ...TRIGGERS.map((t) => `logictrigger_${t}`),
        ...ACTIONS.map((a) => `logicaction_${a}`),
        ...EVENT_VERBS.map((v) => `verb_${v}`),
        ...EVENT_TYPES.map((t) => `objecttype_${t}`),
        'logicanything', 'logicnone', 'logicobjective', 'logicrule', 'logichint', 'logicsettings',
        'logicinvalid', 'logicnoquestion', 'editorchange_add', 'editorchange_delete', 'editorchange_edit',
        'logicroom', 'logicslotoption',
    ];
    const pending = new Pending('mod_mavr/editor_logic:init');

    return getStrings(keys.map((key) => ({key: key, component: 'mod_mavr'}))).then((values) => {
        const str = {};
        keys.forEach((key, i) => {
            str[key] = values[i];
        });
        return build(container, model, str, {questions: questions, maxhints: maxhints, uid: uid, pending: pending});
    }).catch((error) => {
        pending.resolve();
        Notification.exception(error);
        throw error;
    });
};

/**
 * Create the logic editor in a container.
 *
 * The panel appears once its strings are loaded; refresh() before that waits for it.
 *
 * @param {HTMLElement} container The data-region="mavr-editor-logic" element.
 * @param {Object} model The editor model (mod_mavr/editor_model).
 * @param {Object} context {questions: [{id, label, available}], presets, info}
 * @returns {Object} {refresh() → Promise, destroy(), ready: Promise}
 */
export const init = (container, model, context) => {
    let handle = null;
    let destroyed = false;
    const ready = setup(container, model, context).then((created) => {
        if (destroyed) {
            created.destroy();
            return null;
        }
        handle = created;
        return created;
    }).catch(() => null);
    return {
        refresh: () => (handle ? handle.refresh() : ready.then(() => undefined)),
        destroy: () => {
            destroyed = true;
            if (handle) {
                handle.destroy();
                handle = null;
            }
        },
        ready: ready,
    };
};
