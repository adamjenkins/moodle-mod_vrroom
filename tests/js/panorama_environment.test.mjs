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
 * Tests of 360° photo rooms in the built-in viewer: the panorama environment
 * (vrroomruntime_builtin/environment, with the bundled three.js and a stand-in texture loader),
 * the round portal marker (vrroomruntime_builtin/markers) and the layout key with a north offset
 * (vrroomruntime_builtin/spawn).
 *
 * Run from the repository root: node --test tests/js/panorama_environment.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    buildEnvironment, buildPanoramaPlaceholder, choosePanoramaUrl, PANORAMA_RADIUS, panoramaSpan,
} from '../../runtime/builtin/amd/src/environment.js';
import {buildPortalMarker, PORTAL_MARKER_RADIUS} from '../../runtime/builtin/amd/src/markers.js';
import {layoutKey} from '../../runtime/builtin/amd/src/spawn.js';
import {placementPosition} from '../../runtime/builtin/amd/src/placement.js';

const THREE = await import('../../runtime/builtin/amd/src/three.js');

const FILE = {area: 'environment', itemid: 1, filepath: '/', filename: 'hall.jpg', url: 'https://example.com/hall.jpg'};
const VARIANTS = {desktop: 'https://example.com/variants/desktop.jpg', mobile: 'https://example.com/variants/mobile.jpg'};

/**
 * A stand-in texture loader that records its URLs (and can fail).
 *
 * @param {boolean} [fail] Reject every load.
 * @returns {Object} {loads: string[], loadAsync(url)}
 */
const standInLoader = (fail = false) => {
    const loader = {loads: []};
    loader.loadAsync = (url) => {
        loader.loads.push(url);
        return fail ? Promise.reject(new Error('404')) : Promise.resolve(new THREE.Texture());
    };
    return loader;
};

/**
 * The world direction the centre of the photo (u = 0.5, v = 0.5) is seen in from the sphere's centre.
 *
 * @param {Object} sphere The panorama mesh.
 * @param {number} u Texture u (0..1).
 * @returns {Object} THREE.Vector3, unit length
 */
const directionOfU = (sphere, u) => {
    const geometry = sphere.geometry;
    const uv = geometry.attributes.uv;
    const pos = geometry.attributes.position;
    let best = -1;
    let bestscore = Infinity;
    for (let i = 0; i < uv.count; i++) {
        const score = Math.abs(uv.getX(i) - u) + Math.abs(uv.getY(i) - 0.5);
        if (score < bestscore) {
            bestscore = score;
            best = i;
        }
    }
    sphere.updateMatrixWorld(true);
    const point = new THREE.Vector3(pos.getX(best), pos.getY(best), pos.getZ(best)).applyMatrix4(sphere.matrixWorld);
    const centre = new THREE.Vector3().setFromMatrixPosition(sphere.matrixWorld);
    return point.sub(centre).normalize();
};

test('the photo URL: the phone copy on small GPUs or phones, else the desktop copy, else the original', () => {
    const env = {type: 'panorama', file: FILE, variants: VARIANTS};
    assert.equal(choosePanoramaUrl(env, {maxTextureSize: 4096, phone: false}), VARIANTS.mobile);
    assert.equal(choosePanoramaUrl(env, {maxTextureSize: 16384, phone: true}), VARIANTS.mobile);
    assert.equal(choosePanoramaUrl(env, {maxTextureSize: 16384, phone: false}), VARIANTS.desktop);
    assert.equal(choosePanoramaUrl(env, {phone: false}), VARIANTS.desktop, 'unknown GPU, not a phone');
    assert.equal(choosePanoramaUrl({type: 'panorama', file: FILE, variants: {desktop: VARIANTS.desktop}},
        {maxTextureSize: 4096, phone: true}), VARIANTS.desktop, 'no phone copy: the desktop copy');
    assert.equal(choosePanoramaUrl({type: 'panorama', file: FILE}, {maxTextureSize: 4096, phone: true}), FILE.url,
        'no copies (a small original): the original');
    assert.equal(choosePanoramaUrl({type: 'panorama', file: FILE}, {phone: false, resolveUrl: () => 'https://x/y.jpg'}),
        'https://x/y.jpg', 'the editor resolves the original itself');
    assert.equal(choosePanoramaUrl({type: 'panorama'}, {phone: false}), null);
});

