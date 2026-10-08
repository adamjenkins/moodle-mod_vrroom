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
 * The 3D room: renderer, scene from mavr-scene/1, models, zones, clicks, hidden objects and
 * graph effects.
 *
 * The click-to-raycast-to-hotspot-panel flow and the GLTF loading follow mod_gear's viewer.
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
import {getStrings} from 'core/str';
import {buildEnvironment} from 'mavrruntime_builtin/environment';
import {attach} from 'mavrruntime_builtin/controls';
import {createPanels} from 'mavrruntime_builtin/panels';
import {region, plainText, announce, showMessage, clearMessage, startScene} from 'mavrruntime_builtin/util';

/** Degrees to radians. */
const DEG = Math.PI / 180;

/** Distance within which Enter activates the object in the middle of the screen (m). */
const REACH = 6;

/** Opacity of locked station hotspots. */
const LOCKEDOPACITY = 0.4;

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
 * Wrap text into lines that fit a width.
 *
 * @param {CanvasRenderingContext2D} ctx Context with the font set.
 * @param {string} text Text.
 * @param {number} maxwidth Width in px.
 * @returns {string[]}
 */
const wrap = (ctx, text, maxwidth) => {
    const lines = [];
    String(text).split(/\n/).forEach((paragraph) => {
        let line = '';
        paragraph.split(/\s+/).forEach((word) => {
            const candidate = line ? `${line} ${word}` : word;
            if (ctx.measureText(candidate).width > maxwidth && line) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        });
        lines.push(line);
    });
    return lines.slice(0, 12);
};

/**
 * A canvas texture.
 *
 * @param {HTMLCanvasElement} canvas Canvas.
 * @returns {Object} THREE.CanvasTexture
 */
const canvasTexture = (canvas) => {
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 4;
    return texture;
};

/**
 * A billboard label sprite.
 *
 * @param {string} text Plain text.
 * @returns {Object} THREE.Sprite
 */
const makeLabel = (text) => {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const font = 'bold 44px sans-serif';
    ctx.font = font;
    const width = Math.min(1024, Math.ceil(ctx.measureText(text).width) + 48);
    canvas.width = width;
    canvas.height = 80;
    ctx.font = font;
    ctx.fillStyle = 'rgba(20, 24, 30, 0.78)';
    ctx.fillRect(0, 0, width, 80);
    ctx.fillStyle = '#ffffff';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'center';
    ctx.fillText(text, width / 2, 42);
    const material = new THREE.SpriteMaterial({map: canvasTexture(canvas), transparent: true, depthWrite: false});
    const sprite = new THREE.Sprite(material);
    const height = 0.22;
    sprite.scale.set(height * width / 80, height, 1);
    return sprite;
};

/**
 * A text board as a plane with a canvas texture.
 *
 * @param {string} text Plain text.
 * @param {number} width Width in metres.
 * @returns {Object} THREE.Mesh
 */
const makeTextBoard = (text, width) => {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const px = 1024;
    const font = '48px sans-serif';
    ctx.font = font;
    const lines = wrap(ctx, text, px - 96);
    const lineheight = 60;
    canvas.width = px;
    canvas.height = Math.max(120, lines.length * lineheight + 72);
    ctx.font = font;
    ctx.fillStyle = '#fbfaf7';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#1d2125';
    ctx.textBaseline = 'top';
    lines.forEach((line, i) => ctx.fillText(line, 48, 36 + i * lineheight));
    const height = width * canvas.height / canvas.width;
    return new THREE.Mesh(
        new THREE.PlaneGeometry(width, height),
        new THREE.MeshBasicMaterial({map: canvasTexture(canvas), side: THREE.DoubleSide})
    );
};

/**
 * Release GPU resources under an object.
 *
 * @param {Object} object THREE.Object3D
 */
