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
 * The room editor's inspector: a labelled field for every property of the selected object, so
 * that everything the 3D gizmo does can also be done with numbers and the keyboard.
 *
 * The hotspot authoring fields (title, text, position, linked question) follow mod_gear's hotspot
 * form; here they edit the mavr-scene/1 document through the shared model.
 *
 * @module     mod_mavr/editor_inspector
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getStrings, getString} from 'core/str';
import {findRef, findScene} from 'mod_mavr/editor_model';
import {assetsOfKind, findAsset, parseKey, refKey, toRef} from 'mod_mavr/editor_assets';

/** Lang strings the inspector uses, by key (component mod_mavr unless given). */
const STRINGKEYS = [
    'editorname', 'editorpositionx', 'editorpositiony', 'editorpositionz', 'editorrotationx', 'editorrotationy',
    'editorrotationz', 'editorscalex', 'editorscaley', 'editorscalez', 'editorcentrex', 'editorcentrey', 'editorcentrez',
    'editorsizex', 'editorsizey', 'editorsizez', 'editoryaw', 'editortitle', 'editortext', 'editortextformat',
    'editorquestion', 'editornoquestion', 'editorzone', 'editornozone', 'editoropens', 'editornothing', 'editorfile',
    'editornofile', 'editorfit', 'editorwidth', 'editorheight', 'editorshape', 'editorshape_box', 'editorshape_cylinder',
    'editorshape_sphere', 'editorcolour', 'editortargetscene', 'editortargetspawn', 'editormedia', 'editormedia_audio',
    'editormedia_video', 'editorloop', 'editorautoplay', 'editorenvironment', 'editorenvironmentpreset',
    'editorenvironmentmodel', 'editorsky', 'editorarscale', 'editorarscalehint', 'editorrenamescene',
    'editoraddhotspot', 'editoraddmodel', 'editoraddimage', 'editoraddtext', 'editoraddzone', 'editoraddspawn',
    'editoraddportal', 'editoraddgrabbable', 'editoraddmedia', 'editoraddprop', 'editorquestionunavailable',
];

/** Core text format strings. */
const FORMATKEYS = [['formattext', '0'], ['formathtml', '1'], ['formatplain', '2'], ['formatmarkdown', '4']];

/** Default light set of an uploaded environment model. */
const MODELLIGHTS = [
    {type: 'ambient', intensity: 0.8},
    {type: 'directional', intensity: 0.9, position: [4, 10, 6]},
];

/** Default walkable bounds of an uploaded environment model. */
const MODELBOUNDS = {min: [-9, 0, -9], max: [9, 0, 9]};

/**
 * Format a number for a field.
 *
 * @param {*} value Value.
 * @param {number} fallback Default.
 * @returns {string}
 */
const num = (value, fallback = 0) => {
    const n = Number(value);
    return String(Number.isFinite(n) ? Math.round(n * 1000) / 1000 : fallback);
};

/**
 * Read a value at a dot path.
 *
 * @param {Object} object Object.
 * @param {string} path Path such as 'position.0'.
 * @returns {*}
 */
const getPath = (object, path) => path.split('.').reduce((o, key) => (o === null || o === undefined ? undefined : o[key]),
    object);

/**
 * Write a value at a dot path, creating objects and 3-vectors on the way.
 *
 * @param {Object} object Object.
 * @param {string} path Path.
 * @param {*} value Value; undefined deletes the key.
 * @param {number[]} vectordefault Default of a missing vector.
 */
const setPath = (object, path, value, vectordefault = [0, 0, 0]) => {
    const keys = path.split('.');
    let target = object;
    for (let i = 0; i < keys.length - 1; i++) {
        const key = keys[i];
        const nextisindex = /^\d+$/.test(keys[i + 1]);
        if (target[key] === null || typeof target[key] !== 'object') {
            target[key] = nextisindex ? vectordefault.slice() : {};
        }
        target = target[key];
    }
    const last = keys[keys.length - 1];
    if (value === undefined) {
        delete target[last];
    } else {
        target[last] = value;
    }
};

