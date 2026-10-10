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
 * The room editor: loads the document, wires the toolbar, the outline, the inspector, the 3D
 * preview and the logic editor to one model, and saves everything in one call.
 *
 * Where the document comes from and goes to is a backend: by default the activity's own
 * mod_vrroom_editor_load/save services and upload form. Another plugin (a student's tour in an
 * assignment) passes its own, and its editor info turns off what it does not support
 * (info.features: logic, questions, kinds).
 *
 * @module     mod_vrroom/editor_app
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';
import {validate} from 'vrroomruntime_builtin/graph';
import {clone, createModel, findRef, findScene, getCollection} from 'mod_vrroom/editor_model';
import {DEFAULT_FORM, choose, upload, toRef} from 'mod_vrroom/editor_assets';
import {PHOTO_EXCLUDED, isPhotoScene, newPhotoScene, placementOf, setPlacement} from 'mod_vrroom/editor_photo';
import {boundsOf, forgetIds, graphReferences, idsOfScene, isNetworkError, keepInside, portalsInto, retargetPortals,
    usedAssetItemids} from 'mod_vrroom/editor_layout';
import {displayName, init as initInspector} from 'mod_vrroom/editor_inspector';
import {init as initOutline} from 'mod_vrroom/editor_outline';
import {init as initView, isWebGLAvailable} from 'mod_vrroom/editor_view';
import {init as initLogic} from 'mod_vrroom/editor_logic';

/** Kind => lang string naming it. */
const KINDLABELS = {
    hotspot: 'editoraddhotspot', model: 'editoraddmodel', image: 'editoraddimage', text: 'editoraddtext',
    zone: 'editoraddzone', spawn: 'editoraddspawn', portal: 'editoraddportal', grabbable: 'editoraddgrabbable',
    media: 'editoraddmedia', prop: 'editoraddprop',
};

/** Kind => id prefix of new objects. */
const PREFIXES = {
    hotspot: 'h', model: 'm', image: 'i', text: 't', zone: 'z', spawn: 's', portal: 'pt', grabbable: 'g', media: 'a', prop: 'p',
};

/** Interactable kinds. */
const INTERACTABLES = ['hotspot', 'model', 'image', 'text', 'portal', 'grabbable', 'media'];

/** Kind of list => the editor info key of its most items per room. */
const LIMITS = {interactable: 'maxinteractables', zone: 'maxzones'};

/**
 * Find a region inside the editor.
 *
 * @param {HTMLElement} root Editor root.
 * @param {string} name data-region value.
 * @returns {HTMLElement|null}
 */
const region = (root, name) => root.querySelector(`[data-region="${name}"]`);

/**
 * Plain text of an HTML message (exception messages are HTML).
 *
 * @param {string} html Message.
 * @returns {string}
 */
const plain = (html) => {
    const parsed = new DOMParser().parseFromString(String(html || ''), 'text/html');
    return (parsed.body.textContent || '').trim();
};

/**
 * Text made safe to place in HTML.
 *
 * @param {string} text Plain text.
 * @returns {string} The same text with HTML special characters escaped.
 */
