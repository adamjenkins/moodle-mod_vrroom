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
 * Tests of the room editor's layout rules (mod_vrroom/editor_layout): bounds, stations, fitting a
 * room to new surroundings, task references, portals and the files a document uses.
 *
 * Run from the repository root: node --test tests/js/editor_layout.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    INSET, WALLSLACK, boundsOf, clampPoint, keepInside, isInside, stationMembers, moveStation, fitToEnvironment,
    graphReferences, forgetIds, idsOfScene, portalsInto, retargetPortals, usedAssetItemids, isNetworkError,
} from '../../amd/src/editor_layout.js';

const GALLERY = {min: [-5.6, 0, -14.6], max: [5.6, 0, 14.6]};
const KITCHEN = {min: [-2.3, 0, -2.05], max: [3.0, 0, 2.05]};

/**
 * A room in the gallery hall with one station (zone, information point, model and plinth).
 *
 * @returns {Object} Scene document.
 */
const galleryRoom = () => ({
    id: 'room-1',
    name: 'Gallery',
    environment: {type: 'preset', preset: 'gallery-hall', bounds: JSON.parse(JSON.stringify(GALLERY))},
    spawn: [{id: 's-start', name: '', position: [0, 0, 13], yaw: 0}, {id: 's-2', name: 'Back', position: [0, 0, -13], yaw: 180}],
    zones: [{id: 'z-a1', name: 'A1', center: [-4, 1.5, 10], size: [3, 3, 3]}],
    props: [{id: 'p-a1', position: [-5, 0.5, 10], size: [0.9, 1, 0.9]}, {id: 'p-free', position: [0, 0.5, 0]}],
    interactables: [
        {id: 'h-a1', kind: 'hotspot', name: 'A1', zone: 'z-a1', position: [-4.4, 1.4, 10]},
        {id: 'm-a1', kind: 'model', name: 'A1', zone: 'z-a1', position: [-5, 1, 10], file: {area: 'assets', itemid: 3,
            filepath: '/', filename: 'vase.glb'}},
        {id: 't-1', kind: 'text', name: 'Welcome', position: [0, 1.6, 12]},
    ],
});

test('boundsOf reads the environment bounds and rejects broken ones', () => {
    assert.deepEqual(boundsOf(galleryRoom()), {min: GALLERY.min, max: GALLERY.max});
    assert.equal(boundsOf({environment: {bounds: {min: [1, 0, 1], max: [0, 0, 0]}}}), null);
    assert.equal(boundsOf({environment: {}}), null);
    assert.equal(boundsOf(null), null);
});

test('clampPoint keeps start points inset and lets objects reach the walls', () => {
    const far = clampPoint([500, 0, -500], GALLERY, INSET);
    assert.equal(far.clamped, true);
    assert.deepEqual(far.point, [5.3, 0, -14.3]);
    const wall = clampPoint([6.2, 1.6, 0], GALLERY, -WALLSLACK);
    assert.equal(wall.clamped, false);
    const inside = clampPoint([1, 2, 3], GALLERY, INSET);
    assert.equal(inside.clamped, false);
    assert.deepEqual(inside.point, [1, 2, 3]);
    // A room narrower than twice the inset: its middle.
    assert.deepEqual(clampPoint([3, 0, 3], {min: [0, 0, 0], max: [0.4, 0, 0.4]}, INSET).point, [0.2, 0, 0.2]);
});

test('keepInside moves only what is outside, by kind', () => {
    const spawn = {position: [40, 0, 40], yaw: 180};
    assert.equal(keepInside('spawn', spawn, KITCHEN), true);
    assert.deepEqual(spawn.position, [2.7, 0, 1.75]);
    const zone = {center: [200, 1.5, 200]};
    assert.equal(keepInside('zone', zone, KITCHEN), true);
    assert.deepEqual(zone.center, [2.7, 1.5, 1.75]);
    const prop = {position: [0, 0.5, 4.29]};
    assert.equal(keepInside('prop', prop, KITCHEN), true);
    assert.deepEqual(prop.position, [0, 0.5, 3.05]);
    const fine = {position: [0, 0, 0]};
    assert.equal(keepInside('interactable', fine, KITCHEN), false);
    assert.equal(keepInside('spawn', {position: [99, 0, 99]}, null), false);
    assert.equal(isInside('zone', {center: [0, 0, 0]}, KITCHEN), true);
});

