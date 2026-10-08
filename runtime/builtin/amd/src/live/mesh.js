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
 * Mesh transport: one direct WebRTC connection between every pair of browsers in a live room.
 *
 * Signalling (offers, answers, ICE candidates) goes through Moodle (live/signalling). Each link
 * has two negotiated data channels (pose: unordered, no retransmits; state: reliable) and one audio
 * transceiver from the start, so the microphone is switched with replaceTrack and never needs a
 * renegotiation. Links use perfect negotiation (the polite peer has the greater peer id) and carry
 * a generation number: a higher generation replaces a link, a lower one is ignored.
 *
 * Peers see each other's IP addresses (ICE candidates); the activity UI and the privacy metadata
 * say so.
 *
 * The per-peer maps of media and data connections follow mod_gear's collaborative mode; PeerJS
 * is not used.
 *
 * @module     mavrruntime_builtin/live/mesh
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {Transport} from 'mavrruntime_builtin/live/transport';
import {encode, decode} from 'mavrruntime_builtin/live/codec';
import {createSignalling, RECONNECTING_AFTER} from 'mavrruntime_builtin/live/signalling';

/** Skip a peer's pose channel while this many bytes wait to be sent. */
const POSE_BUFFER_LIMIT = 16384;

/** Pose messages accepted per peer per second. */
const MAX_POSE_PER_SECOND = 30;

/** Local ICE candidates are batched for this long (ms). */
const CANDIDATE_BATCH_DELAY = 100;

/** Candidates per signal (server limit). */
const MAX_CANDIDATES_PER_SIGNAL = 30;

/** After a failure and an ICE restart, wait this long for 'connected' (ms). */
const RESTART_GRACE = 15000;

/** A new link that has not connected after this long is re-created (ms). */
const CONNECT_TIMEOUT = 20000;

/** Re-creations of one peer's link before giving up until something changes. */
const MAX_REGENERATIONS = 5;

/** A 'gone' poll within this time of the last good poll means another window took over (ms). */
const REPLACED_WINDOW = 15000;

/** Ask for fresh TURN credentials when they expire within this many seconds. */
const ICE_REFRESH_MARGIN = 600;

/** Do not ask for fresh ICE servers more often than this (ms). */
const ICE_REFRESH_RETRY = 60000;

/** Largest generation number (server validator). */
const MAX_GEN = 1000000;

/** Data channel settings (contract §3.5). */
const CHANNELS = Object.freeze({
    pose: Object.freeze({negotiated: true, id: 0, ordered: false, maxRetransmits: 0}),
    state: Object.freeze({negotiated: true, id: 1, ordered: true}),
});

/** Server error codes → state reasons. */
const REASONS = Object.freeze({
    errorliveroomfull: 'full',
    errorlivedisabled: 'disabled',
    errorlivenoroom: 'noroom',
    errorlivebusy: 'busy',
    errorlivenotallowed: 'notallowed',
    network: 'network',
});

/**
 * Map an error to a state reason.
 *
 * @param {*} error Error with errorcode (from signalling).
 * @returns {string}
 */
const reasonFor = (error) => {
    const code = error && typeof error.errorcode === 'string' ? error.errorcode : '';
    return Object.prototype.hasOwnProperty.call(REASONS, code) ? REASONS[code] : 'error';
};

/**
 * An Error for a failed join.
 *
 * @param {string} reason State reason.
 * @param {*} [cause] Original error.
 * @returns {Error} With reason, errorcode and cause.
 */
const joinError = (reason, cause) => {
    const error = new Error(cause && cause.message ? cause.message : reason);
    error.reason = reason;
    error.errorcode = cause && cause.errorcode ? cause.errorcode : reason;
    error.cause = cause;
    return error;
};

/**
 * Convert server ICE servers to RTCIceServer objects.
 *
 * @param {Array} list Servers from live_join / live_poll.
 * @returns {Array<Object>}
 */
const toIceServers = (list) => (Array.isArray(list) ? list : []).filter((server) => server
        && Array.isArray(server.urls) && server.urls.length).map((server) => {
    const out = {urls: server.urls.filter((url) => typeof url === 'string')};
    if (server.username) {
        out.username = String(server.username);
    }
    if (server.credential) {
        out.credential = String(server.credential);
    }
    return out;
}).filter((server) => server.urls.length);

/**
 * Whether a value is a valid generation number.
 *
 * @param {*} gen
 * @returns {boolean}
 */
const isGen = (gen) => Number.isInteger(gen) && gen >= 0 && gen <= MAX_GEN;