const escapeHTML = (text) => {
    const span = document.createElement('span');
    span.textContent = String(text);
    return span.innerHTML.replace(/"/g, '&quot;');
};

/**
 * Put a string into an element once it has loaded.
 *
 * @param {HTMLElement} element Where the text goes.
 * @param {Promise<string>} text The string.
 * @returns {Promise<null>}
 */
const showText = async(element, text) => {
    element.textContent = await text;
    return null;
};

/**
 * The backend the editor talks to, with today's activity services for anything not given.
 *
 * load and save are functions returning promises. As js_call_amd can only pass data, they may
 * also be web service names: load is then called with 'args', and save with 'args' plus
 * documentjson (the JSON of the payload).
 *
 * @param {number} cmid Course module id.
 * @param {Object|null|undefined} backend {load, save, args, uploadForm, uploadArgs, doneurl}.
 * @returns {Object} {load(), save(payload), form: {formClass, args}, doneurl}
 */
const makeBackend = (cmid, backend) => {
    const given = backend && typeof backend === 'object' ? backend : {};
    const args = given.args && typeof given.args === 'object' ? given.args : {cmid: cmid};
    const call = (methodname, methodargs) => Promise.resolve(Ajax.call([{methodname: methodname, args: methodargs}])[0]);
    let load = () => call('mod_vrroom_editor_load', {cmid: cmid});
    if (typeof given.load === 'function') {
        load = () => Promise.resolve(given.load());
    } else if (typeof given.load === 'string' && given.load !== '') {
        load = () => call(given.load, Object.assign({}, args));
    }
    let save = (payload) => call('mod_vrroom_editor_save', {cmid: cmid, documentjson: JSON.stringify(payload)});
    if (typeof given.save === 'function') {
        save = (payload) => Promise.resolve(given.save(payload));
    } else if (typeof given.save === 'string' && given.save !== '') {
        save = (payload) => call(given.save, Object.assign({}, args, {documentjson: JSON.stringify(payload)}));
    }
    return {
        load: load,
        save: save,
        form: {
            formClass: typeof given.uploadForm === 'string' && given.uploadForm !== '' ? given.uploadForm : DEFAULT_FORM.formClass,
            args: given.uploadArgs && typeof given.uploadArgs === 'object' ? given.uploadArgs : {cmid: cmid},
        },
        doneurl: typeof given.doneurl === 'string' ? given.doneurl : '',
    };
};

/**
 * Start the editor.
 *
 * @param {Object} options {cmid, rootid, backend}
 * @param {number} options.cmid Course module id.
 * @param {string} options.rootid Id of the editor root element.
 * @param {Object} [options.backend] {load: () => Promise<{documentjson, assetsjson, presetsjson, questionsjson,
 *     infojson}>, save: (payload) => Promise<{revision, ...}>, uploadForm, uploadArgs, doneurl}; see makeBackend().
 */
export const init = ({cmid, rootid, backend}) => {
    const root = document.getElementById(rootid);
    if (!root) {
        return;
    }
    const pending = new Pending('mod_vrroom/editor_app:init');
    const strings = {};
    const stringkeys = ['editorsaving', 'editorsaved', 'editorunsaved', 'editornowebgl', 'editorundo', 'editorredo']
        .concat(Object.values(KINDLABELS));
    const api = makeBackend(Number(cmid) || 0, backend);

    Promise.all([
        api.load(),
        getStrings(stringkeys.map((key) => ({key: key, component: 'mod_vrroom'}))),
    ]).then(([response, values]) => {
        stringkeys.forEach((key, i) => {
            strings[key] = values[i];
        });
        return start(root, api, response, strings);
    }).catch((error) => {
        const status = region(root, 'vrroom-editor-status');
        status.textContent = '';
        if (isNetworkError(error)) {
            return showText(status, getString('editornetworkerror', 'mod_vrroom'));
        }
        return Notification.exception(error);
    }).catch(Notification.exception).finally(() => pending.resolve());
};

/**
 * What the editor info allows (an older server sends no features: everything).
 *
 * @param {Object} info Editor info.
 * @returns {Object} {logic: boolean, questions: boolean, kinds: string[]|null}
 */
const featuresOf = (info) => {
    const features = info && info.features && typeof info.features === 'object' ? info.features : {};
    return {
        logic: features.logic !== false,
        questions: features.questions !== false,
        kinds: Array.isArray(features.kinds) ? features.kinds.map(String) : null,
    };
};

/**
 * Whether the editor info allows adding a kind of thing.
 *
 * A kinds list may name 'media' (audio and video) or 'audio' and 'video' one by one.
 *
 * @param {Object} features From featuresOf().
 * @param {string} kind Kind.
 * @param {string} [media] audio or video, for media.
 * @returns {boolean}
 */
const kindAllowed = (features, kind, media) => {
    if (!features.kinds) {
        return true;
    }
    if (kind === 'media') {
        return features.kinds.includes('media') || (!!media && features.kinds.includes(media));
    }
    return features.kinds.includes(kind);
};

/**
 * Run the editor once the document is loaded.
 *
 * @param {HTMLElement} root Editor root.
 * @param {Object} api The backend, from makeBackend().
 * @param {Object} response The load result.
 * @param {Object} strings Strings by key.
 * @returns {Promise}
 */
const start = (root, api, response, strings) => {
    const document0 = JSON.parse(response.documentjson);
    const context = {
        assets: JSON.parse(response.assetsjson),
        presets: JSON.parse(response.presetsjson) || {},
        questions: JSON.parse(response.questionsjson),
        info: JSON.parse(response.infojson),
        actions: {},
    };
    // A list (an assignment sends []) works as an empty set of presets.
    if (Array.isArray(context.presets)) {
        context.presets = {};
    }
    const features = featuresOf(context.info);
    context.info.features = features;
    const model = createModel(document0);
    const status = region(root, 'vrroom-editor-status');
    const live = region(root, 'vrroom-editor-live');
    const message = region(root, 'vrroom-editor-message');
    const stage = region(root, 'vrroom-editor-stage');
    const canvas = region(root, 'vrroom-editor-canvas');
    const undobutton = root.querySelector('[data-action="vrroom-editor-undo"]');
    const redobutton = root.querySelector('[data-action="vrroom-editor-redo"]');
    const savebutton = root.querySelector('[data-action="vrroom-editor-save"]');
    let view = null;
    let saving = false;
    let eversaved = false;

    if (document0.hasattempts) {
        region(root, 'vrroom-editor-attempts').classList.remove('d-none');
    }

    const announce = (text) => {
        live.textContent = '';
        window.setTimeout(() => {
            live.textContent = text;
        }, 50);
    };
    const setStatus = (text) => {
        status.textContent = text;
    };
    const showMessage = (text) => {
        message.textContent = text;
        message.classList.remove('d-none');
    };

    // The note under the toolbar: what the editor did on its own, why something was refused, why a save failed.
    const note = region(root, 'vrroom-editor-note');
    const notetext = region(root, 'vrroom-editor-notetext');
    let notekind = '';

    /**
     * Show a note under the toolbar (it replaces the previous one).
     *
     * @param {string} text Plain text.
     * @param {string} [kind] What the note is about ('error' for a failed save, 'clearance', or '').
     */
    const showNote = (text, kind = '') => {
        notekind = kind;
        notetext.textContent = text;
        note.classList.toggle('alert-danger', kind === 'error');
        note.classList.toggle('alert-warning', kind !== 'error');
        note.classList.remove('d-none');
        // The note sits under the toolbar; after a refused delete at the bottom of the inspector,
        // bring it into view.
        note.scrollIntoView({block: 'nearest'});
    };

    /**
     * Show a failed save's reason in the note once its string has loaded.
     *
     * @param {Promise<string>} reason The reason.
     * @returns {Promise<string>} The reason's text, for the status line.
     */
    const noteError = async(reason) => {
        const text = await reason;
        showNote(text, 'error');
        return text;
    };

    /**
     * Hide the note.
     *
     * @param {string} [kind] Hide it only when it is about this.
     */
    const hideNote = (kind) => {
        if (kind === undefined || kind === notekind) {
            note.classList.add('d-none');
            notetext.textContent = '';
            notekind = '';
        }
    };

    /**
     * Show a note made from a lang string.
     *
     * @param {string} key String key (mod_vrroom).
     * @param {*} a String parameter.
     * @param {string} [kind] As for showNote().
     * @returns {Promise}
     */
    const noteString = (key, a, kind = '') => getString(key, 'mod_vrroom', a).then((text) => {
        showNote(text, kind);
        announce(text);
        return null;
    }).catch(Notification.exception);

    /**
     * A list of names for a message ("A", "B", "C").
     *
     * @param {string[]} names Names.
     * @returns {string}
     */
    const nameList = (names) => Array.from(new Set(names)).map((n) => `"${n}"`).join(', ');

    /**
     * A start point's name for a message: its own, or "Start point <number>" as in the outline.
     *
     * @param {Object} spawn {name, number}.
     * @returns {Promise<string>}
     */
    const spawnLabel = async(spawn) => {
        if (spawn.name) {
            return spawn.name;
        }
        const kind = await getString('editoraddspawn', 'mod_vrroom');
        return getString('editornewname', 'mod_vrroom', {kind: kind, number: spawn.number});
    };

    /**
     * What a list of task and rule references is called, for a message.
     *
     * @param {Object[]} refs From graphReferences().
     * @returns {Promise<string>}
     */
    const describeRefs = (refs) => Promise.all(refs.map((ref) => {
        if (ref.type === 'rule') {
            return getString('logicrule', 'mod_vrroom', ref.index + 1);
        }
        if (ref.title) {
            return getString('editorrefobjective', 'mod_vrroom', {number: ref.index + 1, title: ref.title});
        }
        return getString('logicobjective', 'mod_vrroom', ref.index + 1);
    })).then((labels) => labels.join(', '));

    /**
     * Whether a room has room for one more thing of a kind; tells the teacher when not.
     *
     * @param {Object} scene Scene document.
     * @param {string} collection Kind of list (interactable, zone, ...).
     * @returns {boolean}
     */
    const hasRoomFor = (scene, collection) => {
        const max = Number(context.info[LIMITS[collection]]) || 0;
        if (!max || getCollection(clone(scene), collection).length < max) {
            return true;
        }
        noteString(collection === 'zone' ? 'editortoomanyzones' : 'editortoomanyobjects', max);
        return false;
    };

    /**
     * Where a new object goes when there is no 3D preview: the middle of the room.
     *
     * @returns {number[]}
     */
    const roomCentre = () => {
        const scene = model.getActiveScene();
        const bounds = scene && scene.environment ? scene.environment.bounds : null;
        if (bounds && Array.isArray(bounds.min) && Array.isArray(bounds.max)) {
            return [(Number(bounds.min[0]) + Number(bounds.max[0])) / 2, 0, (Number(bounds.min[2]) + Number(bounds.max[2])) / 2];
        }
        return [0, 0, 0];
    };

    /**
     * A new object of a kind, placed at p.
     *
     * @param {string} kind Kind.
     * @param {string} id New id.
     * @param {string} name Name.
     * @param {number[]} p Floor position.
     * @param {Object} extra {asset, media, scene, doc}
     * @returns {Object|null}
     */
    const newObject = (kind, id, name, p, extra) => {
        const at = (y) => [p[0], y, p[2]];
        switch (kind) {
            case 'hotspot':
                return {id: id, kind: 'hotspot', name: name, position: at(1.3), rotation: [0, 0, 0],
                    content: {title: name, text: '', textformat: '1', media: [], question: null}};
            case 'model':
                return {id: id, kind: 'model', name: name, position: at(0), rotation: [0, 0, 0], scale: [1, 1, 1], fit: 1,
                    file: toRef(extra.asset)};
            case 'image':
                return {id: id, kind: 'image', name: name, position: at(1.6), rotation: [0, 0, 0], width: 1.6,
                    file: toRef(extra.asset)};
            case 'text':
                return {id: id, kind: 'text', name: name, position: at(1.6), rotation: [0, 0, 0], text: name, width: 2};
            case 'portal': {
                const scenes = extra.doc.scenes || [];
                const other = scenes.find((s) => s.id !== extra.scene.id) || extra.scene;
                const spawns = Array.isArray(other.spawn) ? other.spawn : [];
                return {id: id, kind: 'portal', name: name, position: at(0), rotation: [0, 0, 0], width: 1.2, height: 2.2,
                    target: {scene: other.id, spawn: spawns.length ? spawns[0].id : ''}};
            }
            case 'grabbable':
                return {id: id, kind: 'grabbable', name: name, position: at(0.15), rotation: [0, 0, 0], shape: 'box',
                    size: [0.3, 0.3, 0.3], color: '#d4af37'};
            case 'media': {
                const item = {id: id, kind: 'media', name: name, media: extra.media, position: at(1.4), rotation: [0, 0, 0],
                    file: toRef(extra.asset), loop: false, autoplay: false};
                if (extra.media === 'video') {
                    item.width = 1.6;
                }
                return item;
            }
            case 'zone':
                return {id: id, name: name, center: at(1.5), size: [3, 3, 3]};
            case 'spawn':
                return {id: id, name: name, position: at(0), yaw: 0};
            case 'prop':
                return {id: id, name: name, shape: 'box', position: at(0.5), rotation: [0, 0, 0], size: [1, 1, 1],
                    color: '#d9d4cc'};
        }
        return null;
    };

    /**
     * The file a new model, image or media object starts with: the teacher picks one of the
     * activity's files or uploads one (nothing is reused without asking).
     *
     * @param {string} kind Asset kind.
     * @param {string[]} areas Allowed areas.
     * @param {HTMLElement} returnfocus Where focus goes after the dialogue.
     * @returns {Promise<Object|null>}
     */
    const chooseAsset = (kind, areas, returnfocus) => choose(api.form, kind, context.assets, areas, returnfocus);

    /**
     * Add an object to the room being edited.
     *
     * @param {string} kind Kind.
     * @param {string|undefined} media audio or video for media objects.
     * @param {HTMLElement} trigger The menu item.
     */
    const addObject = (kind, media, trigger) => {
        const scene = model.getActiveScene();
        if (!scene || !KINDLABELS[kind] || !kindAllowed(features, kind, media)) {
            return;
        }
        const photo = isPhotoScene(scene);
        if (photo && PHOTO_EXCLUDED.includes(kind)) {
            return;
        }
        if (!hasRoomFor(scene, INTERACTABLES.includes(kind) ? 'interactable' : kind)) {
            return;
        }
        let assetpromise = Promise.resolve(null);
        if (kind === 'model') {
            assetpromise = chooseAsset('model', ['models', 'assets'], trigger);
        } else if (kind === 'image') {
            assetpromise = chooseAsset('image', ['media', 'assets'], trigger);
        } else if (kind === 'media') {
            assetpromise = chooseAsset(media === 'video' ? 'video' : 'audio', ['media', 'assets'], trigger);
        }
        const collection = INTERACTABLES.includes(kind) ? 'interactable' : kind;
        // What the steps below learn, passed along the flat promise chain.
        const state = {asset: null, doc: null, current: null, name: null};
        assetpromise.then((asset) => {
            if (['model', 'image', 'media'].includes(kind) && !asset) {
                return null;
            }
            state.asset = asset;
            state.doc = model.getDocument();
            state.current = findScene(state.doc, scene.id);
            const count = getCollection(clone(state.current), collection)
                .filter((o) => collection !== 'interactable' || o.kind === kind).length;
            return getString('editornewname', 'mod_vrroom', {kind: strings[KINDLABELS[kind]], number: count + 1});
        }).then((name) => {
            if (name === null) {
                return null;
            }
            state.name = name;
            return getString('editorchange_add', 'mod_vrroom', name);
        }).then((label) => {
            if (label === null) {
                return null;
            }
            const id = model.newId(PREFIXES[kind]);
            const p = view && !photo ? view.getPlacement() : roomCentre();
            const object = newObject(kind, id, state.name, p, {asset: state.asset, media: media === 'video' ? 'video' : 'audio',
                scene: state.current, doc: state.doc});
            if (!object) {
                return null;
            }
            if (photo) {
                // In a photo room a new thing goes where the teacher is looking (or ahead of the start).
                const look = view && view.getLookDirection ? view.getLookDirection() : null;
                const spawns = Array.isArray(state.current.spawn) ? state.current.spawn : [];
                const startyaw = spawns.length ? Number(spawns[0].yaw) || 0 : 0;
                setPlacement(object, look ? look.yaw : startyaw, look ? look.pitch : 0);
            }
            keepInside(collection, object, boundsOf(state.current));
            let full = false;
            const added = model.transact(label, (draft) => {
                const target = findScene(draft, scene.id);
                if (!target) {
                    return false;
                }
                const items = getCollection(target, collection);
                const max = Number(context.info[LIMITS[collection]]) || 0;
                if (max && items.length >= max) {
                    // Another object arrived while the file dialogue was open.
                    full = true;
                    return false;
                }
                items.push(object);
                return true;
            });
            if (full) {
                hasRoomFor(findScene(model.getDocument(), scene.id) || scene, collection);
            }
            if (!added) {
                return null;
            }
            // Keyboard users carry on in the new object's Name field.
            if (context.actions.focusField) {
                context.actions.focusField('name');
            }
            model.select({scene: scene.id, kind: collection, id: id});
            announce(label);
            return null;
        }).catch(Notification.exception);
    };

    /** Delete the selected object (not the surroundings, not a room's last start point). */
    const deleteSelection = () => {
        const ref = model.getSelection();
        const doc = model.getDocument();
        const object = findRef(doc, ref);
        if (!ref || !object || ref.kind === 'environment') {
            return;
        }
        const scene = findScene(doc, ref.scene);
        if (ref.kind === 'spawn' && (!Array.isArray(scene.spawn) || scene.spawn.length < 2)) {
            return;
        }
        const name = displayName(object);
        // A thing the tasks need is not deleted: the save would fail with ids the teacher cannot read.
        const refs = graphReferences(doc, new Set([String(ref.id)]));
        if (refs.length) {
            describeRefs(refs).then((uses) => noteString('editordeleteblocked', {name: name, uses: uses}))
                .catch(Notification.exception);
            return;
        }
        let retargeted = 0;
        getString('editorchange_delete', 'mod_vrroom', name).then((label) => {
            const deleted = model.transact(label, (draft) => {
                const target = findScene(draft, ref.scene);
                const list = getCollection(target, ref.kind);
                const index = list.findIndex((o) => o && o.id === ref.id);
                if (index < 0) {
                    return false;
                }
                list.splice(index, 1);
                // Keep the room valid on its own: nothing may point at what is gone.
                getCollection(target, 'interactable').forEach((item) => {
                    if (ref.kind === 'zone' && item.zone === ref.id) {
                        delete item.zone;
                    }
                    if (ref.kind === 'interactable' && item.opens === ref.id) {
                        delete item.opens;
                    }
                });
                forgetIds(draft, new Set([String(ref.id)]));
                if (ref.kind === 'spawn') {
                    retargeted = retargetPortals(draft, ref.scene, ref.id);
                }
                return true;
            });
            if (!deleted) {
                return null;
            }
            model.select(null);
            announce(label);
            if (retargeted) {
                noteString('editorportalsretargeted', retargeted);
            }
            return null;
        }).catch(Notification.exception);
    };

    /** Copy the selected object with new ids, half a metre to the side. */
    const duplicateSelection = () => {
        const ref = model.getSelection();
        const object = findRef(model.getDocument(), ref);
        if (!ref || !object || ref.kind === 'environment') {
            return;
        }
        const kind = ref.kind === 'interactable' ? object.kind : ref.kind;
        const scene = findScene(model.getDocument(), ref.scene);
        if (!scene || !hasRoomFor(scene, ref.kind)) {
            return;
        }
        getString('editorchange_add', 'mod_vrroom', displayName(object)).then((label) => {
            const copy = clone(object);
            copy.id = model.newId(PREFIXES[kind] || 'x');
            const key = ref.kind === 'zone' ? 'center' : 'position';
            if (ref.kind === 'interactable' && isPhotoScene(scene)) {
                // In a photo room the copy sits a little to the right of the original, at the same distance.
                const p = placementOf(copy);
                setPlacement(copy, p.yaw - 10, p.pitch, p.distance);
            } else if (Array.isArray(copy[key]) && copy[key].length === 3) {
                copy[key] = [Math.round((Number(copy[key][0]) + 0.5) * 1000) / 1000, copy[key][1], copy[key][2]];
            }
            keepInside(ref.kind, copy, boundsOf(scene));
            model.transact(label, (draft) => {
                const target = findScene(draft, ref.scene);
                if (!target) {
                    return false;
                }
                getCollection(target, ref.kind).push(copy);
                return true;
            });
            model.select({scene: ref.scene, kind: ref.kind, id: copy.id});
            announce(label);
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Add a photo room: the teacher picks or uploads a 360° photo, and the room is that photo with
     * one start point at its centre.
     *
     * @param {HTMLElement|null} trigger The button, focused again when the dialogue closes.
     */
    const addPhotoScene = (trigger) => {
        const state = {asset: null, name: '', spawnname: ''};
        chooseAsset('panorama', ['environment', 'assets'], trigger || null).then((asset) => {
            if (!asset) {
                return null;
            }
            state.asset = asset;
            const number = (model.getDocument().scenes || []).length + 1;
            return Promise.all([
                getString('editornewphotoscenename', 'mod_vrroom', number),
                getString('editornewname', 'mod_vrroom', {kind: strings.editoraddspawn, number: 1}),
            ]);
        }).then((names) => {
            if (!names) {
                return null;
            }
            [state.name, state.spawnname] = names;
            return getString('editorchange_add', 'mod_vrroom', state.name);
        }).then((label) => {
            if (label === null) {
                return null;
            }
            const sceneid = model.newSceneId();
            const scene = newPhotoScene(sceneid, state.name, model.newId('s'), state.spawnname, toRef(state.asset));
            const added = model.transact(label, (draft) => {
                if ((draft.scenes || []).length >= (Number(context.info.maxscenes) || 20)) {
                    return false;
                }
                draft.scenes.push(scene);
                return true;
            });
            if (added) {
                model.setActiveScene(sceneid);
                model.select({scene: sceneid, kind: 'environment', id: sceneid});
                announce(label);
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Add a room: built from the first built-in surroundings, or a photo room.
     *
     * @param {string} [kind] 'photo' for a photo room; a site without presets (an assignment's
     *     tour) only has photo rooms.
     * @param {HTMLElement} [trigger] The button.
     */
    const addScene = (kind, trigger) => {
        const doc = model.getDocument();
        if ((doc.scenes || []).length >= (Number(context.info.maxscenes) || 20)) {
            return;
        }
        if (kind === 'photo' || !Object.keys(context.presets).length) {
            addPhotoScene(trigger || null);
            return;
        }
        const presetids = Object.keys(context.presets);
        const presetid = presetids.includes('gallery-hall') ? 'gallery-hall' : presetids[0];
        const preset = context.presets[presetid];
        if (!preset) {
            return;
        }
        const number = (doc.scenes || []).length + 1;
        let name = '';
        let spawnname = '';
        Promise.all([
            getString('editornewscene', 'mod_vrroom', number),
            getString('editornewname', 'mod_vrroom', {kind: strings.editoraddspawn, number: 1}),
        ]).then((values) => {
            [name, spawnname] = values;
            return getString('editorchange_add', 'mod_vrroom', name);
        }).then((label) => {
            const sceneid = model.newSceneId();
            const spawn = preset.spawn || {position: [0, 0, 0], yaw: 0};
            const scene = {
                schema: 'vrroom-scene/1',
                id: sceneid,
                name: name,
                environment: {type: 'preset', preset: presetid, shape: clone(preset.shape), sky: preset.sky,
                    lights: clone(preset.lights || []), bounds: clone(preset.bounds)},
                spawn: [{id: model.newId('s'), name: spawnname, position: clone(spawn.position || [0, 0, 0]),
                    yaw: Number(spawn.yaw) || 0}],
                zones: [],
                props: [],
                interactables: [],
            };
            model.transact(label, (draft) => {
                draft.scenes.push(scene);
                return true;
            });
            model.setActiveScene(sceneid);
            model.select({scene: sceneid, kind: 'environment', id: sceneid});
            announce(label);
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Delete a room and everything in it (never the last one), after asking.
     *
     * The portals of other rooms that lead into it go too. A room whose contents (or whose
     * portals into it) the tasks use is not deleted: the teacher is told which tasks.
     *
     * @param {string} sceneid Scene id.
     * @param {HTMLElement} [trigger] The button, focused again when the dialogue closes.
     */
    const deleteScene = (sceneid, trigger) => {
        const doc = model.getDocument();
        const scene = findScene(doc, sceneid);
        if (!scene || doc.scenes.length < 2) {
            return;
        }
        const name = displayName(scene);
        const portals = portalsInto(doc, sceneid);
        const ids = idsOfScene(scene);
        portals.forEach((p) => ids.add(String(p.item.id)));
        const refs = graphReferences(doc, ids, new Set([String(sceneid)]));
        if (refs.length) {
            describeRefs(refs).then((uses) => noteString('editordeleteblocked', {name: name, uses: uses}))
                .catch(Notification.exception);
            return;
        }
        // The confirmation's body is HTML: the room name a teacher typed goes in as text.
        const safename = escapeHTML(name);
        const question = portals.length
            ? getString('editordeletesceneportals', 'mod_vrroom', {name: safename, count: portals.length})
            : getString('editordeletesceneconfirm', 'mod_vrroom', safename);
        const confirmation = Notification.deleteCancelPromise(getString('editordeletescene', 'mod_vrroom'), question,
            getString('editordeletescene', 'mod_vrroom'), {triggerElement: trigger || null});
        confirmation.then(() => true, () => false).then((confirmed) => {
            return confirmed ? getString('editorchange_delete', 'mod_vrroom', name) : null;
        }).then((label) => {
            if (label === null) {
                // Cancelled.
                return null;
            }
            const deleted = model.transact(label, (draft) => {
                if (!findScene(draft, sceneid) || draft.scenes.length < 2) {
                    return false;
                }
                const gone = idsOfScene(findScene(draft, sceneid));
                draft.scenes = draft.scenes.filter((s) => s.id !== sceneid);
                draft.scenes.forEach((s) => {
                    s.interactables = (Array.isArray(s.interactables) ? s.interactables : []).filter((item) => {
                        const into = item && item.kind === 'portal' && item.target && item.target.scene === sceneid;
                        if (into) {
                            gone.add(String(item.id));
                        }
                        return !into;
                    });
                    // Nothing may open a portal that is gone.
                    s.interactables.forEach((item) => {
                        if (item.opens && gone.has(String(item.opens))) {
                            delete item.opens;
                        }
                    });
                });
                forgetIds(draft, gone);
                return true;
            });
            if (deleted) {
                announce(label);
                if (portals.length) {
                    noteString('editorportalsremoved', portals.length);
                }
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Move a room up or down; the first room is where students start.
     *
     * @param {string} sceneid Scene id.
     * @param {number} delta -1 or 1.
     */
    const moveScene = (sceneid, delta) => {
        const doc = model.getDocument();
        const index = doc.scenes.findIndex((s) => s.id === sceneid);
        const other = index + delta;
        if (index < 0 || other < 0 || other >= doc.scenes.length) {
            return;
        }
        getString('editorchange_edit', 'mod_vrroom', displayName(doc.scenes[index])).then((label) => {
            model.transact(label, (draft) => {
                const [moved] = draft.scenes.splice(index, 1);
                draft.scenes.splice(other, 0, moved);
                return true;
            });
            return null;
        }).catch(Notification.exception);
    };

    context.actions = {
        addObject: addObject,
        deleteSelection: deleteSelection,
        duplicateSelection: duplicateSelection,
        addScene: addScene,
        deleteScene: deleteScene,
        moveScene: moveScene,
        upload: (kind, returnfocus) => upload(api.form, kind, context.assets, returnfocus),
        showMessage: ({key, component}) => {
            getString(key, component).then(showMessage).catch(Notification.exception);
        },
        keptInside: (names) => {
            // An unnamed start point arrives as its id: name it as the outline does.
            const scene = model.getActiveScene();
            const spawns = scene && Array.isArray(scene.spawn) ? scene.spawn : [];
            Promise.all(names.map((name) => {
                const index = spawns.findIndex((spawn) => spawn && spawn.id === name && !(spawn.name || '').trim());
                return index < 0 ? name : spawnLabel({name: '', number: index + 1});
            })).then((labels) => noteString('editorkeptinside', nameList(labels))).catch(Notification.exception);
        },
        environmentFitted: ({start, moved}) => {
            if (moved.length) {
                noteString(start ? 'editorenvstartmoved' : 'editorenvmoved', nameList(moved));
            } else if (start) {
                noteString('editorenvstart', null);
            }
        },
        onClearance: (cramped) => {
            if (!cramped.length) {
                hideNote('clearance');
                return;
            }
            const first = cramped[0];
            spawnLabel(first).then((name) => {
                if (first.distance === 0) {
                    return noteString('editorspawnonfurniture', name, 'clearance');
                }
                return noteString('editorspawncramped', {name: name, distance: first.distance}, 'clearance');
            }).catch(Notification.exception);
        },
        onMode: (mode) => {
            root.querySelectorAll('[data-action="vrroom-editor-mode"]').forEach((button) => {
                const on = button.dataset.mode === mode;
                button.classList.toggle('active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        },
    };

    /**
     * Switch between the Room and Tasks tabs.
     *
     * @param {string} tab room or tasks.
     */
    const showTab = (tab) => {
        const roomtab = tab !== 'tasks';
        region(root, 'vrroom-editor-room').classList.toggle('d-none', !roomtab);
        region(root, 'vrroom-editor-tasks').classList.toggle('d-none', roomtab);
        root.querySelectorAll('[data-action="vrroom-editor-tab"]').forEach((button) => {
            const on = button.dataset.tab === tab;
            button.classList.toggle('active', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
            button.setAttribute('tabindex', on ? '0' : '-1');
        });
    };

    /** Undo/Redo buttons and the save status follow the model. */
    const updateToolbar = () => {
        undobutton.disabled = !model.canUndo();
        redobutton.disabled = !model.canRedo();
        const labels = [];
        labels.push(model.canUndo() ? getString('editorundolabel', 'mod_vrroom', model.undoLabel())
            : Promise.resolve(strings.editorundo));
        labels.push(model.canRedo() ? getString('editorredolabel', 'mod_vrroom', model.redoLabel())
            : Promise.resolve(strings.editorredo));
        // A title (not an aria-label) keeps the buttons' accessible names "Undo" and "Redo".
        Promise.all(labels).then(([undo, redo]) => {
            undobutton.title = undo;
            redobutton.title = redo;
            return null;
        }).catch(Notification.exception);
    };

    /** The status line after an edit, undo or redo. */
    const updateStatus = () => {
        if (saving) {
            return;
        }
        if (model.isDirty()) {
            setStatus(strings.editorunsaved);
        } else {
            setStatus(eversaved ? strings.editorsaved : '');
        }
    };

    /**
     * Check what the server will check about the tasks, so the teacher sees it on the Tasks tab.
     *
     * @param {Object} doc Document.
     * @returns {Promise<string|null>} A message, or null when the tasks are fine.
     */
    const checkLogic = (doc) => {
        if (!features.logic) {
            // No tasks here (an assignment's tour): the server checks nothing about them either.
            return Promise.resolve(null);
        }
        const errors = validate(doc.graph || {});
        if (errors.length) {
            return getString('logicinvalid', 'mod_vrroom', errors[0]);
        }
        const objectives = (doc.graph && Array.isArray(doc.graph.objectives)) ? doc.graph.objectives : [];
        const missing = (doc.bindings || []).find((b) => !(Number(b.questionbankentryid) > 0));
        if (missing) {
            const objective = objectives.find((o) => o && o.type === 'answer_question' && Number(o.slot) === Number(missing.slot));
            return getString('logicnoquestion', 'mod_vrroom', objective ? (objective.title || objective.id) : String(missing.slot));
        }
        return Promise.resolve(null);
    };

    /** Validate and save everything. */
    const save = () => {
        if (saving) {
            return;
        }
        const doc = model.getDocument();
        const savepending = new Pending('mod_vrroom/editor_app:save');
        saving = true;
        savebutton.disabled = true;
        // The document being sent, once the checks have passed: a failure after that is a failed save.
        let savedoc = null;
        checkLogic(doc).then((problem) => {
            if (problem) {
                showTab('tasks');
                setStatus(problem);
                announce(problem);
                return null;
            }
            setStatus(strings.editorsaving);
            const payload = {
                schema: doc.schema || 'vrroom-editdoc/1',
                revision: model.getRevision(),
                scenes: doc.scenes,
                graph: doc.graph,
                bindings: doc.bindings,
            };
            savedoc = doc;
            return api.save(payload);
        }).then((result) => {
            if (!result) {
                return null;
            }
            // Only what was sent is stored; edits made while saving stay unsaved.
            model.markSaved(result.revision, savedoc);
            // The server deleted the activity's files the saved rooms no longer use. Undo and redo
            // steps that would bring such a file back are dropped (the other steps are whole
            // documents and still work); the teacher uploads the file again if it is wanted.
            const used = usedAssetItemids(savedoc);
            const removed = new Set(context.assets.filter((a) => a.area === 'assets' && !used.has(Number(a.itemid)))
                .map((a) => Number(a.itemid)));
            context.assets = context.assets.filter((a) => a.area !== 'assets' || used.has(Number(a.itemid)));
            if (removed.size) {
                model.pruneHistory((doc) => Array.from(usedAssetItemids(doc)).some((itemid) => removed.has(itemid)));
            }
            hideNote('error');
            eversaved = true;
            showDone();
            return model.isDirty() ? strings.editorunsaved : strings.editorsaved;
        }).catch((error) => {
            if (!savedoc) {
                // The checks themselves failed: nothing was sent.
                Notification.exception(error);
                return null;
            }
            const reason = isNetworkError(error) ? getString('editornetworkerror', 'mod_vrroom')
                : getString('editorsavefailed', 'mod_vrroom', plain(error && error.message));
            // The status line is short; the whole reason (such as what lies outside the room) goes in the note.
            return noteError(reason);
        }).then((text) => {
            if (text) {
                setStatus(text);
                announce(text);
            }
            return null;
        }).catch(Notification.exception).finally(() => {
            saving = false;
            savebutton.disabled = false;
            updateToolbar();
            savepending.resolve();
        });
    };

    /** After a save, the backend's "Done" link (back to where the editor was opened from). */
    const showDone = () => {
        const done = region(root, 'vrroom-editor-done');
        if (done && api.doneurl) {
            done.setAttribute('href', api.doneurl);
            done.classList.remove('d-none');
        }
    };

    /**
     * Show only what the editor info allows and what suits the active room: a photo room has no
     * zones, start points to add, blocks or things to pick up, and no move/rotate/resize gizmo.
     */
    const updateSceneTools = () => {
        const photo = isPhotoScene(model.getActiveScene());
        root.querySelectorAll('[data-action="vrroom-editor-add"]').forEach((button) => {
            const kind = button.dataset.kind;
            const shown = kindAllowed(features, kind, button.dataset.media) && !(photo && PHOTO_EXCLUDED.includes(kind));
            const item = button.closest('li') || button;
            item.classList.toggle('d-none', !shown);
        });
        // The media heading and its divider go when no media item is left.
        const media = Array.from(root.querySelectorAll('[data-action="vrroom-editor-add"][data-kind="media"]'));
        const anymedia = media.some((button) => !(button.closest('li') || button).classList.contains('d-none'));
        root.querySelectorAll('[data-region="vrroom-editor-add-mediahead"]').forEach((el) => {
            el.classList.toggle('d-none', !anymedia);
        });
        root.querySelectorAll('[data-action="vrroom-editor-mode"]').forEach((button) => {
            button.disabled = !view || photo;
        });
        const modes = region(root, 'vrroom-editor-modes');
        if (modes) {
            modes.classList.toggle('d-none', photo);
        }
        const photohelp = region(root, 'vrroom-editor-photohelp');
        if (photohelp) {
            photohelp.classList.toggle('d-none', !photo);
        }
    };

    if (!features.logic) {
        // No tasks in this editor: the Tasks tab goes, the Room tab is all there is.
        root.querySelectorAll('[data-action="vrroom-editor-tab"][data-tab="tasks"]').forEach((button) => {
            (button.closest('li') || button).classList.add('d-none');
        });
        const tabs = region(root, 'vrroom-editor-tabs');
        if (tabs) {
            tabs.classList.add('d-none');
        }
    }

    // Toolbar.
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !root.contains(button) || button.disabled) {
            return;
        }
        switch (button.dataset.action) {
            case 'vrroom-editor-add':
                e.preventDefault();
                addObject(button.dataset.kind, button.dataset.media, button);
                break;
            case 'vrroom-editor-mode':
                if (view) {
                    view.setMode(button.dataset.mode);
                }
                break;
            case 'vrroom-editor-undo':
                model.undo();
                break;
            case 'vrroom-editor-redo':
                model.redo();
                break;
            case 'vrroom-editor-save':
                save();
                break;
            case 'vrroom-editor-tab':
                showTab(button.dataset.tab);
                break;
            case 'vrroom-editor-note-close':
                hideNote();
                break;
        }
    });
    window.addEventListener('beforeunload', (e) => {
        if (model.isDirty()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    model.on('change', () => {
        updateToolbar();
        updateStatus();
        updateSceneTools();
    });
    model.on('scene', updateSceneTools);
    model.on('saved', updateToolbar);

    // The 3D preview, when the browser can draw it.
    if (isWebGLAvailable()) {
        try {
            view = initView(stage, model, context);
        } catch (error) {
            Log.debug(error);
            view = null;
        }
    }
    if (!view) {
        canvas.classList.add('d-none');
        showMessage(strings.editornowebgl);
        root.querySelectorAll('[data-action="vrroom-editor-mode"]').forEach((button) => {
            button.disabled = true;
        });
    }

    const outline = initOutline(region(root, 'vrroom-editor-outline'), model, context);
    const inspector = initInspector(region(root, 'vrroom-editor-inspector'), model, context);
    let logic = Promise.resolve(null);
    if (features.logic) {
        const logicapi = initLogic(region(root, 'vrroom-editor-logic'), model, {
            questions: context.questions,
            presets: context.presets,
            info: context.info,
        });
        logic = Promise.resolve(logicapi && logicapi.ready ? logicapi.ready : logicapi);
    }

    return Promise.all([outline, inspector, logic]).then(() => {
        const first = model.getActiveScene();
        if (first) {
            model.select({scene: first.id, kind: 'environment', id: first.id});
        }
        root.querySelector('.vrroom-editor-toolbar .dropdown-toggle').disabled = false;
        savebutton.disabled = false;
        setStatus('');
        updateToolbar();
        updateSceneTools();
        root.dataset.vrroomEditorReady = '1';
        return null;
    });
};
