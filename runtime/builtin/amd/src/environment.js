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
 * Environment builder: the procedural presets (hall, courtyard) and uploaded GLB environments.
 *
 * Every floor, wall and ceiling mesh (and an uploaded environment model) is tagged with
 * userData.vrroomShell, so augmented and mixed reality can hide the room's shell while keeping its
 * lights and contents.
 *
 * @module     vrroomruntime_builtin/environment
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Wall thickness of the hall preset in metres. */
const WALL = 0.2;

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
 * Build the environment of a vrroom-scene/1 document.
 *
 * The result is {group, bounds, background, ceiling, shell}: the group holds the lights and the
 * shell; ceiling is the ceiling mesh or null; shell lists the floor, wall and ceiling meshes (or
 * the uploaded model) that augmented and mixed reality hide.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} environment The scene's environment.
 * @param {Object} loader A GLTFLoader (used for model environments).
 * @param {Function|null} resolveUrl fileref → URL or null; default: the file reference's url.
 * @returns {Promise<Object>} {group, bounds, background, ceiling, shell}
 */
export const buildEnvironment = (THREE, environment, loader, resolveUrl = null) => {
    const env = environment || {};
    const group = new THREE.Group();
    group.name = 'vrroom-environment';
    addLights(THREE, env.lights, group);
    const background = colour(THREE, env.sky, '#20242a');
    const shell = [];
    let url = null;
    if (env.type === 'model' && env.file) {
        url = resolveUrl ? resolveUrl(env.file) : (env.file.url || null);
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
