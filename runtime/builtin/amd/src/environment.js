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
 * Environment builder: the procedural presets (hall, courtyard), uploaded GLB environments and
 * 360° photos (panoramas).
 *
 * Every floor, wall and ceiling mesh (and an uploaded environment model) is tagged with
 * userData.vrroomShell, so augmented and mixed reality can hide the room's shell while keeping its
 * lights and contents.
 *
 * A panorama is drawn on the inside of a sphere around the viewer, never as scene.background:
 * three.js turns an equirectangular background into a cube render target of 6 × height² texels,
 * far too much for a phone with an 8192 × 4096 photo. The phone-sized copy (variants.mobile) is
 * used where the GPU or the device is small.
 *
 * @module     vrroomruntime_builtin/environment
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Wall thickness of the hall preset in metres. */
const WALL = 0.2;

/** Radius of the panorama sphere (m); items are placed up to 40 m away, inside it. */
export const PANORAMA_RADIUS = 50;

/** Height of the centre of a panorama: the viewer's eye height (m), as in placement.js. */
const PANORAMA_CENTRE = 1.6;

/** Largest texture side a phone-class GPU is assumed to handle (px). */
const MOBILE_TEXTURE = 4096;

/** Largest shorter screen side of a phone (CSS px). */
const PHONE_SCREEN = 600;

/** Walking bounds of a panorama: nowhere to walk. */
const ZERO_BOUNDS = {min: [0, 0, 0], max: [0, 0, 0]};

/**
 * A finite number or a default.
 *
 * @param {*} value Value.
 * @param {number} fallback Default.
 * @returns {number}
 */
const num = (value, fallback) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
};

/**
 * A colour from a "#rrggbb" string or a default.
 *
 * @param {Object} THREE three.js namespace.
 * @param {*} value Colour string.
 * @param {string} fallback Default colour.
 * @returns {Object} THREE.Color
 */
const colour = (THREE, value, fallback) => {
    const str = typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value) ? value : fallback;
    return new THREE.Color(str);
};

/**
 * Tag a mesh as part of the environment shell and add it to the group.
 *
 * @param {Object} mesh THREE.Object3D
 * @param {Object} group Target group.
 * @param {Object[]} shell Shell list to extend.
 * @returns {Object} the mesh
 */
const addShell = (mesh, group, shell) => {
    mesh.userData.vrroomShell = true;
    group.add(mesh);
    shell.push(mesh);
    return mesh;
};

/**
 * Build the hall preset: floor, four walls, optional ceiling, centred on the origin.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} shape Shape definition.
 * @param {Object} group Target group.
 * @param {Object[]} shell Shell list to extend.
 * @returns {Object|null} The ceiling mesh, if there is one.
 */
const buildHall = (THREE, shape, group, shell) => {
    const width = num(shape.width, 12);
    const length = num(shape.length, 30);
    const height = num(shape.height, 5);
    const wallmaterial = new THREE.MeshStandardMaterial({color: colour(THREE, shape.wallcolor, '#e8e4dc'), roughness: 0.9});
    const floormaterial = new THREE.MeshStandardMaterial({color: colour(THREE, shape.floorcolor, '#8a7f72'), roughness: 0.8});

    const floor = new THREE.Mesh(new THREE.PlaneGeometry(width, length), floormaterial);
    floor.rotation.x = -Math.PI / 2;
    floor.name = 'vrroom-floor';
    floor.receiveShadow = true;
    addShell(floor, group, shell);

    const walls = [
        {size: [width + 2 * WALL, height, WALL], position: [0, height / 2, -length / 2 - WALL / 2]},
        {size: [width + 2 * WALL, height, WALL], position: [0, height / 2, length / 2 + WALL / 2]},
        {size: [WALL, height, length], position: [-width / 2 - WALL / 2, height / 2, 0]},
        {size: [WALL, height, length], position: [width / 2 + WALL / 2, height / 2, 0]},
    ];
    walls.forEach((w) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(w.size[0], w.size[1], w.size[2]), wallmaterial);
        mesh.position.set(w.position[0], w.position[1], w.position[2]);
        mesh.name = 'vrroom-wall';
        addShell(mesh, group, shell);
    });

    if (shape.ceiling) {
        const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(width, length), wallmaterial);
        ceiling.rotation.x = Math.PI / 2;
        ceiling.position.y = height;
        ceiling.name = 'vrroom-ceiling';
        return addShell(ceiling, group, shell);
    }
    return null;
};

/**
 * Build the courtyard preset: ground, path ring, low round wall.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} shape Shape definition.
 * @param {Object} group Target group.
 * @param {Object[]} shell Shell list to extend.
 */
