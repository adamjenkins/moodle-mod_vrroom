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
 * The 3D room: renderer, rooms built by the scene builder, clicks, zones, portals between rooms,
 * media, hidden objects, graph effects, and immersive VR, AR and mixed reality.
 *
 * The click-to-raycast-to-hotspot-panel flow follows mod_gear's viewer.
 *
 * @module     mavrruntime_builtin/viewer
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as THREE from 'mavrruntime_builtin/three';
import {GLTFLoader} from 'mavrruntime_builtin/three';
import Log from 'core/log';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';
import {buildScene, DEG} from 'mavrruntime_builtin/scenebuilder';
import {attach} from 'mavrruntime_builtin/controls';
import {createPanels} from 'mavrruntime_builtin/panels';
import {detect, createXr, createWristPanel} from 'mavrruntime_builtin/xr';
import {createXrInput} from 'mavrruntime_builtin/xrinput';
import {createXrPanels} from 'mavrruntime_builtin/xrpanel';
import {region, plainText, announce, showMessage, clearMessage, startScene} from 'mavrruntime_builtin/util';

/** Distance within which Enter activates the object in the middle of the screen (m). */
const REACH = 6;

/** Eye height of the desktop camera (m). */
const EYE = 1.6;

/** How long the "locked" message stays (ms). */
const MESSAGE_TIME = 4000;

/** How often the wrist panel is refreshed (s). */
const WRIST_INTERVAL = 0.5;

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
 * Read a 3-vector or return a default.
 *
 * @param {*} value Value.
 * @param {number[]} fallback Default.
 * @returns {number[]}
 */
const vec3 = (value, fallback) => {
    if (!Array.isArray(value) || value.length !== 3) {
        return fallback.slice();
    }
    return value.map((v, i) => (Number.isFinite(Number(v)) ? Number(v) : fallback[i]));
};

/**
 * Seconds as m:ss.
 *
 * @param {number} seconds Whole seconds.
 * @returns {string}
 */
const clockText = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(s / 60);
    const rest = s % 60;
    return `${minutes}:${rest < 10 ? '0' : ''}${rest}`;
};

/**
 * Start the 3D view.
 *
 * @param {HTMLElement} root The MAVR root element (holding the shell).
 * @param {Object} session The session.
 * @param {Object} config Launch config.
 * @returns {Promise<Object>} {stop()}
 */
export const start = (root, session, config) => {
    const pending = new Pending('mavrruntime_builtin/viewer:start');
    const stage = region(root, 'mavr-stage');
    const canvas = region(root, 'mavr-canvas');
    const scenes = Array.isArray(config.scenes) ? config.scenes : [];

    return getStrings([
        {key: 'loading', component: 'mavrruntime_builtin'},
        {key: 'modelloadfailed', component: 'mavrruntime_builtin'},
        {key: 'stationlocked', component: 'mavrruntime_builtin'},
        {key: 'entervr', component: 'mavrruntime_builtin'},
        {key: 'enterar', component: 'mavrruntime_builtin'},
        {key: 'entermr', component: 'mavrruntime_builtin'},
        {key: 'exitxr', component: 'mavrruntime_builtin'},
        {key: 'xrinsecure', component: 'mavrruntime_builtin'},
        {key: 'xrfailed', component: 'mavrruntime_builtin'},
        {key: 'xrplace', component: 'mavrruntime_builtin'},
    ]).then(([loading, modelfailed, locked, entervr, enterar, entermr, exitxr, xrinsecure, xrfailed, xrplace]) => {
        const strings = {loading, modelfailed, locked, entervr, enterar, entermr, exitxr, xrinsecure, xrfailed, xrplace};
        if (!startScene(config)) {
            throw new Error('No scene in the launch config');
        }
        stage.classList.remove('d-none');
        showMessage(root, loading, 'info');

        const renderer = new THREE.WebGLRenderer({canvas: canvas, antialias: true});
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.xr.enabled = true;
        const scene3 = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(70, 16 / 9, 0.05, 300);
        const rig = new THREE.Group();
        rig.name = 'mavr-rig';
        rig.add(camera);
        scene3.add(rig);
        const loader = new GLTFLoader();
        const textureloader = new THREE.TextureLoader();
        let stopped = false;

        const roomOptions = () => ({
            loader: loader,
            textureloader: textureloader,
            hidden: session.getState().hidden,
            onLoadError: (error) => {
                Log.debug(error);
                if (!stopped) {
                    showMessage(root, strings.modelfailed, 'warning');
                }
            },
            roomName: (sceneid) => {
                const target = scenes.find((s) => s.id === sceneid);
                return target ? target.name : null;
            },
        });

        return session.loadResume().then((resume) => {
            const resumed = resume ? scenes.find((s) => s.id === resume.scene) : null;
            const doc = resumed || startScene(config);
            return buildScene(THREE, doc, roomOptions()).then((handle) => run({
                root, session, config, scenes, stage, canvas, renderer, scene3, camera, rig, strings, roomOptions,
                doc, handle, resume: resumed ? resume : null, setStopped: () => {
                    stopped = true;
                },
            }));
        }).catch((error) => {
            stopped = true;
            renderer.setAnimationLoop(null);
            renderer.dispose();
            clearMessage(root, loading);
            throw error;
        });
    }).then((handle) => {
        pending.resolve();
        return handle;
    }, (error) => {
        stage.classList.add('d-none');
        pending.resolve();
        throw error;
    });
};