/**
 * Copy a peer for listeners.
 *
 * @param {Object} peer
 * @returns {Object}
 */
const copyPeer = (peer) => ({peerid: peer.peerid, name: peer.name, kind: peer.kind, connected: peer.connected});

/**
 * Whether a transceiver carries audio and is usable.
 *
 * @param {RTCRtpTransceiver} transceiver
 * @returns {boolean}
 */
const isAudioTransceiver = (transceiver) => !transceiver.stopped && transceiver.currentDirection !== 'stopped'
    && transceiver.receiver && transceiver.receiver.track && transceiver.receiver.track.kind === 'audio';

/**
 * One RTCPeerConnection to one remote peer.
 */
class Link {
    /**
     * Constructor.
     *
     * @param {MeshTransport} mesh Owner.
     * @param {string} peerid Remote peer id.
     * @param {number} gen Generation.
     * @param {boolean} initiator Whether this side sends the first offer.
     */
    constructor(mesh, peerid, gen, initiator) {
        this.mesh = mesh;
        this.peerid = peerid;
        this.gen = gen;
        this.initiator = initiator;
        this.polite = mesh.self.peerid > peerid;
        this.makingOffer = false;
        this.ignoreOffer = false;
        // A responding link makes offers of its own (ICE restarts) only after its first answer.
        this.ready = initiator;
        this.closed = false;
        this.connected = false;
        this.stream = null;
        this.queue = Promise.resolve();
        this.candidates = [];
        this.candidatetimer = null;
        this.watchdog = null;
        this.posetimes = [];
        this.pc = new mesh.PeerConnection({iceServers: mesh.iceservers, iceTransportPolicy: 'all'});
        this.channels = {
            pose: this.pc.createDataChannel('pose', CHANNELS.pose),
            state: this.pc.createDataChannel('state', CHANNELS.state),
        };
        Object.keys(this.channels).forEach((name) => {
            const channel = this.channels[name];
            channel.onopen = () => this.updateConnected();
            channel.onclose = () => this.updateConnected();
            channel.onmessage = (e) => this.mesh.onData(this, name, e.data);
        });
        this.pc.onnegotiationneeded = () => this.negotiate();
        this.pc.onicecandidate = (e) => this.queueCandidate(e.candidate);
        this.pc.ontrack = (e) => this.onTrack(e);
        this.pc.onconnectionstatechange = () => this.onConnectionState();
        this.pc.oniceconnectionstatechange = () => {
            if (typeof this.pc.connectionState !== 'string') {
                this.onConnectionState();
            }
        };
        if (initiator) {
            const transceiver = this.pc.addTransceiver('audio', {direction: 'sendrecv'});
            if (mesh.localtrack) {
                Promise.resolve(transceiver.sender.replaceTrack(mesh.localtrack)).catch((error) => Log.debug(error));
            }
        }
        this.arm(CONNECT_TIMEOUT, 'connect');
    }

    /**
     * Start (or restart) the watchdog.
     *
     * @param {number} delay Milliseconds.
     * @param {string} why 'connect' or 'failed'.
     */
    arm(delay, why) {
        this.disarm();
        this.watchdog = setTimeout(() => {
            this.watchdog = null;
            this.mesh.onLinkTimeout(this, why);
        }, delay);
    }

    /**
     * Stop the watchdog.
     */
    disarm() {
        if (this.watchdog) {
            clearTimeout(this.watchdog);
            this.watchdog = null;
        }
    }

    /**
     * The connection state, with a fallback for browsers without connectionState.
     *
     * @returns {string}
     */
    connectionState() {
        if (typeof this.pc.connectionState === 'string') {
            return this.pc.connectionState;
        }
        const ice = this.pc.iceConnectionState;
        return ice === 'completed' ? 'connected' : ice;
    }

    /**
     * React to connection state changes.
     */
    onConnectionState() {
        if (this.closed) {
            return;
        }
        const state = this.connectionState();
        if (state === 'connected') {
            this.disarm();
        } else if (state === 'failed') {
            if (typeof this.pc.restartIce === 'function') {
                try {
                    this.pc.restartIce();
                } catch (error) {
                    Log.debug(error);
                }
            }
            this.arm(RESTART_GRACE, 'failed');
        }
        this.updateConnected();
    }

    /**
     * Recompute "connected": the connection is up and the state channel is open (so the first
     * state message is not lost).
     */
    updateConnected() {
        if (this.closed) {
            return;
        }
        const connected = this.connectionState() === 'connected' && this.channels.state.readyState === 'open';
        if (connected !== this.connected) {
            this.connected = connected;
            this.mesh.onLinkConnected(this, connected);
        }
    }