const buildCourtyard = (THREE, shape, group, shell) => {
    const radius = num(shape.radius, 14);
    const wallheight = num(shape.wallheight, 1.2);
    const pathinner = num(shape.pathinner, 7.5);
    const pathouter = num(shape.pathouter, 10.5);
    const groundsize = 2 * (radius + 6);

    const ground = new THREE.Mesh(
        new THREE.PlaneGeometry(groundsize, groundsize),
        new THREE.MeshStandardMaterial({color: colour(THREE, shape.groundcolor, '#7d9a5b'), roughness: 1})
    );
    ground.rotation.x = -Math.PI / 2;
    ground.name = 'vrroom-floor';
    addShell(ground, group, shell);

    const path = new THREE.Mesh(
        new THREE.RingGeometry(pathinner, pathouter, 96),
        new THREE.MeshStandardMaterial({color: colour(THREE, shape.pathcolor, '#c9b48a'), roughness: 1})
    );
    path.rotation.x = -Math.PI / 2;
    path.position.y = 0.01;
    path.name = 'vrroom-path';
    addShell(path, group, shell);

    const wall = new THREE.Mesh(
        new THREE.CylinderGeometry(radius, radius, wallheight, 96, 1, true),
        new THREE.MeshStandardMaterial({color: new THREE.Color('#b9b0a2'), roughness: 0.9, side: THREE.DoubleSide})
    );
    wall.position.y = wallheight / 2;
    wall.name = 'vrroom-wall';
    addShell(wall, group, shell);
};

/**
 * Add the lights of an environment.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object[]} lights Light definitions.
 * @param {Object} group Target group.
 */
const addLights = (THREE, lights, group) => {
    const list = Array.isArray(lights) && lights.length ? lights : [
        {type: 'ambient', intensity: 0.7},
        {type: 'directional', intensity: 0.9, position: [4, 10, 6]},
    ];
    list.forEach((def) => {
        const intensity = num(def.intensity, 1);
        let light = null;
        if (def.type === 'ambient') {
            light = new THREE.AmbientLight(0xffffff, intensity);
        } else if (def.type === 'directional') {
            light = new THREE.DirectionalLight(0xffffff, intensity);
            const p = Array.isArray(def.position) ? def.position : [4, 10, 6];
            light.position.set(num(p[0], 4), num(p[1], 10), num(p[2], 6));
        } else if (def.type === 'hemisphere') {
            light = new THREE.HemisphereLight(
                colour(THREE, def.skycolor, '#ffffff'),
                colour(THREE, def.groundcolor, '#444444'),
                intensity
            );
        }
        if (light) {
            group.add(light);
        }
    });
};

/**
 * Normalise bounds to {min: [x, y, z], max: [x, y, z]}.
 *
 * @param {Object|null} bounds Bounds from the scene.
 * @returns {Object|null}
 */
const readBounds = (bounds) => {
    if (!bounds || !Array.isArray(bounds.min) || !Array.isArray(bounds.max)) {
        return null;
    }
    const min = bounds.min.map((v) => num(v, 0));
    const max = bounds.max.map((v) => num(v, 0));
    if (min.length !== 3 || max.length !== 3 || min[0] > max[0] || min[2] > max[2]) {
        return null;
    }
    return {min: min, max: max};
};

/**
 * Whether this device looks like a phone: a coarse pointer and a small screen.
 *
 * @returns {boolean}
 */
export const isPhone = () => {
    try {
        if (typeof window === 'undefined' || !window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
            return false;
        }
        const scr = window.screen || {};
        const side = Math.min(Number(scr.width) || Infinity, Number(scr.height) || Infinity);
        return side <= PHONE_SCREEN;
    } catch (e) {
        return false;
    }
};

/**
 * The URL of the photo to show for a panorama environment: the phone copy when the GPU's largest
 * texture is 4096 px or less or the device is a phone, else the desktop copy, else the original.
 *
 * Options: maxTextureSize (renderer.capabilities.maxTextureSize; unknown when missing), phone
 * (bool; default isPhone()), resolveUrl(fileref) → URL|null for the original (default its url).
 *
 * @param {Object} env The panorama environment ({file, variants?: {desktop?, mobile?}}).
 * @param {Object} [options] Options.
 * @returns {string|null}
 */
export const choosePanoramaUrl = (env, options = {}) => {
    const e = env || {};
    const variants = e.variants && typeof e.variants === 'object' ? e.variants : {};
    const valid = (url) => (typeof url === 'string' && url !== '' ? url : null);
    const maxtexture = Number(options.maxTextureSize);
    const phone = typeof options.phone === 'boolean' ? options.phone : isPhone();
    const small = phone || (Number.isFinite(maxtexture) && maxtexture > 0 && maxtexture <= MOBILE_TEXTURE);
    if (small && valid(variants.mobile)) {
        return variants.mobile;
    }
    if (valid(variants.desktop)) {
        return variants.desktop;
    }
    const file = e.file && typeof e.file === 'object' ? e.file : null;
    if (!file) {
        return null;
    }
    if (typeof options.resolveUrl === 'function') {
        return valid(options.resolveUrl(file));
    }
    return valid(file.url);
};