/**
 * Run the room: picking, zones, rooms, effects, XR and the frame loop.
 *
 * @param {Object} p Everything prepared by start().
 * @returns {Object} {stop()}
 */
const run = (p) => {
    const {root, session, scenes, stage, canvas, renderer, scene3, camera, rig, strings, roomOptions, resume} = p;
    let current = {doc: p.doc, handle: p.handle};
    let stopped = false;
    let switching = null;
    const inside = new Set();
    const enteredrecorded = new Set();
    const grabbed = new Set();
    let messagetimer = null;

    // XR state.
    let xrmode = null;
    let placed = false;
    let placement = null;
    let lasthit = null;
    let xrinput = null;
    let xrpanels = null;
    let xrpanelspromise = null;
    let wrist = null;
    let wristclock = 0;
    let xr = null;
    const reticle = new THREE.Mesh(
        new THREE.RingGeometry(0.07, 0.09, 32).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({color: 0xffffff, transparent: true, opacity: 0.9})
    );
    reticle.matrixAutoUpdate = false;
    reticle.visible = false;
    reticle.name = 'mavr-xr-place';
    scene3.add(reticle);

    const xrstatus = region(root, 'mavr-xr-status');
    const exitbutton = stage.querySelector('[data-action="mavr-exit-xr"]');
    const sceneid = () => current.doc.id;
    const findItem = (id) => (Array.isArray(current.doc.interactables) ? current.doc.interactables : [])
        .find((i) => i.id === id) || null;
    const spawnsOf = (doc) => (Array.isArray(doc.spawn) ? doc.spawn : []);
    const zonesOf = (doc) => (Array.isArray(doc.zones) ? doc.zones : []);

    scene3.add(current.handle.root);

    const panels = createPanels(stage, session, {
        onClose: () => canvas.focus({preventScroll: true}),
    });

    // In-world panels are used in VR, mixed reality, and AR without a DOM overlay.
    const worldPanels = () => !!xrmode && !(xrmode === 'ar' && xr && xr.domOverlay);

    const ensureXrPanels = () => {
        if (!xrpanelspromise) {
            xrpanelspromise = createXrPanels(THREE, {parent: scene3, camera: camera, session: session})
                .then((created) => {
                    if (stopped) {
                        created.dispose();
                        return null;
                    }
                    xrpanels = created;
                    return created;
                })
                .catch((error) => {
                    Log.debug(error);
                    xrpanelspromise = null;
                    return null;
                });
        }
        return xrpanelspromise;
    };

    // A short message: in the page, in the AR overlay, or on an in-world panel (sticky ones stay).
    const notify = (text, level = 'warning', sticky = false) => {
        announce(root, text);
        if (xrmode && worldPanels()) {
            if (xrpanels) {
                xrpanels.showMessage(text);
            }
            return;
        }
        if (xrmode && xrstatus) {
            xrstatus.textContent = text;
            xrstatus.classList.remove('d-none');
        } else {
            showMessage(root, text, level);
        }
        if (messagetimer) {
            window.clearTimeout(messagetimer);
            messagetimer = null;
        }
        if (sticky) {
            return;
        }
        messagetimer = window.setTimeout(() => {
            messagetimer = null;
            clearMessage(root, text);
            if (xrstatus && xrstatus.textContent === text) {
                xrstatus.textContent = '';
                xrstatus.classList.add('d-none');
            }
        }, MESSAGE_TIME);
    };

    const showLocked = () => notify(strings.locked, 'warning');

    const openHotspot = (hotspot) => {
        if (worldPanels()) {
            if (xrpanels) {
                xrpanels.openInteractable(hotspot);
            }
        } else {
            panels.openInteractable(hotspot);
        }
    };

    const openQuestion = (slot) => {
        if (worldPanels()) {
            if (xrpanels) {
                xrpanels.openQuestion(Number(slot));
            }
        } else {
            panels.openQuestion(Number(slot));
        }
    };

    // Media.
    const mediaOf = (id) => {
        const record = current.handle.items.get(id);
        return record && record.media ? record : null;
    };
    const playMedia = (record) => {
        const element = record.media;
        if (element.ended) {
            element.currentTime = 0;
        }
        Promise.resolve(element.play()).then(() => getString('mediaplay', 'mavrruntime_builtin',
            plainText(record.item.name || ''))).then((text) => announce(root, text)).catch((error) => Log.debug(error));
    };
    const toggleMedia = (id) => {
        const record = mediaOf(id);
        if (!record) {
            return;
        }
        if (record.media.paused) {
            playMedia(record);
        } else {
            record.media.pause();
            getString('mediapause', 'mavrruntime_builtin', plainText(record.item.name || ''))
                .then((text) => announce(root, text))
                .catch((error) => Log.debug(error));
        }
    };

    // Picking with the mouse or a tap.
    const raycaster = new THREE.Raycaster();
    const isShown = (object) => {
        let o = object;
        while (o) {
            if (o.visible === false) {
                return false;
            }
            o = o.parent;
        }
        return true;
    };
    const pick = (ndc, far) => {
        camera.updateMatrixWorld(true);
        raycaster.setFromCamera(new THREE.Vector2(ndc.x, ndc.y), camera);
        raycaster.far = far;
        const hits = raycaster.intersectObjects(current.handle.pickTargets(), true);
        for (const hit of hits) {
            if (!isShown(hit.object) || hit.object.isLine || hit.object.isLineSegments) {
                continue;
            }
            const found = current.handle.findPickable(hit.object);
            if (found && found.kind === 'interactable') {
                return findItem(found.id);
            }
            if (hit.object.isSprite) {
                continue;
            }
            // The first solid thing that is not an interactable blocks the click.
            return null;
        }
        return null;
    };

    // Zones the player is in, and leaving them all (room change, XR mode change).
    const leaveZones = () => {
        inside.forEach((zoneid) => {
            if (enteredrecorded.has(zoneid)) {
                session.record('exited', 'zone', zoneid, sceneid());
            }
        });
        inside.clear();
        enteredrecorded.clear();
    };

    const enterZone = (zone) => {
        if (session.isZoneLocked(zone.id)) {
            showLocked();
            return;
        }
        enteredrecorded.add(zone.id);
        session.record('entered', 'zone', zone.id, sceneid());
    };

    // Placement of the room in AR (table top) and mixed reality (room scale).
    const userYaw = () => {
        const dir = camera.getWorldDirection(new THREE.Vector3());
        return Math.atan2(-dir.x, -dir.z);
    };
    const firstSpawn = (doc, spawnid) => {
        const list = spawnsOf(doc);
        return list.find((s) => s.id === spawnid) || list[0] || {position: [0, 0, 0], yaw: 0};
    };
    const applyPlacement = () => {
        const roomroot = current.handle.root;
        if (!placement) {
            roomroot.position.set(0, 0, 0);
            roomroot.rotation.set(0, 0, 0);
            roomroot.scale.set(1, 1, 1);
            return;
        }
        roomroot.scale.setScalar(placement.scale);
        roomroot.rotation.set(0, placement.rotation, 0);
        const offset = new THREE.Vector3(placement.centre[0], 0, placement.centre[2])
            .multiplyScalar(placement.scale)
            .applyAxisAngle(new THREE.Vector3(0, 1, 0), placement.rotation);
        roomroot.position.copy(placement.point).sub(offset);
        roomroot.updateMatrixWorld(true);
    };
    const arScale = (doc) => {
        const xr = doc.xr && typeof doc.xr === 'object' ? doc.xr : {};
        const value = Number(xr.arscale);
        if (xr.arscale !== null && xr.arscale !== undefined && Number.isFinite(value) && value > 0) {
            return value;
        }
        const b = current.handle.environment.bounds;
        const extent = b ? Math.max(b.max[0] - b.min[0], b.max[2] - b.min[2]) : 10;
        return 1 / Math.max(extent, 0.01);
    };
    // Compute the placement of the current room at a floor or table point.
    const placeAt = (point, spawnid) => {
        const spawn = firstSpawn(current.doc, spawnid);
        const spawnyaw = (Number(spawn.yaw) || 0) * DEG;
        if (xrmode === 'ar') {
            const b = current.handle.environment.bounds;
            const centre = b ? [(b.min[0] + b.max[0]) / 2, 0, (b.min[2] + b.max[2]) / 2] : [0, 0, 0];
            placement = {point: point.clone(), rotation: userYaw() - spawnyaw, scale: arScale(current.doc), centre: centre};
        } else {
            placement = {point: point.clone(), rotation: userYaw() - spawnyaw, scale: 1, centre: vec3(spawn.position, [0, 0, 0])};
        }
        applyPlacement();
    };
    // The floor point under the head in mixed reality.
    const feetPoint = () => {
        const head = camera.getWorldPosition(new THREE.Vector3());
        head.y = placement ? placement.point.y : head.y - EYE;
        return head;
    };

    const placeRoom = () => {
        let point = null;
        if (lasthit) {
            const m = new THREE.Matrix4().fromArray(lasthit.transform.matrix);
            point = new THREE.Vector3().setFromMatrixPosition(m);
        } else if (xrmode === 'mr') {
            point = feetPoint();
        }
        if (!point) {
            notify(strings.xrplace, 'info', true);
            return;
        }
        placeAt(point, null);
        placed = true;
        reticle.visible = false;
        if (xrstatus) {
            xrstatus.textContent = '';
            xrstatus.classList.add('d-none');
        }
        if (xrpanels) {
            xrpanels.close();
        }
    };

    // Put the player (or, in mixed reality, the room) at a start point of the current room.
    const placeAtSpawn = (spawnid) => {
        const spawn = firstSpawn(current.doc, spawnid);
        const position = vec3(spawn.position, [0, 0, 0]);
        const yaw = Number(spawn.yaw) || 0;
        if (xrmode === 'vr' && xrinput) {
            xrinput.moveHeadTo(position, yaw);
        } else if (xrmode === 'mr') {
            if (placed) {
                placeAt(feetPoint(), spawnid);
            }
        } else if (xrmode === 'ar') {
            if (placement) {
                const b = current.handle.environment.bounds;
                placement.scale = arScale(current.doc);
                placement.centre = b ? [(b.min[0] + b.max[0]) / 2, 0, (b.min[2] + b.max[2]) / 2] : [0, 0, 0];
                applyPlacement();
            }
        } else {
            controls.teleport(position, yaw);
        }
    };

    // How the room looks in the current mode.
    const applyLook = () => {
        const env = current.handle.environment;
        scene3.background = xrmode === 'ar' || xrmode === 'mr' ? null : env.background;
        env.shell.forEach((mesh) => {
            mesh.visible = xrmode !== 'mr';
        });
        if (env.ceiling) {
            env.ceiling.visible = xrmode !== 'ar' && xrmode !== 'mr';
        }
        current.handle.setZonesVisible(xrmode === 'ar');
        applyPlacement();
    };

    // Hidden objects and locked hotspots follow the state.
    const applyState = () => {
        current.handle.setHidden(session.getState().hidden);
        current.handle.setLocked((item) => item.kind === 'hotspot' && !!item.zone && session.isZoneLocked(item.zone));
    };

    // Change room (portal or teleport effect), then go to a start point.
    const goTo = (targetscene, spawnid) => {
        if (!targetscene || targetscene === sceneid()) {
            placeAtSpawn(spawnid);
            return Promise.resolve();
        }
        const doc = scenes.find((s) => s.id === targetscene);
        if (!doc || switching) {
            return switching || Promise.resolve();
        }
        const pending = new Pending('mavrruntime_builtin/viewer:room');
        leaveZones();
        panels.close();
        if (xrpanels) {
            xrpanels.close();
        }
        if (xrinput) {
            xrinput.releaseAll();
        }
        switching = buildScene(THREE, doc, roomOptions()).then((handle) => {
            if (stopped) {
                handle.dispose();
                return undefined;
            }
            current.handle.dispose();
            current = {doc: doc, handle: handle};
            grabbed.clear();
            scene3.add(handle.root);
            session.setScene(doc.id);
            controls.setBounds(handle.environment.bounds);
            applyLook();
            applyState();
            placeAtSpawn(spawnid);
            return getString('scenechanged', 'mavrruntime_builtin', plainText(doc.name || ''));
        }).then((text) => {
            if (text && !stopped) {
                announce(root, text);
            }
            return undefined;
        }).catch((error) => {
            Log.debug(error);
            if (!stopped) {
                notify(strings.modelfailed, 'warning');
            }
        }).then(() => {
            switching = null;
            pending.resolve();
            return undefined;
        });
        return switching;
    };

    // A start point id anywhere in the activity.
    const goToSpawn = (spawnid) => {
        if (spawnsOf(current.doc).some((s) => s.id === spawnid)) {
            placeAtSpawn(spawnid);
            return;
        }
        const target = scenes.find((s) => spawnsOf(s).some((sp) => sp.id === spawnid));
        if (target) {
            goTo(target.id, spawnid);
        }
    };

    // Activate an interactable: interacted statement, then its panel, room change or media.
    const activate = (item) => {
        if (!item || switching) {
            return;
        }
        let hotspot = item.kind === 'hotspot' ? item : null;
        if (item.opens) {
            const target = findItem(item.opens);
            if (target && target.kind === 'hotspot') {
                hotspot = target;
            }
        }
        const eventid = item.opens ? item.opens : item.id;
        if (hotspot && hotspot.zone && session.isZoneLocked(hotspot.zone)) {
            showLocked();
            return;
        }
        const status = session.record('interacted', 'object', eventid, sceneid());
        if (status === 'locked') {
            showLocked();
            return;
        }
        if (status === 'closed') {
            return;
        }
        if (item.kind === 'portal') {
            const target = item.target && typeof item.target === 'object' ? item.target : {};
            goTo(target.scene, target.spawn);
            return;
        }
        if (item.kind === 'media') {
            toggleMedia(item.id);
        }
        if (hotspot) {
            openHotspot(hotspot);
        }
    };

    // Controls (desktop and touch) move the rig.
    const controls = attach(camera, canvas, {
        bounds: current.handle.environment.bounds,
        eyeHeight: EYE,
        rig: rig,
        stick: region(root, 'mavr-stick'),
        onClick: (ndc) => {
            const item = pick(ndc, Infinity);
            if (item) {
                activate(item);
                return true;
            }
            return false;
        },
        onActivate: () => {
            const item = pick({x: 0, y: 0}, REACH);
            if (item) {
                activate(item);
            }
        },
        onTouch: () => stage.classList.add('mavr-touch'),
    });
    if (resume) {
        controls.teleport(resume.position, resume.yaw);
    } else {
        const spawn = firstSpawn(current.doc, null);
        controls.teleport(vec3(spawn.position, [0, 0, 0]), Number(spawn.yaw) || 0);
    }
    applyLook();
    applyState();

    // Zones, each frame: from the walking position, or the head in VR and mixed reality.
    const testZones = () => {
        let p;
        if (!xrmode) {
            p = controls.getPosition();
        } else if (xrmode === 'vr' || (xrmode === 'mr' && placed)) {
            const head = camera.getWorldPosition(new THREE.Vector3());
            current.handle.root.worldToLocal(head);
            p = [head.x, 0, head.z];
        } else {
            return;
        }
        zonesOf(current.doc).forEach((zone) => {
            const c = vec3(zone.center, [0, 0, 0]);
            const s = vec3(zone.size, [0, 0, 0]);
            const isinside = Math.abs(p[0] - c[0]) <= s[0] / 2 && Math.abs(p[2] - c[2]) <= s[2] / 2 &&
                p[1] >= c[1] - s[1] / 2 && p[1] <= c[1] + s[1] / 2;
            if (isinside && !inside.has(zone.id)) {
                inside.add(zone.id);
                enterZone(zone);
            } else if (!isinside && inside.has(zone.id)) {
                inside.delete(zone.id);
                if (enteredrecorded.has(zone.id)) {
                    enteredrecorded.delete(zone.id);
                    session.record('exited', 'zone', zone.id, sceneid());
                }
            }
        });
    };

    // A tap on a zone box in AR counts as visiting it (the list view rule).
    const tapZone = (zoneid) => {
        if (session.isZoneLocked(zoneid)) {
            showLocked();
            return;
        }
        if (session.record('interacted', 'zone', zoneid, sceneid()) === 'locked') {
            showLocked();
        }
    };

    // Select with a controller ray, a hand or a screen tap.
    const onXrSelect = (object) => {
        if ((xrmode === 'ar' || xrmode === 'mr') && !placed) {
            placeRoom();
            return true;
        }
        if (!object) {
            return false;
        }
        if (xrpanels && xrpanels.select(object)) {
            return true;
        }
        if (wrist && wrist.isExit(object)) {
            xr.exit();
            return true;
        }
        const found = current.handle.findPickable(object);
        if (!found) {
            return false;
        }
        if (found.kind === 'interactable') {
            activate(findItem(found.id));
        } else if (found.kind === 'zone' && xrmode === 'ar') {
            tapZone(found.id);
        }
        return true;
    };

    const ensureXrInput = () => {
        if (xrinput) {
            return xrinput;
        }
        xrinput = createXrInput(THREE, renderer, rig, {
            camera: camera,
            getTargets: () => {
                const targets = [];
                if (xrpanels && xrpanels.isOpen()) {
                    targets.push(...xrpanels.getTargets());
                }
                if (wrist) {
                    targets.push(...wrist.getTargets());
                }
                return targets.concat(current.handle.pickTargets());
            },
            onSelect: (object) => onXrSelect(object),
            onTeleport: (point) => xrinput.moveHeadTo([point.x, 0, point.z], null),
            teleportAllowed: () => xrmode === 'vr' && !switching,
            bounds: () => current.handle.environment.bounds,
            onGrabStart: (itemid) => {
                const item = findItem(itemid);
                if (!item || item.kind !== 'grabbable') {
                    return false;
                }
                if (grabbed.has(itemid)) {
                    return true;
                }
                const status = session.record('interacted', 'object', itemid, sceneid());
                if (status === 'locked') {
                    showLocked();
                    return false;
                }
                grabbed.add(itemid);
                return status !== 'closed';
            },
            onGrabEnd: () => undefined,
            onConnect: (handedness, grip) => {
                if (handedness === 'left' && wrist && (xrmode === 'vr' || xrmode === 'mr')) {
                    grip.add(wrist.object);
                }
            },
        });
        return xrinput;
    };

    // The wrist panel: required tasks done and time left.
    const updateWrist = () => {
        if (!wrist) {
            return;
        }
        const state = session.getState();
        const timeleft = session.getTimeLeft();
        const requests = [];
        if (state.requiredtotal > 0) {
            requests.push(getString('xrprogress', 'mavrruntime_builtin', {done: state.requireddone, total: state.requiredtotal}));
        }
        if (timeleft !== null && timeleft !== undefined) {
            requests.push(getString('timeleft', 'mavrruntime_builtin', clockText(timeleft)));
        }
        Promise.all(requests).then((lines) => {
            if (wrist) {
                wrist.setLines(lines);
            }
            return undefined;
        }).catch((error) => Log.debug(error));
    };

    // Resize (not while an immersive session sizes the canvas).
    const resize = () => {
        if (renderer.xr.isPresenting) {
            return;
        }
        const width = Math.max(1, canvas.clientWidth);
        const height = Math.max(1, canvas.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };
    const observer = new ResizeObserver(resize);
    observer.observe(canvas);
    resize();

    // Immersive sessions.
    xr = createXr(renderer, {
        stage: stage,
        onStart: (mode) => {
            if (stopped) {
                return;
            }
            xrmode = mode;
            placed = mode === 'vr';
            placement = null;
            lasthit = null;
            controls.setEnabled(false);
            panels.close();
            leaveZones();
            session.setChannel(`builtin-${mode}`);
            stage.classList.add('mavr-xr');
            stage.classList.toggle('mavr-xr-overlay', mode === 'ar' && xr.domOverlay);
            if (exitbutton) {
                exitbutton.classList.toggle('d-none', !(mode === 'ar' && xr.domOverlay));
            }
            if (mode !== 'vr') {
                // The reference space maps straight onto the world: the room is placed instead.
                rig.position.set(0, 0, 0);
                rig.rotation.set(0, 0, 0);
                rig.updateMatrixWorld(true);
            }
            ensureXrInput();
            if (mode === 'vr' || mode === 'mr') {
                wrist = createWristPanel(THREE, {exitlabel: strings.exitxr});
                const grip = xrinput.getGrip('left');
                if (grip) {
                    grip.add(wrist.object);
                }
                updateWrist();
            }
            applyLook();
            ensureXrPanels().then(() => {
                if (xrmode && !placed) {
                    notify(strings.xrplace, 'info', true);
                }
                return undefined;
            }).catch((error) => Log.debug(error));
        },
        onEnd: (mode) => {
            if (stopped) {
                return;
            }
            const head = camera.getWorldPosition(new THREE.Vector3());
            const headyaw = userYaw() / DEG;
            xrmode = null;
            placed = false;
            placement = null;
            lasthit = null;
            reticle.visible = false;
            if (xrinput) {
                xrinput.releaseAll();
            }
            if (xrpanels) {
                xrpanels.close();
            }
            if (wrist) {
                wrist.dispose();
                wrist = null;
            }
            leaveZones();
            applyLook();
            stage.classList.remove('mavr-xr', 'mavr-xr-overlay');
            if (exitbutton) {
                exitbutton.classList.add('d-none');
            }
            if (xrstatus) {
                xrstatus.textContent = '';
                xrstatus.classList.add('d-none');
            }
            controls.setEnabled(true);
            if (mode === 'vr') {
                controls.teleport([head.x, 0, head.z], headyaw);
            }
            session.setChannel('builtin-3d');
            resize();
            canvas.focus({preventScroll: true});
        },
    });

    const enterXr = (mode) => {
        if (xr.mode || switching) {
            return;
        }
        const pending = new Pending('mavrruntime_builtin/viewer:xr');
        // Called straight from the click, so the browser still sees the user's gesture.
        xr.enter(mode).catch((error) => {
            Log.debug(error);
            if (!stopped) {
                showMessage(root, strings.xrfailed, 'warning');
            }
        }).then(() => pending.resolve()).catch(() => pending.resolve());
    };

    // HUD buttons.
    const vrbutton = stage.querySelector('[data-action="mavr-enter-vr"]');
    const arbutton = stage.querySelector('[data-action="mavr-enter-ar"]');
    const xrnote = region(root, 'mavr-xr-note');
    const onHudClick = (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !stage.contains(button)) {
            return;
        }
        if (button.dataset.action === 'mavr-enter-vr') {
            e.preventDefault();
            enterXr('vr');
        } else if (button.dataset.action === 'mavr-enter-ar') {
            e.preventDefault();
            enterXr('ar');
        } else if (button.dataset.action === 'mavr-exit-xr') {
            e.preventDefault();
            xr.exit();
        }
    };
    stage.addEventListener('click', onHudClick);
    // Taps on the AR overlay's buttons and panels must not also select in the room.
    const onBeforeXrSelect = (e) => {
        if (e.target.closest('button, a, input, select, textarea, label, [data-region="mavr-overlay"], .mavr-hud-buttons')) {
            e.preventDefault();
        }
    };
    stage.addEventListener('beforexrselect', onBeforeXrSelect);

    if (!window.isSecureContext && xrnote) {
        xrnote.textContent = strings.xrinsecure;
        xrnote.classList.remove('d-none');
    }
    detect().then((info) => {
        if (stopped) {
            return undefined;
        }
        if (vrbutton && info.vr) {
            vrbutton.textContent = strings.entervr;
            vrbutton.classList.remove('d-none');
        }
        if (arbutton && info.ar) {
            arbutton.textContent = info.headset ? strings.entermr : strings.enterar;
            arbutton.classList.remove('d-none');
        }
        if (info.vr || info.ar) {
            // Ready before the first click, so entering XR keeps the user's gesture.
            ensureXrPanels();
        }
        return undefined;
    }).catch((error) => Log.debug(error));

    // Session messages: state changes, effects and the end of the time.
    const unsubscribe = session.on((message) => {
        if (stopped) {
            return;
        }
        if (message.type === 'timeup') {
            if (xr.mode) {
                xr.exit();
            }
            return;
        }
        if (message.type !== 'change') {
            return;
        }
        applyState();
        panels.refresh();
        updateWrist();
        message.unlocked.forEach((objective) => {
            if (objective.type === 'visit_zone' && objective.zone && inside.has(objective.zone) &&
                    !enteredrecorded.has(objective.zone)) {
                const zone = zonesOf(current.doc).find((z) => z.id === objective.zone);
                if (zone) {
                    enterZone(zone);
                }
            }
        });
        message.effects.forEach((effect) => {
            if (effect.do === 'teleport') {
                goToSpawn(effect.target);
            } else if (effect.do === 'open_question') {
                openQuestion(Number(effect.target));
            } else if (effect.do === 'play_media') {
                const record = mediaOf(effect.target);
                if (record) {
                    playMedia(record);
                }
            }
        });
    });

    // Frame loop.
    const clock = new THREE.Clock();
    renderer.setAnimationLoop((time, frame) => {
        const dt = clock.getDelta();
        if (!xrmode) {
            controls.update(dt);
        } else {
            if ((xrmode === 'ar' || xrmode === 'mr') && !placed) {
                lasthit = xr.getHitPose(frame);
                reticle.visible = !!lasthit;
                if (lasthit) {
                    reticle.matrix.fromArray(lasthit.transform.matrix);
                }
            }
            if (xrinput) {
                xrinput.update(dt);
            }
            if (xrpanels) {
                xrpanels.update(dt);
            }
            wristclock += dt;
            if (wristclock >= WRIST_INTERVAL) {
                wristclock = 0;
                updateWrist();
            }
        }
        testZones();
        current.handle.update(dt, clock.elapsedTime);
        renderer.render(scene3, camera);
    });

    session.start('builtin-3d', sceneid());
    session.startResume(() => (xrmode ? null : {scene: sceneid(), position: controls.getPosition(), yaw: controls.getYaw()}));
    clearMessage(root, strings.loading);

    // Behat hooks.
    root.dataset.mavrReady = '3d';
    root.mavrTest = {
        enterZone: (name) => {
            const zone = zonesOf(current.doc).find((z) => z.name === name || plainText(z.name) === name);
            if (!zone) {
                return false;
            }
            const c = vec3(zone.center, [0, 0, 0]);
            controls.teleport([c[0], 0, c[2]], null);
            return true;
        },
        clickObject: (name) => {
            const matches = (Array.isArray(current.doc.interactables) ? current.doc.interactables : []).filter((i) => {
                const record = current.handle.items.get(i.id);
                const title = i.content && i.content.title ? plainText(i.content.title) : '';
                return record && record.group.visible && (i.name === name || plainText(i.name) === name || title === name);
            });
            const item = matches.find((i) => i.kind === 'hotspot') || matches[0];
            if (!item) {
                return false;
            }
            activate(item);
            return true;
        },
        currentScene: () => sceneid(),
    };
    canvas.focus({preventScroll: true});

    return {
        /** Stop the 3D view and release everything. */
        stop: () => {
            if (stopped) {
                return;
            }
            stopped = true;
            p.setStopped();
            if (xr.mode) {
                xr.exit();
            }
            renderer.setAnimationLoop(null);
            observer.disconnect();
            controls.detach();
            unsubscribe();
            stage.removeEventListener('click', onHudClick);
            stage.removeEventListener('beforexrselect', onBeforeXrSelect);
            panels.close();
            if (messagetimer) {
                window.clearTimeout(messagetimer);
            }
            if (xrinput) {
                xrinput.dispose();
                xrinput = null;
            }
            if (xrpanels) {
                xrpanels.dispose();
                xrpanels = null;
            }
            if (wrist) {
                wrist.dispose();
                wrist = null;
            }
            session.stop();
            current.handle.dispose();
            reticle.geometry.dispose();
            reticle.material.dispose();
            renderer.dispose();
            [vrbutton, arbutton, exitbutton, xrnote, xrstatus].forEach((element) => {
                if (element) {
                    element.classList.add('d-none');
                }
            });
            stage.classList.add('d-none');
            stage.classList.remove('mavr-touch', 'mavr-xr', 'mavr-xr-overlay');
            delete root.dataset.mavrReady;
            delete root.mavrTest;
        },
    };
};
