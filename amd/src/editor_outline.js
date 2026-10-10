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
 * The room editor's outline: the rooms, then everything in the chosen room grouped by kind, as a
 * list of buttons (the keyboard and screen reader way to select things).
 *
 * @module     mod_vrroom/editor_outline
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';
import {displayName} from 'mod_vrroom/editor_inspector';

/** Outline groups in display order: [group, selection kind, heading string]. */
const GROUPS = [
    ['environment', 'environment', 'editorenvironment'],
    ['spawn', 'spawn', 'editoraddspawn'],
    ['zone', 'zone', 'editoraddzone'],
    ['prop', 'prop', 'editoraddprop'],
    ['hotspot', 'interactable', 'editoraddhotspot'],
    ['model', 'interactable', 'editoraddmodel'],
    ['image', 'interactable', 'editoraddimage'],
    ['text', 'interactable', 'editoraddtext'],
    ['portal', 'interactable', 'editoraddportal'],
    ['grabbable', 'interactable', 'editoraddgrabbable'],
    ['media', 'interactable', 'editoraddmedia'],
];

/**
 * Start the outline.
 *
 * @param {HTMLElement} container The outline region.
 * @param {Object} model The editor model.
 * @param {Object} context Editor context ({info, presets, actions: {addScene(kind, trigger), deleteScene, moveScene}}).
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
export const init = (container, model, context) => getStrings(GROUPS.map(([, , key]) => ({key: key, component: 'mod_vrroom'})))
    .then((headings) => start(container, model, context, headings));

/**
 * Run the outline once its strings are loaded.
 *
 * @param {HTMLElement} container The outline region.
 * @param {Object} model The editor model.
 * @param {Object} context Editor context.
 * @param {string[]} headings Group headings in GROUPS order.
 * @returns {Object} {refresh(), destroy()}
 */