/**
 * The yaw (degrees) the centre of the photo faces: its north offset.
 *
 * @param {Object} env The panorama environment.
 * @returns {number}
 */
const northOffset = (env) => {
    const n = Number(env && env.northoffset);
    return Number.isFinite(n) ? Math.max(-180, Math.min(180, n)) : 0;
};

/**
 * How many degrees from top to bottom a photo covers, taking its width as the full 360°.
 *
 * A 2:1 photo covers 180° (the whole sphere). A wider one, such as a phone's panorama, covers less:
 * it is shown as a band around the horizon. A narrower one is stretched over the whole sphere.
 *
 * @param {number} width Pixels.
 * @param {number} height Pixels.
 * @returns {number} Degrees, more than 0 and at most 180.
 */
export const panoramaSpan = (width, height) => {
    const w = Number(width);
    const h = Number(height);
    if (!(w > 0) || !(h > 0)) {
        return 180;
    }
    return Math.min(180, 360 * h / w);
};

/**
 * The sphere geometry of a photo covering span degrees from top to bottom, centred on the horizon.
 *
 * @param {Object} THREE three.js namespace.
 * @param {number} span Degrees, from panoramaSpan().
 * @returns {Object} THREE.SphereGeometry
 */
const panoramaGeometry = (THREE, span) => {
    if (span >= 180) {
        return new THREE.SphereGeometry(PANORAMA_RADIUS, 64, 32);
    }
    const length = span * Math.PI / 180;
    const rows = Math.max(2, Math.round(32 * span / 180));
    return new THREE.SphereGeometry(PANORAMA_RADIUS, 64, rows, 0, Math.PI * 2, (Math.PI - length) / 2, length);
};

/**
 * The sphere a panorama is drawn on, without its photo yet (plain sky colour).
 *
 * The geometry is mirrored on x (mesh scale −1) and seen from inside (BackSide), so the photo is
 * not reversed; it is turned so the centre of the photo is straight ahead (−z) at north offset 0
 * and at yaw = north offset otherwise. It is centred at eye height; the viewer moves it with the
 * head in VR so the photo shows no parallax.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} env The panorama environment.
 * @param {Object} background Its sky colour (THREE.Color).
 * @returns {Object} THREE.Mesh named vrroom-panorama
 */
export const makePanoramaSphere = (THREE, env, background) => {
    const material = new THREE.MeshBasicMaterial({color: background.clone(), side: THREE.BackSide, depthWrite: false});
    material.toneMapped = false;
    const sphere = new THREE.Mesh(new THREE.SphereGeometry(PANORAMA_RADIUS, 64, 32), material);
    sphere.name = 'vrroom-panorama';
    sphere.scale.set(-1, 1, 1);
    // SphereGeometry puts u = 0.5 at +x; mirrored it is at −x, and a quarter turn back brings it to −z.
    sphere.rotation.set(0, (northOffset(env) - 90) * Math.PI / 180, 0);
    sphere.position.set(0, PANORAMA_CENTRE, 0);
    sphere.renderOrder = -1;
    sphere.userData.vrroomPanorama = true;
    return sphere;
};

/**
 * A panorama environment without its photo: lights, the plain sphere and zero bounds. Used while
 * the photo is missing or failed to load, so a broken photo never turns into a hall.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} environment The panorama environment.
 * @returns {Object} {group, bounds, background, ceiling, shell, panorama}
 */
export const buildPanoramaPlaceholder = (THREE, environment) => {
    const env = environment || {};
    const group = new THREE.Group();
    group.name = 'vrroom-environment';
    addLights(THREE, env.lights, group);
    const background = colour(THREE, env.sky, '#20242a');
    const sphere = makePanoramaSphere(THREE, env, background);
    group.add(sphere);
    return {group: group, bounds: {min: ZERO_BOUNDS.min.slice(), max: ZERO_BOUNDS.max.slice()}, background: background,
        ceiling: null, shell: [], panorama: sphere};
};

/**
 * Build a panorama environment: the sphere with the photo on it.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} env The panorama environment.
 * @param {Function|null} resolveUrl fileref → URL or null.
 * @param {Object} options {textureloader, maxTextureSize, phone}
 * @returns {Promise<Object>} {group, bounds, background, ceiling, shell, panorama}; rejected when
 *     the photo has no URL or fails to load (the caller keeps a plain sky then)
 */
