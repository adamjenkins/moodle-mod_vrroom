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
 * Builds one room (a vrroom-scene/1 document) as three.js objects. Shared by the built-in viewer
 * and the room editor, so the editor shows exactly what students see.
 *
 * Split out of the built-in viewer, whose model loading, hotspot markers and labels follow
 * mod_gear's viewer.
 *
 * Conventions: interactables are groups at their position and rotation (hotspot markers stay
 * upright outside the editor); props, zone helpers and primitive grabbables use unit geometry
 * scaled by their size, so readTransform(object).scale of a prop or zone helper is its size;
 * spawn helpers turn by their yaw (yaw θ looks towards (−sin θ, 0, −cos θ)).
 *
 * @module     vrroomruntime_builtin/scenebuilder
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {getString} from 'core/str';
import {buildEnvironment} from 'vrroomruntime_builtin/environment';
import {plainText} from 'vrroomruntime_builtin/util';

/** Degrees to radians. */
export const DEG = Math.PI / 180;

/** Opacity factor of hidden objects in the editor and of locked hotspots in the room. */
const DIMMED = 0.4;

/** Colours of the editor helpers and portals. */
const COLOURS = {
    zone: 0x3d8bfd,
    spawn: 0x20c997,
    portal: 0x6ea8fe,
    frame: 0x3b3f4a,
    grabbable: '#c8a050',
    prop: '#d9d4cc',
    speaker: 0x2b2f36,
    placeholder: 0xcccccc,
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
 * A positive number or a default.
 *
 * @param {*} value Value.
 * @param {number} fallback Default.
 * @returns {number}
 */
const positive = (value, fallback) => {
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : fallback;
};

/**
 * A "#rrggbb" colour string or a default.
 *
 * @param {*} value Value.
 * @param {string} fallback Default.
 * @returns {string}
 */
const colourOf = (value, fallback) => (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value) ? value : fallback);

/**
 * Round to a number of decimals, without negative zero.
 *
 * @param {number} value Value.
 * @param {number} digits Decimals.
 * @returns {number}
 */
