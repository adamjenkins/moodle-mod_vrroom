// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Adapted from GLTFHubsLightMapExtension in Hubs, src/components/gltf-model-plus.js
// (https://github.com/Hubs-Foundation/hubs, MPL-2.0; the Hubs source file carries no
// copyright line). Changes for VR room: the lightmap's UV set comes from the extension's
// texCoord (three.js r152+ selects it with Texture.channel; Hubs' three.js fork always used
// the second UV set), and the plugin is exported with a register() helper.

/**
 * GLTFLoader plugin for the MOZ_lightmap material extension (baked lighting in Hubs scenes).
 *
 * Without it three.js ignores the extension and a lightmapped, unlit Hubs room renders at full
 * brightness with no shading at all.
 *
 * @module     vrroomruntime_builtin/mozlightmap
 * @copyright  Hubs Foundation and Mozilla contributors (Hubs, src/components/gltf-model-plus.js)
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://mozilla.org/MPL/2.0/ MPL-2.0
 */

/** Extension name. */
export const NAME = 'MOZ_lightmap';

/**
 * The plugin: one instance per GLTFParser.
 */
export class MozLightmapPlugin {
    /**
     * Constructor.
     *
     * @param {Object} parser The GLTFParser.
     */
    constructor(parser) {
        this.parser = parser;
        this.name = NAME;
    }

    /**
     * Load a material with its lightmap.
     *
     * Uses the loadMaterial hook rather than extendMaterialParams because GLTFLoader does not call
     * extendMaterialParams for KHR_materials_unlit materials, and Hubs' lightmapped materials are
     * mostly unlit. The parser's own loadMaterial() builds the material; the lightmap is added on top.
     *
     * @param {number} materialIndex Material index.
     * @returns {Promise<Object>|null} The material, or null when this material has no lightmap.
     */
    loadMaterial(materialIndex) {
        const parser = this.parser;
        const materialDef = parser.json.materials[materialIndex];
        const extensionDef = materialDef.extensions && materialDef.extensions[NAME];
        if (!extensionDef || extensionDef.index === undefined) {
            return null;
        }
        return Promise.all([
            parser.loadMaterial(materialIndex),
            parser.getDependency('texture', extensionDef.index),
        ]).then(([material, texture]) => {
            // Linear data, as in Hubs: no colour space is set on the texture.
            const lightMap = texture.clone();
            lightMap.channel = extensionDef.texCoord !== undefined ? extensionDef.texCoord : 1;
            material.lightMap = lightMap;
            material.lightMapIntensity = extensionDef.intensity !== undefined ? extensionDef.intensity : 1;
            // MeshBasicMaterial divides the lightmap by PI since three.js r139 (PR 23613).
            if (material.isMeshBasicMaterial) {
                material.lightMapIntensity *= Math.PI;
            }
            material.needsUpdate = true;
            return material;
        });
    }
}

/**
 * Register the plugin on a GLTFLoader.
 *
 * @param {Object} loader A GLTFLoader.
 * @returns {Object} The loader.
 */
export const register = (loader) => loader.register((parser) => new MozLightmapPlugin(parser));