    /**
     * Send our offer (perfect negotiation: onnegotiationneeded).
     */
    negotiate() {
        // Creating the data channels flags negotiation on a responding link too; its first
        // description must be the answer to the remote offer.
        if (this.closed || !this.ready) {
            return;
        }
        this.makingOffer = true;
        Promise.resolve().then(() => this.pc.setLocalDescription()).then(() => {
            this.sendDescription();
            return;
        }).catch((error) => Log.debug(error)).then(() => {
            this.makingOffer = false;
            return;
        }).catch(() => undefined);
    }

    /**
     * Send the current local description.
     */
    sendDescription() {
        const description = this.pc.localDescription;
        if (this.closed || !description || (description.type !== 'offer' && description.type !== 'answer')) {
            return;
        }
        this.mesh.signal(this.peerid, 'description', {gen: this.gen, type: description.type, sdp: description.sdp});
    }

    /**
     * Process a signal for this link, in order.
     *
     * @param {string} type 'description' or 'candidates'.
     * @param {Object} payload Parsed payload.
     */
    handleSignal(type, payload) {
        this.queue = this.queue.then(() => {
            if (this.closed) {
                return undefined;
            }
            return type === 'description' ? this.onDescription(payload) : this.onCandidates(payload);
        }).catch((error) => {
            if (!this.ignoreOffer) {
                Log.debug(error);
            }
        });
    }

    /**
     * Apply a remote description.
     *
     * @param {Object} payload {gen, type, sdp}.
     * @returns {Promise|undefined}
     */
    onDescription(payload) {
        if ((payload.type !== 'offer' && payload.type !== 'answer') || typeof payload.sdp !== 'string') {
            return undefined;
        }
        const offerCollision = payload.type === 'offer' && (this.makingOffer || this.pc.signalingState !== 'stable');
        this.ignoreOffer = !this.polite && offerCollision;
        if (this.ignoreOffer) {
            return undefined;
        }
        return this.pc.setRemoteDescription({type: payload.type, sdp: payload.sdp}).then(() => {
            if (payload.type !== 'offer' || this.closed) {
                return undefined;
            }
            return this.prepareTransceivers().then(() => this.pc.setLocalDescription()).then(() => {
                this.sendDescription();
                this.ready = true;
                return;
            });
        });
    }

    /**
     * Before answering: every audio transceiver sends and receives, with the current local track.
     *
     * @returns {Promise}
     */
    prepareTransceivers() {
        const promises = [];
        this.pc.getTransceivers().filter(isAudioTransceiver).forEach((transceiver) => {
            try {
                transceiver.direction = 'sendrecv';
            } catch (error) {
                Log.debug(error);
            }
            promises.push(Promise.resolve(transceiver.sender.replaceTrack(this.mesh.localtrack))
                .catch((error) => Log.debug(error)));
        });
        return Promise.all(promises);
    }

    /**
     * Add remote ICE candidates.
     *
     * @param {Object} payload {gen, candidates}.
     * @returns {Promise}
     */
    onCandidates(payload) {
        const list = Array.isArray(payload.candidates) ? payload.candidates : [];
        let chain = Promise.resolve();
        list.forEach((entry) => {
            if (!entry || typeof entry.candidate !== 'string') {
                return;
            }
            const init = {candidate: entry.candidate};
            if (typeof entry.sdpMid === 'string') {
                init.sdpMid = entry.sdpMid;
            }
            if (Number.isInteger(entry.sdpMLineIndex)) {
                init.sdpMLineIndex = entry.sdpMLineIndex;
            }
            if (typeof entry.usernameFragment === 'string' && entry.usernameFragment !== '') {
                init.usernameFragment = entry.usernameFragment;
            }
            chain = chain.then(() => this.pc.addIceCandidate(init)).catch((error) => {
                // End-of-candidates is not understood everywhere; ignored offers bring stray candidates.
                if (!this.ignoreOffer && init.candidate !== '') {
                    Log.debug(error);
                }
            });
        });
        return chain;
    }