const start = (container, model, context, headings) => {
    let destroyed = false;
    let renderid = 0;

    /**
     * A readable name: the object's own, or "<kind> <number>" for an unnamed one (never its id).
     *
     * @param {Object} object Scene object or room.
     * @param {string} kindlabel What it is, such as "Start point".
     * @param {number} number Its place in its group, from 1.
     * @returns {Promise<string>}
     */
    const nameOf = (object, kindlabel, number) => {
        const name = displayName(object);
        if (name !== '' && name !== String(object.id || '')) {
            return Promise.resolve(name);
        }
        if (kindlabel === null) {
            return getString('editornewscene', 'mod_vrroom', number);
        }
        return getString('editornewname', 'mod_vrroom', {kind: kindlabel, number: number});
    };

    /**
     * Put readable names on the items of a template context.
     *
     * @param {Object} data From build().
     * @returns {Promise<Object>} The same data.
     */
    const nameAll = (data) => {
        const work = [];
        data.scenes.forEach((scene) => {
            work.push(nameOf(scene.object, null, scene.number).then((name) => {
                scene.name = name;
                return null;
            }));
            delete scene.object;
        });
        data.groups.forEach((group) => {
            group.items.forEach((item) => {
                if (item.object) {
                    work.push(nameOf(item.object, group.heading, item.number).then((name) => {
                        item.name = name;
                        return null;
                    }));
                    delete item.object;
                }
            });
        });
        return Promise.all(work).then(() => data);
    };

    /**
     * The template context for the current document (names still to be filled in by nameAll()).
     *
     * @returns {Object}
     */
    const build = () => {
        const doc = model.getDocument();
        const scenes = doc.scenes || [];
        const active = model.getActiveScene();
        const selection = model.getSelection();
        const index = active ? scenes.findIndex((s) => s.id === active.id) : -1;
        const maxscenes = Number(context.info.maxscenes) || 20;
        const isSelected = (kind, id) => !!selection && selection.kind === kind && selection.id === id &&
            !!active && selection.scene === active.id;

        const groups = [];
        if (active) {
            GROUPS.forEach(([group, kind], i) => {
                let items = [];
                if (group === 'environment') {
                    items = [{kind: 'environment', id: active.id, name: headings[i],
                        selected: isSelected('environment', active.id)}];
                } else if (kind === 'interactable') {
                    items = (Array.isArray(active.interactables) ? active.interactables : [])
                        .filter((item) => item && item.kind === group)
                        .map((item, n) => ({kind: kind, id: item.id, object: item, number: n + 1,
                            selected: isSelected(kind, item.id)}));
                } else {
                    const key = {spawn: 'spawn', zone: 'zones', prop: 'props'}[kind];
                    items = (Array.isArray(active[key]) ? active[key] : [])
                        .filter((object) => object && object.id)
                        .map((object, n) => ({kind: kind, id: object.id, object: object, number: n + 1,
                            selected: isSelected(kind, object.id)}));
                }
                if (items.length) {
                    groups.push({group: group, heading: headings[i], items: items});
                }
            });
        }

        return {
            scenes: scenes.map((scene, i) => ({id: scene.id, object: scene, number: i + 1, active: i === index, start: i === 0})),
            canaddscene: scenes.length < maxscenes,
            haspresets: Object.keys(context.presets || {}).length > 0,
            canmoveup: index > 0,
            canmovedown: index >= 0 && index < scenes.length - 1,
            candelete: index >= 0 && scenes.length > 1,
            groups: groups,
        };
    };

    /** Draw the outline, keeping the keyboard focus on the same button. */
    const refresh = () => {
        if (destroyed) {
            return;
        }
        const focused = document.activeElement && container.contains(document.activeElement) ? document.activeElement : null;
        const focuskey = focused ? {action: focused.dataset.action, id: focused.dataset.id || focused.dataset.scene || ''} : null;
        const id = ++renderid;
        const pending = new Pending('mod_vrroom/editor_outline:render');
        nameAll(build()).then((data) => Templates.renderForPromise('mod_vrroom/editor_outline', data)).then(({html, js}) => {
            if (id !== renderid || destroyed) {
                return null;
            }
            Templates.replaceNodeContents(container, html, js);
            if (focuskey) {
                const candidates = Array.from(container.querySelectorAll(`[data-action="${focuskey.action}"]`));
                const again = candidates.find((el) => (el.dataset.id || el.dataset.scene || '') === focuskey.id) ||
                    candidates[0];
                if (again && !again.disabled) {
                    again.focus({preventScroll: true});
                }
            }
            return null;
        }).catch(Notification.exception).finally(() => pending.resolve());
    };

    const onClick = (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !container.contains(button) || button.disabled) {
            return;
        }
        const active = model.getActiveScene();
        switch (button.dataset.action) {
            case 'vrroom-editor-scene':
                model.setActiveScene(button.dataset.scene);
                model.select({scene: button.dataset.scene, kind: 'environment', id: button.dataset.scene});
                break;
            case 'vrroom-editor-select':
                if (active) {
                    model.select({scene: active.id, kind: button.dataset.kind, id: button.dataset.id});
                }
                break;
            case 'vrroom-editor-addscene':
                context.actions.addScene('preset', button);
                break;
            case 'vrroom-editor-addphotoscene':
                context.actions.addScene('photo', button);
                break;
            case 'vrroom-editor-scene-up':
                if (active) {
                    context.actions.moveScene(active.id, -1);
                }
                break;
            case 'vrroom-editor-scene-down':
                if (active) {
                    context.actions.moveScene(active.id, 1);
                }
                break;
            case 'vrroom-editor-scene-delete':
                if (active) {
                    context.actions.deleteScene(active.id, button);
                }
                break;
        }
    };
    container.addEventListener('click', onClick);
    const unsubscribers = [
        model.on('change', refresh),
        model.on('select', refresh),
        model.on('scene', refresh),
    ];
    refresh();

    return {
        refresh: refresh,
        destroy: () => {
            destroyed = true;
            container.removeEventListener('click', onClick);
            unsubscribers.forEach((u) => u());
        },
    };
};
