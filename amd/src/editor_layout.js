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
 * The room editor's layout rules, as plain functions on scene documents (no Moodle imports, so
 * tests/js/editor_layout.test.mjs runs them in Node).
 *
 * - Start points, zones, blocks and objects stay inside the room's walkable bounds.
 * - A station is a zone plus the objects that name it in their 'zone' key (and the template's
 *   plinth block "p-<id>" of zone "z-<id>"): moving the zone moves all of them.
 * - Changing a room's surroundings puts the first start point at the new room's own start and
 *   moves what lies outside the new bounds inside them.
 * - What the tasks refer to, so that nothing they need can be deleted.
 *
 * @module     mod_vrroom/editor_layout
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * How far (metres) start points and zone centres stay inside the bounds: the 3D room's own
 * walking margin (vrroomruntime_builtin/controls MARGIN), so a student can stand there.
 */
export const INSET = 0.3;

/**
 * How far (metres) blocks and objects may stand outside the walkable bounds: pictures and boards
 * hang on the walls, which lie a little outside the area students walk in.
 */
export const WALLSLACK = 1;

/** How far (metres) below the floor (y = 0) a thing may go: a rug or a sunken step, no more. */
export const SINK = 0.5;

/** Rule actions whose target is a thing or a start point. */
const TARGETACTIONS = ['show', 'hide', 'play_media', 'teleport'];

/**
 * Round to 3 decimals.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const r3 = (value) => Math.round(value * 1000) / 1000;

/**
 * Whether a value is a vector of three finite numbers.
 *
 * @param {*} value Value.
 * @returns {boolean}
 */
const isVector = (value) => Array.isArray(value) && value.length === 3 && value.every((v) => Number.isFinite(Number(v)));

/**
 * A list, or an empty one.
 *
 * @param {*} value Value.
 * @returns {Array}
 */
const list = (value) => (Array.isArray(value) ? value : []);

/**
 * The walkable bounds of a room, or null when it has none usable.
 *
 * @param {Object|null} scene Scene document (or an environment: anything with 'bounds').
 * @returns {Object|null} {min: number[3], max: number[3]}
 */
export const boundsOf = (scene) => {
    let bounds = null;
    if (scene && scene.environment) {
        bounds = scene.environment.bounds;
    } else if (scene) {
        bounds = scene.bounds;
    }
    if (!bounds || !isVector(bounds.min) || !isVector(bounds.max)) {
        return null;
    }
    const min = bounds.min.map(Number);
    const max = bounds.max.map(Number);
    if (min[0] > max[0] || min[2] > max[2]) {
        return null;
    }
    return {min: min, max: max};
};

/**
 * The key of an object's position.
 *
 * @param {string} kind spawn, zone, prop or interactable.
 * @returns {string} 'center' for zones, else 'position'.
 */
export const positionKey = (kind) => (kind === 'zone' ? 'center' : 'position');

/**
 * How far inside (positive) or outside (negative) the bounds a kind of thing must stay.
 *
 * @param {string} kind spawn, zone, prop or interactable.
 * @returns {number}
 */
export const insetFor = (kind) => (kind === 'spawn' || kind === 'zone' ? INSET : -WALLSLACK);

/**
 * A floor point (x and z) moved inside bounds.
 *
 * @param {number[]} point [x, y, z].
 * @param {Object} bounds {min, max}.
 * @param {number} inset Metres inside (positive) or outside (negative) the bounds.
 * @returns {Object} {point: number[3], clamped: boolean}
 */
export const clampPoint = (point, bounds, inset) => {
    const p = point.map(Number);
    const out = p.slice();
    [0, 2].forEach((i) => {
        let lo = bounds.min[i] + inset;
        let hi = bounds.max[i] - inset;
        if (lo > hi) {
            // A room narrower than twice the inset: its middle.
            lo = hi = (bounds.min[i] + bounds.max[i]) / 2;
        }
        out[i] = r3(Math.min(Math.max(p[i], lo), hi));
    });
    const clamped = out[0] !== r3(p[0]) || out[2] !== r3(p[2]);
    return {point: clamped ? out : p, clamped: clamped};
};

