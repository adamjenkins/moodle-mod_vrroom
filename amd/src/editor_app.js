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
 * @module     mod_mavr/editor_app
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';
import {validate} from 'mavrruntime_builtin/graph';
import {clone, createModel, findRef, findScene, getCollection} from 'mod_mavr/editor_model';
import {assetsOfKind, upload, toRef} from 'mod_mavr/editor_assets';
import {displayName, init as initInspector} from 'mod_mavr/editor_inspector';
import {init as initOutline} from 'mod_mavr/editor_outline';
import {init as initView, isWebGLAvailable} from 'mod_mavr/editor_view';
import {init as initLogic} from 'mod_mavr/editor_logic';

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
 * Item ids of the assets area that the rooms of a document use.
 *
 * @param {Object} doc Document.
 * @returns {Set<number>}
 */
const usedAssetItemids = (doc) => {
    const used = new Set();
    const note = (ref) => {
        if (ref && ref.area === 'assets') {
            used.add(Number(ref.itemid));
        }
    };
    (doc.scenes || []).forEach((scene) => {
        note(scene.environment && scene.environment.file);
        (scene.interactables || []).forEach((item) => {
            note(item.file);
            ((item.content && item.content.media) || []).forEach(note);
        });
    });
    return used;
};

/**
 * Start the editor.
 *
 * @param {Object} options {cmid, rootid}
 * @param {number} options.cmid Course module id.
 * @param {string} options.rootid Id of the editor root element.
 */
export const init = ({cmid, rootid}) => {
    const root = document.getElementById(rootid);
    if (!root) {
        return;
    }
    const pending = new Pending('mod_mavr/editor_app:init');
    const strings = {};
    const stringkeys = ['editorsaving', 'editorsaved', 'editorunsaved', 'editornowebgl', 'editorundo', 'editorredo']
        .concat(Object.values(KINDLABELS));

    Promise.all([
        Promise.resolve(Ajax.call([{methodname: 'mod_mavr_editor_load', args: {cmid: cmid}}])[0]),
        getStrings(stringkeys.map((key) => ({key: key, component: 'mod_mavr'}))),
    ]).then(([response, values]) => {
        stringkeys.forEach((key, i) => {
            strings[key] = values[i];
        });
        return start(root, cmid, response, strings);
    }).catch((error) => {
        region(root, 'mavr-editor-status').textContent = '';
        Notification.exception(error);
    }).finally(() => pending.resolve());
};

/**
 * Run the editor once the document is loaded.
 *
 * @param {HTMLElement} root Editor root.
 * @param {number} cmid Course module id.
 * @param {Object} response mod_mavr_editor_load result.
 * @param {Object} strings Strings by key.
 * @returns {Promise}
 */
