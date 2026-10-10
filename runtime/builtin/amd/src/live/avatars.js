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
 * The avatar layer of the 3D view in a live room.
 *
 * It sends the own pose about 12 times a second, shows the other people as avatars (interpolated
 * a little in the past), and places their voices at their heads. It talks only to the live
 * controller, never to the transport, so another transport does not change it.
 *
 * Each avatar wears the person's appearance (from the live-room peer list: a "vrroom-avatar/1"
 * JSON text, a picture URL and a JSON object of costume model URLs {outfit, hat}, each '' for none)
 * and can show their webcam on its face screen:
 * setVideo(peerid, stream) gives a person's media stream (or null), and the video fades in while the
 * stream has a live, unmuted video track, and fades back to the face when the track ends or is muted.
 * The layer registers itself with the live controller's attachVideoSink() to get these calls.
 *
 * Own camera: no own avatar is drawn (the view is first person), so setLocalVideo(stream) shows a
 * small mirrored self view in the corner of the overlay element given as option (overlay or
 * getOverlay()); without one it only keeps the stream.
 *
 * In a 360° photo room (option isPanorama() true) everyone looks from the centre of the photo, so
 * the others are drawn on a ring of 1.4 m around it (live/ring.js): each at an angle from a hash of
 * their peer id, the same for every viewer, facing where they look; the positions they send are not used.
 *
 * Avatars kept per peer, created, updated and removed as people come and go, follow mod_gear's
 * collaborative viewer.
 *
 * @module     vrroomruntime_builtin/live/avatars
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {createInterpolator} from 'vrroomruntime_builtin/live/interpolation';
import {createPoseSource} from 'vrroomruntime_builtin/live/posesource';
import {createAvatar, parseAppearance, parseAssets, cleanPictureUrl, hasLiveVideo, FADE_MS, COLOURS, hueOf}
    from 'vrroomruntime_builtin/live/avatarmodel';
import {createSpawnFlash, prefersReducedMotion, timeline as flashTimeline} from 'vrroomruntime_builtin/live/spawnflash';
import {ringPose} from 'vrroomruntime_builtin/live/ring';

/** Seconds between two own pose messages (12 Hz). */
const SEND_INTERVAL = 1 / 12;

/** A pose is sent at least this often, even when standing still (ms). */
const SEND_IDLE = 1000;

/** Moves smaller than this are not sent (m). */
const MOVE_MIN = 0.01;

/** Turns smaller than this are not sent: cos of half of 1 degree. */
const TURN_MIN_DOT = Math.cos((Math.PI / 180) / 2);

/** An avatar without a pose for this long is hidden (ms). */
const HIDE_AFTER = 3000;

/** Interpolation delay (ms). */
const DELAY = 120;

/** Modes in which a person is not drawn. */
const UNDRAWN = ['ar', 'list'];

/** Local modes. */
const MODES = ['3d', 'vr', 'mr', 'ar'];

/** How often the appearances of the peers are compared with the peer list (ms). */
const LOOK_REFRESH = 2000;

/** Size of the self view (px). */
const SELF_VIEW_WIDTH = 120;

/**
 * Local time in ms.
 *
 * @returns {number}
 */
const clockNow = () => (window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now());

/**
 * Whether two poses differ by more than the sending thresholds.
 *
 * @param {number[]|null} a Pose of 7 or null.
 * @param {number[]|null} b Pose of 7 or null.
 * @returns {boolean}
 */
const changed = (a, b) => {
    if (!a || !b) {
        return a !== b;
    }
    const dx = a[0] - b[0];
    const dy = a[1] - b[1];
    const dz = a[2] - b[2];
    if (dx * dx + dy * dy + dz * dz > MOVE_MIN * MOVE_MIN) {
        return true;
    }
    const dot = Math.abs(a[3] * b[3] + a[4] * b[4] + a[5] * b[5] + a[6] * b[6]);
    return dot < TURN_MIN_DOT;
};

