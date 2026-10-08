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
 * WebXR support of the built-in viewer: device detection, immersive VR and AR sessions (AR with
 * a hit-test source, told apart from mixed reality on a headset), and the wrist panel shown in
 * VR and mixed reality.
 *
 * The isSessionSupported() detection and the AR session with hit-test follow mod_gear's viewer.
 *
 * @module     mavrruntime_builtin/xr
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';

/** Cached detection result (a promise), reset when the XR device changes. */
let support = null;

/** Whether the devicechange listener is installed. */
let listening = false;

/**
 * Ask the browser whether a session mode is supported, never rejecting.
 *
 * @param {Object} xr navigator.xr
 * @param {string} mode Session mode.
 * @returns {Promise<boolean>}
 */
const supported = (xr, mode) => Promise.resolve()
    .then(() => xr.isSessionSupported(mode))
    .then((result) => !!result)
    .catch(() => false);

/**
 * What the device can do.
 *
 * navigator.xr exists only in secure contexts, so a plain-HTTP site reports secure false and
 * nothing available. headset is the heuristic "supports VR as well as AR".
 *
 * @returns {Promise<Object>} {secure, available, vr, ar, headset}
 */
export const detect = () => {
    if (support) {
        return support;
    }
    const secure = !!window.isSecureContext;
    const xr = secure && navigator.xr && typeof navigator.xr.isSessionSupported === 'function' ? navigator.xr : null;
    if (!xr) {
        support = Promise.resolve({secure: secure, available: false, vr: false, ar: false, headset: false});
        return support;
    }
    if (!listening && typeof xr.addEventListener === 'function') {
        listening = true;
        xr.addEventListener('devicechange', () => {
            support = null;
        });
    }
    support = Promise.all([supported(xr, 'immersive-vr'), supported(xr, 'immersive-ar')]).then(([vr, ar]) => ({
        secure: secure,
        available: vr || ar,
        vr: vr,
        ar: ar,
        headset: vr && ar,
    }));
    return support;
};

/**
 * End an XR session, ignoring a failure (it may already be ending).
 *
 * @param {XRSession} xrsession The session.
 */
const endQuietly = (xrsession) => {
    Promise.resolve().then(() => xrsession.end()).catch(() => null);
};

/**
 * Create the controller of immersive sessions for a renderer.
 *
 * Options: stage (HTMLElement used as the AR DOM overlay root), onStart(mode), onEnd(mode).
 * enter('vr') starts immersive-vr; enter('ar') starts immersive-ar and resolves to 'ar' on a
 * handheld device or 'mr' on a headset (XRSession.interactionMode, else the headset heuristic).
 *
 * @param {Object} renderer THREE.WebGLRenderer with xr.enabled.
 * @param {Object} options Options.
 * @returns {Object} XrController {enter(mode), exit(), mode, domOverlay, session, getHitPose(frame)}
 */
export const createXr = (renderer, options = {}) => {
    let session = null;
    let mode = null;
    let domoverlay = false;
    let hitsource = null;
    let starting = null;

    const cancelHitSource = () => {
        if (hitsource) {
            try {
                hitsource.cancel();
            } catch (e) {
                // The source ends with its session.
            }
        }
        hitsource = null;
    };

    const onSessionEnd = () => {
        const ended = mode;
        cancelHitSource();
        if (session) {
            session.removeEventListener('end', onSessionEnd);
        }
        session = null;
        mode = null;
        domoverlay = false;
        if (ended && options.onEnd) {
            options.onEnd(ended);
        }
    };

    const requestHitSource = (xrsession) => xrsession.requestReferenceSpace('viewer')
        .then((space) => xrsession.requestHitTestSource({space: space}))
        .then((source) => {
            if (session === xrsession) {
                hitsource = source;
            } else {
                source.cancel();
            }
            return undefined;
        })
        .catch((error) => Log.debug(error));

    const controller = {
        /** @returns {string|null} 'vr', 'ar', 'mr' or null when no session runs. */
        get mode() {
            return mode;
        },

        /** @returns {boolean} Whether the AR session shows the stage as a DOM overlay. */
        get domOverlay() {
            return domoverlay;
        },

        /** @returns {Object|null} The running XRSession. */
        get session() {
            return session;
        },

        /**
         * Start an immersive session.
         *
         * @param {string} requested 'vr' or 'ar'.
         * @returns {Promise<string>} The mode that runs ('vr', 'ar' or 'mr').
         */
        enter(requested) {
            if (session) {
                return Promise.resolve(mode);
            }
            if (starting) {
                return starting;
            }
            const vr = requested === 'vr';
            const sessiontype = vr ? 'immersive-vr' : 'immersive-ar';
            let xrsession = null;
            let info = null;
            starting = detect().then((result) => {
                info = result;
                if (!(vr ? info.vr : info.ar)) {
                    throw new Error(`WebXR ${sessiontype} is not supported`);
                }
                const init = vr ? {
                    requiredFeatures: ['local-floor'],
                    optionalFeatures: ['bounded-floor', 'hand-tracking'],
                } : {
                    requiredFeatures: ['hit-test'],
                    optionalFeatures: ['dom-overlay', 'local-floor', 'hand-tracking'],
                    domOverlay: {root: options.stage},
                };
                if (!vr && !options.stage) {
                    init.optionalFeatures = ['local-floor', 'hand-tracking'];
                    delete init.domOverlay;
                }
                return navigator.xr.requestSession(sessiontype, init);
            }).then((created) => {
                xrsession = created;
                renderer.xr.setReferenceSpaceType(vr ? 'local-floor' : 'local');
                return renderer.xr.setSession(xrsession);
            }).then(() => {
                session = xrsession;
                if (vr) {
                    mode = 'vr';
                } else {
                    const interaction = xrsession.interactionMode;
                    if (interaction === 'world-space') {
                        mode = 'mr';
                    } else if (interaction === 'screen-space') {
                        mode = 'ar';
                    } else {
                        mode = info.headset ? 'mr' : 'ar';
                    }
                    domoverlay = !!xrsession.domOverlayState;
                    requestHitSource(xrsession);
                }
                xrsession.addEventListener('end', onSessionEnd);
                if (options.onStart) {
                    options.onStart(mode);
                }
                return mode;
            }).catch((error) => {
                if (xrsession && session !== xrsession) {
                    endQuietly(xrsession);
                }
                throw error;
            }).then((result) => {
                starting = null;
                return result;
            }, (error) => {
                starting = null;
                throw error;
            });
            return starting;
        },

        /**
         * End the running session (onEnd follows when the browser has ended it).
         *
         * @returns {Promise}
         */
        exit() {
            if (!session) {
                return Promise.resolve();
            }
            const running = session;
            return Promise.resolve().then(() => running.end()).catch((error) => Log.debug(error));
        },

        /**
         * The surface the middle of the view points at (AR and mixed reality).
         *
         * @param {Object} frame XRFrame of the current animation frame.
         * @returns {Object|null} XRPose in the renderer's reference space.
         */
        getHitPose(frame) {
            if (!frame || !hitsource) {
                return null;
            }
            const results = frame.getHitTestResults(hitsource);
            if (!results || !results.length) {
                return null;
            }
            const space = renderer.xr.getReferenceSpace();
            return space ? (results[0].getPose(space) || null) : null;
        },
    };
    return controller;
};

