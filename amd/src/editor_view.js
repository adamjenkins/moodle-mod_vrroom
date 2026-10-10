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
 * The room editor's 3D preview: the room drawn by the run mode's own scene builder, an orbit
 * camera, click selection and a move/rotate/resize gizmo.
 *
 * The gizmo wiring follows mod_gear's viewer: OrbitControls plus TransformControls, with
 * 'dragging-changed' switching the orbit camera off while dragging and the change committed when
 * the drag ends.
 *
 * A photo room (360° photo surroundings) is shown from its centre: dragging looks around, the
 * wheel zooms by field of view, there is no gizmo, and a click on the photo with a thing selected
 * moves that thing to the clicked direction (mod_vrroom/editor_photo writes its placement).
 *
 * @module     mod_vrroom/editor_view
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as THREE from 'vrroomruntime_builtin/three';
import {GLTFLoader, OrbitControls, TransformControls} from 'vrroomruntime_builtin/three';
import {buildScene, readTransform} from 'vrroomruntime_builtin/scenebuilder';
import {register as registerMozLightmap} from 'vrroomruntime_builtin/mozlightmap';
import Log from 'core/log';
import Notification from 'core/notification';
import {getString} from 'core/str';
import {COLLECTIONS, clone, findRef, findScene} from 'mod_vrroom/editor_model';
import {findAsset} from 'mod_vrroom/editor_assets';
import {displayName} from 'mod_vrroom/editor_inspector';
import {boundsOf, keepInside, moveStation} from 'mod_vrroom/editor_layout';
import {EYE, directionToAngles, isPhotoScene, placementOf, setPlacement} from 'mod_vrroom/editor_photo';

/** Gizmo modes each kind of thing allows. */
const MODES = Object.freeze({
    environment: [],
    spawn: ['translate', 'rotate'],
    zone: ['translate', 'scale'],
    prop: ['translate', 'rotate', 'scale'],
    hotspot: ['translate'],
    interactable: ['translate', 'rotate', 'scale'],
});

/** Pointer travel (px) below which a press and release is a click. */
const CLICKSLOP = 5;

/** Selection outline colour. */
const SELECTIONCOLOUR = 0x2f80ed;

/** Metres around a start point that should be free of walls and furniture. */
const CLEARANCE = 0.5;

/** Heights (metres above the start point) of the rays that look for walls around it: knee and chest. */
const CLEARANCEHEIGHTS = [0.5, 1.2];

/** Eye height (metres) of the ray that looks for furniture under a start point. */
const EYEHEIGHT = 1.6;

/** Highest surface (metres above the start point) that still counts as the floor. */
const FLOORTOLERANCE = 0.4;

/** Field of view (degrees) of the editor camera, and its limits when zooming in a photo room. */
const FOV = 60;
const PHOTOFOV = [30, 90];

/** Orbit target distance (metres) in a photo room: just ahead of the eye, so dragging turns the view. */
const PHOTOTARGET = 0.01;

/**
 * Whether the browser can create a WebGL context.
 *
 * @returns {boolean}
 */
export const isWebGLAvailable = () => {
    try {
        if (!window.WebGLRenderingContext) {
            return false;
        }
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
        if (!gl) {
            return false;
        }
        const lose = gl.getExtension('WEBGL_lose_context');
        if (lose) {
            lose.loseContext();
        }
        return true;
    } catch (e) {
        return false;
    }
};

/**
 * Round to 3 decimals.
 *
 * @param {number} value Value.
 * @returns {number}
 */
const r3 = (value) => Math.round(value * 1000) / 1000;

/**
 * A vector of three finite numbers, or a default.
 *
 * @param {*} value Value.
 * @param {number[]} fallback Default.
 * @returns {number[]}
 */
const vec3 = (value, fallback) => (Array.isArray(value) && value.length === 3 && value.every((v) => Number.isFinite(Number(v)))
    ? value.map(Number) : fallback.slice());