const disposeTree = (object) => {
    object.traverse((child) => {
        if (child.geometry) {
            child.geometry.dispose();
        }
        const materials = Array.isArray(child.material) ? child.material : (child.material ? [child.material] : []);
        materials.forEach((material) => {
            Object.keys(material).forEach((key) => {
                const value = material[key];
                if (value && value.isTexture) {
                    value.dispose();
                }
            });
            material.dispose();
        });
    });
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
    const scenedoc = startScene(config);
    const stage = region(root, 'mavr-stage');
    const canvas = region(root, 'mavr-canvas');

    return getStrings([
        {key: 'loading', component: 'mavrruntime_builtin'},
        {key: 'modelloadfailed', component: 'mavrruntime_builtin'},
        {key: 'stationlocked', component: 'mavrruntime_builtin'},
    ]).then(([loading, modelfailed, locked]) => {
        if (!scenedoc) {
            throw new Error('No scene in the launch config');
        }
        const strings = {loading: loading, modelfailed: modelfailed, locked: locked};
        stage.classList.remove('d-none');
        showMessage(root, loading, 'info');

        const renderer = new THREE.WebGLRenderer({canvas: canvas, antialias: true});
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        const scene3 = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(70, 16 / 9, 0.05, 300);
        const loader = new GLTFLoader();
        const textureloader = new THREE.TextureLoader();

        return Promise.all([
            buildEnvironment(THREE, scenedoc.environment, loader),
            session.loadResume(),
        ]).then(([env, resume]) => run({root, session, scenedoc, stage, canvas, renderer, scene3, camera, loader,
            textureloader, env, resume, strings}))
            .catch((error) => {
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
 * Build the scene and run the frame loop.
 *
 * @param {Object} p Everything prepared by start().
 * @returns {Object} {stop()}
 */
const run = (p) => {
    const {root, session, scenedoc, stage, canvas, renderer, scene3, camera, loader, textureloader, env, resume, strings} = p;
    const sceneid = scenedoc.id;
    let stopped = false;
    const items = new Map();
    const props = new Map();
    const interactables = Array.isArray(scenedoc.interactables) ? scenedoc.interactables : [];
    const zones = Array.isArray(scenedoc.zones) ? scenedoc.zones : [];
    const spawns = Array.isArray(scenedoc.spawn) ? scenedoc.spawn : [];
    const inside = new Set();
    const enteredrecorded = new Set();
    let lockedtimer = null;

    scene3.background = env.background;
    scene3.add(env.group);

    // Props.
    (Array.isArray(scenedoc.props) ? scenedoc.props : []).forEach((prop) => {
        const size = vec3(prop.size, [1, 1, 1]);
        const geometry = prop.shape === 'cylinder'
            ? new THREE.CylinderGeometry(size[0] / 2, size[0] / 2, size[1], 32)
            : new THREE.BoxGeometry(size[0], size[1], size[2]);
        const material = new THREE.MeshStandardMaterial({
            color: new THREE.Color(/^#[0-9a-fA-F]{6}$/.test(prop.color || '') ? prop.color : '#d9d4cc'),
            roughness: 0.8,
        });
        const mesh = new THREE.Mesh(geometry, material);
        const pos = vec3(prop.position, [0, 0, 0]);
        mesh.position.set(pos[0], pos[1], pos[2]);
        const rot = vec3(prop.rotation, [0, 0, 0]);
        mesh.rotation.set(rot[0] * DEG, rot[1] * DEG, rot[2] * DEG);
        mesh.userData.mavrProp = prop.id;
        scene3.add(mesh);
        props.set(prop.id, mesh);
    });

    // Models load in the background; a failure leaves a message and the scene goes on.
    const modelpending = new Pending('mavrruntime_builtin/viewer:models');
    const loads = [];
    const failedLoad = (error) => {
        Log.debug(error);
        if (!stopped) {
            showMessage(root, strings.modelfailed, 'warning');
        }
    };

    interactables.forEach((item) => {
        const group = new THREE.Group();
        const pos = vec3(item.position, [0, 0, 0]);
        const rot = vec3(item.rotation, [0, 0, 0]);
        group.position.set(pos[0], pos[1], pos[2]);
        group.rotation.set(rot[0] * DEG, rot[1] * DEG, rot[2] * DEG);
        group.userData.mavrItem = item.id;
        const record = {item: item, group: group, sphere: null, label: null};

        if (item.kind === 'hotspot') {
            const sphere = new THREE.Mesh(
                new THREE.SphereGeometry(0.15, 24, 16),
                new THREE.MeshStandardMaterial({
                    color: 0xffcc33, emissive: 0xffaa00, emissiveIntensity: 0.9, transparent: true,
                })
            );
            group.add(sphere);
            const hit = new THREE.Mesh(new THREE.SphereGeometry(0.35, 12, 8), new THREE.MeshBasicMaterial({visible: false}));
            group.add(hit);
            const title = plainText((item.content && item.content.title) || item.name || '');
            if (title) {
                const label = makeLabel(title);
                label.position.set(0, 0.38, 0);
                group.add(label);
                record.label = label;
            }
            // Labels and spheres are upright regardless of the hotspot's rotation.
            group.rotation.set(0, 0, 0);
            record.sphere = sphere;
        } else if (item.kind === 'model' && item.file && item.file.url) {
            const scale = vec3(item.scale, [1, 1, 1]);
            group.scale.set(scale[0], scale[1], scale[2]);
            loads.push(loader.loadAsync(item.file.url).then((gltf) => {
                if (stopped) {
                    disposeTree(gltf.scene);
                    return;
                }
                const model = gltf.scene || (gltf.scenes && gltf.scenes[0]);
                if (!model) {
                    return;
                }
                const fit = Number(item.fit);
                let box = new THREE.Box3().setFromObject(model);
                const size = box.getSize(new THREE.Vector3());
                const maxdim = Math.max(size.x, size.y, size.z);
                if (Number.isFinite(fit) && fit > 0 && maxdim > 0) {
                    model.scale.multiplyScalar(fit / maxdim);
                    model.updateMatrixWorld(true);
                    box = new THREE.Box3().setFromObject(model);
                }
                const centre = box.getCenter(new THREE.Vector3());
                model.position.x -= centre.x;
                model.position.z -= centre.z;
                model.position.y -= box.min.y;
                group.add(model);
                return;
            }).catch(failedLoad));
        } else if (item.kind === 'image' && item.file && item.file.url) {
            const width = Number(item.width) > 0 ? Number(item.width) : 1.6;
            const placeholder = new THREE.Mesh(
                new THREE.PlaneGeometry(width, width * 0.75),
                new THREE.MeshBasicMaterial({color: 0xcccccc, side: THREE.DoubleSide})
            );
            group.add(placeholder);
            loads.push(textureloader.loadAsync(item.file.url).then((texture) => {
                if (stopped) {
                    texture.dispose();
                    return;
                }
                texture.colorSpace = THREE.SRGBColorSpace;
                const image = texture.image || {};
                const aspect = image.width > 0 && image.height > 0 ? image.height / image.width : 0.75;
                const plane = new THREE.Mesh(
                    new THREE.PlaneGeometry(width, width * aspect),
                    new THREE.MeshBasicMaterial({map: texture, side: THREE.DoubleSide})
                );
                group.remove(placeholder);
                disposeTree(placeholder);
                group.add(plane);
                return;
            }).catch(failedLoad));
        } else if (item.kind === 'text') {
            const width = Number(item.width) > 0 ? Number(item.width) : 2;
            group.add(makeTextBoard(plainText(item.text || item.name || ''), width));
        }

        scene3.add(group);
        items.set(item.id, record);
    });
    Promise.all(loads).then(() => modelpending.resolve(), () => modelpending.resolve());

    const findItem = (id) => interactables.find((i) => i.id === id) || null;

    const panels = createPanels(stage, session, {
        onClose: () => canvas.focus({preventScroll: true}),
    });

    // Picking.
    const raycaster = new THREE.Raycaster();
    const pick = (ndc, far) => {
        raycaster.setFromCamera(new THREE.Vector2(ndc.x, ndc.y), camera);
        raycaster.far = far;
        const targets = [env.group];
        props.forEach((mesh) => {
            if (mesh.visible) {
                targets.push(mesh);
            }
        });
        items.forEach((record) => {
            if (record.group.visible) {
                targets.push(record.group);
            }
        });
        const hits = raycaster.intersectObjects(targets, true);
        for (const hit of hits) {
            let object = hit.object;
            while (object && object.userData.mavrItem === undefined && object.parent) {
                object = object.parent;
            }
            if (object && object.userData.mavrItem !== undefined) {
                return findItem(object.userData.mavrItem);
            }
            if (hit.object.isSprite) {
                continue;
            }
            // The first solid thing that is not an interactable blocks the click.
            return null;
        }
        return null;
    };

    const showLocked = () => {
        showMessage(root, strings.locked, 'warning');
        announce(root, strings.locked);
        if (lockedtimer) {
            window.clearTimeout(lockedtimer);
        }
        lockedtimer = window.setTimeout(() => clearMessage(root, strings.locked), 4000);
    };

    // Activate an interactable: interacted statement + panel.
    const activate = (item) => {
        if (!item) {
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
        const status = session.record('interacted', 'object', eventid, sceneid);
        if (status === 'locked') {
            showLocked();
            return;
        }
        if (hotspot) {
            panels.openInteractable(hotspot);
        }
    };

    // Controls and spawn.
    const controls = attach(camera, canvas, {
        bounds: env.bounds,
        eyeHeight: 1.6,
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
    const spawn = spawns[0] || {position: [0, 0, 0], yaw: 0};
    if (resume && resume.scene === sceneid) {
        controls.teleport(resume.position, resume.yaw);
    } else {
        controls.teleport(vec3(spawn.position, [0, 0, 0]), Number(spawn.yaw) || 0);
    }

    // Hidden objects and locked hotspots follow the state.
    const applyState = () => {
        const state = session.getState();
        items.forEach((record, id) => {
            record.group.visible = !state.hidden.has(id);
            if (record.sphere) {
                const zone = record.item.zone;
                const opacity = zone && session.isZoneLocked(zone) ? LOCKEDOPACITY : 1;
                record.sphere.material.opacity = opacity;
                if (record.label) {
                    record.label.material.opacity = opacity;
                }
            }
        });
        props.forEach((mesh, id) => {
            mesh.visible = !state.hidden.has(id);
        });
    };
    applyState();

    const enterZone = (zone) => {
        if (session.isZoneLocked(zone.id)) {
            showLocked();
            return;
        }
        enteredrecorded.add(zone.id);
        session.record('entered', 'zone', zone.id, sceneid);
    };

    const unsubscribe = session.on((message) => {
        if (message.type !== 'change' || stopped) {
            return;
        }
        applyState();
        panels.refresh();
        message.unlocked.forEach((objective) => {
            if (objective.type === 'visit_zone' && objective.zone && inside.has(objective.zone) &&
                    !enteredrecorded.has(objective.zone)) {
                const zone = zones.find((z) => z.id === objective.zone);
                if (zone) {
                    enterZone(zone);
                }
            }
        });
        message.effects.forEach((effect) => {
            if (effect.do === 'teleport') {
                const target = spawns.find((s) => s.id === effect.target);
                if (target) {
                    controls.teleport(vec3(target.position, [0, 0, 0]), Number(target.yaw) || 0);
                }
            } else if (effect.do === 'open_question') {
                panels.openQuestion(Number(effect.target));
            }
        });
    });

    // Zones, each frame.
    const testZones = () => {
        const p = controls.getPosition();
        zones.forEach((zone) => {
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
                    session.record('exited', 'zone', zone.id, sceneid);
                }
            }
        });
    };

    // Resize.
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

    // Frame loop.
    const clock = new THREE.Clock();
    renderer.setAnimationLoop(() => {
        const dt = clock.getDelta();
        controls.update(dt);
        testZones();
        const t = clock.elapsedTime;
        items.forEach((record) => {
            if (record.sphere) {
                const s = 1 + 0.08 * Math.sin(t * 3);
                record.sphere.scale.set(s, s, s);
            }
        });
        renderer.render(scene3, camera);
    });

    session.start('builtin-3d', sceneid);
    session.startResume(() => ({scene: sceneid, position: controls.getPosition(), yaw: controls.getYaw()}));
    clearMessage(root, strings.loading);

    // Behat hooks.
    root.dataset.mavrReady = '3d';
    root.mavrTest = {
        enterZone: (name) => {
            const zone = zones.find((z) => z.name === name || plainText(z.name) === name);
            if (!zone) {
                return false;
            }
            const c = vec3(zone.center, [0, 0, 0]);
            controls.teleport([c[0], 0, c[2]], null);
            return true;
        },
        clickObject: (name) => {
            const matches = interactables.filter((i) => {
                const record = items.get(i.id);
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
    };
    canvas.focus({preventScroll: true});

    return {
        /** Stop the 3D view and release everything. */
        stop: () => {
            if (stopped) {
                return;
            }
            stopped = true;
            renderer.setAnimationLoop(null);
            observer.disconnect();
            controls.detach();
            unsubscribe();
            panels.close();
            if (lockedtimer) {
                window.clearTimeout(lockedtimer);
            }
            session.stop();
            disposeTree(scene3);
            renderer.dispose();
            stage.classList.add('d-none');
            stage.classList.remove('mavr-touch');
            delete root.dataset.mavrReady;
            delete root.mavrTest;
        },
    };
};
