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
 * The accessible list view: the room's stations as ordinary page sections, usable with a
 * keyboard and a screen reader, emitting the same statements as the 3D room.
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
 * Template context of one item.
 *
 * @param {Object} item Interactable.
 * @param {Set} hidden Hidden ids.
 * @returns {Object}
 */
const itemContext = (item, hidden) => {
    const content = item.content || {};
    const slot = item.kind === 'hotspot' && content.question !== null && content.question !== undefined
        ? Number(content.question) : null;
    return {
        id: item.id,
        title: plainText(item.kind === 'hotspot' ? (content.title || item.name) : item.name),
        html: item.kind === 'hotspot' ? (content.html || '') : '',
        text: item.kind === 'text' ? plainText(item.text || '') : '',
        imageurl: item.kind === 'image' && item.file && item.file.url ? item.file.url : '',
        ismodel: item.kind === 'model',
        hidden: hidden.has(item.id),
        slot: slot,
    };
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
    const scene = startScene(config);
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

    return getStrings(stringrequests).then(([locked, available, completed, stationlocked]) => {
        const labels = {locked: locked, available: available, completed: completed};
        const zones = scene && Array.isArray(scene.zones) ? scene.zones : [];
        const interactables = scene && Array.isArray(scene.interactables) ? scene.interactables : [];
        const sceneid = scene ? scene.id : null;
        const state = session.getState();

        const zoneStatus = (zoneid) => {
            const objective = session.getObjectiveForZone(zoneid);
            if (objective) {
                return objective.status;
            }
            return session.isZoneLocked(zoneid) ? 'locked' : 'available';
        };

        const stations = zones.map((zone) => {
            const status = zoneStatus(zone.id);
            const zoneitems = interactables.filter((i) => i.zone === zone.id);
            const slots = [];
            zoneitems.forEach((i) => {
                const ctx = itemContext(i, state.hidden);
                if (ctx.slot !== null && !slots.some((s) => s.slot === ctx.slot)) {
                    slots.push({slot: ctx.slot});
                }
            });
            return {
                zoneid: zone.id,
                name: plainText(zone.name),
                status: status,
                statuslabel: labels[status],
                badgeclass: BADGES[status],
                hidden: state.hidden.has(zone.id),
                items: zoneitems.map((i) => itemContext(i, state.hidden)),
                slots: slots,
            };
        });
        const others = interactables.filter((i) => !i.zone || !zones.some((z) => z.id === i.zone))
            .map((i) => itemContext(i, state.hidden));

        const context = {
            canswitch: !!config.canswitch3d,
            stations: stations,
            hasothers: others.length > 0,
            others: others,
        };

        return Templates.renderForPromise('mavrruntime_builtin/listview', context).then(({html, js}) => {
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
                session.record('interacted', 'zone', zoneid, sceneid);
                // Reading a station counts as interacting with its hotspots, as clicking them does in 3D.
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
                session.record('interacted', 'object', button.dataset.item, sceneid);
                renderQuestions(body);
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
                const switchbutton = e.target.closest('[data-action="mavr-switch-3d"]');
                if (switchbutton && config.onSwitch3d) {
                    e.preventDefault();
                    config.onSwitch3d();
                }
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
                    if (effect.do !== 'open_question') {
                        return;
                    }
                    const qhost = listview.querySelector(`[data-region="mavr-question"][data-slot="${Number(effect.target)}"]`);
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

            session.start('builtin-list', sceneid);

            return createProgress(region(root, 'mavr-list-progress'), session, {
                onFinish: config.onFinish,
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
                    session.stop();
                    host.replaceChildren();
                    host.classList.add('d-none');
                    delete root.dataset.mavrReady;
                },
            };
        });
    }).catch((error) => {
        pending.resolve();
        Notification.exception(error);
        throw error;
    });
};