/**
 * Write a gizmo transform into the selected thing of a draft document.
 *
 * The thing stays inside the room's bounds, and a zone takes its station (the objects that name
 * it) along.
 *
 * @param {Object} draft Draft document.
 * @param {Object} ref Selection.
 * @param {Object} t {position, rotation, scale} from readTransform().
 * @param {string} mode Gizmo mode that made the change.
 * @param {Object} report Filled in: {clamped: whether the thing had to be kept inside the room}.
 * @returns {boolean} Whether anything could be written.
 */
const writeTransform = (draft, ref, t, mode, report) => { // eslint-disable-line complexity
    const object = findRef(draft, ref);
    if (!object || ref.kind === 'environment') {
        return false;
    }
    const scene = findScene(draft, ref.scene);
    const bounds = boundsOf(scene);
    const position = t.position.map(r3);
    const rotation = t.rotation.map((v) => Math.round(v * 10) / 10);
    const scale = t.scale.map((v) => Math.max(0.01, Math.abs(v)));
    if (ref.kind === 'zone') {
        const before = vec3(object.center, position);
        object.center = position;
        report.clamped = keepInside('zone', object, bounds);
        moveStation(scene, object.id, object.center.map((v, i) => v - before[i]), bounds);
        if (mode === 'scale') {
            object.size = scale.map((v) => Math.max(0.05, r3(v)));
        }
        return true;
    }
    if (ref.kind === 'spawn') {
        object.position = position;
        object.yaw = Math.round(rotation[1] * 10) / 10;
        report.clamped = keepInside('spawn', object, bounds);
        return true;
    }
    if (ref.kind === 'prop') {
        object.position = position;
        object.rotation = rotation;
        report.clamped = keepInside('prop', object, bounds);
        if (mode === 'scale') {
            const size = vec3(object.size, [1, 1, 1]);
            object.size = size.map((v, i) => Math.max(0.01, r3(v * scale[i])));
        }
        return true;
    }
    // Interactables.
    object.position = position;
    report.clamped = keepInside('interactable', object, bounds);
    if (object.kind !== 'hotspot') {
        object.rotation = rotation;
    }
    if (mode !== 'scale') {
        return true;
    }
    switch (object.kind) {
        case 'model':
            object.scale = scale.map(r3);
            break;
        case 'grabbable':
            if (object.file) {
                object.fit = Math.max(0.01, r3((Number(object.fit) > 0 ? Number(object.fit) : 0.3) * scale[0]));
            } else {
                object.size = vec3(object.size, [0.3, 0.3, 0.3]).map((v, i) => Math.max(0.01, r3(v * scale[i])));
            }
            break;
        case 'portal':
            object.width = Math.max(0.01, r3((Number(object.width) > 0 ? Number(object.width) : 1.2) * scale[0]));
            object.height = Math.max(0.01, r3((Number(object.height) > 0 ? Number(object.height) : 2.2) * scale[1]));
            break;
        case 'image':
        case 'text':
        case 'media': {
            const fallback = object.kind === 'text' ? 2 : 1.6;
            object.width = Math.max(0.01, r3((Number(object.width) > 0 ? Number(object.width) : fallback) * scale[0]));
            break;
        }
    }
    return true;
};

/**
 * Start the 3D preview.
 *
 * @param {HTMLElement} stage The stage region (holds the canvas).
 * @param {Object} model The editor model.
 * @param {Object} context Editor context ({assets, actions: {deleteSelection, onMode, showMessage, keptInside,
 *     onClearance}}).
 * @returns {Object} {setMode(mode), getMode(), getPlacement(), getLookDirection(), isPhotoMode(), frame(), destroy()}
 */