    /**
     * Batch a local candidate (null or '' = end of candidates).
     *
     * @param {RTCIceCandidate|null} candidate
     */
    queueCandidate(candidate) {
        if (this.closed) {
            return;
        }
        let entry;
        if (candidate && candidate.candidate) {
            if (candidate.candidate.length > 512) {
                return;
            }
            const mid = typeof candidate.sdpMid === 'string' && /^[A-Za-z0-9_-]{0,32}$/.test(candidate.sdpMid)
                ? candidate.sdpMid : null;
            const index = Number.isInteger(candidate.sdpMLineIndex) && candidate.sdpMLineIndex >= 0
                && candidate.sdpMLineIndex <= 15 ? candidate.sdpMLineIndex : null;
            if (mid === null && index === null) {
                return;
            }
            const ufrag = typeof candidate.usernameFragment === 'string' && candidate.usernameFragment.length <= 64
                ? candidate.usernameFragment : null;
            entry = {candidate: candidate.candidate, sdpMid: mid, sdpMLineIndex: index, usernameFragment: ufrag};
        } else {
            const last = this.candidates[this.candidates.length - 1];
            if (last && last.candidate === '') {
                return;
            }
            entry = {candidate: '', sdpMid: null, sdpMLineIndex: null, usernameFragment: null};
        }
        this.candidates.push(entry);
        if (!this.candidatetimer) {
            this.candidatetimer = setTimeout(() => this.flushCandidates(), CANDIDATE_BATCH_DELAY);
        }
    }

    /**
     * Send batched candidates, at most MAX_CANDIDATES_PER_SIGNAL per signal.
     */
    flushCandidates() {
        this.candidatetimer = null;
        if (this.closed) {
            return;
        }
        while (this.candidates.length) {
            const chunk = this.candidates.splice(0, MAX_CANDIDATES_PER_SIGNAL);
            this.mesh.signal(this.peerid, 'candidates', {gen: this.gen, candidates: chunk});
        }
    }

    /**
     * Remote media arrived.
     *
     * @param {RTCTrackEvent} e
     */
    onTrack(e) {
        if (this.closed || !e.track || e.track.kind !== 'audio') {
            return;
        }
        const stream = e.streams && e.streams[0] ? e.streams[0] : new MediaStream([e.track]);
        if (this.stream === stream) {
            return;
        }
        if (this.stream) {
            this.mesh.onLinkTrackEnded(this);
        }
        this.stream = stream;
        e.track.addEventListener('ended', () => {
            if (this.stream === stream && !this.closed) {
                this.stream = null;
                this.mesh.onLinkTrackEnded(this);
            }
        });
        this.mesh.onLinkTrack(this, stream);
    }

    /**
     * Send data on a channel if it is open.
     *
     * @param {string} name 'pose' or 'state'.
     * @param {string} data Encoded message.
     * @returns {boolean} Whether it was sent.
     */
    sendData(name, data) {
        const channel = this.channels[name];
        if (this.closed || !channel || channel.readyState !== 'open') {
            return false;
        }
        if (name === 'pose' && channel.bufferedAmount >= POSE_BUFFER_LIMIT) {
            return false;
        }
        try {
            channel.send(data);
            return true;
        } catch (error) {
            Log.debug(error);
            return false;
        }
    }

    /**
     * Whether another pose message from this peer may be accepted now.
     *
     * @param {number} now Milliseconds.
     * @returns {boolean}
     */
    acceptPose(now) {
        while (this.posetimes.length && now - this.posetimes[0] >= 1000) {
            this.posetimes.shift();
        }
        if (this.posetimes.length >= MAX_POSE_PER_SECOND) {
            return false;
        }
        this.posetimes.push(now);
        return true;
    }

    /**
     * Switch the local audio track (no renegotiation).
     *
     * @param {MediaStreamTrack|null} track
     * @returns {Promise}
     */
    setTrack(track) {
        if (this.closed) {
            return Promise.resolve();
        }
        return Promise.all(this.pc.getTransceivers().filter(isAudioTransceiver).map((transceiver) =>
            Promise.resolve(transceiver.sender.replaceTrack(track)).catch((error) => Log.debug(error))));
    }

    /**
     * Apply new ICE servers.
     *
     * @param {Array} iceservers
     */
    setIceServers(iceservers) {
        if (this.closed) {
            return;
        }
        try {
            this.pc.setConfiguration({iceServers: iceservers, iceTransportPolicy: 'all'});
        } catch (error) {
            Log.debug(error);
        }
    }

    /**
     * Close everything. Reports the end of the remote stream; the caller handles the peer state.
     */
    close() {
        if (this.closed) {
            return;
        }
        this.closed = true;
        this.disarm();
        if (this.candidatetimer) {
            clearTimeout(this.candidatetimer);
            this.candidatetimer = null;
        }
        this.candidates = [];
        Object.keys(this.channels).forEach((name) => {
            const channel = this.channels[name];
            channel.onopen = null;
            channel.onclose = null;
            channel.onmessage = null;
            try {
                channel.close();
            } catch (error) {
                Log.debug(error);
            }
        });
        this.pc.onnegotiationneeded = null;
        this.pc.onicecandidate = null;
        this.pc.ontrack = null;
        this.pc.onconnectionstatechange = null;
        this.pc.oniceconnectionstatechange = null;
        try {
            this.pc.close();
        } catch (error) {
            Log.debug(error);
        }
        if (this.stream) {
            this.stream = null;
            this.mesh.onLinkTrackEnded(this, true);
        }
        this.connected = false;
    }
}