const start = (root, cmid, response, strings) => {
    const document0 = JSON.parse(response.documentjson);
    const context = {
        assets: JSON.parse(response.assetsjson),
        presets: JSON.parse(response.presetsjson),
        questions: JSON.parse(response.questionsjson),
        info: JSON.parse(response.infojson),
        actions: {},
    };
    const model = createModel(document0);
    const status = region(root, 'mavr-editor-status');
    const live = region(root, 'mavr-editor-live');
    const message = region(root, 'mavr-editor-message');
    const stage = region(root, 'mavr-editor-stage');
    const canvas = region(root, 'mavr-editor-canvas');
    const undobutton = root.querySelector('[data-action="mavr-editor-undo"]');
    const redobutton = root.querySelector('[data-action="mavr-editor-redo"]');
    const savebutton = root.querySelector('[data-action="mavr-editor-save"]');
    let view = null;
    let saving = false;
    let eversaved = false;

    if (document0.hasattempts) {
        region(root, 'mavr-editor-attempts').classList.remove('d-none');
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
     * The file a new model, image or media object starts with: an existing one, or a fresh upload.
     *
     * @param {string} kind Asset kind.
     * @param {string[]} areas Allowed areas.
     * @param {HTMLElement} returnfocus Where focus goes after the upload form.
     * @returns {Promise<Object|null>}
     */
    const chooseAsset = (kind, areas, returnfocus) => {
        const existing = assetsOfKind(context.assets, kind, areas);
        if (existing.length) {
            return Promise.resolve(existing[existing.length - 1]);
        }
        return upload(cmid, kind, context.assets, returnfocus);
    };

    /**
     * Add an object to the room being edited.
     *
     * @param {string} kind Kind.
     * @param {string|undefined} media audio or video for media objects.
     * @param {HTMLElement} trigger The menu item.
     */
    const addObject = (kind, media, trigger) => {
        const scene = model.getActiveScene();
        if (!scene || !KINDLABELS[kind]) {
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
            return getString('editornewname', 'mod_mavr', {kind: strings[KINDLABELS[kind]], number: count + 1});
        }).then((name) => {
            if (name === null) {
                return null;
            }
            state.name = name;
            return getString('editorchange_add', 'mod_mavr', name);
        }).then((label) => {
            if (label === null) {
                return null;
            }
            const id = model.newId(PREFIXES[kind]);
            const p = view ? view.getPlacement() : roomCentre();
            const object = newObject(kind, id, state.name, p, {asset: state.asset, media: media === 'video' ? 'video' : 'audio',
                scene: state.current, doc: state.doc});
            if (!object) {
                return null;
            }
            model.transact(label, (draft) => {
                const target = findScene(draft, scene.id);
                if (!target) {
                    return false;
                }
                getCollection(target, collection).push(object);
                return true;
            });
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
        getString('editorchange_delete', 'mod_mavr', displayName(object)).then((label) => {
            model.transact(label, (draft) => {
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
                return true;
            });
            model.select(null);
            announce(label);
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
        getString('editorchange_add', 'mod_mavr', displayName(object)).then((label) => {
            const copy = clone(object);
            copy.id = model.newId(PREFIXES[kind] || 'x');
            const key = ref.kind === 'zone' ? 'center' : 'position';
            if (Array.isArray(copy[key]) && copy[key].length === 3) {
                copy[key] = [Math.round((Number(copy[key][0]) + 0.5) * 1000) / 1000, copy[key][1], copy[key][2]];
            }
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

    /** Add a room built from the first built-in surroundings. */
    const addScene = () => {
        const doc = model.getDocument();
        if ((doc.scenes || []).length >= (Number(context.info.maxscenes) || 20)) {
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
            getString('editornewscene', 'mod_mavr', number),
            getString('editornewname', 'mod_mavr', {kind: strings.editoraddspawn, number: 1}),
        ]).then((values) => {
            [name, spawnname] = values;
            return getString('editorchange_add', 'mod_mavr', name);
        }).then((label) => {
            const sceneid = model.newSceneId();
            const spawn = preset.spawn || {position: [0, 0, 0], yaw: 0};
            const scene = {
                schema: 'mavr-scene/1',
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
     * Delete a room and everything in it (never the last one).
     *
     * @param {string} sceneid Scene id.
     */
    const deleteScene = (sceneid) => {
        const doc = model.getDocument();
        const scene = findScene(doc, sceneid);
        if (!scene || doc.scenes.length < 2) {
            return;
        }
        getString('editorchange_delete', 'mod_mavr', displayName(scene)).then((label) => {
            model.transact(label, (draft) => {
                draft.scenes = draft.scenes.filter((s) => s.id !== sceneid);
                return true;
            });
            announce(label);
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
        getString('editorchange_edit', 'mod_mavr', displayName(doc.scenes[index])).then((label) => {
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
        upload: (kind, returnfocus) => upload(cmid, kind, context.assets, returnfocus),
        showMessage: ({key, component}) => {
            getString(key, component).then(showMessage).catch(Notification.exception);
        },
        onMode: (mode) => {
            root.querySelectorAll('[data-action="mavr-editor-mode"]').forEach((button) => {
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
        region(root, 'mavr-editor-room').classList.toggle('d-none', !roomtab);
        region(root, 'mavr-editor-tasks').classList.toggle('d-none', roomtab);
        root.querySelectorAll('[data-action="mavr-editor-tab"]').forEach((button) => {
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
        labels.push(model.canUndo() ? getString('editorundolabel', 'mod_mavr', model.undoLabel())
            : Promise.resolve(strings.editorundo));
        labels.push(model.canRedo() ? getString('editorredolabel', 'mod_mavr', model.redoLabel())
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
        const errors = validate(doc.graph || {});
        if (errors.length) {
            return getString('logicinvalid', 'mod_mavr', errors[0]);
        }
        const objectives = (doc.graph && Array.isArray(doc.graph.objectives)) ? doc.graph.objectives : [];
        const missing = (doc.bindings || []).find((b) => !(Number(b.questionbankentryid) > 0));
        if (missing) {
            const objective = objectives.find((o) => o && o.type === 'answer_question' && Number(o.slot) === Number(missing.slot));
            return getString('logicnoquestion', 'mod_mavr', objective ? (objective.title || objective.id) : String(missing.slot));
        }
        return Promise.resolve(null);
    };

    /** Validate and save everything. */
    const save = () => {
        if (saving) {
            return;
        }
        const doc = model.getDocument();
        const savepending = new Pending('mod_mavr/editor_app:save');
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
                schema: doc.schema || 'mavr-editdoc/1',
                revision: model.getRevision(),
                scenes: doc.scenes,
                graph: doc.graph,
                bindings: doc.bindings,
            };
            savedoc = doc;
            return Ajax.call([{methodname: 'mod_mavr_editor_save',
                args: {cmid: cmid, documentjson: JSON.stringify(payload)}}])[0];
        }).then((result) => {
            if (!result) {
                return null;
            }
            // Only what was sent is stored; edits made while saving stay unsaved.
            model.markSaved(result.revision, savedoc);
            const used = usedAssetItemids(savedoc);
            context.assets = context.assets.filter((a) => a.area !== 'assets' || used.has(Number(a.itemid)));
            eversaved = true;
            return model.isDirty() ? strings.editorunsaved : strings.editorsaved;
        }).catch((error) => {
            if (!savedoc) {
                // The checks themselves failed: nothing was sent.
                Notification.exception(error);
                return null;
            }
            return getString('editorsavefailed', 'mod_mavr', plain(error && error.message));
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

    // Toolbar.
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !root.contains(button) || button.disabled) {
            return;
        }
        switch (button.dataset.action) {
            case 'mavr-editor-add':
                e.preventDefault();
                addObject(button.dataset.kind, button.dataset.media, button);
                break;
            case 'mavr-editor-mode':
                if (view) {
                    view.setMode(button.dataset.mode);
                }
                break;
            case 'mavr-editor-undo':
                model.undo();
                break;
            case 'mavr-editor-redo':
                model.redo();
                break;
            case 'mavr-editor-save':
                save();
                break;
            case 'mavr-editor-tab':
                showTab(button.dataset.tab);
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
    });
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
        root.querySelectorAll('[data-action="mavr-editor-mode"]').forEach((button) => {
            button.disabled = true;
        });
    }

    const outline = initOutline(region(root, 'mavr-editor-outline'), model, context);
    const inspector = initInspector(region(root, 'mavr-editor-inspector'), model, context);
    const logicapi = initLogic(region(root, 'mavr-editor-logic'), model, {
        questions: context.questions,
        presets: context.presets,
        info: context.info,
    });
    const logic = Promise.resolve(logicapi && logicapi.ready ? logicapi.ready : logicapi);

    return Promise.all([outline, inspector, logic]).then(() => {
        const first = model.getActiveScene();
        if (first) {
            model.select({scene: first.id, kind: 'environment', id: first.id});
        }
        root.querySelector('.mavr-editor-toolbar .dropdown-toggle').disabled = false;
        savebutton.disabled = false;
        setStatus('');
        updateToolbar();
        root.dataset.mavrEditorReady = '1';
        return null;
    });
};