/**
 * Whether a thing stands where its kind is allowed.
 *
 * @param {string} kind spawn, zone, prop or interactable.
 * @param {Object} object The thing.
 * @param {Object|null} bounds {min, max}, or null (anything goes).
 * @returns {boolean}
 */
export const isInside = (kind, object, bounds) => {
    const p = object ? object[positionKey(kind)] : null;
    if (!bounds || !isVector(p)) {
        return true;
    }
    return !clampPoint(p, bounds, insetFor(kind)).clamped;
};

/**
 * Move a thing inside the bounds of its room (changes it).
 *
 * @param {string} kind spawn, zone, prop or interactable.
 * @param {Object} object The thing.
 * @param {Object|null} bounds {min, max}, or null (nothing to do).
 * @returns {boolean} Whether it had to be moved.
 */
export const keepInside = (kind, object, bounds) => {
    const key = positionKey(kind);
    if (!bounds || !object || !isVector(object[key])) {
        return false;
    }
    const result = clampPoint(object[key], bounds, insetFor(kind));
    const height = clampHeight(kind, object, result.point);
    if (result.clamped || height.clamped) {
        object[key] = height.point;
    }
    return result.clamped || height.clamped;
};

/**
 * A point's height kept where its kind works: a zone must reach down to the floor (y = 0, where the
 * 3D room tests the walker's feet), and nothing may sink below the floor.
 *
 * @param {string} kind spawn, zone, prop or interactable.
 * @param {Object} object The thing (a zone's size is read from it).
 * @param {number[]} point [x, y, z].
 * @returns {Object} {point: number[3], clamped: boolean}
 */
export const clampHeight = (kind, object, point) => {
    const out = point.slice();
    let lo = -SINK;
    let hi = Infinity;
    if (kind === 'zone' && isVector(object.size)) {
        // The floor may lie on the zone's top or bottom face: the 3D room counts the faces as inside.
        const half = Math.abs(Number(object.size[1])) / 2;
        lo = -half;
        hi = half;
    }
    out[1] = r3(Math.min(Math.max(Number(point[1]) || 0, lo), hi));
    return {point: out, clamped: out[1] !== r3(Number(point[1]) || 0)};
};

/**
 * The objects and blocks that belong to a zone's station.
 *
 * @param {Object} scene Scene document.
 * @param {string} zoneid Zone id.
 * @returns {Object[]} [{kind: 'interactable'|'prop', object}]
 */
export const stationMembers = (scene, zoneid) => {
    const members = list(scene.interactables).filter((item) => item && item.zone === zoneid)
        .map((item) => ({kind: 'interactable', object: item}));
    // The gallery and escape templates put a plinth "p-<station>" under the model of zone "z-<station>".
    if (typeof zoneid === 'string' && zoneid.startsWith('z-')) {
        const plinth = `p-${zoneid.slice(2)}`;
        list(scene.props).filter((prop) => prop && prop.id === plinth)
            .forEach((prop) => members.push({kind: 'prop', object: prop}));
    }
    return members;
};

/**
 * Move a zone's station across the floor by the same amount as the zone (changes the scene).
 *
 * @param {Object} scene Scene document.
 * @param {string} zoneid Zone id.
 * @param {number[]} delta [dx, dy, dz].
 * @param {Object|null} bounds {min, max} to keep the members inside, or null.
 * @returns {Object[]} The members moved (as stationMembers() returns them).
 */
export const moveStation = (scene, zoneid, delta, bounds) => {
    if (!isVector(delta) || delta.every((v) => Number(v) === 0)) {
        return [];
    }
    const members = stationMembers(scene, zoneid).filter((m) => isVector(m.object.position));
    // Across the floor only: a zone's height says where feet count, its markers keep their own height.
    members.forEach((m) => {
        m.object.position = m.object.position.map((v, i) => (i === 1 ? Number(v) : r3(Number(v) + Number(delta[i]))));
        keepInside(m.kind, m.object, bounds);
    });
    return members;
};

/**
 * Where a point of old surroundings goes in new ones: the same place relative to the bounds.
 *
 * @param {number[]} point [x, y, z].
 * @param {Object|null} from Old bounds.
 * @param {Object} to New bounds.
 * @returns {number[]}
 */