test('a panorama is a mirrored back-side sphere of radius 50 at eye height, with zero bounds', async() => {
    const loader = standInLoader();
    const env = await buildEnvironment(THREE, {type: 'panorama', file: FILE, variants: VARIANTS, sky: '#112233'}, null, null,
        {textureloader: loader, maxTextureSize: 16384, phone: false});
    assert.deepEqual(loader.loads, [VARIANTS.desktop]);
    assert.deepEqual(env.bounds, {min: [0, 0, 0], max: [0, 0, 0]});
    assert.equal(env.ceiling, null);
    assert.deepEqual(env.shell, []);
    assert.ok(env.background.isColor);
    assert.equal(env.background.getHexString(), '112233');
    const sphere = env.panorama;
    assert.ok(sphere && sphere.isMesh);
    assert.equal(sphere.name, 'vrroom-panorama');
    assert.equal(sphere.parent, env.group);
    assert.equal(sphere.geometry.type, 'SphereGeometry');
    assert.equal(sphere.geometry.parameters.radius, PANORAMA_RADIUS);
    assert.equal(sphere.geometry.parameters.widthSegments, 64);
    assert.equal(sphere.geometry.parameters.heightSegments, 32);
    assert.equal(sphere.material.type, 'MeshBasicMaterial');
    assert.equal(sphere.material.side, THREE.BackSide);
    assert.ok(sphere.material.map && sphere.material.map.isTexture);
    assert.equal(sphere.material.map.colorSpace, THREE.SRGBColorSpace);
    assert.equal(sphere.scale.x, -1, 'mirrored so the photo is not reversed');
    assert.equal(sphere.position.y, 1.6);
    // Lights are there for the markers.
    assert.ok(env.group.children.some((c) => c.isLight));
    // Never the scene background (an equirectangular background becomes a huge cube texture).
    assert.ok(!env.background.isTexture);
});

test('the centre of the photo is straight ahead; its left quarter is to the left; north offset turns it', async() => {
    const build = (northoffset) => buildEnvironment(THREE, {type: 'panorama', file: FILE, northoffset}, null, null,
        {textureloader: standInLoader(), phone: false});
    const plain = (await build(0)).panorama;
    const ahead = directionOfU(plain, 0.5);
    assert.ok(ahead.z < -0.99, `centre of the photo at ${ahead.toArray()}`);
    const left = directionOfU(plain, 0.25);
    assert.ok(left.x < -0.99, `u = 0.25 (yaw +90) at ${left.toArray()}`);
    const right = directionOfU(plain, 0.75);
    assert.ok(right.x > 0.99, `u = 0.75 (yaw −90) at ${right.toArray()}`);

    // North offset 90: the centre of the photo is at yaw 90, where placement puts a point at yaw 90.
    const turned = (await build(90)).panorama;
    const centre = directionOfU(turned, 0.5);
    const expected = placementPosition(90, 0, 1);
    assert.ok(Math.abs(centre.x - expected[0]) < 0.01 && Math.abs(centre.z - expected[2]) < 0.01,
        `centre at ${centre.toArray()}`);
});

test('a photo of any shape is shown: 2:1 or narrower fills the sphere, a wider one is a band round the horizon', async() => {
    assert.equal(panoramaSpan(4096, 2048), 180);
    assert.equal(panoramaSpan(1024, 1024), 180);
    assert.equal(panoramaSpan(8000, 1000), 45);
    assert.equal(panoramaSpan(0, 0), 180);

    const shaped = async(width, height) => {
        const loader = {loadAsync: () => {
            const texture = new THREE.Texture();
            texture.image = {width, height};
            return Promise.resolve(texture);
        }};
        return (await buildEnvironment(THREE, {type: 'panorama', file: FILE}, null, null,
            {textureloader: loader, phone: false})).panorama;
    };
    const heights = (sphere) => {
        const pos = sphere.geometry.attributes.position;
        let top = -Infinity;
        let bottom = Infinity;
        for (let i = 0; i < pos.count; i++) {
            top = Math.max(top, pos.getY(i));
            bottom = Math.min(bottom, pos.getY(i));
        }
        return [top, bottom];
    };
    const full = heights(await shaped(2048, 1024));
    assert.ok(Math.abs(full[0] - PANORAMA_RADIUS) < 1e-6 && Math.abs(full[1] + PANORAMA_RADIUS) < 1e-6);
    const square = heights(await shaped(1024, 1024));
    assert.ok(Math.abs(square[0] - PANORAMA_RADIUS) < 1e-6);
    // 8000 x 1000 covers 45°: from 22.5° above the horizon to 22.5° below it.
    const band = await shaped(8000, 1000);
    const [top, bottom] = heights(band);
    const edge = PANORAMA_RADIUS * Math.sin(22.5 * Math.PI / 180);
    assert.ok(Math.abs(top - edge) < 1e-6, `top ${top}`);
    assert.ok(Math.abs(bottom + edge) < 1e-6, `bottom ${bottom}`);
    assert.ok(band.material.map);
});