const round = (value, digits) => {
    const f = Math.pow(10, digits);
    const r = Math.round(value * f) / f;
    return r === 0 ? 0 : r;
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
 * All materials of an object.
 *
 * @param {Object} object THREE.Object3D
 * @returns {Object[]}
 */
const materialsOf = (object) => {
    if (!object.material) {
        return [];
    }
    return Array.isArray(object.material) ? object.material : [object.material];
};

/**
 * Release GPU resources under an object.
 *
 * @param {Object} object THREE.Object3D
 */
export const disposeTree = (object) => {
    object.traverse((child) => {
        if (child.geometry) {
            child.geometry.dispose();
        }
        materialsOf(child).forEach((material) => {
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
 * Multiply the opacity of every material under an object (1 restores the original).
 *
 * @param {Object} object THREE.Object3D
 * @param {number} factor Opacity factor.
 */
const setOpacity = (object, factor) => {
    object.traverse((child) => {
        materialsOf(child).forEach((material) => {
            if (material.userData.vrroomBase === undefined) {
                material.userData.vrroomBase = {opacity: material.opacity, transparent: material.transparent};
            }
            const base = material.userData.vrroomBase;
            const transparent = factor < 1 ? true : base.transparent;
            const opacity = base.opacity * factor;
            if (material.opacity !== opacity || material.transparent !== transparent) {
                material.opacity = opacity;
                material.transparent = transparent;
                material.needsUpdate = true;
            }
        });
    });
};

/**
 * Apply position, rotation (degrees) and scale of a scene object to a three.js object.
 *
 * Only the fields present in the data are applied.
 *
 * @param {Object} object3d THREE.Object3D
 * @param {Object} data Scene object with position, rotation and/or scale.
 */
export const applyTransform = (object3d, data) => {
    const d = data || {};
    if (Array.isArray(d.position) && d.position.length === 3) {
        const p = vec3(d.position, [0, 0, 0]);
        object3d.position.set(p[0], p[1], p[2]);
    }
    if (Array.isArray(d.rotation) && d.rotation.length === 3) {
        const r = vec3(d.rotation, [0, 0, 0]);
        object3d.rotation.set(r[0] * DEG, r[1] * DEG, r[2] * DEG);
    }
    if (Array.isArray(d.scale) && d.scale.length === 3) {
        const s = vec3(d.scale, [1, 1, 1]);
        object3d.scale.set(s[0], s[1], s[2]);
    }
};

/**
 * Read the transform of a three.js object as scene values.
 *
 * @param {Object} object3d THREE.Object3D
 * @returns {Object} {position: number[3] (3 dp), rotation: number[3] (degrees, 0.1), scale: number[3] (3 dp)}
 */
export const readTransform = (object3d) => ({
    position: [object3d.position.x, object3d.position.y, object3d.position.z].map((v) => round(v, 3)),
    rotation: [object3d.rotation.x, object3d.rotation.y, object3d.rotation.z].map((v) => round(v / DEG, 1)),
    scale: [object3d.scale.x, object3d.scale.y, object3d.scale.z].map((v) => round(v, 3)),
});

/**
 * A promise that resolves (to undefined) when all the given promises have resolved.
 *
 * @param {Promise[]} promises The promises.
 * @returns {Promise<undefined>}
 */
const allSettledToUndefined = (promises) => Promise.all(promises).then(() => undefined);

/**
 * Build one room.
 *
 * Options: loader (GLTFLoader), textureloader (THREE.TextureLoader), resolveUrl(fileref) →
 * string|null (default: fileref.url), editor (bool), hidden (Set of initially hidden ids),
 * onLoadError(error, item), roomName(sceneid) → string|null (portal labels).
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} scenedoc vrroom-scene/1 document.
 * @param {Object} options Options.
 * @returns {Promise<Object>} SceneHandle
 */
export const buildScene = (THREE, scenedoc, options = {}) => {
    const doc = scenedoc || {};
    const editor = !!options.editor;
    const loader = options.loader;
    const textureloader = options.textureloader || new THREE.TextureLoader();
    const resolveUrl = (fileref) => {
        if (!fileref || typeof fileref !== 'object') {
            return null;
        }
        if (options.resolveUrl) {
            return options.resolveUrl(fileref) || null;
        }
        return typeof fileref.url === 'string' && fileref.url ? fileref.url : null;
    };
    const onLoadError = (error, item) => {
        if (options.onLoadError) {
            options.onLoadError(error, item);
        } else {
            Log.debug(error);
        }
    };
    const roomName = (sceneid) => (options.roomName ? options.roomName(sceneid) : null);

    const root = new THREE.Group();
    root.name = 'vrroom-room';
    const items = new Map();
    const props = new Map();
    const zones = new Map();
    const spawns = new Map();
    let hidden = new Set(options.hidden instanceof Set ? options.hidden : []);
    let lockfn = null;
    let zonesvisible = editor;
    let disposed = false;
    const loads = [];

    // Track a background load: settles the ready promise, reports failures.
    const track = (promise, item) => {
        const settled = promise.catch((error) => {
            if (!disposed) {
                onLoadError(error, item);
            }
        });
        loads.push(settled);
        return settled;
    };

    const canvasTexture = (canvas) => {
        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.anisotropy = 4;
        return texture;
    };

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

    // A wireframe box that stands in for a model in the editor while it loads or has no file.
    const makePlaceholder = (size) => {
        const mesh = new THREE.Mesh(
            new THREE.BoxGeometry(size, size, size),
            new THREE.MeshBasicMaterial({color: COLOURS.placeholder, wireframe: true})
        );
        mesh.position.y = size / 2;
        mesh.name = 'vrroom-placeholder';
        return mesh;
    };

    // Scale a loaded model to fit a size; put it on (or centred at) the group origin.
    const placeModel = (model, fit, centred) => {
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
        model.position.y -= centred ? centre.y : box.min.y;
    };

    // Load a GLB into a group (placeholder in the editor until it arrives).
    const loadModel = (record, fileref, fit, centred) => {
        const url = resolveUrl(fileref);
        const placeholder = editor ? makePlaceholder(Number.isFinite(fit) && fit > 0 ? fit : 0.5) : null;
        if (placeholder) {
            if (centred) {
                placeholder.position.y = 0;
            }
            record.group.add(placeholder);
        }
        if (!url || !loader) {
            return;
        }
        track(loader.loadAsync(url).then((gltf) => {
            const model = gltf.scene || (gltf.scenes && gltf.scenes[0]);
            if (disposed || record.disposed) {
                if (model) {
                    disposeTree(model);
                }
                return;
            }
            if (!model) {
                return;
            }
            placeModel(model, fit, centred);
            if (placeholder) {
                record.group.remove(placeholder);
                disposeTree(placeholder);
            }
            record.group.add(model);
            refreshItem(record);
            return;
        }), record.item);
    };

    // Primitive mesh with unit geometry scaled by a size.
    const primitive = (shape, size, colour) => {
        let geometry;
        if (shape === 'cylinder') {
            geometry = new THREE.CylinderGeometry(0.5, 0.5, 1, 32);
        } else if (shape === 'sphere') {
            geometry = new THREE.SphereGeometry(0.5, 32, 16);
        } else {
            geometry = new THREE.BoxGeometry(1, 1, 1);
        }
        const mesh = new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({color: new THREE.Color(colour), roughness: 0.8}));
        mesh.scale.set(size[0], size[1], size[2]);
        return mesh;
    };

    const buildHotspot = (record) => {
        const item = record.item;
        const sphere = new THREE.Mesh(
            new THREE.SphereGeometry(0.15, 24, 16),
            new THREE.MeshStandardMaterial({color: 0xffcc33, emissive: 0xffaa00, emissiveIntensity: 0.9, transparent: true})
        );
        record.group.add(sphere);
        const hit = new THREE.Mesh(new THREE.SphereGeometry(0.35, 12, 8), new THREE.MeshBasicMaterial({visible: false}));
        record.group.add(hit);
        const title = plainText((item.content && item.content.title) || item.name || '');
        if (title) {
            const label = makeLabel(title);
            label.position.set(0, 0.38, 0);
            record.group.add(label);
            record.label = label;
        }
        if (!editor) {
            // Markers and labels are upright whatever the hotspot's rotation.
            record.group.rotation.set(0, 0, 0);
        }
        record.sphere = sphere;
    };

    const buildImage = (record) => {
        const item = record.item;
        const width = positive(item.width, 1.6);
        const placeholder = new THREE.Mesh(
            new THREE.PlaneGeometry(width, width * 0.75),
            new THREE.MeshBasicMaterial({color: COLOURS.placeholder, side: THREE.DoubleSide})
        );
        record.group.add(placeholder);
        const url = resolveUrl(item.file);
        if (!url) {
            return;
        }
        track(textureloader.loadAsync(url).then((texture) => {
            if (disposed || record.disposed) {
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
            record.group.remove(placeholder);
            disposeTree(placeholder);
            record.group.add(plane);
            refreshItem(record);
            return;
        }), item);
    };

    const buildPortal = (record) => {
        const item = record.item;
        const width = positive(item.width, 1.2);
        const height = positive(item.height, 2.2);
        const t = 0.08;
        const framematerial = new THREE.MeshStandardMaterial({color: COLOURS.frame, roughness: 0.7});
        [
            {size: [t, height, t], position: [-width / 2, height / 2, 0]},
            {size: [t, height, t], position: [width / 2, height / 2, 0]},
            {size: [width + t, t, t], position: [0, height, 0]},
            {size: [width + t, t / 2, t], position: [0, t / 4, 0]},
        ].forEach((part) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(part.size[0], part.size[1], part.size[2]), framematerial.clone());
            mesh.position.set(part.position[0], part.position[1], part.position[2]);
            record.group.add(mesh);
        });
        framematerial.dispose();
        const plane = new THREE.Mesh(
            new THREE.PlaneGeometry(width, height),
            new THREE.MeshStandardMaterial({
                color: COLOURS.portal, emissive: COLOURS.portal, emissiveIntensity: 0.6,
                transparent: true, opacity: 0.45, side: THREE.DoubleSide, depthWrite: false,
            })
        );
        plane.position.set(0, height / 2, 0);
        record.group.add(plane);
        record.portal = plane;
        const target = item.target && typeof item.target === 'object' ? item.target : {};
        const name = plainText(roomName(target.scene) || '');
        const labeltext = name ? getString('portalto', 'vrroomruntime_builtin', name) : Promise.resolve(plainText(item.name || ''));
        track(Promise.resolve(labeltext).then((text) => {
            if (disposed || record.disposed || !text) {
                return;
            }
            const label = makeLabel(text);
            label.position.set(0, height + 0.25, 0);
            record.group.add(label);
            record.label = label;
            refreshItem(record);
            return;
        }), item);
    };

    const buildGrabbable = (record) => {
        const item = record.item;
        if (item.file && typeof item.file === 'object') {
            loadModel(record, item.file, Number(item.fit), true);
            return;
        }
        const size = vec3(item.size, [0.2, 0.2, 0.2]).map((v) => (v > 0 ? v : 0.2));
        const mesh = primitive(item.shape, size, colourOf(item.color, COLOURS.grabbable));
        record.group.add(mesh);
    };

    const buildMedia = (record) => {
        const item = record.item;
        const url = editor ? null : resolveUrl(item.file);
        const isvideo = item.media === 'video';
        if (isvideo) {
            const width = positive(item.width, 1.6);
            let element = null;
            let material;
            if (url) {
                element = document.createElement('video');
                element.playsInline = true;
                element.setAttribute('playsinline', '');
                element.preload = 'metadata';
                element.loop = !!item.loop;
                element.src = url;
                const texture = new THREE.VideoTexture(element);
                texture.colorSpace = THREE.SRGBColorSpace;
                material = new THREE.MeshBasicMaterial({map: texture, side: THREE.DoubleSide});
            } else {
                material = new THREE.MeshBasicMaterial({color: 0x111418, side: THREE.DoubleSide});
            }
            const plane = new THREE.Mesh(new THREE.PlaneGeometry(width, width * 9 / 16), material);
            record.group.add(plane);
            if (element) {
                element.addEventListener('loadedmetadata', () => {
                    if (disposed || record.disposed || !(element.videoWidth > 0 && element.videoHeight > 0)) {
                        return;
                    }
                    plane.geometry.dispose();
                    plane.geometry = new THREE.PlaneGeometry(width, width * element.videoHeight / element.videoWidth);
                });
                record.media = element;
            }
        } else {
            const body = new THREE.Mesh(
                new THREE.BoxGeometry(0.25, 0.35, 0.2),
                new THREE.MeshStandardMaterial({color: COLOURS.speaker, roughness: 0.6})
            );
            const cone = new THREE.Mesh(
                new THREE.CylinderGeometry(0.08, 0.05, 0.03, 24),
                new THREE.MeshStandardMaterial({color: 0x6c757d, roughness: 0.4})
            );
            cone.rotation.x = Math.PI / 2;
            cone.position.set(0, 0.03, 0.11);
            record.group.add(body);
            record.group.add(cone);
            if (url) {
                const element = document.createElement('audio');
                element.preload = 'metadata';
                element.loop = !!item.loop;
                element.src = url;
                record.media = element;
            }
        }
        const title = plainText(item.name || '');
        if (title) {
            const label = makeLabel(title);
            label.position.set(0, isvideo ? positive(item.width, 1.6) * 9 / 32 + 0.25 : 0.45, 0);
            record.group.add(label);
            record.label = label;
        }
        if (record.media && item.autoplay) {
            Promise.resolve(record.media.play()).catch(() => null);
        }
    };

    // Visibility, hidden dimming and lock dimming of one interactable.
    const refreshItem = (record) => {
        const ishidden = hidden.has(record.item.id);
        if (editor) {
            record.group.visible = true;
            setOpacity(record.group, ishidden ? DIMMED : 1);
            return;
        }
        record.group.visible = !ishidden;
        if (record.sphere) {
            const opacity = lockfn && lockfn(record.item) ? DIMMED : 1;
            record.sphere.material.opacity = opacity;
            if (record.label) {
                record.label.material.opacity = opacity;
            }
        }
    };

    const refreshProp = (record) => {
        const ishidden = hidden.has(record.prop.id);
        if (editor) {
            record.mesh.visible = true;
            setOpacity(record.mesh, ishidden ? DIMMED : 1);
        } else {
            record.mesh.visible = !ishidden;
        }
    };

    const releaseMedia = (record) => {
        if (record.media) {
            try {
                record.media.pause();
                record.media.removeAttribute('src');
                record.media.load();
            } catch (e) {
                // A media element that never loaded has nothing to release.
            }
            record.media = null;
        }
    };

    const buildInteractable = (item) => {
        const group = new THREE.Group();
        applyTransform(group, {position: vec3(item.position, [0, 0, 0]), rotation: vec3(item.rotation, [0, 0, 0])});
        group.userData.vrroomItem = item.id;
        group.userData.vrroomKind = item.kind;
        group.name = `vrroom-item-${item.id}`;
        const record = {item: item, kind: item.kind, group: group, pickable: group, sphere: null, label: null,
            media: null, portal: null, disposed: false};
        const before = loads.length;
        switch (item.kind) {
            case 'hotspot':
                buildHotspot(record);
                break;
            case 'model':
                applyTransform(group, {scale: vec3(item.scale, [1, 1, 1])});
                loadModel(record, item.file, Number(item.fit), false);
                break;
            case 'image':
                buildImage(record);
                break;
            case 'text':
                group.add(makeTextBoard(plainText(item.text || item.name || ''), positive(item.width, 2)));
                break;
            case 'portal':
                buildPortal(record);
                break;
            case 'grabbable':
                buildGrabbable(record);
                break;
            case 'media':
                buildMedia(record);
                break;
            default:
                break;
        }
        root.add(group);
        items.set(item.id, record);
        refreshItem(record);
        return Promise.all(loads.slice(before)).then(() => undefined);
    };

    const buildProp = (prop) => {
        const size = vec3(prop.size, [1, 1, 1]).map((v) => (v > 0 ? v : 1));
        const mesh = primitive(prop.shape, size, colourOf(prop.color, COLOURS.prop));
        applyTransform(mesh, {position: vec3(prop.position, [0, 0, 0]), rotation: vec3(prop.rotation, [0, 0, 0])});
        mesh.userData.vrroomProp = prop.id;
        mesh.name = `vrroom-prop-${prop.id}`;
        root.add(mesh);
        const record = {prop: prop, mesh: mesh};
        props.set(prop.id, record);
        refreshProp(record);
    };

    const makeZoneHelper = (zone) => {
        const helper = new THREE.Mesh(
            new THREE.BoxGeometry(1, 1, 1),
            new THREE.MeshBasicMaterial({color: COLOURS.zone, transparent: true, opacity: 0.15, depthWrite: false})
        );
        const edges = new THREE.LineSegments(
            new THREE.EdgesGeometry(helper.geometry),
            new THREE.LineBasicMaterial({color: COLOURS.zone, transparent: true, opacity: 0.8})
        );
        helper.add(edges);
        const c = vec3(zone.center, [0, 1.5, 0]);
        const s = vec3(zone.size, [2, 3, 2]).map((v) => (v > 0 ? v : 1));
        helper.position.set(c[0], c[1], c[2]);
        helper.scale.set(s[0], s[1], s[2]);
        helper.userData.vrroomZone = zone.id;
        helper.name = `vrroom-zone-${zone.id}`;
        root.add(helper);
        return helper;
    };

    const buildZone = (zone) => {
        zones.set(zone.id, {zone: zone, helper: zonesvisible ? makeZoneHelper(zone) : null});
    };

    const buildSpawn = (spawn) => {
        let helper = null;
        if (editor) {
            helper = new THREE.Group();
            const material = new THREE.MeshBasicMaterial({color: COLOURS.spawn});
            const disc = new THREE.Mesh(new THREE.RingGeometry(0.18, 0.28, 32), material);
            disc.rotation.x = -Math.PI / 2;
            disc.position.y = 0.02;
            helper.add(disc);
            const arrow = new THREE.Mesh(new THREE.ConeGeometry(0.12, 0.4, 16), material.clone());
            // The cone points along −z, the direction a yaw of 0 looks at.
            arrow.rotation.x = -Math.PI / 2;
            arrow.position.set(0, 0.1, -0.35);
            helper.add(arrow);
            const p = vec3(spawn.position, [0, 0, 0]);
            helper.position.set(p[0], p[1], p[2]);
            helper.rotation.y = (Number(spawn.yaw) || 0) * DEG;
            helper.userData.vrroomSpawn = spawn.id;
            helper.name = `vrroom-spawn-${spawn.id}`;
            root.add(helper);
        }
        spawns.set(spawn.id, {spawn: spawn, helper: helper});
    };

    const removeObject = (object) => {
        if (object.parent) {
            object.parent.remove(object);
        }
        disposeTree(object);
    };

    const removeRecord = (kind, id) => {
        if (kind === 'interactable') {
            const record = items.get(id);
            if (record) {
                record.disposed = true;
                releaseMedia(record);
                removeObject(record.group);
                items.delete(id);
            }
        } else if (kind === 'prop') {
            const record = props.get(id);
            if (record) {
                removeObject(record.mesh);
                props.delete(id);
            }
        } else if (kind === 'zone') {
            const record = zones.get(id);
            if (record) {
                if (record.helper) {
                    removeObject(record.helper);
                }
                zones.delete(id);
            }
        } else if (kind === 'spawn') {
            const record = spawns.get(id);
            if (record) {
                if (record.helper) {
                    removeObject(record.helper);
                }
                spawns.delete(id);
            }
        }
    };

    // The environment, with a plain hall when an uploaded environment fails to load.
    const makeEnvironment = (environment) => buildEnvironment(THREE, environment, loader, resolveUrl).catch((error) => {
        onLoadError(error, {kind: 'environment'});
        const env = environment || {};
        const b = env.bounds && Array.isArray(env.bounds.min) && Array.isArray(env.bounds.max) ? env.bounds : null;
        const width = b ? Math.max(2, Number(b.max[0]) - Number(b.min[0]) + 0.8) : 12;
        const length = b ? Math.max(2, Number(b.max[2]) - Number(b.min[2]) + 0.8) : 12;
        return buildEnvironment(THREE, Object.assign({}, env, {
            type: 'preset',
            file: null,
            shape: {kind: 'hall', width: width, length: length, height: 3, ceiling: false},
        }), loader, resolveUrl);
    });

    const handle = {
        root: root,
        environment: null,
        items: items,
        props: props,
        zones: zones,
        spawns: spawns,
        ready: null,

        /**
         * Animate hotspot markers and portals.
         *
         * @param {number} dt Seconds since the last frame.
         * @param {number} t Seconds since the start.
         */
        update(dt, t) {
            const pulse = 1 + 0.08 * Math.sin(t * 3);
            items.forEach((record) => {
                if (record.sphere) {
                    record.sphere.scale.set(pulse, pulse, pulse);
                }
                if (record.portal) {
                    record.portal.material.opacity = 0.4 + 0.1 * Math.sin(t * 2);
                    record.portal.material.emissiveIntensity = 0.5 + 0.2 * Math.sin(t * 2.6);
                }
            });
        },

        /**
         * Set the hidden ids: invisible in the room, dimmed in the editor.
         *
         * @param {Set|string[]} set Hidden ids.
         */
        setHidden(set) {
            hidden = new Set(set instanceof Set || Array.isArray(set) ? set : []);
            items.forEach(refreshItem);
            props.forEach(refreshProp);
        },

        /**
         * Dim the hotspots for which fn(item) is true (room only).
         *
         * @param {Function|null} fn fn(item) → bool.
         */
        setLocked(fn) {
            lockfn = typeof fn === 'function' ? fn : null;
            items.forEach(refreshItem);
        },

        /**
         * Show or hide the zones as translucent boxes (always shown in the editor).
         *
         * @param {boolean} visible Whether zone boxes are shown.
         */
        setZonesVisible(visible) {
            zonesvisible = editor || !!visible;
            zones.forEach((record) => {
                if (zonesvisible && !record.helper) {
                    record.helper = makeZoneHelper(record.zone);
                } else if (!zonesvisible && record.helper) {
                    removeObject(record.helper);
                    record.helper = null;
                }
            });
        },

        /**
         * Objects a click or a controller ray can hit: the environment, visible props and
         * interactables, and the zone boxes while they are shown.
         *
         * @returns {Object[]} THREE.Object3D list
         */
        pickTargets() {
            const targets = [];
            if (handle.environment) {
                targets.push(handle.environment.group);
            }
            props.forEach((record) => {
                if (record.mesh.visible) {
                    targets.push(record.mesh);
                }
            });
            items.forEach((record) => {
                if (record.group.visible) {
                    targets.push(record.group);
                }
            });
            zones.forEach((record) => {
                if (record.helper) {
                    targets.push(record.helper);
                }
            });
            return targets;
        },

        /**
         * What a hit object belongs to.
         *
         * @param {Object} object3d THREE.Object3D that was hit.
         * @returns {Object|null} {kind: 'interactable'|'prop'|'zone'|'spawn', id}
         */
        findPickable(object3d) {
            let object = object3d;
            while (object) {
                const data = object.userData || {};
                if (data.vrroomItem !== undefined) {
                    return {kind: 'interactable', id: data.vrroomItem};
                }
                if (data.vrroomProp !== undefined) {
                    return {kind: 'prop', id: data.vrroomProp};
                }
                if (data.vrroomZone !== undefined) {
                    return {kind: 'zone', id: data.vrroomZone};
                }
                if (data.vrroomSpawn !== undefined) {
                    return {kind: 'spawn', id: data.vrroomSpawn};
                }
                if (object === root) {
                    return null;
                }
                object = object.parent;
            }
            return null;
        },

        /**
         * (Re)build one object.
         *
         * @param {string} kind interactable, prop, zone or spawn.
         * @param {Object} data The scene object.
         * @returns {Promise} settles when its files have loaded (or failed)
         */
        addOrUpdate(kind, data) {
            if (!data || typeof data.id !== 'string') {
                return Promise.resolve();
            }
            removeRecord(kind, data.id);
            if (kind === 'interactable') {
                return buildInteractable(data);
            }
            if (kind === 'prop') {
                buildProp(data);
            } else if (kind === 'zone') {
                buildZone(data);
            } else if (kind === 'spawn') {
                buildSpawn(data);
            }
            return Promise.resolve();
        },

        /**
         * Remove one object.
         *
         * @param {string} kind interactable, prop, zone or spawn.
         * @param {string} id Its id.
         */
        remove(kind, id) {
            removeRecord(kind, id);
        },

        /**
         * Replace the environment.
         *
         * @param {Object} environment The scene's environment.
         * @returns {Promise}
         */
        setEnvironment(environment) {
            return makeEnvironment(environment).then((env) => {
                if (disposed) {
                    disposeTree(env.group);
                    return undefined;
                }
                if (handle.environment) {
                    removeObject(handle.environment.group);
                }
                handle.environment = env;
                root.add(env.group);
                return undefined;
            });
        },

        /** Release every object, media element and GPU resource of the room. */
        dispose() {
            if (disposed) {
                return;
            }
            disposed = true;
            items.forEach((record) => {
                record.disposed = true;
                releaseMedia(record);
            });
            if (root.parent) {
                root.parent.remove(root);
            }
            disposeTree(root);
            items.clear();
            props.clear();
            zones.clear();
            spawns.clear();
        },
    };

    return handle.setEnvironment(doc.environment).then(() => {
        (Array.isArray(doc.props) ? doc.props : []).forEach((prop) => {
            if (prop && typeof prop.id === 'string') {
                buildProp(prop);
            }
        });
        (Array.isArray(doc.zones) ? doc.zones : []).forEach((zone) => {
            if (zone && typeof zone.id === 'string') {
                buildZone(zone);
            }
        });
        (Array.isArray(doc.spawn) ? doc.spawn : []).forEach((spawn) => {
            if (spawn && typeof spawn.id === 'string') {
                buildSpawn(spawn);
            }
        });
        (Array.isArray(doc.interactables) ? doc.interactables : []).forEach((item) => {
            if (item && typeof item.id === 'string') {
                buildInteractable(item);
            }
        });
        handle.ready = allSettledToUndefined(loads);
        return handle;
    });
};