const mapPoint = (point, from, to) => {
    const p = point.map(Number);
    if (!from) {
        return p;
    }
    const out = p.slice();
    [0, 2].forEach((i) => {
        const span = from.max[i] - from.min[i];
        const t = span > 0 ? (p[i] - from.min[i]) / span : 0.5;
        out[i] = r3(to.min[i] + Math.min(Math.max(t, 0), 1) * (to.max[i] - to.min[i]));
    });
    return out;
};

/**
 * Fit a room's contents to new surroundings (changes the scene; its environment is already the new one).
 *
 * The first start point goes to the new surroundings' own start (when they have one). Every other
 * start point, zone, block and object that lies outside the new bounds is moved to the same place
 * relative to the old bounds, then kept inside; a zone takes its station along.
 *
 * @param {Object} scene Draft scene with its new environment.
 * @param {Object|null} oldbounds The bounds of the old surroundings.
 * @param {Object|null} start {position, yaw} of the new surroundings, or null.
 * @returns {Object} {start: boolean (the first start point was set), moved: [{kind, object}]}
 */
export const fitToEnvironment = (scene, oldbounds, start) => {
    const bounds = boundsOf(scene);
    const result = {start: false, moved: []};
    const spawns = list(scene.spawn);
    if (start && isVector(start.position) && spawns.length && spawns[0]) {
        const yaw = Number(start.yaw);
        spawns[0].position = start.position.map((v) => r3(Number(v)));
        spawns[0].yaw = Number.isFinite(yaw) ? yaw : 0;
        result.start = true;
    }
    if (!bounds) {
        return result;
    }
    const fit = (kind, object) => {
        const key = positionKey(kind);
        if (!object || !isVector(object[key]) || isInside(kind, object, bounds)) {
            return false;
        }
        object[key] = mapPoint(object[key], oldbounds, bounds);
        keepInside(kind, object, bounds);
        result.moved.push({kind: kind, object: object});
        return true;
    };
    spawns.forEach((spawn, i) => {
        if (i > 0 || !result.start) {
            fit('spawn', spawn);
        }
    });
    const carried = new Set();
    list(scene.zones).forEach((zone) => {
        if (!zone || !isVector(zone.center)) {
            return;
        }
        const before = zone.center.map(Number);
        if (fit('zone', zone)) {
            const delta = zone.center.map((v, i) => Number(v) - before[i]);
            moveStation(scene, zone.id, delta, bounds).forEach((m) => {
                carried.add(m.object);
                result.moved.push(m);
            });
        }
    });
    list(scene.props).forEach((prop) => {
        if (!carried.has(prop)) {
            fit('prop', prop);
        }
    });
    list(scene.interactables).forEach((item) => {
        if (!carried.has(item)) {
            fit('interactable', item);
        }
    });
    return result;
};

/**
 * The tasks and rules that refer to things, rooms or start points.
 *
 * Hidden-at-start entries are not listed: they are dropped with the thing (see forgetIds()).
 *
 * @param {Object} doc Document (with 'graph').
 * @param {Set<string>} ids Ids of zones, objects, blocks and start points.
 * @param {Set<string>} [sceneids] Ids of rooms.
 * @returns {Object[]} [{type: 'objective'|'rule', index: number, title: string}]
 */
export const graphReferences = (doc, ids, sceneids = new Set()) => {
    const graph = doc && doc.graph && typeof doc.graph === 'object' ? doc.graph : {};
    const has = (value) => value !== null && value !== undefined && ids.has(String(value));
    const found = [];
    list(graph.objectives).forEach((o, index) => {
        if (!o || typeof o !== 'object') {
            return;
        }
        const uses = (o.type === 'visit_zone' && (has(o.zone) || sceneids.has(String(o.scene))))
            || (o.type === 'interact' && has(o.target))
            || (o.type === 'collect' && list(o.targets).some(has));
        if (uses) {
            found.push({type: 'objective', index: index, title: typeof o.title === 'string' ? o.title.trim() : ''});
        }
    });
    list(graph.rules).forEach((rule, index) => {
        if (!rule || typeof rule !== 'object') {
            return;
        }
        const on = rule.on && typeof rule.on === 'object' ? rule.on : {};
        const trigger = on.on === 'event' && on.id !== null && on.id !== undefined
            && (on.type === 'scene' ? sceneids.has(String(on.id)) : has(on.id));
        const acts = list(rule.actions).some((a) => a && TARGETACTIONS.includes(a.do) && has(a.target));
        if (trigger || acts) {
            found.push({type: 'rule', index: index, title: ''});
        }
    });
    return found;
};