export const init = (stage, model, context) => {
    const canvas = stage.querySelector('[data-region="vrroom-editor-canvas"]');
    const renderer = new THREE.WebGLRenderer({canvas: canvas, antialias: true});
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    const scene3 = new THREE.Scene();
    scene3.background = new THREE.Color('#20242a');
    const camera = new THREE.PerspectiveCamera(FOV, 16 / 9, 0.05, 500);
    camera.position.set(0, 6, 12);
    // Hubs rooms keep their baked lighting (MOZ_lightmap), as in the viewer.
    const loader = registerMozLightmap(new GLTFLoader());
    const textureloader = new THREE.TextureLoader();

    const orbit = new OrbitControls(camera, canvas);
    orbit.enableDamping = true;
    orbit.dampingFactor = 0.08;
    orbit.screenSpacePanning = false;
    orbit.minDistance = 0.5;
    orbit.maxDistance = 120;

    const gizmo = new TransformControls(camera, canvas);
    gizmo.setSize(0.9);
    const gizmohelper = gizmo.getHelper();
    scene3.add(gizmohelper);

    let destroyed = false;
    let handle = null;
    let token = 0;
    let built = {sceneid: null, environment: '', objects: new Map(), hidden: ''};
    let mode = 'translate';
    let selectionbox = null;
    let queue = Promise.resolve();
    // Whether the drawn room is a photo room (camera at the centre, no gizmo).
    let photomode = false;

    /**
     * Set the orbit camera up for a photo room (look around from the centre) or a walkable room.
     *
     * @param {boolean} on Photo room.
     */
    const setPhotoMode = (on) => {
        photomode = on;
        orbit.enablePan = !on;
        orbit.enableZoom = !on;
        orbit.rotateSpeed = on ? -0.35 : 1;
        orbit.minDistance = on ? 0 : 0.5;
        orbit.maxDistance = on ? PHOTOTARGET * 2 : 120;
        // Pitch up to about 85 degrees either way.
        orbit.minPolarAngle = on ? 5 * Math.PI / 180 : 0;
        orbit.maxPolarAngle = on ? 175 * Math.PI / 180 : Math.PI;
        camera.fov = FOV;
        camera.updateProjectionMatrix();
        canvas.classList.toggle('vrroom-editor-photomode', on);
    };

    /**
     * URL of a file reference, from the editor's asset list (a pack room from the environment list).
     *
     * @param {Object} fileref File reference, or {kind: 'pack', pack, room}.
     * @returns {string|null}
     */
    const resolveUrl = (fileref) => {
        if (fileref && fileref.kind === 'pack') {
            const p = context.presets[`${fileref.pack}/${fileref.room}`];
            return p && p.url ? p.url : null;
        }
        const asset = findAsset(context.assets, fileref);
        return asset ? asset.url : null;
    };

    /**
     * The hidden ids of the task graph.
     *
     * @returns {Set<string>}
     */
    const hiddenSet = () => {
        const graph = model.getDocument().graph || {};
        return new Set(Array.isArray(graph.hidden) ? graph.hidden : []);
    };

    /**
     * The three.js object that stands for a selection.
     *
     * @param {Object|null} ref Selection.
     * @returns {Object|null} THREE.Object3D
     */
    const objectFor = (ref) => {
        if (!handle || !ref) {
            return null;
        }
        let record = null;
        switch (ref.kind) {
            case 'interactable':
                record = handle.items.get(ref.id);
                return record ? record.group : null;
            case 'prop':
                record = handle.props.get(ref.id);
                return record ? record.mesh : null;
            case 'zone':
                record = handle.zones.get(ref.id);
                return record ? record.helper : null;
            case 'spawn':
                record = handle.spawns.get(ref.id);
                return record ? record.helper : null;
        }
        return null;
    };

    /**
     * The gizmo modes a selection allows.
     *
     * @param {Object|null} ref Selection.
     * @returns {string[]}
     */
    const modesFor = (ref) => {
        if (!ref) {
            return [];
        }
        if (ref.kind === 'interactable') {
            const item = findRef(model.getDocument(), ref);
            return item && item.kind === 'hotspot' ? MODES.hotspot : MODES.interactable;
        }
        return MODES[ref.kind] || [];
    };

    /** Put the gizmo and the selection outline on the selected thing. */
    const attachGizmo = () => {
        if (selectionbox) {
            scene3.remove(selectionbox);
            selectionbox.geometry.dispose();
            selectionbox.material.dispose();
            selectionbox = null;
        }
        const ref = model.getSelection();
        const object = objectFor(ref);
        if (!object) {
            gizmo.detach();
            return;
        }
        selectionbox = new THREE.BoxHelper(object, SELECTIONCOLOUR);
        scene3.add(selectionbox);
        if (photomode) {
            // Things in a photo room move by clicking the photo or by the inspector's direction fields.
            gizmo.detach();
            return;
        }
        const modes = modesFor(ref);
        if (!modes.includes(mode)) {
            gizmo.detach();
            return;
        }
        gizmo.setMode(mode);
        gizmo.setSpace(mode === 'scale' ? 'local' : 'world');
        const yawonly = ref.kind === 'spawn' && mode === 'rotate';
        gizmo.showX = !yawonly;
        gizmo.showZ = !yawonly;
        gizmo.showY = true;
        gizmo.attach(object);
    };

    /**
     * Comparable description of what is drawn for the active room.
     *
     * @param {Object} scene Scene document.
     * @returns {Map<string, string>} 'kind:id' => JSON
     */
    const snapshot = (scene) => {
        const objects = new Map();
        Object.keys(COLLECTIONS).forEach((kind) => {
            (Array.isArray(scene[COLLECTIONS[kind]]) ? scene[COLLECTIONS[kind]] : []).forEach((object) => {
                if (object && object.id) {
                    objects.set(`${kind}:${object.id}`, JSON.stringify(object));
                }
            });
        });
        return objects;
    };

    /** Throw the drawn room away. */
    const disposeRoom = () => {
        gizmo.detach();
        if (selectionbox) {
            scene3.remove(selectionbox);
            selectionbox = null;
        }
        if (handle) {
            scene3.remove(handle.root);
            handle.dispose();
            handle = null;
        }
        built = {sceneid: null, environment: '', objects: new Map(), hidden: ''};
    };

    /**
     * Point the camera at a room from its first start point.
     *
     * The camera stands a little behind and above the start point, but never outside the room's
     * walkable bounds: a start point near a wall would otherwise put it behind that wall.
     *
     * @param {Object} scene Scene document.
     */
    const placeCamera = (scene) => {
        const spawn = Array.isArray(scene.spawn) && scene.spawn.length ? scene.spawn[0] : {position: [0, 0, 0], yaw: 0};
        const p = vec3(spawn.position, [0, 0, 0]);
        const yaw = (Number(spawn.yaw) || 0) * Math.PI / 180;
        const forward = [-Math.sin(yaw), 0, -Math.cos(yaw)];
        if (photomode) {
            // At the centre, at eye height, looking where the start point faces.
            camera.position.set(0, EYE, 0);
            orbit.target.set(PHOTOTARGET * forward[0], EYE, PHOTOTARGET * forward[2]);
            orbit.update();
            return;
        }
        let x = p[0] - 3 * forward[0];
        let y = p[1] + 3;
        let z = p[2] - 3 * forward[2];
        const bounds = handle && handle.environment ? handle.environment.bounds : null;
        if (bounds && Array.isArray(bounds.min) && Array.isArray(bounds.max)) {
            const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
            x = clamp(x, bounds.min[0], bounds.max[0]);
            z = clamp(z, bounds.min[2], bounds.max[2]);
            if (bounds.max[1] > bounds.min[1] + 0.5) {
                y = clamp(y, bounds.min[1] + 0.5, bounds.max[1] - 0.2);
            }
        }
        camera.position.set(x, y, z);
        orbit.target.set(p[0] + 4 * forward[0], p[1] + 1, p[2] + 4 * forward[2]);
        orbit.update();
    };

    /**
     * How close a start point is to the room's walls, furniture or blocks.
     *
     * Rays at knee and chest height in eight directions, and one down from eye height (a counter
     * or a table under the start point). Cheap enough to run after every change.
     *
     * @param {Object} spawn Start point document.
     * @returns {number|null} Metres to the nearest surface within CLEARANCE, 0 when standing on
     *     furniture, or null when nothing is that close.
     */
    const clearanceOf = (spawn) => {
        if (!handle || !handle.environment || !handle.environment.group) {
            return null;
        }
        const targets = [handle.environment.group];
        handle.props.forEach((record) => {
            if (record && record.mesh && record.mesh.visible) {
                targets.push(record.mesh);
            }
        });
        handle.root.updateMatrixWorld(true);
        const p = vec3(spawn.position, [0, 0, 0]);
        const ray = new THREE.Raycaster();
        let nearest = null;
        CLEARANCEHEIGHTS.forEach((height) => {
            const origin = new THREE.Vector3(p[0], p[1] + height, p[2]);
            for (let i = 0; i < 8; i++) {
                const angle = i * Math.PI / 4;
                ray.set(origin, new THREE.Vector3(Math.sin(angle), 0, Math.cos(angle)));
                ray.far = CLEARANCE;
                const hit = ray.intersectObjects(targets, true)[0];
                if (hit && (nearest === null || hit.distance < nearest)) {
                    nearest = hit.distance;
                }
            }
        });
        ray.set(new THREE.Vector3(p[0], p[1] + EYEHEIGHT, p[2]), new THREE.Vector3(0, -1, 0));
        ray.far = EYEHEIGHT - FLOORTOLERANCE;
        if (ray.intersectObjects(targets, true).length) {
            nearest = 0;
        }
        return nearest;
    };

    // The last cramped start points told to the editor.
    let lastclearance = '';

    /** Tell the editor which start points of the drawn room are cramped, when that changes. */
    const checkClearance = () => {
        const scene = model.getActiveScene();
        if (!scene || !handle || !context.actions.onClearance) {
            return;
        }
        const cramped = [];
        if (photomode) {
            // Nobody walks in a photo room: nothing can be cramped.
            const key = JSON.stringify([scene.id, cramped]);
            if (key !== lastclearance) {
                lastclearance = key;
                context.actions.onClearance(cramped);
            }
            return;
        }
        (Array.isArray(scene.spawn) ? scene.spawn : []).forEach((spawn, index) => {
            if (!spawn) {
                return;
            }
            const distance = clearanceOf(spawn);
            if (distance !== null) {
                // An unnamed start point is named by its number in the outline, never by its id.
                const name = typeof spawn.name === 'string' ? spawn.name.trim() : '';
                cramped.push({id: spawn.id, name: name, number: index + 1, distance: r3(distance)});
            }
        });
        const key = JSON.stringify([scene.id, cramped]);
        if (key !== lastclearance) {
            lastclearance = key;
            context.actions.onClearance(cramped);
        }
    };

    /**
     * Draw the active room from scratch.
     *
     * @param {boolean} movecamera Whether to put the camera at the room's start.
     * @returns {Promise}
     */
    const rebuild = (movecamera) => {
        const scene = model.getActiveScene();
        const mytoken = ++token;
        disposeRoom();
        if (!scene) {
            return Promise.resolve();
        }
        const doc = clone(scene);
        const photo = isPhotoScene(scene);
        if (photo && doc.environment.file) {
            // Smaller copies of the photo, when the server lists them with the asset (the run mode picks one).
            const asset = findAsset(context.assets, doc.environment.file);
            if (asset && asset.variants && typeof asset.variants === 'object') {
                doc.environment.variants = clone(asset.variants);
            }
        }
        return buildScene(THREE, doc, {
            loader: loader,
            textureloader: textureloader,
            resolveUrl: resolveUrl,
            editor: true,
            maxTextureSize: renderer.capabilities.maxTextureSize,
            hidden: hiddenSet(),
            roomName: (sceneid) => {
                const target = findScene(model.getDocument(), sceneid);
                return target ? displayName(target) : null;
            },
            onLoadError: (error) => {
                Log.debug(error);
                context.actions.showMessage({key: 'modelloadfailed', component: 'vrroomruntime_builtin'});
            },
        }).then((newhandle) => {
            if (mytoken !== token || destroyed) {
                newhandle.dispose();
                return null;
            }
            handle = newhandle;
            scene3.add(handle.root);
            // A new room, or a room that became (or stopped being) a photo room: camera set up afresh.
            const switched = photo !== photomode;
            if (switched || movecamera) {
                setPhotoMode(photo);
            }
            scene3.background = handle.environment && handle.environment.background
                ? handle.environment.background : new THREE.Color('#20242a');
            built = {
                sceneid: scene.id,
                environment: JSON.stringify(scene.environment || null),
                objects: snapshot(scene),
                hidden: JSON.stringify(Array.from(hiddenSet())),
            };
            if (movecamera || switched) {
                placeCamera(scene);
            }
            attachGizmo();
            checkClearance();
            return null;
        });
    };

    /**
     * Bring the drawing up to date with the document: rebuild the room when the room or its
     * surroundings changed, otherwise rebuild only the objects that changed.
     *
     * @returns {Promise}
     */
    const update = () => {
        const scene = model.getActiveScene();
        if (!scene) {
            disposeRoom();
            return Promise.resolve();
        }
        if (!handle || built.sceneid !== scene.id) {
            return rebuild(built.sceneid !== scene.id);
        }
        if (JSON.stringify(scene.environment || null) !== built.environment) {
            return rebuild(false);
        }
        const now = snapshot(scene);
        const work = [];
        const selection = model.getSelection();
        now.forEach((json, key) => {
            if (built.objects.get(key) !== json) {
                const [kind, ...rest] = key.split(':');
                const id = rest.join(':');
                const object = findRef(model.getDocument(), {scene: scene.id, kind: kind, id: id});
                if (object) {
                    if (selection && selection.kind === kind && selection.id === id) {
                        // The gizmo must not hold an object that leaves the scene graph (a new file
                        // replaces the whole object); attachGizmo() puts it on the new one.
                        gizmo.detach();
                    }
                    work.push(handle.addOrUpdate(kind, clone(object)));
                }
            }
        });
        built.objects.forEach((json, key) => {
            if (!now.has(key)) {
                const [kind, ...rest] = key.split(':');
                handle.remove(kind, rest.join(':'));
            }
        });
        built.objects = now;
        const hidden = hiddenSet();
        const hiddenjson = JSON.stringify(Array.from(hidden));
        if (hiddenjson !== built.hidden) {
            built.hidden = hiddenjson;
            handle.setHidden(hidden);
        }
        return Promise.all(work).then(() => {
            if (!destroyed && handle) {
                handle.setHidden(hiddenSet());
                attachGizmo();
                checkClearance();
            }
            return null;
        });
    };

    /** Queue an update so that rebuilds never overlap. */
    const scheduleUpdate = () => {
        queue = queue.then(() => (destroyed ? null : update())).catch((error) => {
            Log.debug(error);
            Notification.exception(error);
        });
    };

    /** Commit the gizmo's drag as one undoable step. */
    const commitTransform = () => {
        const ref = model.getSelection();
        const object = gizmo.object;
        if (!ref || !object) {
            return;
        }
        const t = readTransform(object);
        const gizmomode = mode;
        const target = findRef(model.getDocument(), ref);
        const report = {clamped: false};
        getString('editorchange_move', 'mod_vrroom', displayName(target)).then((label) => {
            const changed = model.transact(label, (draft) => writeTransform(draft, ref, t, gizmomode, report));
            if (!changed) {
                // Nothing moved after all: redraw the object where the document has it.
                built.objects.delete(`${ref.kind}:${ref.id}`);
                scheduleUpdate();
            }
            if (report.clamped && context.actions.keptInside) {
                context.actions.keptInside([displayName(target)]);
            }
            return null;
        }).catch(Notification.exception);
    };

    gizmo.addEventListener('dragging-changed', (event) => {
        orbit.enabled = !event.value;
    });
    gizmo.addEventListener('mouseUp', commitTransform);

    /**
     * Move the selected thing of a photo room to a direction from the centre, keeping its distance.
     * With nothing (or no thing of this room) selected, nothing happens.
     *
     * @param {Object} direction THREE.Vector3 from the eye.
     */
    const moveSelectionTo = (direction) => {
        const ref = model.getSelection();
        const scene = model.getActiveScene();
        if (!ref || !scene || ref.kind !== 'interactable' || ref.scene !== scene.id) {
            return;
        }
        const target = findRef(model.getDocument(), ref);
        if (!target) {
            return;
        }
        const angles = directionToAngles(direction.x, direction.y, direction.z);
        getString('editorchange_move', 'mod_vrroom', displayName(target)).then((label) => {
            model.transact(label, (draft) => {
                const item = findRef(draft, ref);
                if (!item) {
                    return false;
                }
                setPlacement(item, angles.yaw, angles.pitch, placementOf(item).distance);
                return true;
            });
            return null;
        }).catch(Notification.exception);
    };

    // Click selection.
    const raycaster = new THREE.Raycaster();
    let down = null;
    const onPointerDown = (e) => {
        down = e.button === 0 && gizmo.axis === null ? {x: e.clientX, y: e.clientY} : null;
    };
    const onPointerUp = (e) => {
        if (!down || !handle || e.button !== 0) {
            down = null;
            return;
        }
        const moved = Math.hypot(e.clientX - down.x, e.clientY - down.y);
        down = null;
        if (moved > CLICKSLOP || gizmo.dragging) {
            return;
        }
        const rect = canvas.getBoundingClientRect();
        const ndc = new THREE.Vector2(((e.clientX - rect.left) / rect.width) * 2 - 1,
            -((e.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(ndc, camera);
        const hits = raycaster.intersectObject(handle.root, true);
        const scene = model.getActiveScene();
        for (const hit of hits) {
            const picked = handle.findPickable(hit.object);
            if (picked && scene) {
                const kind = picked.kind === 'item' ? 'interactable' : picked.kind;
                model.select({scene: scene.id, kind: kind, id: picked.id});
                return;
            }
        }
        if (photomode) {
            // A click on the photo itself places the selected thing there.
            moveSelectionTo(raycaster.ray.direction.clone());
            return;
        }
        model.select(null);
    };
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointerup', onPointerUp);

    // In a photo room the wheel zooms by field of view (the camera stays at the centre).
    const onWheel = (e) => {
        if (!photomode) {
            return;
        }
        e.preventDefault();
        camera.fov = Math.min(Math.max(camera.fov + Math.sign(e.deltaY) * 3, PHOTOFOV[0]), PHOTOFOV[1]);
        camera.updateProjectionMatrix();
    };
    canvas.addEventListener('wheel', onWheel, {passive: false});

    /**
     * Change the gizmo mode.
     *
     * @param {string} newmode translate, rotate or scale.
     */
    const setMode = (newmode) => {
        if (!['translate', 'rotate', 'scale'].includes(newmode)) {
            return;
        }
        mode = newmode;
        attachGizmo();
        if (context.actions.onMode) {
            context.actions.onMode(mode);
        }
    };

    /** Bring the selection into view. */
    const frame = () => {
        const object = objectFor(model.getSelection());
        if (!object) {
            return;
        }
        if (photomode) {
            // From the centre: turn the view towards it.
            const at = new THREE.Vector3();
            object.getWorldPosition(at);
            const direction = at.sub(camera.position);
            if (direction.lengthSq() > 1e-8) {
                direction.normalize();
                orbit.target.copy(camera.position).addScaledVector(direction, PHOTOTARGET);
                orbit.update();
            }
            return;
        }
        const box = new THREE.Box3().setFromObject(object);
        if (box.isEmpty()) {
            object.getWorldPosition(orbit.target);
        } else {
            box.getCenter(orbit.target);
        }
        const size = box.isEmpty() ? 1 : box.getSize(new THREE.Vector3()).length();
        const direction = camera.position.clone().sub(orbit.target).normalize();
        camera.position.copy(orbit.target).addScaledVector(direction, Math.max(2, size * 1.8 + 1));
        orbit.update();
    };

    // Keyboard in the preview.
    const onKeyDown = (e) => {
        const key = e.key.toLowerCase();
        const ctrl = e.ctrlKey || e.metaKey;
        if (ctrl && key === 'z') {
            e.preventDefault();
            if (e.shiftKey) {
                model.redo();
            } else {
                model.undo();
            }
        } else if (ctrl && key === 'y') {
            e.preventDefault();
            model.redo();
        } else if (ctrl || e.altKey) {
            return;
        } else if (key === 'w') {
            setMode('translate');
        } else if (key === 'e') {
            setMode('rotate');
        } else if (key === 'r') {
            setMode('scale');
        } else if (key === 'delete' || key === 'backspace') {
            e.preventDefault();
            context.actions.deleteSelection();
        } else if (key === 'escape') {
            model.select(null);
        } else if (key === 'f') {
            frame();
        }
    };
    canvas.addEventListener('keydown', onKeyDown);

    /**
     * Where a new object goes: two metres in front of the camera, on the floor, inside the room.
     *
     * @returns {number[]} [x, 0, z]
     */
    const getPlacement = () => {
        if (photomode) {
            return [0, 0, 0];
        }
        const direction = new THREE.Vector3();
        camera.getWorldDirection(direction);
        direction.y = 0;
        if (direction.lengthSq() < 1e-6) {
            direction.set(0, 0, -1);
        }
        direction.normalize();
        let x = camera.position.x + 2 * direction.x;
        let z = camera.position.z + 2 * direction.z;
        const bounds = handle && handle.environment ? handle.environment.bounds : null;
        if (bounds && Array.isArray(bounds.min) && Array.isArray(bounds.max)) {
            x = Math.min(Math.max(x, bounds.min[0]), bounds.max[0]);
            z = Math.min(Math.max(z, bounds.min[2]), bounds.max[2]);
        }
        return [r3(x), 0, r3(z)];
    };

    /**
     * Where the camera looks in a photo room (a new thing goes there).
     *
     * @returns {Object|null} {yaw, pitch} in degrees, or null outside a photo room.
     */
    const getLookDirection = () => {
        if (!photomode) {
            return null;
        }
        const direction = new THREE.Vector3();
        camera.getWorldDirection(direction);
        return directionToAngles(direction.x, direction.y, direction.z);
    };

    // Size and frame loop.
    const resize = () => {
        const width = Math.max(1, canvas.clientWidth);
        const height = Math.max(1, canvas.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };
    const observer = new ResizeObserver(resize);
    observer.observe(canvas);
    resize();
    // THREE.Timer, not the deprecated THREE.Clock; it also skips the time a hidden tab was away.
    const timer = new THREE.Timer();
    timer.connect(document);
    renderer.setAnimationLoop((time) => {
        timer.update(time);
        const dt = timer.getDelta();
        orbit.update();
        if (handle) {
            handle.update(dt, timer.getElapsed());
        }
        if (selectionbox) {
            selectionbox.update();
        }
        renderer.render(scene3, camera);
    });

    const unsubscribers = [
        model.on('change', scheduleUpdate),
        model.on('scene', scheduleUpdate),
        model.on('select', attachGizmo),
    ];
    queue = rebuild(true).catch((error) => {
        Log.debug(error);
        Notification.exception(error);
    });

    return {
        setMode: setMode,
        getMode: () => mode,
        getPlacement: getPlacement,
        getLookDirection: getLookDirection,
        isPhotoMode: () => photomode,
        frame: frame,
        /** Release the renderer and everything else. */
        destroy: () => {
            destroyed = true;
            unsubscribers.forEach((u) => u());
            renderer.setAnimationLoop(null);
            timer.dispose();
            observer.disconnect();
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('keydown', onKeyDown);
            canvas.removeEventListener('wheel', onWheel);
            disposeRoom();
            gizmo.dispose();
            scene3.remove(gizmohelper);
            orbit.dispose();
            renderer.dispose();
        },
    };
};

