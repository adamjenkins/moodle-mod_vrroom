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
 * The live room of one launch: joins and leaves through a transport, runs the voice chat and the
 * panel, and passes poses and states between the transport and the avatar layer of the viewer.
 *
 * Only the transport interface (live/transport) is used, so another transport (Phase 4) can be
 * chosen by config.live.transport without changing the avatars, the voice or the panel.
 *
 * @module     vrroomruntime_builtin/live/controller
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getStrings} from 'core/str';
import {Emitter} from 'vrroomruntime_builtin/live/transport';
import {createMeshTransport} from 'vrroomruntime_builtin/live/mesh';
import {createVoice} from 'vrroomruntime_builtin/live/voice';
import {fetchRooms} from 'vrroomruntime_builtin/live/signalling';
import {createPanel} from 'vrroomruntime_builtin/live/panel';
import {attemptEnded, roomRemoved, listCapacity, mutePeerOf} from 'vrroomruntime_builtin/live/roomstatus';
import {region, announce, plainText, startScene} from 'vrroomruntime_builtin/util';

/** Strings of the live room (all in vrroomruntime_builtin). */
const STRINGS = [
    'errorlivebusy', 'errorlivedisabled', 'errorlivenoroom', 'errorlivenotallowed', 'errorliveroomgone',
    'errorliveroomlimit', 'errorliveroomnotfound',
    'livealone', 'liveclosed', 'liveconnectfailed', 'liveconnecting', 'livecurrentroom', 'livefull', 'livejoined',
    'livejoinfailed', 'livejoining', 'liveleft', 'livemicdenied', 'livemicinsecure', 'livemicoff',
    'livemicon', 'livemicstate_muted', 'livemicstate_off', 'livemicstate_on', 'livemute',
    'livemutepeer', 'liveparticipants', 'livepeerjoined', 'livepeerleft', 'livereconnecting',
    'livereplaced', 'liveroomslistfailed', 'liveunmute', 'liveunmutepeer', 'livewhere3d', 'livewherear',
    'livewherelist', 'livewheremr', 'livewhereroom', 'livewherevr', 'liveyou',
    'liveoff', 'liveattemptended', 'liveroomremoved',
];

/** Transports by config.live.transport. */
const TRANSPORTS = {
    mesh: createMeshTransport,
};

/** AMD modules a launch config may name as its transport: another run mode's module. */
const TRANSPORT_MODULE = /^vrroomruntime_[a-z][a-z0-9_]*\/[a-z][a-z0-9_]*(\/[a-z][a-z0-9_]*)*$/;

/**
 * The transport factory for a live block: a built-in transport by name, or the createTransport
 * export of the AMD module the run mode names in transportmodule (loaded on first use).
 *
 * @param {Object} liveconfig config.live
 * @returns {Promise<Function|null>}
 */
const loadTransportFactory = (liveconfig) => {
    const builtin = TRANSPORTS[liveconfig.transport || 'mesh'];
    if (builtin) {
        return Promise.resolve(builtin);
    }
    const name = typeof liveconfig.transportmodule === 'string' ? liveconfig.transportmodule : '';
    if (!TRANSPORT_MODULE.test(name)) {
        return Promise.resolve(null);
    }
    return new Promise((resolve) => {
        // RequireJS loads a module by a name known only at run time (core: lib/amd/src/chart_builder.js).
        window.require([name], (module) => {
            resolve(module && typeof module.createTransport === 'function' ? module.createTransport : null);
        }, () => resolve(null));
    });
};

/** Modes a state message may carry. */
const MODES = ['3d', 'vr', 'mr', 'ar', 'list'];

/** How often the own state is sent again, and the panel refreshed (ms). */
const STATE_INTERVAL = 5000;

/** How often the speaking indicators are updated (ms). */
const SPEAKING_INTERVAL = 250;

/** After this long without a connection, a person is shown as "could not connect" (ms). */
const CONNECT_TIMEOUT = 20000;

/** How often the list of rooms is loaded again while not joined, by default and at most/least (ms). */
const ROOMS_INTERVAL = 5000;
const ROOMS_MIN_INTERVAL = 3000;
const ROOMS_MAX_INTERVAL = 30000;

/** Largest pose sequence number before it wraps. */
const MAX_SEQ = 2147483647;

/** Transport failure reasons → strings. */
const FAILURES = {
    disabled: 'errorlivedisabled',
    noroom: 'errorlivenoroom',
    busy: 'errorlivebusy',
    notallowed: 'errorlivenotallowed',
};

/** Error codes of live_join → failure reasons (when a transport rejects instead of reporting). */
const ERRORCODES = {
    errorliveroomfull: 'full',
    errorlivedisabled: 'disabled',
    errorlivenoroom: 'noroom',
    errorlivebusy: 'busy',
    errorlivenotallowed: 'notallowed',
    errorliveroomnotfound: 'noroom',
    errorliveroomgone: 'roomgone',
    errorliveroomlimit: 'limit',
};

/**
 * Clamp the interval a live_rooms answer asks for.
 *
 * @param {*} interval Milliseconds from the server.
 * @returns {number}
 */
const roomsInterval = (interval) => {
    const value = Number(interval);
    if (!isFinite(value) || value <= 0) {
        return ROOMS_INTERVAL;
    }
    return Math.min(ROOMS_MAX_INTERVAL, Math.max(ROOMS_MIN_INTERVAL, Math.round(value)));
};