test('keepInside keeps a zone around the floor and nothing below it', () => {
    const sunk = {center: [0, -6.217, 0], size: [3, 3, 3]};
    assert.equal(keepInside('zone', sunk, KITCHEN), true);
    assert.deepEqual(sunk.center, [0, -1.5, 0]);
    const high = {center: [0, 40, 0], size: [3, 3, 3]};
    assert.equal(keepInside('zone', high, KITCHEN), true);
    assert.deepEqual(high.center, [0, 1.5, 0]);
    // A template station's zone stands on the floor.
    const usual = {center: [0, 1.5, 0], size: [3, 3, 3]};
    assert.equal(keepInside('zone', usual, KITCHEN), false);
    const point = {position: [0, -6.4, 0]};
    assert.equal(keepInside('interactable', point, KITCHEN), true);
    assert.deepEqual(point.position, [0, -0.5, 0]);
    const lamp = {position: [0, 2.6, 0]};
    assert.equal(keepInside('prop', lamp, KITCHEN), false);
});

test('a station is its zone, the objects naming the zone and the template plinth', () => {
    const scene = galleryRoom();
    const members = stationMembers(scene, 'z-a1').map((m) => `${m.kind}:${m.object.id}`).sort();
    assert.deepEqual(members, ['interactable:h-a1', 'interactable:m-a1', 'prop:p-a1']);
    const moved = moveStation(scene, 'z-a1', [2, 0, -1], boundsOf(scene));
    assert.equal(moved.length, 3);
    assert.deepEqual(scene.interactables[0].position, [-2.4, 1.4, 9]);
    assert.deepEqual(scene.props[0].position, [-3, 0.5, 9]);
    // Objects of no station stay where they are.
    assert.deepEqual(scene.interactables[2].position, [0, 1.6, 12]);
    assert.deepEqual(scene.props[1].position, [0, 0.5, 0]);
    assert.equal(moveStation(scene, 'z-a1', [0, 0, 0], null).length, 0);
    // Raising or lowering the zone leaves its markers at their height.
    moveStation(scene, 'z-a1', [0, -3, 0], boundsOf(scene));
    assert.deepEqual(scene.interactables[0].position, [-2.4, 1.4, 9]);
});

test('new surroundings: the start point goes to their start and outside things come inside', () => {
    const scene = galleryRoom();
    const old = boundsOf(scene);
    scene.environment = {type: 'pack', pack: 'rooms', room: 'kitchen', bounds: JSON.parse(JSON.stringify(KITCHEN))};
    const result = fitToEnvironment(scene, old, {position: [-1, 0, 1.4], yaw: 90});
    assert.equal(result.start, true);
    assert.deepEqual(scene.spawn[0].position, [-1, 0, 1.4]);
    assert.equal(scene.spawn[0].yaw, 90);
    const bounds = boundsOf(scene);
    assert.equal(isInside('spawn', scene.spawn[1], bounds), true);
    assert.equal(isInside('zone', scene.zones[0], bounds), true);
    scene.interactables.forEach((item) => assert.equal(isInside('interactable', item, bounds), true, item.id));
    scene.props.forEach((prop) => assert.equal(isInside('prop', prop, bounds), true, prop.id));
    const names = result.moved.map((m) => m.object.id);
    assert.ok(names.includes('z-a1'));
    assert.ok(names.includes('h-a1'));
    // The block in the middle of the hall was inside the kitchen already.
    assert.ok(!names.includes('p-free'));
});

test('new surroundings without a start of their own keep the start point inside', () => {
    const scene = galleryRoom();
    const old = boundsOf(scene);
    scene.environment = {type: 'model', bounds: {min: [-9, 0, -9], max: [9, 0, 9]}};
    const result = fitToEnvironment(scene, old, null);
    assert.equal(result.start, false);
    assert.equal(isInside('spawn', scene.spawn[0], boundsOf(scene)), true);
});

