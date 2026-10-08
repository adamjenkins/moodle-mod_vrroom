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
 * The accessible list view: the stations of every room as ordinary page sections, usable with a
 * keyboard and a screen reader, emitting the same statements as the 3D room.
 *
 * Objects the tasks ask for (keys, things to use, objects to pick up) get a button, audio and
 * video play in the page, and portals say where they lead.
 *
 * @module     mavrruntime_builtin/listview
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import {createProgress} from 'mavrruntime_builtin/panels';
import {renderQuestion} from 'mavrruntime_builtin/question';
import {BADGES, region, plainText, announce, showMessage, clearMessage, startScene} from 'mavrruntime_builtin/util';

/**
 * Ids the tasks ask the student to use: interact targets and collect targets.
 *
 * @param {Object} graph The client graph.
 * @returns {Set<string>}
 */
const usableIds = (graph) => {
    const ids = new Set();
    const objectives = graph && Array.isArray(graph.objectives) ? graph.objectives : [];
    objectives.forEach((o) => {
        if (!o) {
            return;
        }
        if (o.type === 'interact' && o.target) {
            ids.add(String(o.target));
        } else if (o.type === 'collect' && Array.isArray(o.targets)) {
            o.targets.forEach((t) => ids.add(String(t)));
        }
    });
    return ids;
};

/**
 * The scenes of the launch config, start room first, then the others in their order.
 *
 * @param {Object} config Launch config.
 * @returns {Object[]}
 */
const orderedScenes = (config) => {
    const scenes = Array.isArray(config.scenes) ? config.scenes.filter((s) => s && s.id) : [];
    const first = startScene(config);
    if (!first) {
        return scenes;
    }
    return [first].concat(scenes.filter((s) => s.id !== first.id));
};

/**
 * Template context of one item (labels that need strings are filled in later).
 *
 * @param {Object} item Interactable.
 * @param {Object} scene Its room.
 * @param {Set} hidden Hidden ids.
 * @param {Set} usable Ids the tasks ask for.
 * @returns {Object}
 */
const itemContext = (item, scene, hidden, usable) => {
    const content = item.content || {};
    const slot = item.kind === 'hotspot' && content.question !== null && content.question !== undefined
        ? Number(content.question) : null;
    const mediaurl = item.kind === 'media' && item.file && item.file.url ? item.file.url : '';
    const usebutton = item.kind === 'grabbable' || usable.has(String(item.id));
    return {
        id: item.id,
        sceneid: scene.id,
        kind: item.kind,
        name: plainText(item.name || ''),
        title: plainText(item.kind === 'hotspot' ? (content.title || item.name) : item.name),
        html: item.kind === 'hotspot' ? (content.html || '') : '',
        text: item.kind === 'text' ? plainText(item.text || '') : '',
        imageurl: item.kind === 'image' && item.file && item.file.url ? item.file.url : '',
        ismodel: item.kind === 'model',
        isaudio: !!mediaurl && item.media === 'audio',
        isvideo: !!mediaurl && item.media === 'video',
        mediaurl: mediaurl,
        portaltarget: item.kind === 'portal' && item.target ? String(item.target.scene || '') : '',
        portaltext: '',
        usebutton: usebutton,
        uselabel: '',
        hidden: hidden.has(item.id),
        slot: slot,
    };
};

/**
 * Fill in the labels of item contexts that need a string with a parameter.
 *
 * @param {Object[]} items Item contexts (changed in place).
 * @param {Map} scenenames Scene id => plain-text room name.
 * @returns {Promise}
 */
const fillLabels = (items, scenenames) => {
    const requests = [];
    const targets = [];
    items.forEach((item) => {
        if (item.usebutton) {
            requests.push({key: item.kind === 'grabbable' ? 'pickup' : 'useitem', component: 'mavrruntime_builtin',
                param: item.name || item.title});
            targets.push([item, 'uselabel']);
        }
        if (item.portaltarget && scenenames.has(item.portaltarget)) {
            requests.push({key: 'leadsto', component: 'mavrruntime_builtin', param: scenenames.get(item.portaltarget)});
            targets.push([item, 'portaltext']);
        }
    });
    if (requests.length === 0) {
        return Promise.resolve();
    }
    return getStrings(requests).then((strings) => {
        strings.forEach((text, i) => {
            const [item, field] = targets[i];
            item[field] = text;
        });
        return undefined;
    });
};