/**
 * The wrist panel: a small canvas board with progress and time left, and an Exit button.
 *
 * It is attached to the left controller grip by the viewer; the button plane carries
 * userData.mavrWristAction = 'exit'.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} options {exitlabel}
 * @returns {Object} {object, getTargets(), isExit(object3d), setLines(lines), dispose()}
 */
export const createWristPanel = (THREE, options = {}) => {
    const group = new THREE.Group();
    group.name = 'mavr-wrist';
    // Above the back of the left hand, tilted towards the eyes.
    group.position.set(0, 0.06, 0.06);
    group.rotation.set(-Math.PI / 3, 0, 0);

    const boardcanvas = document.createElement('canvas');
    boardcanvas.width = 512;
    boardcanvas.height = 192;
    const boardtexture = new THREE.CanvasTexture(boardcanvas);
    boardtexture.colorSpace = THREE.SRGBColorSpace;
    const board = new THREE.Mesh(
        new THREE.PlaneGeometry(0.16, 0.06),
        new THREE.MeshBasicMaterial({map: boardtexture, transparent: true, depthTest: false})
    );
    board.renderOrder = 10;
    group.add(board);

    const buttoncanvas = document.createElement('canvas');
    buttoncanvas.width = 256;
    buttoncanvas.height = 96;
    const bctx = buttoncanvas.getContext('2d');
    bctx.fillStyle = '#ca3120';
    bctx.fillRect(0, 0, 256, 96);
    bctx.fillStyle = '#ffffff';
    bctx.font = 'bold 44px sans-serif';
    bctx.textAlign = 'center';
    bctx.textBaseline = 'middle';
    bctx.fillText(String(options.exitlabel || ''), 128, 50, 236);
    const buttontexture = new THREE.CanvasTexture(buttoncanvas);
    buttontexture.colorSpace = THREE.SRGBColorSpace;
    const button = new THREE.Mesh(
        new THREE.PlaneGeometry(0.07, 0.026),
        new THREE.MeshBasicMaterial({map: buttontexture, depthTest: false})
    );
    button.position.set(0, -0.048, 0.001);
    button.renderOrder = 11;
    button.userData.mavrWristAction = 'exit';
    group.add(button);

    let current = '';
    const draw = (lines) => {
        const ctx = boardcanvas.getContext('2d');
        ctx.clearRect(0, 0, boardcanvas.width, boardcanvas.height);
        ctx.fillStyle = 'rgba(20, 24, 30, 0.85)';
        ctx.fillRect(0, 0, boardcanvas.width, boardcanvas.height);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 38px sans-serif';
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        const shown = lines.slice(0, 3);
        const step = boardcanvas.height / (shown.length + 1);
        shown.forEach((line, i) => ctx.fillText(line, boardcanvas.width / 2, step * (i + 1), boardcanvas.width - 32));
        boardtexture.needsUpdate = true;
    };
    draw([]);

    return {
        object: group,

        /**
         * Objects a controller ray can select.
         *
         * @returns {Object[]}
         */
        getTargets: () => (group.parent ? [button] : []),

        /**
         * Whether a hit object is the Exit button.
         *
         * @param {Object} object3d Hit object.
         * @returns {boolean}
         */
        isExit: (object3d) => !!object3d && object3d.userData.mavrWristAction === 'exit',

        /**
         * Show lines of plain text (redrawn only when they change).
         *
         * @param {string[]} lines Up to three lines.
         */
        setLines: (lines) => {
            const list = (Array.isArray(lines) ? lines : []).map((l) => String(l));
            const key = list.join('\n');
            if (key !== current) {
                current = key;
                draw(list);
            }
        },

        /** Remove the panel and release its resources. */
        dispose: () => {
            if (group.parent) {
                group.parent.remove(group);
            }
            [board, button].forEach((mesh) => {
                mesh.geometry.dispose();
                mesh.material.map.dispose();
                mesh.material.dispose();
            });
        },
    };
};