test('a photo that fails to load is rejected, and the placeholder keeps a plain sky (no hall)', async() => {
    await assert.rejects(buildEnvironment(THREE, {type: 'panorama', file: FILE}, null, null,
        {textureloader: standInLoader(true), phone: false}));
    await assert.rejects(buildEnvironment(THREE, {type: 'panorama'}, null, null,
        {textureloader: standInLoader(), phone: false}), 'no file at all');
    const placeholder = buildPanoramaPlaceholder(THREE, {type: 'panorama', file: FILE, sky: '#445566'});
    assert.ok(placeholder.panorama);
    assert.equal(placeholder.panorama.material.map, null);
    assert.equal(placeholder.panorama.material.color.getHexString(), '445566');
    assert.deepEqual(placeholder.bounds, {min: [0, 0, 0], max: [0, 0, 0]});
    assert.ok(!placeholder.group.children.some((c) => c.name === 'vrroom-floor' || c.name === 'vrroom-wall'));
});

test('other environments are unchanged: a hall has no panorama', async() => {
    const env = await buildEnvironment(THREE, {type: 'preset', shape: {kind: 'hall', width: 10, length: 20}}, null, null);
    assert.equal(env.panorama, undefined);
    assert.deepEqual(env.bounds, {min: [-5, 0, -10], max: [5, 0, 10]});
});

test('the portal marker is a disc with a ring, an arrow and a larger hit target, facing +z', () => {
    const marker = buildPortalMarker(THREE);
    assert.equal(marker.group.name, 'vrroom-portal-marker');
    assert.equal(marker.disc.geometry.type, 'CircleGeometry');
    assert.equal(marker.disc.geometry.parameters.radius, PORTAL_MARKER_RADIUS);
    assert.ok(marker.disc.material.emissive, 'the disc pulses like the door portal (emissive)');
    assert.equal(marker.ring.geometry.type, 'RingGeometry');
    assert.equal(marker.arrow.geometry.type, 'ShapeGeometry');
    assert.ok(marker.hit.geometry.parameters.radius > PORTAL_MARKER_RADIUS);
    assert.equal(marker.hit.material.visible, false);
    assert.ok(marker.labelY > PORTAL_MARKER_RADIUS);
    [marker.disc, marker.ring, marker.arrow, marker.hit].forEach((mesh) => assert.equal(mesh.parent, marker.group));
    // Flat in the xy plane: it faces +z, which a turn by the placement's yaw points at the centre.
    marker.disc.geometry.computeBoundingBox();
    assert.ok(Math.abs(marker.disc.geometry.boundingBox.max.z) < 1e-9);
    // No door frame.
    assert.ok(!marker.group.children.some((c) => c.geometry && c.geometry.type === 'BoxGeometry'));
    assert.equal(buildPortalMarker(THREE, {radius: 0.5}).disc.geometry.parameters.radius, 0.5);
});

test('the layout key changes with the north offset and stays the same without one', () => {
    const scene = {id: 's1', environment: {type: 'panorama', file: FILE}, spawn: [{id: 'sp', position: [0, 0, 0], yaw: 0}]};
    const without = layoutKey(scene);
    const zero = layoutKey(Object.assign({}, scene, {environment: Object.assign({}, scene.environment, {northoffset: 0})}));
    const ninety = layoutKey(Object.assign({}, scene, {environment: Object.assign({}, scene.environment, {northoffset: 90})}));
    assert.notEqual(zero, ninety);
    assert.notEqual(without, ninety);
    // A room without a north offset keeps the key it had before panoramas existed.
    const hall = {id: 'h', environment: {type: 'preset', preset: 'gallery-hall'}, spawn: [{id: 'a', position: [1, 0, 2], yaw: 90}]};
    assert.equal(layoutKey(hall), layoutKey(JSON.parse(JSON.stringify(hall))));
    assert.match(layoutKey(hall), /^v1-[0-9a-z]+$/);
});