/**
 * The display name of an object.
 *
 * @param {Object} object Scene object.
 * @returns {string}
 */
export const displayName = (object) => {
    if (!object) {
        return '';
    }
    if (typeof object.name === 'string' && object.name.trim() !== '') {
        return object.name;
    }
    if (object.content && typeof object.content.title === 'string' && object.content.title.trim() !== '') {
        return object.content.title;
    }
    return String(object.id || '');
};

/**
 * Start the inspector.
 *
 * @param {HTMLElement} container The inspector region.
 * @param {Object} model The editor model.
 * @param {Object} context {assets, presets, questions, info, actions: {deleteSelection, duplicateSelection, upload}}
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
export const init = (container, model, context) => {
    const keys = STRINGKEYS.map((key) => ({key: key, component: 'mod_mavr'}))
        .concat(FORMATKEYS.map(([key]) => ({key: key, component: 'core'})));
    return getStrings(keys).then((values) => {
        const str = {};
        keys.forEach((k, i) => {
            str[k.key] = values[i];
        });
        return start(container, model, context, str);
    });
};

/**
 * Run the inspector once its strings are loaded.
 *
 * @param {HTMLElement} container The inspector region.
 * @param {Object} model The editor model.
 * @param {Object} context Editor context.
 * @param {Object} str Strings by key.
 * @returns {Object} {refresh(), destroy()}
 */