/**
 * Transport over a mesh of direct browser-to-browser connections.
 */
class MeshTransport extends Transport {
    /**
     * Constructor.
     *
     * @param {Object} liveconfig The launch config live block (§3.1).
     * @param {Object} options {signalling?: Signalling, RTCPeerConnection?: constructor} (tests).
     */
    constructor(liveconfig, options) {
        super();
        this.config = liveconfig || {};
        this.signalling = options.signalling || createSignalling(this.config);
        this.PeerConnection = options.RTCPeerConnection
            || (typeof window !== 'undefined' && window.RTCPeerConnection ? window.RTCPeerConnection : null);
        this.state = 'idle';
        this.self = null;
        this.room = null;
        this.groupid = Number(this.config.defaultgroupid) || 0;
        /** @type {Map<string, Object>} peer id → peer */
        this.peers = new Map();
        /** @type {Map<string, Link>} peer id → link */
        this.links = new Map();
        /** @type {Map<string, number>} peer id → re-creations since the last connection */
        this.regens = new Map();
        this.iceservers = [];
        this.iceexpires = 0;
        this.icerequested = 0;
        this.localtrack = null;
        this.lastok = 0;
        this.joining = null;
        this.rejoining = false;
        this.disposed = false;
        this.unsubscribe = this.signalling.onSendError((detail) => this.onSendError(detail));
    }

    /**
     * Transport kind.
     *
     * @returns {string}
     */
    get kind() {
        return 'mesh';
    }

    /**
     * What this transport can do.
     *
     * @returns {{voice: boolean, maxpeers: number}}
     */
    get capabilities() {
        const maxpeers = this.room ? this.room.maxpeers : this.config.maxpeers;
        return {voice: true, maxpeers: Number(maxpeers) || 0};
    }

    /**
     * The current state (one of STATES).
     *
     * @returns {string}
     */
    getState() {
        return this.state;
    }

    /**
     * Change state and tell listeners.
     *
     * @param {string} state
     * @param {string} reason
     */
    setState(state, reason) {
        this.state = state;
        this.emit('state', {state: state, reason: reason});
    }

    /**
     * Whether the room is in use (joined or reconnecting).
     *
     * @returns {boolean}
     */
    isActive() {
        return this.state === 'joined' || this.state === 'reconnecting';
    }

    /**
     * What join() resolves with.
     *
     * @returns {Object}
     */
    joinInfo() {
        return {
            self: this.getSelf(),
            room: this.room ? {groupid: this.room.groupid, name: this.room.name, maxpeers: this.room.maxpeers} : null,
            peers: this.getPeers(),
        };
    }

    /**
     * Join the room.
     *
     * @param {number|{groupid: number}} [groupid] Group room; the default room when left out.
     * @returns {Promise<Object>} {self, room, peers}; rejects with an Error carrying reason.
     */
    join(groupid) {
        if (this.disposed) {
            return Promise.reject(joinError('closed'));
        }
        if (this.isActive()) {
            return Promise.resolve(this.joinInfo());
        }
        if (this.joining) {
            return this.joining;
        }
        const requested = groupid && typeof groupid === 'object' ? groupid.groupid : groupid;
        if (requested !== undefined && requested !== null && isFinite(Number(requested))) {
            this.groupid = Number(requested);
        }
        if (!this.PeerConnection) {
            this.setState('failed', 'error');
            return Promise.reject(joinError('error'));
        }
        this.setState('joining', '');
        this.joining = this.signalling.join(this.groupid).then((result) => {
            this.joining = null;
            if (this.disposed || this.state !== 'joining') {
                // Left while joining: remove the presence the server just made.
                this.signalling.leave().catch(() => undefined);
                throw joinError('closed');
            }
            this.applyJoin(result);
            this.setState('joined', '');
            return this.joinInfo();
        }, (error) => {
            this.joining = null;
            const reason = reasonFor(error);
            if (this.state === 'joining') {
                this.setState('failed', reason);
            }
            throw joinError(reason, error);
        });
        return this.joining;
    }

