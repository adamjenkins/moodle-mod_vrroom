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
 * @module     mod_mavr/editor_outline
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getStrings} from 'core/str';
import {displayName} from 'mod_mavr/editor_inspector';

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
 * @param {Object} context Editor context ({info, actions: {addScene, deleteScene, moveScene}}).
 * @returns {Promise<Object>} {refresh(), destroy()}
 */
export const init = (container, model, context) => getStrings(GROUPS.map(([, , key]) => ({key: key, component: 'mod_mavr'})))
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
     * The template context for the current document.
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
                        .map((item) => ({kind: kind, id: item.id, name: displayName(item), selected: isSelected(kind, item.id)}));
                } else {
                    const key = {spawn: 'spawn', zone: 'zones', prop: 'props'}[kind];
                    items = (Array.isArray(active[key]) ? active[key] : [])
                        .filter((object) => object && object.id)
                        .map((object) => ({kind: kind, id: object.id, name: displayName(object),
                            selected: isSelected(kind, object.id)}));
                }
                if (items.length) {
                    groups.push({group: group, heading: headings[i], items: items});
                }
            });
        }

        return {
            scenes: scenes.map((scene, i) => ({id: scene.id, name: displayName(scene), active: i === index, start: i === 0})),
            canaddscene: scenes.length < maxscenes,
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
        const pending = new Pending('mod_mavr/editor_outline:render');
        Templates.renderForPromise('mod_mavr/editor_outline', build()).then(({html, js}) => {
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
        }).catch(Notification.exception).then(() => pending.resolve(), () => pending.resolve());
    };

    const onClick = (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !container.contains(button) || button.disabled) {
            return;
        }
        const active = model.getActiveScene();
        switch (button.dataset.action) {
            case 'mavr-editor-scene':
                model.setActiveScene(button.dataset.scene);
                model.select({scene: button.dataset.scene, kind: 'environment', id: button.dataset.scene});
                break;
            case 'mavr-editor-select':
                if (active) {
                    model.select({scene: active.id, kind: button.dataset.kind, id: button.dataset.id});
                }
                break;
            case 'mavr-editor-addscene':
                context.actions.addScene();
                break;
            case 'mavr-editor-scene-up':
                if (active) {
                    context.actions.moveScene(active.id, -1);
                }
                break;
            case 'mavr-editor-scene-down':
                if (active) {
                    context.actions.moveScene(active.id, 1);
                }
                break;
            case 'mavr-editor-scene-delete':
                if (active) {
                    context.actions.deleteScene(active.id);
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
