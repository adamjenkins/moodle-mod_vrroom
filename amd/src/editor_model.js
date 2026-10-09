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
 * The room editor's document model: one document (rooms, tasks, question bindings), a snapshot
 * history for undo and redo, the selection and the room being edited.
 *
 * Every change goes through transact(), which works on a copy, so a snapshot in the history is
 * never changed afterwards. The room editor and the logic editor share one model and so one
 * history.
 *
 * @module     mod_vrroom/editor_model
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';

/** Most undo steps kept. */
export const MAXHISTORY = 100;

/** Selection kind => key of the list in a scene. */
export const COLLECTIONS = Object.freeze({
    spawn: 'spawn',
    zone: 'zones',
    prop: 'props',
    interactable: 'interactables',
});

/**
 * Deep copy of plain JSON data.
 *
 * @param {*} value JSON-compatible value.
 * @returns {*}
 */
export const clone = (value) => {
    if (typeof window.structuredClone === 'function') {
        return window.structuredClone(value);
    }
    return JSON.parse(JSON.stringify(value));
};

/**
 * A random version 4 UUID.
 *
 * @returns {string}
 */
export const uuid = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    // RFC 4122 version 4: the version nibble is 4, the variant bits are 10.
    bytes[6] = (bytes[6] % 16) + 64;
    bytes[8] = (bytes[8] % 64) + 128;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/**
 * The list of a kind in a scene, created when missing.
 *
 * @param {Object} scene Scene document.
 * @param {string} kind spawn, zone, prop or interactable.
 * @returns {Array}
 */
export const getCollection = (scene, kind) => {
    const key = COLLECTIONS[kind];
    if (!key) {
        return [];
    }
    if (!Array.isArray(scene[key])) {
        scene[key] = [];
    }
    return scene[key];
};

/**
 * A scene of a document.
 *
 * @param {Object} doc Document.
 * @param {string} sceneid Scene id.
 * @returns {Object|null}
 */
export const findScene = (doc, sceneid) => (doc.scenes || []).find((s) => s && s.id === sceneid) || null;

/**
 * The object a selection reference points at.
 *
 * @param {Object} doc Document.
 * @param {Object|null} ref {scene, kind, id}.
 * @returns {Object|null} The scene's environment, or the object.
 */
export const findRef = (doc, ref) => {
    if (!ref) {
        return null;
    }
    const scene = findScene(doc, ref.scene);
    if (!scene) {
        return null;
    }
    if (ref.kind === 'environment') {
        return scene.environment || null;
    }
    if (!COLLECTIONS[ref.kind] || !Array.isArray(scene[COLLECTIONS[ref.kind]])) {
        return null;
    }
    return scene[COLLECTIONS[ref.kind]].find((o) => o && o.id === ref.id) || null;
};

/**
 * Every id used by spawns, zones, props and interactables of all rooms.
 *
 * @param {Object} doc Document.
 * @returns {Set<string>}
 */
export const allIds = (doc) => {
    const ids = new Set();
    (doc.scenes || []).forEach((scene) => {
        Object.values(COLLECTIONS).forEach((key) => {
            (Array.isArray(scene[key]) ? scene[key] : []).forEach((o) => {
                if (o && o.id) {
                    ids.add(o.id);
                }
            });
        });
    });
    return ids;
};

/**
 * Create the model.
 *
 * @param {Object} initial Parsed vrroom-editdoc/1 document.
 * @returns {Object} Model
 */