/**
 * Drop ids from the tasks' hidden-at-start list (changes the document).
 *
 * @param {Object} doc Draft document.
 * @param {Set<string>} ids Ids that are gone.
 */
export const forgetIds = (doc, ids) => {
    if (doc.graph && Array.isArray(doc.graph.hidden)) {
        doc.graph.hidden = doc.graph.hidden.filter((id) => !ids.has(String(id)));
    }
};

/**
 * Every id a room holds (start points, zones, blocks, objects).
 *
 * @param {Object} scene Scene document.
 * @returns {Set<string>}
 */
export const idsOfScene = (scene) => {
    const ids = new Set();
    ['spawn', 'zones', 'props', 'interactables'].forEach((key) => {
        list(scene[key]).forEach((o) => {
            if (o && o.id) {
                ids.add(String(o.id));
            }
        });
    });
    return ids;
};

/**
 * The portals of other rooms that lead into a room.
 *
 * @param {Object} doc Document.
 * @param {string} sceneid Room id.
 * @returns {Object[]} [{scene, item}]
 */
export const portalsInto = (doc, sceneid) => {
    const found = [];
    list(doc.scenes).forEach((scene) => {
        if (!scene || scene.id === sceneid) {
            return;
        }
        list(scene.interactables).forEach((item) => {
            if (item && item.kind === 'portal' && item.target && item.target.scene === sceneid) {
                found.push({scene: scene, item: item});
            }
        });
    });
    return found;
};

/**
 * Point portals that lead to a deleted start point at their room's first start point (changes the document).
 *
 * @param {Object} doc Draft document.
 * @param {string} sceneid The room of the start point.
 * @param {string} spawnid The deleted start point.
 * @returns {number} How many portals changed.
 */
export const retargetPortals = (doc, sceneid, spawnid) => {
    const scene = list(doc.scenes).find((s) => s && s.id === sceneid);
    const first = scene ? list(scene.spawn).find((s) => s && s.id !== spawnid) : null;
    let count = 0;
    list(doc.scenes).forEach((s) => {
        list(s && s.interactables).forEach((item) => {
            if (item && item.kind === 'portal' && item.target && item.target.scene === sceneid && item.target.spawn === spawnid) {
                item.target.spawn = first ? first.id : '';
                count++;
            }
        });
    });
    return count;
};

/**
 * Item ids of the activity's own files ('assets' area) that a document uses.
 *
 * The server keeps exactly these when it saves (editor_manager::prune_assets).
 *
 * @param {Object} doc Document.
 * @returns {Set<number>}
 */
export const usedAssetItemids = (doc) => {
    const used = new Set();
    const note = (ref) => {
        if (ref && ref.area === 'assets') {
            used.add(Number(ref.itemid));
        }
    };
    list(doc && doc.scenes).forEach((scene) => {
        note(scene && scene.environment && scene.environment.file);
        list(scene && scene.interactables).forEach((item) => {
            note(item && item.file);
            list(item && item.content && item.content.media).forEach(note);
        });
    });
    return used;
};

/**
 * Whether an error is the browser failing to reach the server (offline, connection dropped).
 *
 * @param {*} error Error or Moodle exception object.
 * @returns {boolean}
 */
export const isNetworkError = (error) => {
    if (typeof navigator !== 'undefined' && navigator && navigator.onLine === false) {
        return true;
    }
    // Moodle's core/ajax rejects with jQuery's empty error text when no answer came back at all; a server
    // exception always has a message or an error code.
    if (error === null || error === undefined || error === '') {
        return true;
    }
    if (typeof error === 'object' && !error.message && !error.errorcode) {
        return true;
    }
    const message = String((error && error.message) || error);
    return /failed to fetch|networkerror|load failed|network request failed|err_internet_disconnected/i.test(message);
};