const start = (container, model, context, str) => {
    let destroyed = false;
    let renderid = 0;

    const kindlabels = {
        environment: str.editorenvironment, spawn: str.editoraddspawn, zone: str.editoraddzone, prop: str.editoraddprop,
        hotspot: str.editoraddhotspot, model: str.editoraddmodel, image: str.editoraddimage, text: str.editoraddtext,
        portal: str.editoraddportal, grabbable: str.editoraddgrabbable, media: str.editoraddmedia,
    };

    /* Field builders. */
    const field = (id, label, path, type, extra = {}) => Object.assign({
        id: id, label: label, path: path, type: type,
        isnumber: type === 'number' || type === 'numbernull',
        istext: type === 'text',
        istextarea: type === 'textarea',
        isselect: ['select', 'selectnull', 'selectint', 'fileref', 'grabsource', 'envsource', 'targetscene'].includes(type),
        ischeckbox: type === 'checkbox',
        iscolour: type === 'colour',
    }, extra);
    const idOf = (path) => path.replace(/[^A-Za-z0-9]+/g, '-');
    const textField = (label, path, value) => field(idOf(path), label, path, 'text', {value: value === undefined ? '' : value});
    const numberField = (label, path, value, step = '0.1', extra = {}) => field(idOf(path), label, path, 'number',
        Object.assign({value: value, step: step}, extra));
    const vectorFields = (labels, path, vector, fallback, step = '0.1') => labels.map((label, i) => numberField(label,
        `${path}.${i}`, num(Array.isArray(vector) ? vector[i] : undefined, fallback[i]), step));
    const option = (value, label, selected) => ({value: value, label: label, selected: !!selected});
    const selectField = (label, path, type, options, extra = {}) => field(idOf(path), label, path, type,
        Object.assign({options: options}, extra));

    const POSITION = [str.editorpositionx, str.editorpositiony, str.editorpositionz];
    const ROTATION = [str.editorrotationx, str.editorrotationy, str.editorrotationz];
    const SCALE = [str.editorscalex, str.editorscaley, str.editorscalez];
    const CENTRE = [str.editorcentrex, str.editorcentrey, str.editorcentrez];
    const SIZE = [str.editorsizex, str.editorsizey, str.editorsizez];
    const shapeOptions = (value) => ['box', 'cylinder', 'sphere'].map((s) => option(s, str[`editorshape_${s}`], value === s));

    /**
     * A select of the files of a kind, with an Upload button.
     *
     * @param {string} label Label.
     * @param {string} path Path of the file reference.
     * @param {Object|null} current Current file reference.
     * @param {string} kind Asset kind.
     * @param {string[]} areas Allowed areas.
     * @param {string} type fileref or grabsource.
     * @param {string|null} nonelabel Label of the "no file" option, if allowed.
     * @returns {Object} Field.
     */
    const fileField = (label, path, current, kind, areas, type, nonelabel) => {
        const options = [];
        if (nonelabel !== null) {
            options.push(option('', nonelabel, !current));
        }
        const assets = assetsOfKind(context.assets, kind, areas);
        assets.forEach((asset) => options.push(option(refKey(asset), asset.filename, findAsset([asset], current))));
        if (current && !findAsset(assets, current)) {
            options.push(option(refKey(current), String(current.filename || ''), true));
        }
        if (nonelabel === null && !current) {
            options.unshift(option('', str.editornofile, true));
        }
        return selectField(label, path, type, options, {uploadkind: kind});
    };

    /**
     * The fields of the selected thing.
     *
     * @param {Object} doc Document.
     * @param {Object} ref Selection.
     * @returns {Object|null} {heading, fields, candelete, canduplicate}
     */
    const describe = (doc, ref) => { // eslint-disable-line complexity
        const scene = findScene(doc, ref.scene);
        const object = findRef(doc, ref);
        if (!scene || !object) {
            return null;
        }
        const fields = [];
        let kind = ref.kind;

        if (ref.kind === 'environment') {
            const env = scene.environment || {};
            fields.push(textField(str.editorrenamescene, 'name', scene.name));
            const presetoptions = Object.keys(context.presets).map((id) => option(`preset:${id}`,
                String(context.presets[id].name || id), env.type === 'preset' && env.preset === id));
            const models = assetsOfKind(context.assets, 'model', ['environment', 'assets']);
            const modeloptions = models.map((asset) => option(`file:${refKey(asset)}`, asset.filename,
                env.type === 'model' && findAsset([asset], env.file)));
            if (env.type === 'model' && env.file && !findAsset(models, env.file)) {
                modeloptions.push(option(`file:${refKey(env.file)}`, String(env.file.filename || ''), true));
            }
            const optgroups = [{label: str.editorenvironmentpreset, options: presetoptions}];
            if (modeloptions.length) {
                optgroups.push({label: str.editorenvironmentmodel, options: modeloptions});
            }
            fields.push(field('environment', str.editorenvironment, 'environment', 'envsource',
                {options: [], optgroups: optgroups, uploadkind: 'model'}));
            fields.push(field('environment-sky', str.editorsky, 'environment.sky', 'colour',
                {value: /^#[0-9a-fA-F]{6}$/.test(env.sky || '') ? env.sky.toLowerCase() : '#20242a'}));
            const arscale = scene.xr && scene.xr.arscale !== null && scene.xr.arscale !== undefined ? num(scene.xr.arscale) : '';
            fields.push(field('xr-arscale', str.editorarscale, 'xr.arscale', 'numbernull', {value: arscale, step: '0.01',
                min: '0.01', max: '1', hint: str.editorarscalehint, hintid: 'mavr-insp-xr-arscale-hint'}));
            return {heading: kindlabels.environment, fields: fields, candelete: false, canduplicate: false};
        }

        if (ref.kind === 'spawn') {
            fields.push(textField(str.editorname, 'name', object.name));
            fields.push(...vectorFields(POSITION, 'position', object.position, [0, 0, 0]));
            fields.push(numberField(str.editoryaw, 'yaw', num(object.yaw), '1'));
            const spawns = Array.isArray(scene.spawn) ? scene.spawn : [];
            return {heading: kindlabels.spawn, fields: fields, candelete: spawns.length > 1, canduplicate: true};
        }

        if (ref.kind === 'zone') {
            fields.push(textField(str.editorname, 'name', object.name));
            fields.push(...vectorFields(CENTRE, 'center', object.center, [0, 1.5, 0]));
            fields.push(...vectorFields(SIZE, 'size', object.size, [3, 3, 3]));
            return {heading: kindlabels.zone, fields: fields, candelete: true, canduplicate: true};
        }

        if (ref.kind === 'prop') {
            fields.push(textField(str.editorname, 'name', object.name));
            fields.push(selectField(str.editorshape, 'shape', 'select', shapeOptions(object.shape)));
            fields.push(...vectorFields(POSITION, 'position', object.position, [0, 0, 0]));
            fields.push(...vectorFields(ROTATION, 'rotation', object.rotation, [0, 0, 0], '1'));
            fields.push(...vectorFields(SIZE, 'size', object.size, [1, 1, 1]));
            fields.push(field('color', str.editorcolour, 'color', 'colour',
                {value: /^#[0-9a-fA-F]{6}$/.test(object.color || '') ? object.color.toLowerCase() : '#d9d4cc'}));
            return {heading: kindlabels.prop, fields: fields, candelete: true, canduplicate: true};
        }

        // Interactables.
        kind = object.kind;
        const zones = Array.isArray(scene.zones) ? scene.zones : [];
        const items = Array.isArray(scene.interactables) ? scene.interactables : [];
        fields.push(textField(str.editorname, 'name', object.name));
        fields.push(...vectorFields(POSITION, 'position', object.position, [0, 0, 0]));
        if (kind !== 'hotspot') {
            fields.push(...vectorFields(ROTATION, 'rotation', object.rotation, [0, 0, 0], '1'));
        }
        fields.push(selectField(str.editorzone, 'zone', 'selectnull', [option('', str.editornozone, !object.zone)]
            .concat(zones.map((z) => option(z.id, displayName(z), object.zone === z.id)))));

        const opensField = () => selectField(str.editoropens, 'opens', 'selectnull',
            [option('', str.editornothing, !object.opens)].concat(items
                .filter((i) => i.kind === 'hotspot' && i.id !== object.id)
                .map((i) => option(i.id, displayName(i), object.opens === i.id))));

        if (kind === 'hotspot') {
            const content = object.content || {};
            fields.push(textField(str.editortitle, 'content.title', content.title));
            fields.push(field('content-text', str.editortext, 'content.text', 'textarea', {value: content.text || ''}));
            const format = String(content.textformat === undefined ? '1' : content.textformat);
            fields.push(selectField(str.editortextformat, 'content.textformat', 'select',
                FORMATKEYS.map(([key, value]) => option(value, str[key], format === value))));
            const graph = doc.graph || {};
            const questions = (Array.isArray(graph.objectives) ? graph.objectives : [])
                .filter((o) => o && o.type === 'answer_question');
            const qoptions = [option('', str.editornoquestion, !content.question)]
                .concat(questions.map((o) => option(String(o.slot), `${o.title || o.id} (${o.slot})`,
                    Number(content.question) === Number(o.slot))));
            if (content.question && !questions.some((o) => Number(o.slot) === Number(content.question))) {
                qoptions.push(option(String(content.question), str.editorquestionunavailable, true));
            }
            fields.push(selectField(str.editorquestion, 'content.question', 'selectint', qoptions));
        } else if (kind === 'model') {
            fields.push(fileField(str.editorfile, 'file', object.file || null, 'model', ['models', 'assets'], 'fileref', null));
            fields.push(numberField(str.editorfit, 'fit', num(object.fit, 1), '0.1', {min: '0.01'}));
            fields.push(...vectorFields(SCALE, 'scale', object.scale, [1, 1, 1]));
            fields.push(opensField());
        } else if (kind === 'image') {
            fields.push(fileField(str.editorfile, 'file', object.file || null, 'image', ['media', 'assets'], 'fileref', null));
            fields.push(numberField(str.editorwidth, 'width', num(object.width, 1.6), '0.1', {min: '0.01'}));
            fields.push(opensField());
        } else if (kind === 'text') {
            fields.push(field('text', str.editortext, 'text', 'textarea', {value: object.text || ''}));
            fields.push(numberField(str.editorwidth, 'width', num(object.width, 2), '0.1', {min: '0.01'}));
        } else if (kind === 'portal') {
            const target = object.target || {};
            fields.push(numberField(str.editorwidth, 'width', num(object.width, 1.2), '0.1', {min: '0.01'}));
            fields.push(numberField(str.editorheight, 'height', num(object.height, 2.2), '0.1', {min: '0.01'}));
            fields.push(selectField(str.editortargetscene, 'target.scene', 'targetscene',
                (doc.scenes || []).map((s) => option(s.id, displayName(s), target.scene === s.id))));
            const targetscene = findScene(doc, target.scene);
            const spawns = targetscene && Array.isArray(targetscene.spawn) ? targetscene.spawn : [];
            const soptions = spawns.map((s) => option(s.id, displayName(s), target.spawn === s.id));
            if (!spawns.some((s) => s.id === target.spawn)) {
                soptions.unshift(option('', str.editornothing, true));
            }
            fields.push(selectField(str.editortargetspawn, 'target.spawn', 'select', soptions));
        } else if (kind === 'grabbable') {
            fields.push(fileField(str.editorfile, 'file', object.file || null, 'model', ['models', 'assets'], 'grabsource',
                str.editornofile));
            if (object.file) {
                fields.push(numberField(str.editorfit, 'fit', num(object.fit, 0.3), '0.1', {min: '0.01'}));
            } else {
                fields.push(selectField(str.editorshape, 'shape', 'select', shapeOptions(object.shape)));
                fields.push(...vectorFields(SIZE, 'size', object.size, [0.3, 0.3, 0.3]));
                fields.push(field('color', str.editorcolour, 'color', 'colour',
                    {value: /^#[0-9a-fA-F]{6}$/.test(object.color || '') ? object.color.toLowerCase() : '#d4af37'}));
            }
        } else if (kind === 'media') {
            const mediatype = object.media === 'video' ? 'video' : 'audio';
            fields.push(selectField(str.editormedia, 'media', 'select', ['audio', 'video']
                .map((m) => option(m, str[`editormedia_${m}`], mediatype === m))));
            fields.push(fileField(str.editorfile, 'file', object.file || null, mediatype, ['media', 'assets'], 'fileref', null));
            if (mediatype === 'video') {
                fields.push(numberField(str.editorwidth, 'width', num(object.width, 1.6), '0.1', {min: '0.01'}));
            }
            fields.push(field('loop', str.editorloop, 'loop', 'checkbox', {checked: object.loop === true}));
            fields.push(field('autoplay', str.editorautoplay, 'autoplay', 'checkbox', {checked: object.autoplay === true}));
        }
        return {heading: kindlabels[kind] || '', fields: fields, candelete: true, canduplicate: true};
    };

    /**
     * Apply one field's value to the selected thing in a draft document.
     *
     * @param {Object} draft Draft document.
     * @param {Object} ref Selection.
     * @param {string} path Field path.
     * @param {string} type Field type.
     * @param {*} raw Field value (string or boolean).
     * @returns {boolean} False when the value is not usable (nothing changes).
     */
    const apply = (draft, ref, path, type, raw) => { // eslint-disable-line complexity
        const scene = findScene(draft, ref.scene);
        if (!scene) {
            return false;
        }
        const target = ref.kind === 'environment' ? scene : findRef(draft, ref);
        if (!target) {
            return false;
        }
        switch (type) {
            case 'number': {
                const value = Number(raw);
                if (raw === '' || !Number.isFinite(value)) {
                    return false;
                }
                const sizes = /^(size|scale)\.\d$/.test(path) || ['width', 'height', 'fit'].includes(path);
                if (sizes && value <= 0) {
                    return false;
                }
                const fallback = path.startsWith('scale') || path.startsWith('size') ? [1, 1, 1] : [0, 0, 0];
                setPath(target, path, Math.round(value * 1000) / 1000, getPath(target, path.split('.')[0]) || fallback);
                return true;
            }
            case 'numbernull': {
                if (raw === '') {
                    setPath(target, path, null);
                    return true;
                }
                const value = Number(raw);
                if (!Number.isFinite(value) || value < 0.01 || value > 1) {
                    return false;
                }
                setPath(target, path, Math.round(value * 1000) / 1000);
                return true;
            }
            case 'text':
            case 'textarea':
            case 'select':
                setPath(target, path, String(raw));
                return true;
            case 'colour':
                if (!/^#[0-9a-fA-F]{6}$/.test(String(raw))) {
                    return false;
                }
                setPath(target, path, String(raw).toLowerCase());
                return true;
            case 'checkbox':
                setPath(target, path, !!raw);
                return true;
            case 'selectnull':
                setPath(target, path, raw === '' ? undefined : String(raw));
                return true;
            case 'selectint':
                setPath(target, path, raw === '' ? null : parseInt(raw, 10));
                return true;
            case 'fileref': {
                const ref2 = parseKey(String(raw));
                if (!ref2) {
                    return false;
                }
                setPath(target, path, ref2);
                return true;
            }
            case 'grabsource': {
                if (raw === '') {
                    delete target.file;
                    delete target.fit;
                    if (!['box', 'cylinder', 'sphere'].includes(target.shape)) {
                        target.shape = 'box';
                    }
                    return true;
                }
                const ref2 = parseKey(String(raw));
                if (!ref2) {
                    return false;
                }
                target.file = ref2;
                if (!(Number(target.fit) > 0)) {
                    target.fit = 0.3;
                }
                return true;
            }
            case 'targetscene': {
                const destination = findScene(draft, String(raw));
                if (!destination) {
                    return false;
                }
                const spawns = Array.isArray(destination.spawn) ? destination.spawn : [];
                target.target = {scene: destination.id, spawn: spawns.length ? spawns[0].id : ''};
                return true;
            }
            case 'envsource':
                return applyEnvironment(scene, String(raw));
        }
        return false;
    };

    /**
     * Change a room's surroundings to a preset or an uploaded model.
     *
     * @param {Object} scene Draft scene.
     * @param {string} value 'preset:<id>' or 'file:<key>'.
     * @returns {boolean}
     */
    const applyEnvironment = (scene, value) => {
        const env = scene.environment || {};
        if (value.startsWith('preset:')) {
            const id = value.slice(7);
            const preset = context.presets[id];
            if (!preset) {
                return false;
            }
            scene.environment = {
                type: 'preset',
                preset: id,
                shape: JSON.parse(JSON.stringify(preset.shape)),
                sky: preset.sky,
                lights: JSON.parse(JSON.stringify(preset.lights || [])),
                bounds: JSON.parse(JSON.stringify(preset.bounds || MODELBOUNDS)),
            };
            return true;
        }
        if (value.startsWith('file:')) {
            const ref = parseKey(value.slice(5));
            if (!ref) {
                return false;
            }
            scene.environment = {
                type: 'model',
                file: ref,
                sky: /^#[0-9a-fA-F]{6}$/.test(env.sky || '') ? env.sky : '#20242a',
                lights: Array.isArray(env.lights) && env.lights.length ? env.lights : MODELLIGHTS,
                bounds: env.bounds && env.bounds.min && env.bounds.max ? env.bounds : MODELBOUNDS,
            };
            return true;
        }
        return false;
    };

    /**
     * Commit a field change as one undoable step.
     *
     * @param {HTMLElement} input The field.
     */
    const commit = (input) => {
        const ref = model.getSelection();
        if (!ref) {
            return;
        }
        const path = input.dataset.path;
        const type = input.dataset.type;
        const raw = type === 'checkbox' ? input.checked : input.value;
        const object = findRef(model.getDocument(), ref);
        const name = ref.kind === 'environment'
            ? displayName(findScene(model.getDocument(), ref.scene)) : displayName(object);
        getString('editorchange_edit', 'mod_mavr', name).then((label) => {
            const changed = model.transact(label, (draft) => apply(draft, ref, path, type, raw));
            if (!changed) {
                // Show the stored value again.
                refresh();
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Upload a file and put it where the button says.
     *
     * @param {HTMLElement} button The Upload button.
     */
    const uploadFor = (button) => {
        const ref = model.getSelection();
        if (!ref) {
            return;
        }
        const path = button.dataset.path;
        const kind = button.dataset.kind;
        let key = null;
        context.actions.upload(kind, button).then((asset) => {
            if (!asset || destroyed) {
                return null;
            }
            key = refKey(toRef(asset));
            return getString('editorchange_edit', 'mod_mavr', asset.filename);
        }).then((label) => {
            if (label === null || destroyed) {
                return null;
            }
            model.transact(label, (draft) => {
                if (path === 'environment') {
                    const scene = findScene(draft, ref.scene);
                    return scene ? applyEnvironment(scene, `file:${key}`) : false;
                }
                const type = path === 'file' && findRef(draft, ref) && findRef(draft, ref).kind === 'grabbable'
                    ? 'grabsource' : 'fileref';
                return apply(draft, ref, path, type, key);
            });
            refresh();
            return null;
        }).catch(Notification.exception);
    };

    /** Draw the inspector for the current selection. */
    const refresh = () => {
        if (destroyed) {
            return;
        }
        const ref = model.getSelection();
        const description = ref ? describe(model.getDocument(), ref) : null;
        const templatecontext = description ? Object.assign({hasselection: true}, description) : {hasselection: false};
        const active = document.activeElement && container.contains(document.activeElement) ? document.activeElement.id : null;
        const id = ++renderid;
        const pending = new Pending('mod_mavr/editor_inspector:render');
        Templates.renderForPromise('mod_mavr/editor_inspector', templatecontext).then(({html, js}) => {
            if (id !== renderid || destroyed) {
                return null;
            }
            Templates.replaceNodeContents(container, html, js);
            if (active) {
                const again = document.getElementById(active);
                if (again && container.contains(again)) {
                    again.focus({preventScroll: true});
                }
            }
            return null;
        }).catch(Notification.exception).finally(() => pending.resolve());
    };

    const onChange = (e) => {
        const input = e.target.closest('[data-path][data-type]');
        if (input && container.contains(input)) {
            commit(input);
        }
    };
    const onKeyDown = (e) => {
        // Enter in a one-line field commits it, as leaving the field does.
        const input = e.target.closest('input[data-path]');
        if (input && e.key === 'Enter') {
            e.preventDefault();
            commit(input);
        }
    };
    const onClick = (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !container.contains(button)) {
            return;
        }
        if (button.dataset.action === 'mavr-insp-delete') {
            context.actions.deleteSelection();
        } else if (button.dataset.action === 'mavr-insp-duplicate') {
            context.actions.duplicateSelection();
        } else if (button.dataset.action === 'mavr-insp-upload') {
            uploadFor(button);
        }
    };
    container.addEventListener('change', onChange);
    container.addEventListener('keydown', onKeyDown);
    container.addEventListener('click', onClick);
    const unsubscribers = [
        model.on('change', refresh),
        model.on('select', refresh),
    ];
    refresh();

    return {
        refresh: refresh,
        destroy: () => {
            destroyed = true;
            container.removeEventListener('change', onChange);
            container.removeEventListener('keydown', onKeyDown);
            container.removeEventListener('click', onClick);
            unsubscribers.forEach((u) => u());
        },
    };
};