/**
 * Whether keyboard focus is in a text field, where T must type a T.
 *
 * @param {EventTarget} target The event target.
 * @returns {boolean}
 */
const isTyping = (target) => {
    if (!target || !target.tagName) {
        return false;
    }
    const tag = target.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || !!target.isContentEditable;
};

/**
 * The live room of one launch.
 */
class LiveController extends Emitter {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root The VR room root.
     * @param {Object} config Launch config (with config.live).
     * @param {Object} session The session (vrroomruntime_builtin/session).
     */
    constructor(root, config, session) {
        super();
        this.root = root;
        this.config = config;
        this.session = session;
        this.liveconfig = config.live;
        this.transport = null;
        this.unbind = [];
        this.voice = createVoice(null);
        this.panel = null;
        this.strings = {};
        this.joined = false;
        this.joining = false;
        this.leaving = null;
        this.destroyed = false;
        this.mode = 'list';
        const first = startScene(config);
        this.sceneid = first ? String(first.id) : '';
        this.seq = 0;
        this.names = new Map();
        this.peerstates = new Map();
        this.firstseen = new Map();
        this.statuskey = '';
        this.statetimer = null;
        this.speakingtimer = null;
        this.refreshtimer = null;
        this.refreshpending = null;
        // The list of rooms (several rooms per activity): shown and refreshed while not joined.
        this.roomlist = this.liveconfig.roomlist === true;
        this.roomstimer = null;
        this.roomsseq = 0;
        this.roomsfailed = false;
        this.failureshown = false;
        this.roomname = '';
        // The room joined ({groupid, number}), and the one just closed for this user (its cause is
        // looked for in the next list of rooms).
        this.room = null;
        this.closedroom = null;
        // The room asked for in the running join, and the last list of rooms (for the current room size).
        this.joinroom = 0;
        this.lastrooms = null;
        // Joining is not offered (the string key says why); with lockoutretry the list is still
        // asked for now and then, and joining comes back when live mode is turned on again.
        this.lockout = '';
        this.lockoutretry = false;

        this.voice.on('micstate', () => {
            this.sendState();
            this.updateVoiceControls();
            this.scheduleRefresh();
        });

        this.onPageHide = () => {
            if (this.transport && (this.joined || this.joining)) {
                try {
                    this.transport.leaveOnUnload();
                } catch (e) {
                    // Best effort: the presence times out after 20 seconds anyway.
                }
            }
        };
        window.addEventListener('pagehide', this.onPageHide);

        this.onKeyDown = (e) => {
            if (!this.isTalkKey(e)) {
                return;
            }
            this.voice.talk(true);
            if (this.panel) {
                this.panel.setTalking(true);
            }
        };
        this.onKeyUp = (e) => {
            if (!this.isTalkKey(e)) {
                return;
            }
            this.voice.talk(false);
            if (this.panel) {
                this.panel.setTalking(false);
            }
        };
        this.onBlur = () => {
            if (this.voice.isPushToTalk()) {
                this.voice.talk(false);
                if (this.panel) {
                    this.panel.setTalking(false);
                }
            }
        };
        document.addEventListener('keydown', this.onKeyDown);
        document.addEventListener('keyup', this.onKeyUp);
        window.addEventListener('blur', this.onBlur);

        this.onVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                if (!this.joined && !this.joining && !this.lockout) {
                    // The attempt may have been finished in another window meanwhile.
                    this.checkAttempt();
                }
                this.refreshRooms();
            } else {
                this.stopRoomsTimer();
            }
        };
        if (this.roomlist) {
            document.addEventListener('visibilitychange', this.onVisibilityChange);
        }
    }

    /**
     * A string of the live room, with {$a} replaced.
     *
     * @param {string} key String key.
     * @param {*} a Value for {$a}.
     * @returns {string} Plain text.
     */
    str(key, a) {
        const raw = Object.prototype.hasOwnProperty.call(this.strings, key) ? this.strings[key] : '';
        if (a === undefined) {
            return raw;
        }
        const value = String(a);
        return raw.replace(/\{\$a\}/g, () => value);
    }

    /**
     * Load the strings and render the panel into the vrroom-liveroom region.
     *
     * @returns {Promise}
     */
    render() {
        const container = region(this.root, 'vrroom-liveroom');
        if (!container || this.destroyed) {
            return Promise.resolve();
        }
        const pending = new Pending('vrroomruntime_builtin/live/controller:render');
        const handlers = {
            join: (room) => this.join(room).catch(Notification.exception),
            groupchange: () => this.refreshRooms(),
            leave: () => this.leave().catch(Notification.exception),
            mic: () => this.toggleMic(),
            mute: () => this.toggleMute(),
            ptt: (on) => this.setPushToTalk(on),
            talk: (on) => this.talk(on),
            mutepeer: (peerid) => this.toggleMutePeer(peerid),
        };
        return getStrings(STRINGS.map((key) => ({key: key, component: 'vrroomruntime_builtin'}))).then((values) => {
            STRINGS.forEach((key, i) => {
                this.strings[key] = values[i];
            });
            this.panel = createPanel(container, {
                cmid: Number(this.liveconfig.cmid) || 0,
                live: this.liveconfig,
                secure: this.voice.canSend(),
                str: (key, a) => this.str(key, a),
                handlers: handlers,
            });
            return this.panel.render();
        }).then(() => {
            this.updateVoiceControls();
            if (this.lockout) {
                // Found out before the panel was there.
                this.setStatus(this.lockout);
                this.showJoining(false);
            }
            this.refreshRooms();
            pending.resolve();
            return undefined;
        }, (error) => {
            pending.resolve();
            throw error;
        });
    }

    /**
     * Load the list of rooms now, then again after the interval the server asks for. Runs only in
     * room-list mode, while not joined or joining and while the page is visible.
     */
    refreshRooms() {
        this.stopRoomsTimer();
        if (!this.roomlist || !this.panel || this.destroyed || this.joined || this.joining || !this.liveconfig.canjoin) {
            return;
        }
        if (this.lockout && !this.lockoutretry) {
            return;
        }
        if (document.visibilityState && document.visibilityState !== 'visible') {
            // Loaded again when the page is shown (visibilitychange).
            return;
        }
        const seq = ++this.roomsseq;
        const groupid = this.panel.getGroupId() || Number(this.liveconfig.defaultgroupid) || 0;
        const pending = new Pending('vrroomruntime_builtin/live/controller:rooms');
        const current = () => seq === this.roomsseq && !!this.panel && !this.destroyed && !this.joined && !this.joining;
        let interval = ROOMS_INTERVAL;
        fetchRooms(Number(this.liveconfig.cmid) || 0, groupid).then((answer) => {
            if (!current()) {
                return undefined;
            }
            interval = roomsInterval(answer ? answer.interval : 0);
            if (this.lockout) {
                // Live mode is on again.
                this.unlock();
            }
            this.lastrooms = answer || null;
            if (this.closedroom) {
                // Moved out because the teacher lowered the number of rooms: say so instead of "closed".
                const removed = roomRemoved(answer, this.closedroom);
                this.closedroom = null;
                if (removed && this.statuskey === 'liveclosed') {
                    this.setStatus('liveroomremoved');
                }
            }
            if (this.roomsfailed) {
                this.roomsfailed = false;
                if (this.statuskey === '' && this.panel) {
                    this.panel.setStatus('');
                }
            }
            return this.panel.setRooms(answer || {});
        }).catch((error) => {
            if (!current()) {
                return undefined;
            }
            const code = error && error.errorcode ? String(error.errorcode) : '';
            if (code === 'errorlivedisabled') {
                // The teacher turned live mode off: no stale list with Join buttons. Asked again
                // now and then (lockOut() schedules it), in case it is turned on again.
                if (this.lockout !== 'liveoff') {
                    this.lockOut('liveoff', true);
                }
                interval = ROOMS_MAX_INTERVAL;
                return undefined;
            }
            if (code === 'errorlivenotallowed') {
                this.lockOut('errorlivenotallowed', false);
                return undefined;
            }
            if (this.lockout) {
                // Already explained; keep asking quietly.
                interval = ROOMS_MAX_INTERVAL;
                return undefined;
            }
            // Shown on the status line only when it holds nothing else (a join failure stays); the
            // list is asked for again after the interval.
            if (this.panel && ((this.statuskey === '' && !this.failureshown) || this.roomsfailed)) {
                this.statuskey = '';
                this.roomsfailed = true;
                this.panel.setStatus(this.str('liveroomslistfailed'));
            }
            return undefined;
        }).then(() => {
            if (current() && (!this.lockout || this.lockoutretry)) {
                this.scheduleRooms(interval);
            }
            pending.resolve();
            return undefined;
        }).catch((error) => {
            // Only reached when scheduling the next load fails: the earlier catch handles the fetch.
            pending.resolve();
            Notification.exception(error);
        });
    }

    /**
     * Load the list of rooms again after a delay.
     *
     * @param {number} delay Milliseconds.
     */
    scheduleRooms(delay) {
        this.stopRoomsTimer();
        this.roomstimer = window.setTimeout(() => {
            this.roomstimer = null;
            this.refreshRooms();
        }, delay);
    }

    /**
     * Stop loading the list of rooms (answers still on their way are dropped).
     */
    stopRoomsTimer() {
        if (this.roomstimer) {
            window.clearTimeout(this.roomstimer);
            this.roomstimer = null;
        }
    }

    /**
     * Join a room. Call inside the Join click: it also starts the audio context.
     *
     * @param {number} [room] Room number from the list of rooms; 0 starts a new room. Left out with a
     *     single room per activity.
     * @returns {Promise}
     */
    join(room) {
        if (this.destroyed || this.joined || this.joining || !this.liveconfig.canjoin || this.lockout) {
            return Promise.resolve();
        }
        const number = Number(room);
        const roomnumber = isFinite(number) && number > 0 ? Math.floor(number) : 0;
        this.joinroom = roomnumber;
        this.closedroom = null;
        // Answers of the list that are on their way no longer matter.
        this.stopRoomsTimer();
        this.roomsseq++;
        this.roomsfailed = false;
        this.failureshown = false;
        this.roomname = '';
        // Inside the user gesture: browsers start audio only then.
        this.voice.ensureAudio();
        const pending = new Pending('vrroomruntime_builtin/live/controller:join');
        this.joining = true;
        if (this.panel) {
            this.panel.setBusy(true);
        }
        this.setStatus('livejoining');
        const groupid = this.panel ? this.panel.getGroupId() : 0;
        const liveconfig = Object.assign({}, this.liveconfig, {
            defaultgroupid: groupid || Number(this.liveconfig.defaultgroupid) || 0,
            groupid: groupid || Number(this.liveconfig.defaultgroupid) || 0,
            room: roomnumber,
        });
        const giveUp = () => {
            this.joining = false;
            if (this.panel) {
                this.panel.setBusy(false);
            }
            this.setStatus('livejoinfailed');
            this.refreshRooms();
        };
        return loadTransportFactory(this.liveconfig).then((factory) => {
            if (this.destroyed || !this.joining) {
                return undefined;
            }
            if (!factory) {
                giveUp();
                return undefined;
            }
            const transport = factory(liveconfig, {});
            this.bind(transport);
            return this.joinTransport(transport);
        }).catch((error) => {
            // The transport could not be created.
            giveUp();
            Notification.exception(error);
            return undefined;
        }).then(() => {
            pending.resolve();
            return undefined;
        });
    }

    /**
     * Join through a bound transport; a join failure is shown, not thrown.
     *
     * @param {Object} transport The transport (already bound).
     * @returns {Promise}
     */
    joinTransport(transport) {
        return Promise.resolve().then(() => transport.join()).then((result) => {
            if (this.transport !== transport) {
                return undefined;
            }
            if (result && result.self) {
                this.names.set(result.self.peerid, String(result.self.name || ''));
            }
            if (result && result.room && result.room.name) {
                this.roomname = String(result.room.name);
            }
            this.rememberRoom(result ? result.room : null);
            this.onJoined();
            return undefined;
        }).catch((error) => {
            if (this.transport !== transport) {
                return undefined;
            }
            const code = error && error.errorcode ? String(error.errorcode) : '';
            this.onFailed(ERRORCODES[code] || 'error');
            return undefined;
        });
    }

    /**
     * Leave the room.
     *
     * @returns {Promise}
     */
    leave() {
        if (this.leaving) {
            return this.leaving;
        }
        if (!this.transport) {
            return Promise.resolve();
        }
        const transport = this.transport;
        const pending = new Pending('vrroomruntime_builtin/live/controller:leave');
        if (this.panel) {
            this.panel.setBusy(true);
        }
        this.leaving = Promise.resolve().then(() => transport.leave()).catch(() => undefined).then(() => {
            if (this.transport === transport) {
                this.teardown('liveleft');
            }
            this.leaving = null;
            pending.resolve();
            return undefined;
        });
        return this.leaving;
    }

    /**
     * Whether this browser is in the room.
     *
     * @returns {boolean}
     */
    isJoined() {
        return this.joined;
    }

    /**
     * The others in the room.
     *
     * @returns {Object[]} Transport peers {peerid, name, kind, connected}.
     */
    getPeers() {
        return this.transport && this.joined ? this.transport.getPeers() : [];
    }

    /**
     * The name of a person in the room, as the server sent it.
     *
     * @param {string} peerid Peer id.
     * @returns {string} Plain text.
     */
    getPeerName(peerid) {
        return this.names.get(peerid) || '';
    }

    /**
     * The last state message of a person.
     *
     * @param {string} peerid Peer id.
     * @returns {Object|null}
     */
    getPeerState(peerid) {
        return this.peerstates.get(peerid) || null;
    }

    /**
     * The name of a room of the activity.
     *
     * @param {string} sceneid Scene id.
     * @returns {string} Plain text ('' when unknown).
     */
    getSceneName(sceneid) {
        const scenes = Array.isArray(this.config.scenes) ? this.config.scenes : [];
        const scene = scenes.find((s) => String(s.id) === String(sceneid));
        return scene ? plainText(scene.name) : '';
    }

    /**
     * Send the own pose to everyone in the room (dropped while not joined).
     *
     * @param {Object} pose {sc, m, h, l, r} in room coordinates.
     */
    broadcastPose(pose) {
        if (!this.joined || !this.transport || !pose) {
            return;
        }
        this.seq = this.seq >= MAX_SEQ ? 1 : this.seq + 1;
        const message = Object.assign({}, pose, {t: 'p', q: this.seq, ts: Date.now()});
        if (typeof pose.sc === 'string' && pose.sc && pose.sc !== this.sceneid && this.mode !== 'list') {
            this.sceneid = pose.sc;
            this.sendState();
            this.scheduleRefresh();
        }
        this.transport.broadcast('pose', message);
    }

    /**
     * The local view mode changed.
     *
     * @param {string} mode '3d', 'vr', 'mr', 'ar' or 'list'.
     * @param {string} [sceneid] The room shown now, when it changed.
     */
    setLocalMode(mode, sceneid) {
        const value = MODES.indexOf(mode) >= 0 ? mode : '3d';
        const changed = value !== this.mode || (typeof sceneid === 'string' && sceneid !== '' && sceneid !== this.sceneid);
        this.mode = value;
        if (typeof sceneid === 'string' && sceneid !== '') {
            this.sceneid = sceneid;
        }
        if (value === 'list') {
            this.voice.setSpatial(false);
        }
        if (changed) {
            this.sendState();
            this.scheduleRefresh();
        }
    }

    /**
     * The voice chat.
     *
     * @returns {Object} live/voice Voice.
     */
    getVoice() {
        return this.voice;
    }

    /**
     * Leave and release everything (finish, page unload).
     *
     * @returns {Promise}
     */
    destroy() {
        if (this.destroyed) {
            return Promise.resolve();
        }
        const leaving = this.leave();
        this.destroyed = true;
        this.stopRoomsTimer();
        this.roomsseq++;
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('keydown', this.onKeyDown);
        document.removeEventListener('keyup', this.onKeyUp);
        window.removeEventListener('blur', this.onBlur);
        return leaving.catch(() => undefined).then(() => {
            this.teardown('');
            this.voice.dispose();
            if (this.panel) {
                this.panel.destroy();
                this.panel = null;
            }
            if (typeof this.clear === 'function') {
                this.clear();
            }
            return undefined;
        });
    }

    /**
     * Listen to a new transport.
     *
     * @param {Object} transport live/transport Transport.
     */
    bind(transport) {
        this.unbindTransport();
        this.transport = transport;
        this.voice.setTransport(transport);
        const listen = (type, fn) => {
            this.unbind.push(transport.on(type, (detail) => {
                if (this.transport === transport) {
                    fn(detail || {});
                }
            }));
        };
        listen('state', (detail) => this.onTransportState(detail));
        listen('peerjoined', (detail) => this.onPeerJoined(detail.peer));
        listen('peerupdated', (detail) => this.onPeerUpdated(detail.peer));
        listen('peerleft', (detail) => this.onPeerLeft(detail.peerid));
        listen('message', (detail) => this.onMessage(detail));
    }

    /**
     * Stop listening to the current transport.
     */
    unbindTransport() {
        this.unbind.forEach((fn) => {
            if (typeof fn === 'function') {
                fn();
            }
        });
        this.unbind = [];
    }

    /**
     * A state change of the transport.
     *
     * @param {Object} detail {state, reason}
     */
    onTransportState(detail) {
        const state = String(detail.state || '');
        const reason = String(detail.reason || '');
        if (state === 'joined') {
            this.onJoined();
        } else if (state === 'reconnecting') {
            this.setStatus('livereconnecting');
        } else if (state === 'left') {
            const keys = {closed: 'liveclosed', replaced: 'livereplaced'};
            const room = this.room;
            this.teardown(keys[reason] || 'liveleft');
            if (reason === 'closed') {
                this.explainClosed(room);
            }
        } else if (state === 'failed') {
            this.onFailed(reason);
        }
        this.emit('state', {state: state, reason: reason});
    }

    /**
     * The transport is in the room.
     */
    onJoined() {
        if (!this.transport) {
            return;
        }
        const wasjoined = this.joined;
        this.joined = true;
        this.joining = false;
        this.root.dataset.vrroomLive = 'joined';
        const self = this.transport.getSelf();
        if (self) {
            this.names.set(self.peerid, String(self.name || ''));
        }
        this.transport.getPeers().forEach((peer) => this.rememberPeer(peer));
        this.stopRoomsTimer();
        this.roomsseq++;
        if (typeof this.transport.joinInfo === 'function') {
            const info = this.transport.joinInfo();
            if (info && info.room && info.room.name) {
                this.roomname = String(info.room.name);
            }
            this.rememberRoom(info ? info.room : null);
        }
        if (this.panel) {
            this.panel.setBusy(false);
            this.panel.setJoined(true);
            if (this.transport && this.transport.capabilities && this.transport.capabilities.voice === false) {
                this.panel.setVoiceAvailable(false);
            }
            if (this.roomlist) {
                this.panel.setCurrentRoom(this.roomname ? this.str('livecurrentroom', this.roomname) : '');
            }
        }
        this.updatePresenceStatus(true);
        this.updateVoiceControls();
        if (!wasjoined) {
            this.startTimers();
            this.sendState();
        }
        this.scheduleRefresh();
    }

    /**
     * Joining failed, or the transport gave up.
     *
     * @param {string} reason Failure reason of the transport.
     */
    onFailed(reason) {
        let text;
        if (reason === 'full') {
            // The size the teacher set now (from the last list of rooms), not the one of page load.
            const size = listCapacity(this.lastrooms, this.joinroom) || Number(this.liveconfig.maxpeers) || 0;
            text = this.str('livefull', size);
        } else if (reason === 'limit') {
            const most = Number(this.lastrooms ? this.lastrooms.maxrooms : 0) || Number(this.liveconfig.maxrooms) || 0;
            text = this.str('errorliveroomlimit', most);
        } else if (reason === 'roomgone') {
            text = this.str('errorliveroomgone');
        } else if (FAILURES[reason]) {
            text = this.str(FAILURES[reason]);
        } else {
            text = this.str('livejoinfailed');
        }
        this.teardown('');
        this.statuskey = '';
        this.roomsfailed = false;
        if (reason === 'disabled') {
            this.lockOut('liveoff', true);
            return;
        }
        if (reason === 'limit' && this.roomlist) {
            // The list of rooms, loaded again by teardown(), shows the limit under "Start a new room":
            // say it once to screen readers here, not a second time on the status line.
            this.failureshown = false;
            announce(this.root, text);
            return;
        }
        this.failureshown = true;
        if (this.panel) {
            this.panel.setStatus(text);
        }
        if (reason === 'notallowed' || reason === 'error') {
            // Perhaps the attempt was finished in another window.
            this.checkAttempt();
        }
    }

    /**
     * Remember the room joined.
     *
     * @param {Object|null} room Transport room {groupid, number, name, maxpeers}.
     */
    rememberRoom(room) {
        if (!room || typeof room !== 'object') {
            return;
        }
        const number = Number(room.number);
        if (isFinite(number) && number > 0) {
            this.room = {groupid: Number(room.groupid) || 0, number: Math.floor(number)};
        }
    }

    /**
     * The server closed the room for this user: find out why, where it can be told. A finished
     * attempt ends joining; a room the teacher removed is named on the status line by the next
     * list of rooms; anything else keeps "The live room has been closed.".
     *
     * @param {Object|null} room {groupid, number} of the room that was closed.
     */
    explainClosed(room) {
        this.closedroom = room;
        this.checkAttempt();
    }

    /**
     * Ask the server whether the attempt of this launch is still open; when it is not, joining is
     * no longer offered. A preview has no attempt and is never asked about.
     *
     * @returns {Promise}
     */
    checkAttempt() {
        const attemptid = Number(this.liveconfig.attemptid) || 0;
        if (!attemptid || this.destroyed) {
            return Promise.resolve();
        }
        const pending = new Pending('vrroomruntime_builtin/live/controller:attempt');
        return Promise.resolve(Ajax.call([{
            methodname: 'mod_vrroom_get_attempt_state',
            args: {attemptid: attemptid},
        }])[0]).then((state) => {
            if (attemptEnded(state) && !this.destroyed && !this.joined && !this.joining) {
                this.lockOut('liveattemptended', false);
            }
            return undefined;
        }).catch(() => {
            // Unknown: the general message stays.
            return undefined;
        }).then(() => {
            pending.resolve();
            return undefined;
        });
    }

    /**
     * Stop offering to join, with the reason on the status line.
     *
     * @param {string} key String key of the reason.
     * @param {boolean} retry Keep asking for the list of rooms now and then (live mode may come back).
     */
    lockOut(key, retry) {
        this.lockout = key;
        this.lockoutretry = !!retry;
        this.closedroom = null;
        // Answers of the list that are on their way no longer matter.
        this.stopRoomsTimer();
        this.roomsseq++;
        this.setStatus(key);
        this.showJoining(false);
        if (this.lockoutretry && !this.destroyed) {
            this.scheduleRooms(ROOMS_MAX_INTERVAL);
        }
    }

    /**
     * Offer joining again (live mode was turned on again).
     */
    unlock() {
        const key = this.lockout;
        this.lockout = '';
        this.lockoutretry = false;
        this.showJoining(true);
        if (key && this.statuskey === key) {
            this.setStatus('');
        }
    }

    /**
     * Show or hide the ways to join (the list of rooms, or the single Join button). The hidden
     * attribute is used, so the panel's own showing and hiding (d-none, by joined state) is kept.
     *
     * @param {boolean} show Show.
     */
    showJoining(show) {
        const container = region(this.root, 'vrroom-liveroom');
        if (!container) {
            return;
        }
        container.querySelectorAll('[data-region="vrroom-live-rooms"], [data-action="vrroom-live-join"]').forEach((el) => {
            el.hidden = !show;
        });
    }

    /**
     * Leave the joined state: stop the microphone, forget the people, release the transport.
     *
     * @param {string} statuskey Status string to show ('' keeps the line empty).
     */
    teardown(statuskey) {
        const transport = this.transport;
        const peers = Array.from(this.names.keys());
        this.transport = null;
        this.unbindTransport();
        this.voice.disableMic();
        this.voice.setTransport(null);
        this.joined = false;
        this.joining = false;
        delete this.root.dataset.vrroomLive;
        this.stopTimers();
        if (transport) {
            try {
                transport.dispose();
            } catch (e) {
                // Already disposed.
            }
            // Let the avatar layer drop everyone.
            peers.forEach((peerid) => this.emit('peerleft', {peerid: peerid}));
            this.emit('state', {state: 'left', reason: ''});
        }
        this.names.clear();
        this.peerstates.clear();
        this.firstseen.clear();
        this.roomname = '';
        this.room = null;
        if (this.panel) {
            this.panel.setBusy(false);
            this.panel.setJoined(false);
            this.updateVoiceControls();
        }
        this.setStatus(statuskey);
        // Back to the list of rooms, loaded again at once.
        this.refreshRooms();
    }

    /**
     * Remember a person's name and when they were first seen.
     *
     * @param {Object} peer Transport peer.
     */
    rememberPeer(peer) {
        if (!peer || typeof peer.peerid !== 'string') {
            return;
        }
        this.names.set(peer.peerid, String(peer.name || ''));
        if (!this.firstseen.has(peer.peerid)) {
            this.firstseen.set(peer.peerid, Date.now());
        }
    }

    /**
     * Someone joined.
     *
     * @param {Object} peer Transport peer.
     */
    onPeerJoined(peer) {
        if (!peer || typeof peer.peerid !== 'string') {
            return;
        }
        const known = this.names.has(peer.peerid);
        this.rememberPeer(peer);
        if (!known && this.joined) {
            announce(this.root, this.str('livepeerjoined', this.getPeerName(peer.peerid)));
        }
        this.emit('peerjoined', {peer: peer});
        this.updatePresenceStatus(false);
        this.scheduleRefresh();
    }

    /**
     * Someone's connection changed.
     *
     * @param {Object} peer Transport peer.
     */
    onPeerUpdated(peer) {
        if (!peer || typeof peer.peerid !== 'string') {
            return;
        }
        this.rememberPeer(peer);
        if (peer.connected) {
            // A fresh link: tell the other side where we are.
            this.sendState();
        }
        this.scheduleRefresh();
    }

    /**
     * Someone left.
     *
     * @param {string} peerid Peer id.
     */
    onPeerLeft(peerid) {
        if (typeof peerid !== 'string') {
            return;
        }
        const name = this.getPeerName(peerid);
        const known = this.names.has(peerid);
        this.names.delete(peerid);
        this.peerstates.delete(peerid);
        this.firstseen.delete(peerid);
        if (known && this.joined) {
            announce(this.root, this.str('livepeerleft', name));
        }
        this.emit('peerleft', {peerid: peerid});
        this.updatePresenceStatus(false);
        this.scheduleRefresh();
    }

    /**
     * A decoded data message.
     *
     * @param {Object} detail {peerid, channel, message}
     */
    onMessage(detail) {
        const peerid = detail.peerid;
        const message = detail.message;
        if (typeof peerid !== 'string' || !message) {
            return;
        }
        if (detail.channel === 'pose') {
            this.emit('pose', {peerid: peerid, pose: message});
        } else if (detail.channel === 'state') {
            const previous = this.peerstates.get(peerid);
            this.peerstates.set(peerid, message);
            this.emit('peerstate', {peerid: peerid, state: message});
            if (!previous || previous.m !== message.m || previous.sc !== message.sc || previous.mic !== message.mic) {
                this.scheduleRefresh();
            }
        }
    }

    /**
     * Send the own state (mode, room, microphone) to everyone.
     */
    sendState() {
        if (!this.joined || !this.transport) {
            return;
        }
        this.transport.broadcast('state', {
            t: 's',
            m: this.mode,
            sc: this.mode === 'list' ? '' : this.sceneid,
            mic: this.voice.getMicState(),
        });
    }

    /**
     * Show "Only you are here so far." or "You are in the live room." as people come and go.
     *
     * @param {boolean} force Also replace another status line.
     */
    updatePresenceStatus(force) {
        if (!this.joined) {
            return;
        }
        if (!force && ['livealone', 'livejoined', 'livereconnecting', 'livejoining'].indexOf(this.statuskey) < 0) {
            return;
        }
        this.setStatus(this.transport.getPeers().length ? 'livejoined' : 'livealone');
    }

    /**
     * Show a status string.
     *
     * @param {string} key String key, or '' to clear.
     */
    setStatus(key) {
        this.statuskey = key || '';
        this.failureshown = false;
        this.roomsfailed = false;
        if (this.panel) {
            this.panel.setStatus(key ? this.str(key) : '');
        }
    }

    /**
     * Turn the own microphone on or off (the Mic button).
     */
    toggleMic() {
        if (!this.joined) {
            return;
        }
        if (this.voice.getMicState() !== 'off') {
            this.voice.disableMic();
            return;
        }
        if (!this.voice.canSend()) {
            if (this.panel) {
                this.panel.setStatus(this.str('livemicinsecure'));
            }
            return;
        }
        const pending = new Pending('vrroomruntime_builtin/live/controller:mic');
        this.voice.enableMic().then(() => {
            if (!this.joined) {
                // Left while the browser asked for permission.
                this.voice.disableMic();
            }
            return undefined;
        }).catch(() => {
            this.statuskey = '';
            if (this.panel) {
                this.panel.setStatus(this.str('livemicdenied'));
            }
        }).finally(() => {
            this.updateVoiceControls();
            pending.resolve();
        });
    }

    /**
     * Mute or unmute the own microphone (the Mute button).
     */
    toggleMute() {
        this.voice.setMuted(!this.voice.isMuted());
        this.updateVoiceControls();
    }

    /**
     * Turn push-to-talk on or off.
     *
     * @param {boolean} on On.
     */
    setPushToTalk(on) {
        this.voice.setPushToTalk(on);
        if (this.panel) {
            this.panel.setTalking(false);
        }
        this.updateVoiceControls();
    }

    /**
     * The hold-to-talk button was pressed or released.
     *
     * @param {boolean} on Pressed.
     */
    talk(on) {
        if (!this.voice.isPushToTalk()) {
            return;
        }
        this.voice.talk(on);
        if (this.panel) {
            this.panel.setTalking(on);
        }
    }

    /**
     * Mute or unmute one person for me.
     *
     * @param {string} peerid Peer id.
     */
    toggleMutePeer(peerid) {
        if (!peerid || !this.names.has(peerid)) {
            return;
        }
        this.voice.setPeerMuted(peerid, !this.voice.isPeerMuted(peerid));
        this.scheduleRefresh();
    }

    /**
     * Whether a key event is the push-to-talk key (T) and push-to-talk applies.
     *
     * @param {KeyboardEvent} e The event.
     * @returns {boolean}
     */
    isTalkKey(e) {
        if (!this.joined || !this.voice.isPushToTalk() || e.ctrlKey || e.metaKey || e.altKey) {
            return false;
        }
        if (String(e.key || '').toLowerCase() !== 't' && e.code !== 'KeyT') {
            return false;
        }
        return !isTyping(e.target);
    }

    /**
     * Show the own voice state on the panel.
     */
    updateVoiceControls() {
        if (!this.panel) {
            return;
        }
        this.panel.setVoiceState({
            micstate: this.voice.getMicState(),
            muted: this.voice.isMuted(),
            ptt: this.voice.isPushToTalk(),
        });
    }

    /**
     * Where someone is, as text.
     *
     * @param {string} mode Mode of the state message.
     * @param {string} sceneid Scene id.
     * @returns {string}
     */
    whereText(mode, sceneid) {
        switch (mode) {
            case 'vr':
                return this.str('livewherevr');
            case 'mr':
                return this.str('livewheremr');
            case 'ar':
                return this.str('livewherear');
            case 'list':
                return this.str('livewherelist');
            default: {
                const name = sceneid ? this.getSceneName(sceneid) : '';
                return name ? this.str('livewhereroom', name) : this.str('livewhere3d');
            }
        }
    }

    /**
     * A microphone state as text.
     *
     * @param {string} state 'off', 'on' or 'muted'.
     * @returns {string}
     */
    micText(state) {
        return ['off', 'on', 'muted'].indexOf(state) >= 0 ? this.str('livemicstate_' + state) : '';
    }

    /**
     * Contexts of the participant lines, oneself first.
     *
     * @returns {Object[]}
     */
    participants() {
        if (!this.transport) {
            return [];
        }
        const now = Date.now();
        const list = [];
        const self = this.transport.getSelf();
        if (self) {
            const selfname = this.getPeerName(self.peerid) || String(this.liveconfig.selfname || '');
            list.push({
                peerid: self.peerid,
                name: this.str('liveyou', selfname),
                isself: true,
                kindpreview: self.kind === 'preview',
                where: this.whereText(this.mode, this.sceneid),
                micstate: this.micText(this.voice.getMicState()),
                mutedforme: false,
                mutelabel: '',
            });
        }
        this.transport.getPeers().forEach((peer) => {
            const name = this.getPeerName(peer.peerid) || String(peer.name || '');
            const state = this.peerstates.get(peer.peerid) || null;
            let where;
            if (!peer.connected) {
                const since = this.firstseen.get(peer.peerid) || now;
                where = now - since > CONNECT_TIMEOUT ? this.str('liveconnectfailed', name) : this.str('liveconnecting');
            } else if (state) {
                where = this.whereText(state.m, state.sc);
            } else {
                where = this.str('liveconnecting');
            }
            const muted = this.voice.isPeerMuted(peer.peerid);
            list.push({
                peerid: peer.peerid,
                name: name,
                isself: false,
                kindpreview: peer.kind === 'preview',
                where: where,
                micstate: state && peer.connected ? this.micText(state.mic) : '',
                mutedforme: muted,
                mutelabel: this.str(muted ? 'liveunmutepeer' : 'livemutepeer', name),
            });
        });
        return list;
    }

    /**
     * Re-render the participant list soon (several changes in a row render once).
     */
    scheduleRefresh() {
        if (!this.panel || !this.joined || this.refreshtimer) {
            return;
        }
        this.refreshpending = new Pending('vrroomruntime_builtin/live/controller:refresh');
        this.refreshtimer = window.setTimeout(() => {
            this.refreshtimer = null;
            const pending = this.refreshpending;
            this.refreshpending = null;
            const release = () => {
                if (pending) {
                    pending.resolve();
                }
            };
            if (!this.panel || !this.joined) {
                release();
                return;
            }
            const focused = this.focusedMutePeer();
            this.panel.setParticipants(this.participants()).then(() => {
                this.refocusMutePeer(focused);
                return undefined;
            }).catch(Notification.exception).finally(release);
        }, 100);
    }

    /**
     * The person whose "Mute for me" button has the keyboard focus, or ''.
     *
     * @returns {string} Peer id.
     */
    focusedMutePeer() {
        const container = region(this.root, 'vrroom-liveroom');
        const active = document.activeElement;
        return container && active && container.contains(active) ? mutePeerOf(active) : '';
    }

    /**
     * Give the focus back to a person's "Mute for me" button after the list was drawn again, when
     * the focus was lost with the old button (and nothing else took it meanwhile).
     *
     * @param {string} peerid Peer id from focusedMutePeer().
     */
    refocusMutePeer(peerid) {
        if (!peerid) {
            return;
        }
        const active = document.activeElement;
        if (active && active !== document.body) {
            return;
        }
        const container = region(this.root, 'vrroom-liveroom');
        // The peer id is 32 hex characters (checked by mutePeerOf), safe in a selector.
        const button = container
            ? container.querySelector(`[data-peerid="${peerid}"] [data-action="vrroom-live-mutepeer"]`) : null;
        if (button) {
            button.focus();
        }
    }

    /**
     * Start the state and speaking timers.
     */
    startTimers() {
        this.stopTimers();
        this.statetimer = window.setInterval(() => {
            this.sendState();
            const waiting = this.transport && this.transport.getPeers().some((peer) => !peer.connected);
            if (waiting) {
                this.scheduleRefresh();
            }
        }, STATE_INTERVAL);
        this.speakingtimer = window.setInterval(() => {
            if (!this.panel || !this.transport) {
                return;
            }
            const self = this.transport.getSelf();
            const selfid = self ? self.peerid : '';
            this.panel.setSpeaking((peerid) => (peerid === selfid ? this.voice.isLocalSpeaking() : this.voice.isSpeaking(peerid)));
        }, SPEAKING_INTERVAL);
    }

    /**
     * Stop the timers.
     */
    stopTimers() {
        if (this.statetimer) {
            window.clearInterval(this.statetimer);
            this.statetimer = null;
        }
        if (this.speakingtimer) {
            window.clearInterval(this.speakingtimer);
            this.speakingtimer = null;
        }
        if (this.refreshtimer) {
            window.clearTimeout(this.refreshtimer);
            this.refreshtimer = null;
        }
        if (this.refreshpending) {
            this.refreshpending.resolve();
            this.refreshpending = null;
        }
    }
}

/**
 * Create the live room of a launch.
 *
 * @param {HTMLElement} root The VR room root.
 * @param {Object} config Launch config.
 * @param {Object} session The session.
 * @returns {LiveController|null} null when live mode is not available for this launch.
 */
export const createLiveController = (root, config, session) => {
    if (!config || !config.live || !config.live.available) {
        return null;
    }
    return new LiveController(root, config, session);
};