    /**
     * Take over a live_join result: self, room, ICE servers, roster, links, poll loop.
     *
     * @param {Object} result live_join result.
     */
    applyJoin(result) {
        this.self = {
            peerid: String(result.peerid),
            name: String(this.config.selfname || ''),
            kind: Number(this.config.attemptid) ? 'attempt' : 'preview',
            connected: true,
        };
        this.groupid = Number(result.groupid) || 0;
        this.room = {groupid: this.groupid, name: String(result.roomname || ''), maxpeers: Number(result.maxpeers) || 0};
        this.iceservers = toIceServers(result.iceservers);
        this.iceexpires = Number(result.iceexpires) || 0;
        this.icerequested = 0;
        this.lastok = Date.now();
        this.regens.clear();
        this.applyRoster(result.peers);
        // The newcomer opens a link to everybody already in the room.
        this.peers.forEach((peer) => this.replaceLink(peer.peerid, 0, true));
        this.signalling.start((poll) => this.onPoll(poll), (failures) => this.onNetworkError(failures));
    }

    /**
     * Bring the peer map in line with a server roster.
     *
     * @param {Array} roster [{peerid, name, kind}] of the others.
     */
    applyRoster(roster) {
        const seen = new Set();
        (Array.isArray(roster) ? roster : []).forEach((entry) => {
            if (!entry || typeof entry.peerid !== 'string' || !entry.peerid || (this.self && entry.peerid === this.self.peerid)) {
                return;
            }
            seen.add(entry.peerid);
            const name = String(entry.name || '');
            const kind = entry.kind === 'preview' ? 'preview' : 'attempt';
            const peer = this.peers.get(entry.peerid);
            if (!peer) {
                const added = {peerid: entry.peerid, name: name, kind: kind, connected: false};
                this.peers.set(entry.peerid, added);
                this.emit('peerjoined', {peer: copyPeer(added)});
            } else if (peer.name !== name || peer.kind !== kind) {
                peer.name = name;
                peer.kind = kind;
                this.emit('peerupdated', {peer: copyPeer(peer)});
            }
        });
        Array.from(this.peers.keys()).forEach((peerid) => {
            if (!seen.has(peerid)) {
                this.removePeer(peerid, 'left');
            }
        });
    }

    /**
     * Remove a peer and its link.
     *
     * @param {string} peerid
     * @param {string} reason
     */
    removePeer(peerid, reason) {
        this.closeLink(peerid, false);
        this.regens.delete(peerid);
        if (this.peers.delete(peerid)) {
            this.emit('peerleft', {peerid: peerid, reason: reason});
        }
    }

    /**
     * Remove every peer and link.
     *
     * @param {string} reason
     */
    removeAllPeers(reason) {
        Array.from(this.peers.keys()).forEach((peerid) => this.removePeer(peerid, reason));
        Array.from(this.links.keys()).forEach((peerid) => this.closeLink(peerid, false));
    }

    /**
     * Close a peer's link.
     *
     * @param {string} peerid
     * @param {boolean} notify Whether to report the peer as no longer connected.
     */
    closeLink(peerid, notify) {
        const link = this.links.get(peerid);
        if (!link) {
            return;
        }
        this.links.delete(peerid);
        link.close();
        const peer = this.peers.get(peerid);
        if (notify && peer && peer.connected) {
            peer.connected = false;
            this.emit('peerupdated', {peer: copyPeer(peer)});
        }
    }

    /**
     * Replace a peer's link with a new one.
     *
     * @param {string} peerid
     * @param {number} gen Generation.
     * @param {boolean} initiator Whether the new link sends the first offer.
     * @returns {Link|null}
     */
    replaceLink(peerid, gen, initiator) {
        this.closeLink(peerid, true);
        if (!this.self || !this.peers.has(peerid)) {
            return null;
        }
        let link = null;
        try {
            link = new Link(this, peerid, gen, initiator);
        } catch (error) {
            Log.error(error);
            return null;
        }
        this.links.set(peerid, link);
        return link;
    }