/**
 * Start the list view.
 *
 * @param {HTMLElement} root The MAVR root element (holding the shell).
 * @param {Object} session The session.
 * @param {Object} config Launch config; config.canswitch3d shows the switch button,
 *     config.onFinish finishes the attempt, config.onSwitch3d switches to the 3D room.
 * @returns {Promise<Object>} {stop()}
 */
export const start = (root, session, config) => {
    const pending = new Pending('mavrruntime_builtin/listview:start');
    const startscene = startScene(config);
    const scenes = orderedScenes(config);
    const multiroom = scenes.length > 1;
    const host = region(root, 'mavr-list');
    let stopped = false;
    let progress = null;
    let unsubscribe = () => null;
    let lockedtimer = null;

    const stringrequests = [
        {key: 'objectivestatus_locked', component: 'mavrruntime_builtin'},
        {key: 'objectivestatus_available', component: 'mavrruntime_builtin'},
        {key: 'objectivestatus_completed', component: 'mavrruntime_builtin'},
        {key: 'stationlocked', component: 'mavrruntime_builtin'},
    ];
    scenes.forEach((scene) => {
        stringrequests.push({key: 'roomlabel', component: 'mavrruntime_builtin', param: plainText(scene.name || '')});
    });

    return getStrings(stringrequests).then((strings) => {
        const [locked, available, completed, stationlocked] = strings;
        const roomlabels = strings.slice(4);
        const labels = {locked: locked, available: available, completed: completed};
        const state = session.getState();
        const usable = usableIds(session.graph);
        const scenenames = new Map(scenes.map((s) => [s.id, plainText(s.name || '')]));
        const allitems = [];

        const zoneStatus = (zoneid) => {
            const objective = session.getObjectiveForZone(zoneid);
            if (objective) {
                return objective.status;
            }
            return session.isZoneLocked(zoneid) ? 'locked' : 'available';
        };

        const stations = [];
        const others = [];
        scenes.forEach((scene, index) => {
            const zones = Array.isArray(scene.zones) ? scene.zones : [];
            const interactables = Array.isArray(scene.interactables) ? scene.interactables : [];
            zones.forEach((zone) => {
                const status = zoneStatus(zone.id);
                const items = interactables.filter((i) => i.zone === zone.id)
                    .map((i) => itemContext(i, scene, state.hidden, usable));
                const slots = [];
                items.forEach((ctx) => {
                    if (ctx.slot !== null && !slots.some((s) => s.slot === ctx.slot)) {
                        slots.push({slot: ctx.slot});
                    }
                });
                allitems.push(...items);
                stations.push({
                    zoneid: zone.id,
                    sceneid: scene.id,
                    name: plainText(zone.name),
                    roomlabel: multiroom ? roomlabels[index] : '',
                    status: status,
                    statuslabel: labels[status],
                    badgeclass: BADGES[status],
                    hidden: state.hidden.has(zone.id),
                    items: items,
                    slots: slots,
                });
            });
            interactables.filter((i) => !i.zone || !zones.some((z) => z.id === i.zone)).forEach((i) => {
                const ctx = itemContext(i, scene, state.hidden, usable);
                ctx.roomlabel = multiroom ? roomlabels[index] : '';
                others.push(ctx);
                allitems.push(ctx);
            });
        });

        const context = {
            canswitch: !!config.canswitch3d,
            stations: stations,
            hasothers: others.length > 0,
            others: others,
        };

        return fillLabels(allitems, scenenames)
            .then(() => Templates.renderForPromise('mavrruntime_builtin/listview', context))
            .then(({html, js}) => {
                Templates.replaceNodeContents(host, html, js);
                host.classList.remove('d-none');

                const showLocked = (station) => {
                    const note = station ? station.querySelector('[data-region="mavr-station-locked"]') : null;
                    if (note) {
                        note.hidden = false;
                    }
                    showMessage(root, stationlocked, 'warning');
                    announce(root, stationlocked);
                    if (lockedtimer) {
                        window.clearTimeout(lockedtimer);
                    }
                    lockedtimer = window.setTimeout(() => clearMessage(root, stationlocked), 4000);
                };

                const renderQuestions = (container) => {
                    container.querySelectorAll('[data-region="mavr-question"]').forEach((qhost) => {
                        const qstate = qhost.dataset.mavrQuestionState;
                        if (!qstate || (qstate === 'locked' && session.isSlotAvailable(qhost.dataset.slot))) {
                            renderQuestion(qhost, session, qhost.dataset.slot);
                        }
                    });
                };

                const openStation = (button, quiet) => {
                    const station = button.closest('[data-region="mavr-station"]');
                    const zoneid = button.dataset.zone;
                    const sceneid = station.dataset.scene;
                    const body = station.querySelector('[data-region="mavr-station-body"]');
                    if (button.getAttribute('aria-expanded') === 'true') {
                        if (!quiet) {
                            body.hidden = true;
                            button.setAttribute('aria-expanded', 'false');
                        }
                        return;
                    }
                    if (session.isZoneLocked(zoneid)) {
                        showLocked(station);
                        return;
                    }
                    station.querySelector('[data-region="mavr-station-locked"]').hidden = true;
                    // Expand first: recording may run graph effects that look at the expanded state.
                    body.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                    session.setScene(sceneid);
                    session.record('interacted', 'zone', zoneid, sceneid);
                    // Reading a station counts as interacting with its hotspots, as clicking them does in 3D.
                    const scene = scenes.find((s) => s.id === sceneid);
                    const interactables = scene && Array.isArray(scene.interactables) ? scene.interactables : [];
                    interactables.filter((i) => i.zone === zoneid && i.kind === 'hotspot').forEach((i) => {
                        session.record('interacted', 'object', i.id, sceneid);
                    });
                    renderQuestions(body);
                };

                const openItem = (button) => {
                    const card = button.closest('[data-interactable]');
                    const body = card.querySelector('[data-region="mavr-item-body"]');
                    if (button.getAttribute('aria-expanded') === 'true') {
                        body.hidden = true;
                        button.setAttribute('aria-expanded', 'false');
                        return;
                    }
                    if (session.isObjectLocked(button.dataset.item)) {
                        showLocked(null);
                        return;
                    }
                    body.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                    session.setScene(card.dataset.scene);
                    session.record('interacted', 'object', button.dataset.item, card.dataset.scene);
                    renderQuestions(body);
                };

                const useItem = (button) => {
                    const itemid = button.dataset.item;
                    if (session.isObjectLocked(itemid)) {
                        showLocked(button.closest('[data-region="mavr-station"]'));
                        return;
                    }
                    session.setScene(button.dataset.scene);
                    session.record('interacted', 'object', itemid, button.dataset.scene);
                };

                const listview = region(root, 'mavr-listview');
                listview.addEventListener('click', (e) => {
                    const stationbutton = e.target.closest('[data-action="mavr-open-station"]');
                    if (stationbutton) {
                        e.preventDefault();
                        openStation(stationbutton, false);
                        return;
                    }
                    const itembutton = e.target.closest('[data-action="mavr-open-item"]');
                    if (itembutton) {
                        e.preventDefault();
                        openItem(itembutton);
                        return;
                    }
                    const usebutton = e.target.closest('[data-action="mavr-use-item"]');
                    if (usebutton) {
                        e.preventDefault();
                        useItem(usebutton);
                        return;
                    }
                    const switchbutton = e.target.closest('[data-action="mavr-switch-3d"]');
                    if (switchbutton && config.onSwitch3d) {
                        e.preventDefault();
                        config.onSwitch3d();
                    }
                });

                // The first play of an audio or video counts as interacting with it.
                const played = new Set();
                listview.querySelectorAll('[data-region="mavr-media"]').forEach((media) => {
                    media.addEventListener('play', () => {
                        const itemid = media.dataset.item;
                        if (stopped || played.has(itemid)) {
                            return;
                        }
                        played.add(itemid);
                        session.setScene(media.dataset.scene);
                        session.record('interacted', 'object', itemid, media.dataset.scene);
                    });
                });

                // Keep badges, hidden items and questions in step with the state.
                const update = (message) => {
                    const current = message.state;
                    listview.querySelectorAll('[data-region="mavr-station"]').forEach((station) => {
                        const zoneid = station.dataset.zone;
                        const status = zoneStatus(zoneid);
                        const badge = station.querySelector('[data-region="mavr-station-status"]');
                        if (badge.dataset.status !== status) {
                            badge.dataset.status = status;
                            badge.className = `badge ${BADGES[status]}`;
                            badge.textContent = labels[status];
                        }
                        if (status !== 'locked') {
                            station.querySelector('[data-region="mavr-station-locked"]').hidden = true;
                        }
                        const item = station.closest('[data-region="mavr-station-item"]');
                        if (item) {
                            item.hidden = current.hidden.has(zoneid);
                        }
                    });
                    listview.querySelectorAll('[data-interactable]').forEach((el) => {
                        el.hidden = current.hidden.has(el.dataset.interactable);
                    });
                    listview.querySelectorAll('[data-region="mavr-station-body"]:not([hidden]),' +
                        '[data-region="mavr-item-body"]:not([hidden])').forEach((body) => renderQuestions(body));
                    message.completed.concat(message.unlocked).forEach((objective) => {
                        getString('objectivestatuschanged', 'mavrruntime_builtin', {
                            title: objective.title,
                            status: labels[objective.status],
                        }).then((text) => announce(root, text)).catch(() => null);
                    });
                    message.effects.forEach((effect) => {
                        if (effect.do === 'play_media') {
                            const media = listview.querySelector(
                                `[data-region="mavr-media"][data-item="${CSS.escape(String(effect.target))}"]`);
                            if (media) {
                                Promise.resolve(media.play()).catch(() => null);
                            }
                            return;
                        }
                        if (effect.do !== 'open_question') {
                            return;
                        }
                        const qhost = listview.querySelector(
                            `[data-region="mavr-question"][data-slot="${Number(effect.target)}"]`);
                        if (!qhost) {
                            return;
                        }
                        const station = qhost.closest('[data-region="mavr-station"]');
                        const button = station ? station.querySelector('[data-action="mavr-open-station"]') : null;
                        if (button && button.getAttribute('aria-expanded') !== 'true') {
                            openStation(button, true);
                        } else {
                            renderQuestions(qhost.parentElement);
                        }
                    });
                };
                unsubscribe = session.on((message) => {
                    if (message.type === 'change' && !stopped) {
                        update(message);
                    }
                });

                session.start('builtin-list', startscene ? startscene.id : null);

                return createProgress(region(root, 'mavr-list-progress'), session, {
                    root: root,
                    onFinish: config.onFinish,
                });
            });
    }).then((created) => {
        progress = created;
        root.dataset.mavrReady = 'list';
        const heading = host.querySelector('h2');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
        }
        pending.resolve();
        return {
            /** Stop the list view. */
            stop: () => {
                if (stopped) {
                    return;
                }
                stopped = true;
                unsubscribe();
                if (progress) {
                    progress.destroy();
                }
                if (lockedtimer) {
                    window.clearTimeout(lockedtimer);
                }
                host.querySelectorAll('audio, video').forEach((media) => media.pause());
                session.stop();
                host.replaceChildren();
                host.classList.add('d-none');
                delete root.dataset.mavrReady;
            },
        };
    }).catch((error) => {
        pending.resolve();
        Notification.exception(error);
        throw error;
    });
};