export const createModel = (initial) => {
    let current = clone(initial);
    if (!Array.isArray(current.scenes)) {
        current.scenes = [];
    }
    if (!current.graph || typeof current.graph !== 'object') {
        current.graph = {schema: 'vrroom-graph/1', hidden: [], objectives: [], rules: []};
    }
    if (!Array.isArray(current.bindings)) {
        current.bindings = [];
    }
    let revision = String(initial.revision || '');
    let saved = current;
    let past = [];
    let future = [];
    let selection = null;
    let activescene = current.scenes.length ? current.scenes[0].id : null;
    const listeners = {change: new Set(), select: new Set(), saved: new Set(), scene: new Set()};

    const emit = (type, detail) => {
        (listeners[type] || new Set()).forEach((listener) => {
            try {
                listener(detail);
            } catch (e) {
                Log.debug(e);
            }
        });
    };

    /** Keep the selection and the active room pointing at things that exist. */
    const repair = () => {
        if (!findScene(current, activescene)) {
            activescene = current.scenes.length ? current.scenes[0].id : null;
            emit('scene', {scene: activescene});
        }
        if (selection && !findRef(current, selection)) {
            selection = null;
            emit('select', {ref: null});
        }
    };

    const model = {
        /**
         * The current document (read only: change it through transact()).
         *
         * @returns {Object}
         */
        getDocument: () => {
            current.revision = revision;
            return current;
        },

        /**
         * Change the document as one undoable step.
         *
         * @param {string} label What the step did, for the Undo and Redo buttons.
         * @param {Function} mutate Called with a copy of the document to change.
         * @returns {boolean} Whether anything changed.
         */
        transact: (label, mutate) => {
            const draft = clone(current);
            const result = mutate(draft);
            if (result === false || JSON.stringify(draft) === JSON.stringify(current)) {
                return false;
            }
            past.push({label: label, doc: current});
            if (past.length > MAXHISTORY) {
                past = past.slice(past.length - MAXHISTORY);
            }
            future = [];
            current = draft;
            repair();
            emit('change', {label: label});
            return true;
        },

        /** Go back one step. */
        undo: () => {
            const step = past.pop();
            if (!step) {
                return;
            }
            future.push({label: step.label, doc: current});
            current = step.doc;
            repair();
            emit('change', {label: step.label, undo: true});
        },

        /** Go forward one step. */
        redo: () => {
            const step = future.pop();
            if (!step) {
                return;
            }
            past.push({label: step.label, doc: current});
            current = step.doc;
            repair();
            emit('change', {label: step.label, redo: true});
        },

        canUndo: () => past.length > 0,
        canRedo: () => future.length > 0,
        undoLabel: () => (past.length ? past[past.length - 1].label : ''),
        redoLabel: () => (future.length ? future[future.length - 1].label : ''),

        /**
         * Select something, or nothing.
         *
         * @param {Object|null} ref {scene, kind, id}
         */
        select: (ref) => {
            const next = ref && findRef(current, ref) ? {scene: ref.scene, kind: ref.kind, id: ref.id} : null;
            if (next && next.scene !== activescene) {
                activescene = next.scene;
                emit('scene', {scene: activescene});
            }
            selection = next;
            emit('select', {ref: next});
        },

        getSelection: () => selection,

        /**
         * The room being edited.
         *
         * @returns {Object|null}
         */
        getActiveScene: () => findScene(current, activescene),

        /**
         * Edit another room.
         *
         * @param {string} sceneid Scene id.
         */
        setActiveScene: (sceneid) => {
            if (sceneid === activescene || !findScene(current, sceneid)) {
                return;
            }
            activescene = sceneid;
            emit('scene', {scene: activescene});
            if (selection && selection.scene !== activescene) {
                selection = null;
                emit('select', {ref: null});
            }
        },

        isDirty: () => current !== saved,

        /**
         * A document has been stored.
         *
         * @param {string} newrevision The revision the server returned.
         * @param {Object} [sent] The document that was sent (default: the current one); edits
         *     made after it was sent stay unsaved.
         */
        markSaved: (newrevision, sent) => {
            revision = String(newrevision);
            saved = sent || current;
            emit('saved', {revision: revision});
        },

        getRevision: () => revision,

        /**
         * Forget the undo and redo steps whose document fails a test (each step is a whole
         * document, so the others still undo and redo correctly).
         *
         * @param {Function} unusable Called with a step's document; true drops the step.
         * @returns {number} How many steps were dropped.
         */
        pruneHistory: (unusable) => {
            const before = past.length + future.length;
            past = past.filter((step) => !unusable(step.doc));
            future = future.filter((step) => !unusable(step.doc));
            const dropped = before - past.length - future.length;
            if (dropped) {
                emit('saved', {revision: revision});
            }
            return dropped;
        },

        /**
         * A new object id that no room uses yet.
         *
         * @param {string} prefix Short prefix such as 'h' or 'z'.
         * @returns {string}
         */
        newId: (prefix) => {
            const used = allIds(current);
            let id = `${prefix}-${uuid()}`;
            while (used.has(id)) {
                id = `${prefix}-${uuid()}`;
            }
            return id;
        },

        /**
         * A new room id (a bare UUID, at most 36 characters).
         *
         * @returns {string}
         */
        newSceneId: () => uuid(),

        /**
         * Listen to 'change', 'select', 'saved' or 'scene'.
         *
         * @param {string} type Event type.
         * @param {Function} listener Called with the event detail.
         * @returns {Function} Unsubscribe.
         */
        on: (type, listener) => {
            if (!listeners[type]) {
                listeners[type] = new Set();
            }
            listeners[type].add(listener);
            return () => listeners[type].delete(listener);
        },
    };
    return model;
};