    /**
     * Handle a poll result.
     *
     * @param {Object} result Poll result (signals parsed).
     */
    onPoll(result) {
        if (!this.isActive() || this.rejoining) {
            return;
        }
        if (result.status === 'closed') {
            this.shutdown('left', 'closed');
            return;
        }
        if (result.status !== 'ok') {
            if (Date.now() - this.lastok < REPLACED_WINDOW) {
                this.shutdown('left', 'replaced');
            } else {
                this.rejoin();
            }
            return;
        }
        this.lastok = Date.now();
        if (this.state === 'reconnecting') {
            this.setState('joined', '');
        }
        this.applyRoster(result.peers);
        result.signals.forEach((signal) => this.onSignal(signal));
        if (result.iceexpires > 0 && result.iceexpires !== this.iceexpires) {
            this.iceservers = toIceServers(result.iceservers);
            this.iceexpires = result.iceexpires;
            this.links.forEach((link) => link.setIceServers(this.iceservers));
        }
        const now = Date.now();
        if (this.iceexpires > 0 && this.iceexpires - now / 1000 < ICE_REFRESH_MARGIN
                && now - this.icerequested > ICE_REFRESH_RETRY) {
            this.icerequested = now;
            this.signalling.refreshIce();
        }
    }

    /**
     * Handle one signal from a roster peer.
     *
     * @param {Object} signal {from, type, payload}.
     */
    onSignal(signal) {
        if (!this.peers.has(signal.from)) {
            return;
        }
        if (signal.type !== 'description' && signal.type !== 'candidates') {
            return;
        }
        const gen = signal.payload.gen;
        if (!isGen(gen)) {
            return;
        }
        let link = this.links.get(signal.from);
        if (!link || gen > link.gen) {
            // The remote side opened a (new) link: answer it.
            link = this.replaceLink(signal.from, gen, false);
        } else if (gen < link.gen) {
            return;
        }
        if (link) {
            link.handleSignal(signal.type, signal.payload);
        }
    }

    /**
     * Poll errors: after RECONNECTING_AFTER in a row, report 'reconnecting'.
     *
     * @param {number} failures Consecutive failures.
     */
    onNetworkError(failures) {
        if (failures >= RECONNECTING_AFTER && this.state === 'joined') {
            this.setState('reconnecting', 'network');
        }
    }

    /**
     * Our presence expired (for example after a long network outage): join again once, with a
     * new peer id; links are re-created.
     */
    rejoin() {
        this.rejoining = true;
        this.setState('reconnecting', 'gone');
        Array.from(this.links.keys()).forEach((peerid) => this.closeLink(peerid, true));
        this.signalling.join(this.groupid).then((result) => {
            this.rejoining = false;
            if (this.disposed || this.state !== 'reconnecting') {
                this.signalling.leave().catch(() => undefined);
                return;
            }
            this.applyJoin(result);
            this.setState('joined', '');
            return;
        }, (error) => {
            this.rejoining = false;
            if (this.state === 'reconnecting') {
                this.shutdown('failed', reasonFor(error));
            }
        }).catch((error) => Log.error(error));
    }

    /**
     * End the session locally: stop polling, close links, forget peers.
     *
     * @param {string} state 'left' or 'failed'.
     * @param {string} reason State reason.
     */
    shutdown(state, reason) {
        this.signalling.stop();
        this.removeAllPeers('left');
        this.self = null;
        this.setState(state, reason);
    }

    /**
     * A refused or lost signal. Negotiation recovers through the link watchdog; the roster
     * removes peers that have gone.
     *
     * @param {Object} detail {to, type, error}.
     */
    onSendError(detail) {
        Log.debug('mavrruntime_builtin/live/mesh: signal ' + detail.type + ' not delivered (' + detail.error + ')');
    }

    /**
     * A link's connected flag changed.
     *
     * @param {Link} link
     * @param {boolean} connected
     */
    onLinkConnected(link, connected) {
        if (this.links.get(link.peerid) !== link) {
            return;
        }
        const peer = this.peers.get(link.peerid);
        if (!peer) {
            return;
        }
        if (connected) {
            this.regens.set(link.peerid, 0);
        }
        if (peer.connected !== connected) {
            peer.connected = connected;
            this.emit('peerupdated', {peer: copyPeer(peer)});
        }
    }

    /**
     * A link did not connect in time: the impolite side (or, for a new link, its initiator)
     * re-creates it with the next generation.
     *
     * @param {Link} link
     * @param {string} why 'connect' or 'failed'.
     */
    onLinkTimeout(link, why) {
        if (link.closed || link.connected || this.links.get(link.peerid) !== link || !this.isActive()) {
            return;
        }
        if (link.polite && !(why === 'connect' && link.initiator)) {
            return;
        }
        const count = (this.regens.get(link.peerid) || 0) + 1;
        if (count > MAX_REGENERATIONS || link.gen >= MAX_GEN) {
            Log.debug('mavrruntime_builtin/live/mesh: giving up on peer ' + link.peerid);
            return;
        }
        this.regens.set(link.peerid, count);
        this.replaceLink(link.peerid, link.gen + 1, true);
    }

