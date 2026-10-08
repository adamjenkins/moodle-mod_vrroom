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
 * @module     mavrruntime_builtin/live/controller
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import {getStrings} from 'core/str';
import {Emitter} from 'mavrruntime_builtin/live/transport';
import {createMeshTransport} from 'mavrruntime_builtin/live/mesh';
import {createVoice} from 'mavrruntime_builtin/live/voice';
import {createPanel} from 'mavrruntime_builtin/live/panel';
import {region, announce, plainText, startScene} from 'mavrruntime_builtin/util';

/** Strings of the live room (all in mavrruntime_builtin). */
const STRINGS = [
    'errorlivebusy', 'errorlivedisabled', 'errorlivenoroom', 'errorlivenotallowed',
    'livealone', 'liveclosed', 'liveconnectfailed', 'liveconnecting', 'livefull', 'livejoined',
    'livejoinfailed', 'livejoining', 'liveleft', 'livemicdenied', 'livemicinsecure', 'livemicoff',
    'livemicon', 'livemicstate_muted', 'livemicstate_off', 'livemicstate_on', 'livemute',
    'livemutepeer', 'liveparticipants', 'livepeerjoined', 'livepeerleft', 'livereconnecting',
    'livereplaced', 'liveunmute', 'liveunmutepeer', 'livewhere3d', 'livewherear', 'livewherelist',
    'livewheremr', 'livewhereroom', 'livewherevr', 'liveyou',
];

/** Transports by config.live.transport. */
const TRANSPORTS = {
    mesh: createMeshTransport,
};

/** Modes a state message may carry. */
const MODES = ['3d', 'vr', 'mr', 'ar', 'list'];

/** How often the own state is sent again, and the panel refreshed (ms). */
const STATE_INTERVAL = 5000;

/** How often the speaking indicators are updated (ms). */
const SPEAKING_INTERVAL = 250;

/** After this long without a connection, a person is shown as "could not connect" (ms). */
const CONNECT_TIMEOUT = 20000;

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
     * @param {HTMLElement} root The MAVR root.
     * @param {Object} config Launch config (with config.live).
     * @param {Object} session The session (mavrruntime_builtin/session).
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
     * Load the strings and render the panel into the mavr-liveroom region.
     *
     * @returns {Promise}
     */
    render() {
        const container = region(this.root, 'mavr-liveroom');
        if (!container || this.destroyed) {
            return Promise.resolve();
        }
        const pending = new Pending('mavrruntime_builtin/live/controller:render');
        return getStrings(STRINGS.map((key) => ({key: key, component: 'mavrruntime_builtin'}))).then((values) => {
            STRINGS.forEach((key, i) => {
                this.strings[key] = values[i];
            });
            this.panel = createPanel(container, {
                cmid: Number(this.liveconfig.cmid) || 0,
                live: this.liveconfig,
                secure: this.voice.canSend(),
                str: (key, a) => this.str(key, a),
                handlers: {
                    join: () => this.join().catch(Notification.exception),
                    leave: () => this.leave().catch(Notification.exception),
                    mic: () => this.toggleMic(),
                    mute: () => this.toggleMute(),
                    ptt: (on) => this.setPushToTalk(on),
                    talk: (on) => this.talk(on),
                    mutepeer: (peerid) => this.toggleMutePeer(peerid),
                },
            });
            return this.panel.render();
        }).then(() => {
            this.updateVoiceControls();
            pending.resolve();
            return undefined;
        }, (error) => {
            pending.resolve();
            throw error;
        });
    }

    /**
     * Join the room. Call inside the Join click: it also starts the audio context.
     *
     * @returns {Promise}
     */
    join() {
        if (this.destroyed || this.joined || this.joining || !this.liveconfig.canjoin) {
            return Promise.resolve();
        }
        // Inside the user gesture: browsers start audio only then.
        this.voice.ensureAudio();
        const factory = TRANSPORTS[this.liveconfig.transport || 'mesh'];
        if (!factory) {
            this.setStatus('livejoinfailed');
            return Promise.resolve();
        }
        const pending = new Pending('mavrruntime_builtin/live/controller:join');
        this.joining = true;
        if (this.panel) {
            this.panel.setBusy(true);
        }
        this.setStatus('livejoining');
        const groupid = this.panel ? this.panel.getGroupId() : 0;
        const liveconfig = Object.assign({}, this.liveconfig, {
            defaultgroupid: groupid || Number(this.liveconfig.defaultgroupid) || 0,
            groupid: groupid || Number(this.liveconfig.defaultgroupid) || 0,
        });
        let transport;
        try {
            transport = factory(liveconfig, {});
        } catch (error) {
            this.joining = false;
            if (this.panel) {
                this.panel.setBusy(false);
            }
            pending.resolve();
            return Promise.reject(error);
        }
        this.bind(transport);
        return Promise.resolve().then(() => transport.join()).then((result) => {
            if (this.transport !== transport) {
                return undefined;
            }
            if (result && result.self) {
                this.names.set(result.self.peerid, String(result.self.name || ''));
            }
            this.onJoined();
            return undefined;
        }).catch((error) => {
            if (this.transport !== transport) {
                return undefined;
            }
            const code = error && error.errorcode ? String(error.errorcode) : '';
            this.onFailed(ERRORCODES[code] || 'error');
            return undefined;
        }).then(() => {
            pending.resolve();
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
        const pending = new Pending('mavrruntime_builtin/live/controller:leave');
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
            this.teardown(keys[reason] || 'liveleft');
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
        this.root.dataset.mavrLive = 'joined';
        const self = this.transport.getSelf();
        if (self) {
            this.names.set(self.peerid, String(self.name || ''));
        }
        this.transport.getPeers().forEach((peer) => this.rememberPeer(peer));
        if (this.panel) {
            this.panel.setBusy(false);
            this.panel.setJoined(true);
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
            text = this.str('livefull', Number(this.liveconfig.maxpeers) || 0);
        } else if (FAILURES[reason]) {
            text = this.str(FAILURES[reason]);
        } else {
            text = this.str('livejoinfailed');
        }
        this.teardown('');
        this.statuskey = '';
        if (this.panel) {
            this.panel.setStatus(text);
        }
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
        delete this.root.dataset.mavrLive;
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
        if (this.panel) {
            this.panel.setBusy(false);
            this.panel.setJoined(false);
            this.updateVoiceControls();
        }
        this.setStatus(statuskey);
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
        const pending = new Pending('mavrruntime_builtin/live/controller:mic');
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
        }).then(() => {
            this.updateVoiceControls();
            pending.resolve();
            return undefined;
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
        this.refreshpending = new Pending('mavrruntime_builtin/live/controller:refresh');
        this.refreshtimer = window.setTimeout(() => {
            this.refreshtimer = null;
            const pending = this.refreshpending;
            this.refreshpending = null;
            const done = () => {
                if (pending) {
                    pending.resolve();
                }
            };
            if (!this.panel || !this.joined) {
                done();
                return;
            }
            this.panel.setParticipants(this.participants()).catch(Notification.exception).then(done, done);
        }, 100);
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
 * @param {HTMLElement} root The MAVR root.
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