test('graphReferences finds the tasks and rules that use things and rooms', () => {
    const doc = {
        scenes: [galleryRoom()],
        graph: {
            hidden: ['t-1', 'm-a1'],
            objectives: [
                {id: 'v-a1', type: 'visit_zone', title: 'Visit A1', zone: 'z-a1', scene: 'room-1'},
                {id: 'i-1', type: 'interact', title: '', target: 'm-a1'},
                {id: 'c-1', type: 'collect', title: 'Collect', targets: ['t-9', 'h-a1']},
                {id: 'q-1', type: 'answer_question', title: 'Q', slot: 1},
            ],
            rules: [
                {on: {on: 'event', type: 'zone', id: 'z-a1'}, actions: []},
                {on: {on: 'start'}, actions: [{do: 'teleport', target: 's-2'}]},
                {on: {on: 'event', type: 'scene', id: 'room-1'}, actions: [{do: 'finish'}]},
            ],
        },
    };
    assert.deepEqual(graphReferences(doc, new Set(['z-a1'])),
        [{type: 'objective', index: 0, title: 'Visit A1'}, {type: 'rule', index: 0, title: ''}]);
    assert.deepEqual(graphReferences(doc, new Set(['m-a1'])).map((r) => `${r.type}${r.index}`), ['objective1']);
    assert.deepEqual(graphReferences(doc, new Set(['s-2'])).map((r) => `${r.type}${r.index}`), ['rule1']);
    assert.deepEqual(graphReferences(doc, new Set(['t-1'])), []);
    const all = graphReferences(doc, idsOfScene(doc.scenes[0]), new Set(['room-1']));
    assert.deepEqual(all.map((r) => `${r.type}${r.index}`), ['objective0', 'objective1', 'objective2', 'rule0', 'rule1', 'rule2']);
    forgetIds(doc, new Set(['t-1']));
    assert.deepEqual(doc.graph.hidden, ['m-a1']);
});

test('portals into a room, and portals of a deleted start point', () => {
    const one = galleryRoom();
    const two = {id: 'room-2', spawn: [{id: 's-x', position: [0, 0, 0]}], interactables: [
        {id: 'pt-1', kind: 'portal', target: {scene: 'room-1', spawn: 's-2'}},
        {id: 'pt-2', kind: 'portal', target: {scene: 'room-1', spawn: 's-start'}},
    ]};
    const doc = {scenes: [one, two], graph: {}};
    assert.deepEqual(portalsInto(doc, 'room-1').map((p) => p.item.id), ['pt-1', 'pt-2']);
    assert.deepEqual(portalsInto(doc, 'room-2'), []);
    one.spawn = one.spawn.filter((s) => s.id !== 's-2');
    assert.equal(retargetPortals(doc, 'room-1', 's-2'), 1);
    assert.equal(two.interactables[0].target.spawn, 's-start');
    assert.equal(two.interactables[1].target.spawn, 's-start');
});

test('usedAssetItemids lists the activity files a document uses', () => {
    const scene = galleryRoom();
    scene.interactables.push({id: 'h-2', kind: 'hotspot', content: {media: [{area: 'assets', itemid: 7, filename: 'a.png'}]}});
    scene.interactables.push({id: 'i-2', kind: 'image', file: {area: 'media', itemid: 2, filename: 'b.png'}});
    assert.deepEqual(Array.from(usedAssetItemids({scenes: [scene]})).sort(), [3, 7]);
    assert.deepEqual(Array.from(usedAssetItemids({})), []);
});

test('isNetworkError tells a lost connection from a server error', () => {
    assert.equal(isNetworkError(new TypeError('Failed to fetch')), true);
    assert.equal(isNetworkError(''), true);
    assert.equal(isNetworkError({}), true);
    assert.equal(isNetworkError({message: 'The room cannot be saved', errorcode: 'invaliddocument'}), false);
    assert.equal(isNetworkError(new Error('Something else')), false);
});