    /**
     * A remote audio stream arrived.
     *
     * @param {Link} link
     * @param {MediaStream} stream
     */
    onLinkTrack(link, stream) {
        if (this.links.get(link.peerid) === link) {
            this.emit('track', {peerid: link.peerid, stream: stream});
        }
    }

    /**
     * A remote audio stream ended.
     *
     * @param {Link} link
     * @param {boolean} [closing] Whether the link is being closed (already removed from the map).
     */
    onLinkTrackEnded(link, closing) {
        if (closing || this.links.get(link.peerid) === link) {
            this.emit('trackended', {peerid: link.peerid});
        }
    }

    /**
     * Data from a link's channel.
     *
     * @param {Link} link
     * @param {string} channel 'pose' or 'state'.
     * @param {*} data
     */
    onData(link, channel, data) {
        if (this.links.get(link.peerid) !== link || typeof data !== 'string') {
            return;
        }
        if (channel === 'pose' && !link.acceptPose(Date.now())) {
            return;
        }
        const message = decode(channel, data);
        if (!message) {
            return;
        }
        this.emit('message', {peerid: link.peerid, channel: channel, message: message});
    }

    /**
     * Queue a signal.
     *
     * @param {string} to
     * @param {string} type
     * @param {Object} payload
     */
    signal(to, type, payload) {
        if (this.isActive() && !this.rejoining) {
            this.signalling.send(to, type, payload);
        }
    }

    /**
     * Leave the room. Idempotent; never rejects.
     *
     * @returns {Promise<void>}
     */
    leave() {
        if (this.state === 'idle' || this.state === 'left' || this.state === 'failed') {
            this.signalling.stop();
            return this.signalling.leave();
        }
        const wasjoining = this.state === 'joining';
        this.removeAllPeers('left');
        this.self = null;
        this.setState('left', '');
        if (wasjoining) {
            // The pending join removes its presence when it resolves.
            return Promise.resolve();
        }
        return this.signalling.leave();
    }

    /**
     * Best-effort synchronous leave (pagehide).
     */
    leaveOnUnload() {
        this.signalling.leaveOnUnload();
        if (this.state !== 'idle' && this.state !== 'left' && this.state !== 'failed') {
            this.removeAllPeers('left');
            this.self = null;
            this.setState('left', '');
        }
    }

    /**
     * The local peer while joined.
     *
     * @returns {Object|null}
     */
    getSelf() {
        return this.self ? copyPeer(this.self) : null;
    }

    /**
     * The other peers.
     *
     * @returns {Object[]}
     */
    getPeers() {
        return Array.from(this.peers.values()).map(copyPeer);
    }

    /**
     * Send to every peer whose channel is open.
     *
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message
     */
    broadcast(channel, message) {
        if (!this.isActive() || !this.links.size) {
            return;
        }
        const data = encode(channel, message);
        if (data === null) {
            return;
        }
        this.links.forEach((link) => link.sendData(channel, data));
    }

    /**
     * Send to one peer if its channel is open.
     *
     * @param {string} peerid
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message
     */
    send(peerid, channel, message) {
        const link = this.links.get(peerid);
        if (!this.isActive() || !link) {
            return;
        }
        const data = encode(channel, message);
        if (data !== null) {
            link.sendData(channel, data);
        }
    }

    /**
     * Set the local audio track on every link (replaceTrack, no renegotiation). New links pick it
     * up when they are created or answer.
     *
     * @param {MediaStreamTrack|null} track
     * @returns {Promise<void>}
     */
    setLocalAudio(track) {
        this.localtrack = track || null;
        return Promise.all(Array.from(this.links.values()).map((link) => link.setTrack(this.localtrack)))
            .then(() => undefined);
    }

    /**
     * A peer's remote audio stream.
     *
     * @param {string} peerid
     * @returns {MediaStream|null}
     */
    getRemoteStream(peerid) {
        const link = this.links.get(peerid);
        return link && link.stream ? link.stream : null;
    }

    /**
     * Leave (best effort) and release everything, listeners included.
     */
    dispose() {
        if (this.disposed) {
            return;
        }
        Promise.resolve(this.leave()).catch((error) => Log.debug(error));
        this.disposed = true;
        this.localtrack = null;
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
        this.clear();
    }
}

/**
 * Create a mesh transport.
 *
 * @param {Object} liveconfig The launch config live block (§3.1).
 * @param {Object} [options] {signalling?: Signalling, RTCPeerConnection?: constructor} for tests.
 * @returns {MeshTransport}
 */
export const createMeshTransport = (liveconfig, options) => new MeshTransport(liveconfig, options || {});