/**
 * Create the avatar layer.
 *
 * Options: live (LiveController), camera, getRoomRoot() → Object3D, getSceneId() → string,
 * getGrip(handedness) → Object3D|null, getHand(index) → Object3D|null, overlay (HTMLElement) or
 * getOverlay() → HTMLElement|null for the self view (optional), isPanorama() → bool (the current
 * room is a 360° photo: the others stand on a ring around its centre; optional).
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} options Options.
 * @returns {Object} AvatarLayer {setRoom(roomroot, sceneid), setMode(mode), update(dt, nowms), getPeerPositions(),
 *     setVideo(peerid, stream), setLocalVideo(stream), dispose()}
 */
export const createAvatarLayer = (THREE, options) => {
    const live = options.live;
    let mode = '3d';
    let roomroot = options.getRoomRoot ? options.getRoomRoot() : null;
    let sceneid = options.getSceneId ? options.getSceneId() : '';
    let disposed = false;
    let sendclock = 0;
    let lastsent = null;
    let lastsenttime = 0;
    const ringed = () => {
        try {
            return typeof options.isPanorama === 'function' && !!options.isPanorama();
        } catch (error) {
            return false;
        }
    };

    const posesource = createPoseSource(THREE, {
        camera: options.camera,
        getRoomRoot: () => roomroot,
        getGrip: options.getGrip,
        getHand: options.getHand,
        isXr: () => mode !== '3d',
    });

    // Peer id → {avatar|null, interpolator, scene, mode, mic, lastpos ([x, z] last drawn, or null),
    // look {appearance, picture, assets} (texts from the peer list), video (see attachVideo) or null}.
    const peers = new Map();
    const unsubscribers = [];
    let lookclock = 0;

    // Own camera: {stream, element|null}.
    const local = {stream: null, element: null};

    const voice = () => (live && typeof live.getVoice === 'function' ? live.getVoice() : null);

    const entryOf = (peerid) => {
        let entry = peers.get(peerid);
        if (!entry) {
            entry = {
                peerid: peerid,
                avatar: null,
                shown: false,
                flash: null,
                flashstart: null,
                interpolator: createInterpolator({delay: DELAY, maxsamples: 20, holdafter: 1000}),
                scene: '',
                mode: '',
                mic: 'off',
                lastpos: null,
                look: {appearance: '', picture: '', assets: ''},
                video: null,
            };
            peers.set(peerid, entry);
        }
        return entry;
    };

    const nameOf = (peerid) => {
        const name = live && typeof live.getPeerName === 'function' ? live.getPeerName(peerid) : '';
        return typeof name === 'string' ? name : '';
    };

    /**
     * The appearance texts of a peer, from a peer object or the controller's peer list.
     *
     * @param {string} peerid Peer id.
     * @param {Object|null} peer Peer object if at hand.
     * @returns {Object|null} {appearance, picture, assets}, or null when the peer is not listed.
     */
    const lookOf = (peerid, peer) => {
        let found = peer && peer.peerid === peerid ? peer : null;
        if (!found && live && typeof live.getPeers === 'function') {
            try {
                found = (live.getPeers() || []).find((p) => p && p.peerid === peerid) || null;
            } catch (error) {
                Log.debug(error);
            }
        }
        if (!found) {
            return null;
        }
        return {
            appearance: typeof found.appearance === 'string' ? found.appearance : '',
            picture: typeof found.picture === 'string' ? found.picture : '',
            assets: typeof found.assets === 'string' ? found.assets : '',
        };
    };

    /**
     * Give an avatar a (new) look when it changed.
     *
     * @param {Object} entry Peer entry.
     * @param {Object|null} look {appearance, picture, assets}, or null to keep the last one.
     */
    const applyLook = (entry, look) => {
        if (!look || (look.appearance === entry.look.appearance && look.picture === entry.look.picture &&
                look.assets === entry.look.assets)) {
            return;
        }
        entry.look = look;
        if (entry.avatar) {
            entry.avatar.setAppearance(parseAppearance(look.appearance), cleanPictureUrl(look.picture),
                parseAssets(look.assets));
        }
    };

    /**
     * A costume that cannot be loaded or worn only leaves the avatar without it.
     *
     * @param {Error} error What went wrong.
     */
    const costumeFailed = (error) => Log.debug(error);

    /**
     * Show or hide a peer's video on their avatar, from the state of the stream.
     *
     * @param {Object} entry Peer entry.
     */
    const refreshVideo = (entry) => {
        const video = entry.video;
        if (!entry.avatar) {
            return;
        }
        if (video && video.element && hasLiveVideo(video.stream)) {
            watchTracks(video);
            entry.avatar.setVideo(video.element);
        } else {
            entry.avatar.setVideo(null);
        }
    };

    /**
     * Listen to the video tracks of a stream (once per track).
     *
     * @param {Object} video Video state {stream, tracks (Set), onchange, listeners}.
     */
    function watchTracks(video) {
        video.stream.getVideoTracks().forEach((track) => {
            if (video.tracks.has(track)) {
                return;
            }
            video.tracks.add(track);
            ['ended', 'mute', 'unmute'].forEach((type) => {
                track.addEventListener(type, video.onchange);
                video.listeners.push([track, type]);
            });
        });
    }

    /**
     * A hidden, muted video element that plays a stream (for a texture or the self view).
     *
     * @param {Object} stream MediaStream.
     * @returns {HTMLVideoElement}
     */
    const videoElement = (stream) => {
        const element = document.createElement('video');
        element.muted = true;
        element.autoplay = true;
        element.playsInline = true;
        element.setAttribute('muted', '');
        element.setAttribute('playsinline', '');
        element.setAttribute('aria-hidden', 'true');
        element.tabIndex = -1;
        element.srcObject = stream;
        return element;
    };

    /**
     * Start playing a video element; a refused play() is logged only.
     *
     * @param {HTMLVideoElement} element Video element.
     */
    const play = (element) => {
        try {
            const promise = element.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch((error) => Log.debug(error));
            }
        } catch (error) {
            Log.debug(error);
        }
    };

    /**
     * Stop a video element and take it out of the page.
     *
     * @param {HTMLVideoElement|null} element Video element.
     */
    const dropElement = (element) => {
        if (!element) {
            return;
        }
        try {
            element.pause();
        } catch (error) {
            Log.debug(error);
        }
        element.srcObject = null;
        if (element.parentNode) {
            element.parentNode.removeChild(element);
        }
    };

    /**
     * Stop listening to a peer's stream; the element goes once the avatar has faded back.
     *
     * @param {Object} entry Peer entry.
     * @param {boolean} atonce Drop the element at once (the avatar is gone).
     */
    const detachVideo = (entry, atonce) => {
        const video = entry.video;
        if (!video) {
            return;
        }
        entry.video = null;
        video.listeners.forEach(([target, type]) => target.removeEventListener(type, video.onchange));
        video.listeners.length = 0;
        video.tracks.clear();
        if (entry.avatar && !atonce) {
            entry.avatar.setVideo(null);
            window.setTimeout(() => dropElement(video.element), FADE_MS + 100);
        } else {
            dropElement(video.element);
        }
    };

    /**
     * Use a stream as a peer's video.
     *
     * @param {Object} entry Peer entry.
     * @param {Object} stream MediaStream.
     */
    const attachVideo = (entry, stream) => {
        const element = videoElement(stream);
        // Kept in the page (out of sight): some browsers stop decoding a detached video.
        element.style.cssText = 'position:fixed;left:0;top:0;width:2px;height:2px;opacity:0;pointer-events:none;';
        if (document.body) {
            document.body.appendChild(element);
        }
        const video = {stream: stream, element: element, tracks: new Set(), listeners: [], onchange: null};
        video.onchange = () => {
            if (!disposed && entry.video === video) {
                refreshVideo(entry);
            }
        };
        ['addtrack', 'removetrack'].forEach((type) => {
            stream.addEventListener(type, video.onchange);
            video.listeners.push([stream, type]);
        });
        ['loadeddata', 'playing'].forEach((type) => {
            element.addEventListener(type, video.onchange);
            video.listeners.push([element, type]);
        });
        watchTracks(video);
        entry.video = video;
        play(element);
        refreshVideo(entry);
    };

    /**
     * The overlay element for the self view, if any.
     *
     * @returns {HTMLElement|null}
     */
    const overlayOf = () => {
        let element = options.overlay || null;
        if (!element && typeof options.getOverlay === 'function') {
            try {
                element = options.getOverlay();
            } catch (error) {
                Log.debug(error);
            }
        }
        return element && typeof element.appendChild === 'function' ? element : null;
    };

    const isDrawn = (entry, now) => entry.scene === sceneid && UNDRAWN.indexOf(entry.mode) < 0 &&
        entry.interpolator.lastReceived() > 0 && now - entry.interpolator.lastReceived() < HIDE_AFTER;

    // The "beam in" light where an avatar appears (spawnflash.js), in the person's accent colour.
    const flashColour = (entry) => {
        const appearance = parseAppearance(entry.look ? entry.look.appearance : '');
        if (appearance && COLOURS[appearance.accent]) {
            return COLOURS[appearance.accent];
        }
        return `hsl(${hueOf(entry.peerid || '')}, 70%, 65%)`;
    };
    const startFlash = (entry, pose, now) => {
        stopFlash(entry);
        entry.flashstart = now;
        if (prefersReducedMotion() || !roomroot) {
            return;
        }
        entry.flash = createSpawnFlash(THREE, {colour: flashColour(entry)});
        entry.flash.object.position.set(Number(pose.h[0]) || 0, 0, Number(pose.h[2]) || 0);
        roomroot.add(entry.flash.object);
    };
    const stopFlash = (entry) => {
        if (entry.flash) {
            entry.flash.dispose();
            entry.flash = null;
        }
        entry.flashstart = null;
        if (entry.avatar) {
            entry.avatar.object.scale.set(1, 1, 1);
        }
    };
    const stepFlash = (entry, now) => {
        if (entry.flashstart === null || entry.flashstart === undefined) {
            return;
        }
        const elapsed = now - entry.flashstart;
        const state = entry.flash ? entry.flash.update(elapsed) : flashTimeline(elapsed, true);
        entry.avatar.object.scale.set(state.avatar.xz, state.avatar.y, state.avatar.xz);
        if (state.done) {
            stopFlash(entry);
        }
    };

    const removePeer = (peerid) => {
        const entry = peers.get(peerid);
        if (!entry) {
            return;
        }
        stopFlash(entry);
        detachVideo(entry, true);
        if (entry.avatar) {
            entry.avatar.dispose();
        }
        peers.delete(peerid);
    };

    const removeAll = () => {
        Array.from(peers.keys()).forEach((peerid) => removePeer(peerid));
    };

    const onPose = (detail) => {
        if (disposed || !detail || typeof detail.peerid !== 'string' || !detail.pose) {
            return;
        }
        const pose = detail.pose;
        const entry = entryOf(detail.peerid);
        const kept = entry.interpolator.push({seq: pose.q, ts: pose.ts, h: pose.h, l: pose.l, r: pose.r}, clockNow());
        if (!kept) {
            return;
        }
        entry.scene = typeof pose.sc === 'string' ? pose.sc : '';
        entry.mode = typeof pose.m === 'string' ? pose.m : '';
        if (!entry.avatar) {
            const look = lookOf(detail.peerid, null);
            if (look) {
                entry.look = look;
            }
            entry.avatar = createAvatar(THREE, parseAppearance(entry.look.appearance), {
                seed: detail.peerid,
                name: nameOf(detail.peerid),
                pictureurl: cleanPictureUrl(entry.look.picture),
                assets: parseAssets(entry.look.assets),
                onError: costumeFailed,
            });
            entry.avatar.object.visible = false;
            if (roomroot) {
                roomroot.add(entry.avatar.object);
            }
            refreshVideo(entry);
        }
    };

    const onPeerState = (detail) => {
        if (disposed || !detail || typeof detail.peerid !== 'string' || !detail.state) {
            return;
        }
        const state = detail.state;
        const entry = entryOf(detail.peerid);
        entry.mode = typeof state.m === 'string' ? state.m : entry.mode;
        entry.scene = typeof state.sc === 'string' ? state.sc : entry.scene;
        entry.mic = typeof state.mic === 'string' ? state.mic : 'off';
    };

    const onPeerJoined = (detail) => {
        const peer = detail && detail.peer ? detail.peer : null;
        if (disposed || !peer || typeof peer.peerid !== 'string') {
            return;
        }
        const entry = peers.get(peer.peerid);
        if (entry && entry.avatar) {
            entry.avatar.setName(typeof peer.name === 'string' ? peer.name : nameOf(peer.peerid));
        }
        if (entry) {
            applyLook(entry, lookOf(peer.peerid, peer));
        }
    };


    const onPeerLeft = (detail) => {
        if (!disposed && detail && typeof detail.peerid === 'string') {
            removePeer(detail.peerid);
        }
    };

    const onState = (detail) => {
        const state = detail ? detail.state : '';
        if (!disposed && (state === 'left' || state === 'failed' || state === 'idle')) {
            removeAll();
            lastsent = null;
            lastsenttime = 0;
        }
    };

    const listen = (type, handler) => {
        if (!live || typeof live.on !== 'function') {
            return;
        }
        const unsubscribe = live.on(type, handler);
        if (typeof unsubscribe === 'function') {
            unsubscribers.push(unsubscribe);
        }
    };
    listen('pose', onPose);
    listen('peerstate', onPeerState);
    listen('peerjoined', onPeerJoined);
    listen('peerleft', onPeerLeft);
    listen('state', onState);

    // The own pose: sent at 12 Hz when it changed, and at least once a second.
    const sendOwn = (own, dt, now) => {
        sendclock += dt;
        if (sendclock < SEND_INTERVAL) {
            return;
        }
        sendclock = Math.min(sendclock - SEND_INTERVAL, SEND_INTERVAL);
        const due = !lastsent || now - lastsenttime >= SEND_IDLE || changed(own.h, lastsent.h) ||
            changed(own.l, lastsent.l) || changed(own.r, lastsent.r);
        if (!due || !sceneid) {
            return;
        }
        live.broadcastPose({sc: sceneid, m: mode, h: own.h, l: own.l, r: own.r});
        lastsent = own;
        lastsenttime = now;
    };

    const layer = {
        /**
         * Move every avatar into a (new) room; hide those in another room.
         *
         * @param {Object} root THREE.Object3D, the room root.
         * @param {string} id Scene id of the room.
         */
        setRoom(root, id) {
            if (disposed) {
                return;
            }
            roomroot = root || null;
            sceneid = typeof id === 'string' ? id : '';
            peers.forEach((entry) => {
                if (!entry.avatar) {
                    return;
                }
                if (roomroot) {
                    roomroot.add(entry.avatar.object);
                } else if (entry.avatar.object.parent) {
                    entry.avatar.object.parent.remove(entry.avatar.object);
                }
                if (entry.scene !== sceneid) {
                    entry.avatar.object.visible = false;
                }
                // The light belonged to the old room; whoever is in the new one beams in there.
                stopFlash(entry);
                entry.shown = false;
            });
            // Tell the others at once.
            lastsent = null;
        },

        /**
         * The local view mode changed.
         *
         * @param {string} value '3d', 'vr', 'mr' or 'ar'.
         */
        setMode(value) {
            if (disposed) {
                return;
            }
            mode = MODES.indexOf(value) >= 0 ? value : '3d';
            lastsent = null;
            if (live && typeof live.setLocalMode === 'function') {
                live.setLocalMode(mode, sceneid);
            }
            const v = voice();
            if (v) {
                v.setSpatial(mode !== 'ar');
            }
        },

        /**
         * Once per frame, before rendering.
         *
         * @param {number} dt Seconds since the last frame.
         * @param {number} nowms performance.now()
         */
        update(dt, nowms) {
            if (disposed || !live) {
                return;
            }
            const now = Number.isFinite(Number(nowms)) ? Number(nowms) : clockNow();
            const step = Number(dt) >= 0 && Number.isFinite(Number(dt)) ? Number(dt) : 0;
            const joined = typeof live.isJoined === 'function' ? live.isJoined() : false;
            if (!joined) {
                return;
            }
            const v = voice();
            lookclock += step * 1000;
            if (lookclock >= LOOK_REFRESH) {
                lookclock = 0;
                peers.forEach((entry, peerid) => applyLook(entry, lookOf(peerid, null)));
            }
            try {
                const own = posesource.sample();
                if (own) {
                    sendOwn(own, step, now);
                    if (v) {
                        v.setListenerPose(own.h.slice(0, 3), own.h.slice(3, 7));
                    }
                }
            } catch (error) {
                Log.debug(error);
            }
            peers.forEach((entry, peerid) => {
                if (!entry.avatar) {
                    return;
                }
                const drawn = isDrawn(entry, now);
                const sampled = drawn ? entry.interpolator.sample(now) : null;
                // In a photo room the received position is replaced by the peer's place on the ring.
                const pose = sampled && ringed() ? ringPose(sampled, peerid) : sampled;
                entry.avatar.object.visible = !!pose;
                if (!pose) {
                    entry.lastpos = null;
                    if (entry.shown) {
                        entry.shown = false;
                        stopFlash(entry);
                    }
                    return;
                }
                if (!entry.shown) {
                    // They joined the room, came back from the list view or from another room.
                    entry.shown = true;
                    startFlash(entry, pose, now);
                }
                entry.lastpos = [Number(pose.h[0]) || 0, Number(pose.h[2]) || 0];
                entry.avatar.setPose(pose, entry.mode);
                stepFlash(entry, now);
                entry.avatar.setMicState(entry.mic);
                if (v) {
                    v.setPeerPose(peerid, pose.h.slice(0, 3), pose.h.slice(3, 7));
                    entry.avatar.setSpeaking(v.getLevel(peerid));
                } else {
                    entry.avatar.setSpeaking(0);
                }
            });
        },

        /**
         * Where the others shown in the current room stand, so a start spot can keep clear of them.
         *
         * @returns {number[][]} [[x, z], ...] in room coordinates
         */
        getPeerPositions() {
            const list = [];
            if (disposed) {
                return list;
            }
            const now = clockNow();
            peers.forEach((entry) => {
                if (entry.lastpos && isDrawn(entry, now)) {
                    list.push(entry.lastpos.slice());
                }
            });
            return list;
        },

        /**
         * Show a person's webcam on their avatar, or stop showing it.
         *
         * The stream stays the caller's: its tracks are never stopped here. Without a live video
         * track nothing shows; the video comes and goes with the track.
         *
         * @param {string} peerid Peer id.
         * @param {Object|null} stream MediaStream, or null.
         */
        setVideo(peerid, stream) {
            if (disposed || typeof peerid !== 'string' || peerid === '') {
                return;
            }
            const valid = stream && typeof stream.getVideoTracks === 'function' ? stream : null;
            const entry = valid ? entryOf(peerid) : peers.get(peerid);
            if (!entry) {
                return;
            }
            if (entry.video && entry.video.stream === valid) {
                refreshVideo(entry);
                return;
            }
            detachVideo(entry, false);
            if (valid) {
                attachVideo(entry, valid);
            }
        },

        /**
         * The own camera: a small mirrored self view in the overlay, if there is one.
         *
         * @param {Object|null} stream MediaStream, or null.
         */
        setLocalVideo(stream) {
            if (disposed) {
                return;
            }
            const valid = stream && typeof stream.getVideoTracks === 'function' ? stream : null;
            if (valid === local.stream) {
                return;
            }
            dropElement(local.element);
            local.element = null;
            local.stream = valid;
            const overlay = valid ? overlayOf() : null;
            if (!overlay) {
                return;
            }
            const element = videoElement(valid);
            element.className = 'vrroom-live-selfview';
            // Mirrored, as people expect to see themselves; the others see the camera image as it is.
            element.style.cssText = `position:absolute;right:8px;bottom:8px;width:${SELF_VIEW_WIDTH}px;height:auto;` +
                'border-radius:8px;transform:scaleX(-1);pointer-events:none;z-index:2;opacity:0;' +
                `transition:opacity ${FADE_MS}ms ease;background:#000;`;
            element.addEventListener('playing', () => {
                element.style.opacity = '1';
            });
            overlay.appendChild(element);
            local.element = element;
            play(element);
        },

        /** Remove every avatar and stop listening. */
        dispose() {
            if (disposed) {
                return;
            }
            dropElement(local.element);
            local.element = null;
            local.stream = null;
            removeAll();
            disposed = true;
            unsubscribers.forEach((unsubscribe) => {
                try {
                    unsubscribe();
                } catch (error) {
                    Log.debug(error);
                }
            });
            unsubscribers.length = 0;
            posesource.dispose();
        },
    };

    // The controller hands over the videos (also those shown already).
    if (live && typeof live.attachVideoSink === 'function') {
        const detach = live.attachVideoSink(layer);
        if (typeof detach === 'function') {
            unsubscribers.push(detach);
        }
    }
    return layer;
};