const buildPanorama = (THREE, env, resolveUrl, options) => {
    const url = choosePanoramaUrl(env, {
        maxTextureSize: options.maxTextureSize,
        phone: options.phone,
        resolveUrl: resolveUrl,
    });
    if (!url) {
        return Promise.reject(new Error('The 360° photo of this room has no file.'));
    }
    const textureloader = options.textureloader || new THREE.TextureLoader();
    return textureloader.loadAsync(url).then((texture) => {
        texture.colorSpace = THREE.SRGBColorSpace;
        // No mipmaps: a sphere seen from its centre is hardly minified, and they would add a third.
        texture.generateMipmaps = false;
        texture.minFilter = THREE.LinearFilter;
        texture.magFilter = THREE.LinearFilter;
        texture.needsUpdate = true;
        const result = buildPanoramaPlaceholder(THREE, env);
        const image = texture.image || {};
        const span = panoramaSpan(image.naturalWidth || image.width, image.naturalHeight || image.height);
        if (span < 180) {
            // Wider than 2:1: a band around the horizon; above and below it the sky colour shows.
            result.panorama.geometry.dispose();
            result.panorama.geometry = panoramaGeometry(THREE, span);
        }
        const material = result.panorama.material;
        material.map = texture;
        material.color.set(0xffffff);
        material.needsUpdate = true;
        return result;
    });
};

/**
 * Build the environment of a vrroom-scene/1 document.
 *
 * The result is {group, bounds, background, ceiling, shell}: the group holds the lights and the
 * shell; ceiling is the ceiling mesh or null; shell lists the floor, wall and ceiling meshes (or
 * the uploaded model) that augmented and mixed reality hide. A panorama also has panorama: the
 * photo sphere (never a pick target or an obstacle), zero bounds and an empty shell.
 *
 * Options: textureloader (THREE.TextureLoader for the photo), maxTextureSize (the renderer's),
 * phone (bool, default: detected).
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} environment The scene's environment.
 * @param {Object} loader A GLTFLoader (used for model environments).
 * @param {Function|null} resolveUrl fileref → URL or null; default: the file reference's url.
 * @param {Object} [options] Options.
 * @returns {Promise<Object>} {group, bounds, background, ceiling, shell, panorama?}
 */
export const buildEnvironment = (THREE, environment, loader, resolveUrl = null, options = {}) => {
    const env = environment || {};
    if (env.type === 'panorama') {
        return buildPanorama(THREE, env, resolveUrl, options || {});
    }
    const group = new THREE.Group();
    group.name = 'vrroom-environment';
    addLights(THREE, env.lights, group);
    const background = colour(THREE, env.sky, '#20242a');
    const shell = [];
    let url = null;
    if (env.type === 'model' && env.file) {
        url = resolveUrl ? resolveUrl(env.file) : (env.file.url || null);
    } else if (env.type === 'pack') {
        // A room from a room pack: the launch config (or the editor's environment list) gives its URL.
        // Without one the pack is not installed, and the caller falls back to a plain hall sized from
        // env.bounds.
        const ref = {kind: 'pack', pack: env.pack, room: env.room, url: env.url || null};
        url = resolveUrl ? resolveUrl(ref) : ref.url;
        if (!url) {
            return Promise.reject(new Error(`Room ${env.pack}/${env.room} is not installed on this site.`));
        }
    }

    if (url) {
        return loader.loadAsync(url).then((gltf) => {
            const model = gltf.scene || (gltf.scenes && gltf.scenes[0]);
            if (model) {
                model.name = 'vrroom-environment-model';
                addShell(model, group, shell);
            }
            let bounds = readBounds(env.bounds);
            if (!bounds && model) {
                const box = new THREE.Box3().setFromObject(model);
                bounds = {min: [box.min.x, 0, box.min.z], max: [box.max.x, 0, box.max.z]};
            }
            if (!bounds) {
                bounds = {min: [-6, 0, -6], max: [6, 0, 6]};
            }
            return {group: group, bounds: bounds, background: background, ceiling: null, shell: shell};
        });
    }

    const shape = env.shape || {kind: 'hall'};
    let ceiling = null;
    if (shape.kind === 'courtyard') {
        buildCourtyard(THREE, shape, group, shell);
    } else {
        ceiling = buildHall(THREE, shape, group, shell);
    }
    let bounds = readBounds(env.bounds);
    if (!bounds) {
        if (shape.kind === 'courtyard') {
            const r = num(shape.radius, 14) - 1;
            bounds = {min: [-r, 0, -r], max: [r, 0, r]};
        } else {
            const hw = num(shape.width, 12) / 2;
            const hl = num(shape.length, 30) / 2;
            bounds = {min: [-hw, 0, -hl], max: [hw, 0, hl]};
        }
    }
    return Promise.resolve({group: group, bounds: bounds, background: background, ceiling: ceiling, shell: shell});
};
