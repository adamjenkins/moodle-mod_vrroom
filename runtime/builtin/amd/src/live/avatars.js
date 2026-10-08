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
import {createAvatar} from 'vrroomruntime_builtin/live/avatarmodel';

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
 * getGrip(handedness) → Object3D|null, getHand(index) → Object3D|null.
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} options Options.
 * @returns {Object} AvatarLayer {setRoom(roomroot, sceneid), setMode(mode), update(dt, nowms), dispose()}
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

    const posesource = createPoseSource(THREE, {
        camera: options.camera,
        getRoomRoot: () => roomroot,
        getGrip: options.getGrip,
        getHand: options.getHand,
        isXr: () => mode !== '3d',
    });

    // Peer id → {avatar|null, interpolator, scene, mode, mic}.
    const peers = new Map();
    const unsubscribers = [];

    const voice = () => (live && typeof live.getVoice === 'function' ? live.getVoice() : null);

    const entryOf = (peerid) => {
        let entry = peers.get(peerid);
        if (!entry) {
            entry = {
                avatar: null,
                interpolator: createInterpolator({delay: DELAY, maxsamples: 20, holdafter: 1000}),
                scene: '',
                mode: '',
                mic: 'off',
            };
            peers.set(peerid, entry);
        }
        return entry;
    };

    const nameOf = (peerid) => {
        const name = live && typeof live.getPeerName === 'function' ? live.getPeerName(peerid) : '';
        return typeof name === 'string' ? name : '';
    };

    const isDrawn = (entry, now) => entry.scene === sceneid && UNDRAWN.indexOf(entry.mode) < 0 &&
        entry.interpolator.lastReceived() > 0 && now - entry.interpolator.lastReceived() < HIDE_AFTER;

    const removePeer = (peerid) => {
        const entry = peers.get(peerid);
        if (!entry) {
            return;
        }
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
            entry.avatar = createAvatar(THREE, {peerid: detail.peerid, name: nameOf(detail.peerid)});
            entry.avatar.object.visible = false;
            if (roomroot) {
                roomroot.add(entry.avatar.object);
            }
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

    return {
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
                const pose = drawn ? entry.interpolator.sample(now) : null;
                entry.avatar.object.visible = !!pose;
                if (!pose) {
                    return;
                }
                entry.avatar.setPose(pose, entry.mode);
                entry.avatar.setMicState(entry.mic);
                if (v) {
                    v.setPeerPose(peerid, pose.h.slice(0, 3), pose.h.slice(3, 7));
                    entry.avatar.setSpeaking(v.getLevel(peerid));
                } else {
                    entry.avatar.setSpeaking(0);
                }
            });
        },

        /** Remove every avatar and stop listening. */
        dispose() {
            if (disposed) {
                return;
            }
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
};
