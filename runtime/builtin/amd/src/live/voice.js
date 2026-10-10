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
 * Voice and webcams in a live room: the own microphone (with mute and push-to-talk) and the voices
 * of the others, placed in the room with Web Audio so a voice comes from the speaker's avatar; and
 * next to it the own camera (on or off, sent with replaceTrack so switching needs no reconnect)
 * and the videos of the others, reported for their avatars' face screens.
 *
 * Uses only the transport interface (live/transport), so it works with any transport. All Web
 * Audio and WebRTC behaviour here is unverified in this repository's tests (manual check); the
 * video bookkeeping (live/videostate) has node tests.
 *
 * @module     vrroomruntime_builtin/live/voice
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {CAMERA_CONSTRAINTS, createVideoBook} from 'vrroomruntime_builtin/live/videostate';

/** Level above which a voice counts as speaking. */
const SPEAKING_LEVEL = 0.04;

/** How long a voice keeps counting as speaking after it dropped below the level (ms). */
const SPEAKING_HOLD = 300;

/** Minimum time between two level measurements of one voice (ms). */
const LEVEL_INTERVAL = 30;

/** Scale from the RMS of the signal to the 0..1 level. */
const LEVEL_SCALE = 4;

/** Panner settings: a voice is full volume within 1.5 m and fades with distance. */
const PANNER = {
    panningModel: 'HRTF',
    distanceModel: 'inverse',
    refDistance: 1.5,
    maxDistance: 20,
    rolloffFactor: 1,
};

/**
 * Rotate a vector by a unit quaternion.
 *
 * @param {number[]} v Vector [x, y, z].
 * @param {number[]} q Quaternion [x, y, z, w].
 * @returns {number[]}
 */
const rotate = (v, q) => {
    const [x, y, z] = v;
    const [qx, qy, qz, qw] = q;
    // T = 2 * cross(q.xyz, v); v' = v + w * t + cross(q.xyz, t).
    const tx = 2 * (qy * z - qz * y);
    const ty = 2 * (qz * x - qx * z);
    const tz = 2 * (qx * y - qy * x);
    return [
        x + qw * tx + (qy * tz - qz * ty),
        y + qw * ty + (qz * tx - qx * tz),
        z + qw * tz + (qx * ty - qy * tx),
    ];
};

/**
 * Whether a value is a list of finite numbers of the given length.
 *
 * @param {*} value The value.
 * @param {number} length Expected length.
 * @returns {boolean}
 */
const isVector = (value, length) => Array.isArray(value) && value.length >= length
    && value.slice(0, length).every((n) => typeof n === 'number' && isFinite(n));

/**
 * Set an AudioParam-style property, with the older setter as a fallback.
 *
 * @param {Object} node PannerNode or AudioListener.
 * @param {string[]} params Names of the AudioParams, e.g. ['positionX', 'positionY', 'positionZ'].
 * @param {number[]} values Values.
 * @param {Function} fallback Called with the values when the AudioParams do not exist.
 * @param {number} time Context time.
 */
const setParams = (node, params, values, fallback, time) => {
    if (node[params[0]] && typeof node[params[0]].setValueAtTime === 'function') {
        params.forEach((name, i) => {
            node[name].setValueAtTime(values[i], time);
        });
        return;
    }
    fallback(values);
};

/**
 * Voice chat over one transport.
 */
class Voice {
    /**
     * Constructor.
     *
     * @param {Object} transport A live/transport Transport.
     * @param {Object} [options] {video: boolean} webcams are allowed in this room (live config 'video').
     */
    constructor(transport, options) {
        this.ctx = null;
        this.voices = new Map();
        this.mutedpeers = new Set();
        this.listeners = new Map();
        this.track = null;
        this.stream = null;
        this.local = null;
        this.muted = false;
        this.ptt = false;
        this.talking = false;
        this.spatial = true;
        this.micstate = 'off';
        this.requesting = null;
        this.unlocked = false;
        this.disposed = false;
        this.transport = null;
        this.unsubscribe = [];
        // The camera: allowed by the live config, the own track, and the others' videos.
        this.videoallowed = !!(options && options.video === true);
        this.camtrack = null;
        this.camstream = null;
        this.camrequesting = null;
        this.camstate = 'off';
        this.videos = createVideoBook((peerid, stream) => this.emit('video', {peerid: peerid, stream: stream}),
            this.videoallowed);
        this.setTransport(transport);
    }

    /**
     * Use another transport (a new one is made for every join); the microphone track moves along.
     *
     * @param {Object|null} transport A live/transport Transport, or null.
     */
    setTransport(transport) {
        this.unsubscribe.forEach((fn) => fn());
        this.unsubscribe = [];
        Array.from(this.voices.keys()).forEach((peerid) => this.removeVoice(peerid));
        // Peer ids are new for every join: the videos of the old room go (reported as gone).
        this.videos.clear();
        this.transport = transport || null;
        if (!this.transport) {
            return;
        }
        this.unsubscribe.push(this.transport.on('track', (detail) => this.addVoice(detail.peerid, detail.stream)));
        this.unsubscribe.push(this.transport.on('trackended', (detail) => this.removeVoice(detail.peerid)));
        this.unsubscribe.push(this.transport.on('peerleft', (detail) => {
            this.removeVoice(detail.peerid);
            this.videos.remove(detail.peerid);
        }));
        this.unsubscribe.push(this.transport.on('video', (detail) => {
            if (detail && typeof detail.peerid === 'string') {
                this.videos.setTrack(detail.peerid, detail.stream || null, !!detail.flowing);
            }
        }));
        if (this.track) {
            this.applyTrack();
        }
        if (this.camtrack) {
            this.applyCamTrack();
        }
    }

    /**
     * Create or resume the audio context. Call only inside a user gesture (the Join click).
     */
    ensureAudio() {
        if (this.disposed) {
            return;
        }
        this.unlocked = true;
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                // No Web Audio: voices play through their media elements, without placement.
                this.attachPending();
                return;
            }
            try {
                this.ctx = new AudioCtx();
            } catch (e) {
                this.ctx = null;
                this.attachPending();
                return;
            }
        }
        if (this.ctx.state === 'suspended' && typeof this.ctx.resume === 'function') {
            Promise.resolve(this.ctx.resume()).catch(() => undefined);
        }
        this.attachPending();
        if (this.track && !this.local) {
            this.local = this.createLocalAnalyser(this.track);
        }
    }

    /**
     * Whether this page can send the microphone at all (a secure context with getUserMedia).
     *
     * @returns {boolean}
     */
    canSend() {
        return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    /**
     * Turn the microphone on.
     *
     * @returns {Promise} Rejects when the page is not secure or the browser refuses the microphone.
     */
    enableMic() {
        if (this.disposed) {
            return Promise.reject(new Error('disposed'));
        }
        if (!this.canSend()) {
            return Promise.reject(new Error('insecure'));
        }
        if (this.track) {
            return Promise.resolve();
        }
        if (this.requesting) {
            return this.requesting;
        }
        this.requesting = Promise.resolve(navigator.mediaDevices.getUserMedia({
            audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true},
            video: false,
        })).then((stream) => {
            this.requesting = null;
            const track = stream.getAudioTracks()[0] || null;
            if (!track || this.disposed) {
                stream.getTracks().forEach((t) => t.stop());
                throw new Error('notrack');
            }
            this.stream = stream;
            this.track = track;
            track.addEventListener('ended', () => {
                if (this.track === track) {
                    this.disableMic();
                }
            });
            this.applyEnabled();
            if (this.ctx) {
                this.local = this.createLocalAnalyser(track);
            }
            this.updateMicState();
            return this.applyTrack();
        }).catch((error) => {
            this.requesting = null;
            throw error;
        });
        return this.requesting;
    }

    /**
     * Turn the microphone off and stop the track.
     */
    disableMic() {
        const track = this.track;
        this.track = null;
        if (this.local) {
            this.disconnect(this.local.source);
            this.local = null;
        }
        if (this.stream) {
            this.stream.getTracks().forEach((t) => t.stop());
            this.stream = null;
        } else if (track) {
            track.stop();
        }
        this.talking = false;
        if (this.transport) {
            Promise.resolve(this.transport.setLocalAudio(null)).catch(() => undefined);
        }
        this.updateMicState();
    }

    /**
     * Whether webcams can be used in this room at all: allowed by the live config, and the
     * transport sends video.
     *
     * @returns {boolean}
     */
    isVideoAllowed() {
        if (!this.videoallowed) {
            return false;
        }
        const caps = this.transport ? this.transport.capabilities : null;
        return !this.transport || !!(caps && caps.video);
    }

    /**
     * Whether this page can send the camera (a secure context with getUserMedia).
     *
     * @returns {boolean}
     */
    canSendVideo() {
        return this.videoallowed && this.canSend();
    }

    /**
     * Turn the camera on (about 320×240 at 15 fps). The video is sent by replacing the track of
     * the video transceiver each link already has, so others see it fade in on the same avatar.
     *
     * @returns {Promise} Rejects with Error('insecure'), Error('notallowed'), Error('notrack') or
     *     the getUserMedia error (NotAllowedError, NotFoundError, NotReadableError ...).
     */
    enableCam() {
        if (this.disposed) {
            return Promise.reject(new Error('disposed'));
        }
        if (!this.isVideoAllowed()) {
            return Promise.reject(new Error('notallowed'));
        }
        if (!this.canSend()) {
            return Promise.reject(new Error('insecure'));
        }
        if (this.camtrack) {
            return Promise.resolve();
        }
        if (this.camrequesting) {
            return this.camrequesting;
        }
        this.camrequesting = Promise.resolve(navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                width: Object.assign({}, CAMERA_CONSTRAINTS.width),
                height: Object.assign({}, CAMERA_CONSTRAINTS.height),
                frameRate: Object.assign({}, CAMERA_CONSTRAINTS.frameRate),
                facingMode: CAMERA_CONSTRAINTS.facingMode,
            },
        })).then((stream) => {
            this.camrequesting = null;
            const track = stream.getVideoTracks()[0] || null;
            if (!track || this.disposed) {
                stream.getTracks().forEach((t) => t.stop());
                throw new Error('notrack');
            }
            if ('contentHint' in track) {
                try {
                    // A face: keep it sharp rather than smooth when the bitrate is low.
                    track.contentHint = 'detail';
                } catch (e) {
                    // Not supported.
                }
            }
            this.camstream = stream;
            this.camtrack = track;
            track.addEventListener('ended', () => {
                // Unplugged, or the permission was taken back.
                if (this.camtrack === track) {
                    this.disableCam();
                }
            });
            this.updateCamState();
            return this.applyCamTrack();
        }).catch((error) => {
            this.camrequesting = null;
            throw error;
        });
        return this.camrequesting;
    }

    /**
     * Turn the camera off: stop the track (the camera light goes out) and send nothing.
     */
    disableCam() {
        const track = this.camtrack;
        this.camtrack = null;
        if (this.camstream) {
            this.camstream.getTracks().forEach((t) => t.stop());
            this.camstream = null;
        } else if (track) {
            track.stop();
        }
        if (this.transport && typeof this.transport.setLocalVideo === 'function') {
            Promise.resolve(this.transport.setLocalVideo(null)).catch(() => undefined);
        }
        this.updateCamState();
    }

    /**
     * The own camera state: 'off' or 'on'.
     *
     * @returns {string}
     */
    getCamState() {
        return this.camtrack ? 'on' : 'off';
    }

    /**
     * The own camera stream (for a self view), or null.
     *
     * @returns {MediaStream|null}
     */
    getLocalVideo() {
        return this.camtrack ? this.camstream : null;
    }

    /**
     * Another person's camera state, from their state message.
     *
     * @param {string} peerid Peer id.
     * @param {boolean} on Their camera is on.
     */
    setPeerCam(peerid, on) {
        this.videos.setCam(peerid, on);
    }

    /**
     * Hide or show one other person's video, for this user only.
     *
     * @param {string} peerid Peer id.
     * @param {boolean} hidden Hidden.
     */
    setPeerVideoHidden(peerid, hidden) {
        this.videos.setHidden(peerid, hidden);
    }

    /**
     * Whether one other person's video is hidden for this user.
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isPeerVideoHidden(peerid) {
        return this.videos.isHidden(peerid);
    }

    /**
     * Whether one other person's camera is on (their last state message).
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isPeerCamOn(peerid) {
        return this.videos.isCamOn(peerid);
    }

    /**
     * The videos shown now.
     *
     * @returns {Array<{peerid: string, stream: MediaStream}>}
     */
    getShownVideos() {
        return this.videos.shown();
    }

    /**
     * Forget the others' videos and "hidden for me" (left the room). Shown videos are reported gone.
     */
    clearVideos() {
        this.videos.clear();
    }

    /**
     * Mute or unmute the own microphone (the track stays, so unmuting is instant).
     *
     * @param {boolean} muted Muted.
     */
    setMuted(muted) {
        this.muted = !!muted;
        this.applyEnabled();
        this.updateMicState();
    }

    /**
     * Whether the own microphone is muted.
     *
     * @returns {boolean}
     */
    isMuted() {
        return this.muted;
    }

    /**
     * Turn push-to-talk on or off. With push-to-talk the microphone sends only while talk(true).
     *
     * @param {boolean} on On.
     */
    setPushToTalk(on) {
        this.ptt = !!on;
        this.talking = false;
        this.applyEnabled();
        this.updateMicState();
    }

    /**
     * Whether push-to-talk is on.
     *
     * @returns {boolean}
     */
    isPushToTalk() {
        return this.ptt;
    }

    /**
     * Press or release the talk key (push-to-talk).
     *
     * @param {boolean} talking Pressed.
     */
    talk(talking) {
        const value = !!talking;
        if (this.talking === value) {
            return;
        }
        this.talking = value;
        this.applyEnabled();
        this.updateMicState();
    }

    /**
     * The own microphone state: 'off', 'on' or 'muted' (push-to-talk not pressed counts as muted).
     *
     * @returns {string}
     */
    getMicState() {
        if (!this.track) {
            return 'off';
        }
        if (this.muted || (this.ptt && !this.talking)) {
            return 'muted';
        }
        return 'on';
    }

    /**
     * Mute or unmute one other person, for this listener only.
     *
     * @param {string} peerid Peer id.
     * @param {boolean} muted Muted.
     */
    setPeerMuted(peerid, muted) {
        if (muted) {
            this.mutedpeers.add(peerid);
        } else {
            this.mutedpeers.delete(peerid);
        }
        const voice = this.voices.get(peerid);
        if (voice) {
            this.applyPeerVolume(voice);
        }
    }

    /**
     * Whether one other person is muted for this listener.
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isPeerMuted(peerid) {
        return this.mutedpeers.has(peerid);
    }

    /**
     * The voice level of one other person, 0..1.
     *
     * @param {string} peerid Peer id.
     * @returns {number}
     */
    getLevel(peerid) {
        const voice = this.voices.get(peerid);
        return voice ? this.measure(voice) : 0;
    }

    /**
     * The level of the own microphone, 0..1 (0 while it sends nothing).
     *
     * @returns {number}
     */
    getLocalLevel() {
        if (!this.local || this.getMicState() !== 'on') {
            return 0;
        }
        return this.measure(this.local);
    }

    /**
     * Whether one other person is speaking (level above the threshold, held for 300 ms).
     *
     * @param {string} peerid Peer id.
     * @returns {boolean}
     */
    isSpeaking(peerid) {
        const voice = this.voices.get(peerid);
        if (!voice) {
            return false;
        }
        this.measure(voice);
        return Date.now() < voice.speakinguntil;
    }

    /**
     * Whether the own microphone picks up speech.
     *
     * @returns {boolean}
     */
    isLocalSpeaking() {
        if (!this.local || this.getMicState() !== 'on') {
            return false;
        }
        this.measure(this.local);
        return Date.now() < this.local.speakinguntil;
    }

    /**
     * Place the voices in the room (true) or play them flat (false: list view, AR).
     *
     * @param {boolean} spatial Spatial.
     */
    setSpatial(spatial) {
        const value = !!spatial;
        if (this.spatial === value) {
            return;
        }
        this.spatial = value;
        this.voices.forEach((voice) => this.route(voice));
    }

    /**
     * Where the listener is, in room coordinates.
     *
     * @param {number[]} pos Position [x, y, z].
     * @param {number[]} quat Orientation [x, y, z, w].
     */
    setListenerPose(pos, quat) {
        if (!this.ctx || !isVector(pos, 3) || !isVector(quat, 4)) {
            return;
        }
        const listener = this.ctx.listener;
        const time = this.ctx.currentTime;
        const forward = rotate([0, 0, -1], quat);
        const up = rotate([0, 1, 0], quat);
        setParams(listener, ['positionX', 'positionY', 'positionZ'], pos.slice(0, 3), (v) => {
            if (typeof listener.setPosition === 'function') {
                listener.setPosition(v[0], v[1], v[2]);
            }
        }, time);
        setParams(listener, ['forwardX', 'forwardY', 'forwardZ', 'upX', 'upY', 'upZ'], forward.concat(up), (v) => {
            if (typeof listener.setOrientation === 'function') {
                listener.setOrientation(v[0], v[1], v[2], v[3], v[4], v[5]);
            }
        }, time);
    }

    /**
     * Where one other person is, in room coordinates (their head).
     *
     * @param {string} peerid Peer id.
     * @param {number[]} pos Position [x, y, z].
     * @param {number[]} quat Orientation [x, y, z, w].
     */
    setPeerPose(peerid, pos, quat) {
        const voice = this.voices.get(peerid);
        if (!voice || !voice.panner || !isVector(pos, 3)) {
            return;
        }
        const panner = voice.panner;
        const time = this.ctx.currentTime;
        setParams(panner, ['positionX', 'positionY', 'positionZ'], pos.slice(0, 3), (v) => {
            if (typeof panner.setPosition === 'function') {
                panner.setPosition(v[0], v[1], v[2]);
            }
        }, time);
        if (isVector(quat, 4)) {
            const forward = rotate([0, 0, -1], quat);
            setParams(panner, ['orientationX', 'orientationY', 'orientationZ'], forward, (v) => {
                if (typeof panner.setOrientation === 'function') {
                    panner.setOrientation(v[0], v[1], v[2]);
                }
            }, time);
        }
    }

    /**
     * Listen for voice events: 'micstate' {state}, 'camstate' {state, stream} (stream: the own
     * camera or null) and 'video' {peerid, stream} (someone's video to show, or null to show
     * their designed face again).
     *
     * @param {string} type Event type.
     * @param {Function} fn Listener.
     * @returns {Function} Unsubscribe.
     */
    on(type, fn) {
        if (!this.listeners.has(type)) {
            this.listeners.set(type, new Set());
        }
        this.listeners.get(type).add(fn);
        return () => {
            const set = this.listeners.get(type);
            if (set) {
                set.delete(fn);
            }
        };
    }

    /**
     * Stop the microphone, release every voice and close the audio context.
     */
    dispose() {
        if (this.disposed) {
            return;
        }
        this.disableMic();
        this.disableCam();
        this.setTransport(null);
        this.videos.clear();
        this.disposed = true;
        this.listeners.clear();
        if (this.ctx && typeof this.ctx.close === 'function') {
            Promise.resolve(this.ctx.close()).catch(() => undefined);
        }
        this.ctx = null;
    }

    /**
     * Tell listeners about a micstate change.
     *
     * @param {string} type Event type.
     * @param {Object} detail Detail.
     */
    emit(type, detail) {
        const set = this.listeners.get(type);
        if (!set) {
            return;
        }
        Array.from(set).forEach((fn) => {
            try {
                fn(detail);
            } catch (e) {
                window.console.error(e);
            }
        });
    }

    /**
     * Recompute the mic state and announce a change.
     */
    updateMicState() {
        const state = this.getMicState();
        if (state !== this.micstate) {
            this.micstate = state;
            this.emit('micstate', {state: state});
        }
    }

    /**
     * Recompute the camera state and announce a change.
     */
    updateCamState() {
        const state = this.getCamState();
        if (state !== this.camstate) {
            this.camstate = state;
            this.emit('camstate', {state: state, stream: this.getLocalVideo()});
        }
    }

    /**
     * Hand the current camera track to the transport.
     *
     * @returns {Promise}
     */
    applyCamTrack() {
        if (!this.transport || typeof this.transport.setLocalVideo !== 'function') {
            return Promise.resolve();
        }
        return Promise.resolve(this.transport.setLocalVideo(this.camtrack)).catch((e) => {
            window.console.error(e);
        });
    }

    /**
     * Enable the track only while it should send.
     */
    applyEnabled() {
        if (this.track) {
            this.track.enabled = !this.muted && (!this.ptt || this.talking);
        }
    }

    /**
     * Hand the current track to the transport.
     *
     * @returns {Promise}
     */
    applyTrack() {
        if (!this.transport) {
            return Promise.resolve();
        }
        return Promise.resolve(this.transport.setLocalAudio(this.track)).catch((e) => {
            window.console.error(e);
        });
    }

    /**
     * An analyser on the own microphone (not connected to the speakers).
     *
     * @param {MediaStreamTrack} track The track.
     * @returns {Object|null}
     */
    createLocalAnalyser(track) {
        if (!this.ctx) {
            return null;
        }
        try {
            const source = this.ctx.createMediaStreamSource(new MediaStream([track]));
            const analyser = this.ctx.createAnalyser();
            analyser.fftSize = 512;
            source.connect(analyser);
            return {source: source, analyser: analyser, level: 0, measured: 0, speakinguntil: 0, data: null};
        } catch (e) {
            return null;
        }
    }

    /**
     * A remote voice arrived.
     *
     * @param {string} peerid Peer id.
     * @param {MediaStream} stream The stream.
     */
    addVoice(peerid, stream) {
        if (this.disposed || !stream) {
            return;
        }
        const existing = this.voices.get(peerid);
        if (existing && existing.stream === stream) {
            return;
        }
        if (existing) {
            this.removeVoice(peerid);
        }
        // Chrome plays a remote WebRTC stream through Web Audio only when a media element also
        // holds it (known issue, unverified here): a muted element does that.
        const audio = document.createElement('audio');
        audio.muted = true;
        audio.autoplay = true;
        audio.setAttribute('playsinline', '');
        try {
            audio.srcObject = stream;
        } catch (e) {
            // Very old browsers: no srcObject; nothing can be played then.
        }
        const voice = {
            peerid: peerid,
            stream: stream,
            audio: audio,
            source: null,
            analyser: null,
            gain: null,
            panner: null,
            attached: false,
            level: 0,
            measured: 0,
            speakinguntil: 0,
            data: null,
        };
        this.voices.set(peerid, voice);
        this.attach(peerid, voice);
    }

    /**
     * Connect every voice that arrived before the audio context existed.
     */
    attachPending() {
        this.voices.forEach((voice, peerid) => this.attach(peerid, voice));
    }

    /**
     * Build the audio graph of one voice: source, analyser, gain (mute for me), panner.
     *
     * Before ensureAudio() nothing is played.
     *
     * @param {string} peerid Peer id.
     * @param {Object} voice The voice record.
     */
    attach(peerid, voice) {
        if (voice.attached) {
            return;
        }
        if (!this.unlocked) {
            // Waits for ensureAudio().
            return;
        }
        if (!this.ctx) {
            // No Web Audio: play through the element itself, without placement.
            voice.attached = true;
            voice.audio.muted = this.mutedpeers.has(peerid);
            Promise.resolve(voice.audio.play()).catch(() => undefined);
            return;
        }
        try {
            voice.source = this.ctx.createMediaStreamSource(voice.stream);
            voice.analyser = this.ctx.createAnalyser();
            voice.analyser.fftSize = 512;
            voice.gain = this.ctx.createGain();
            voice.panner = this.ctx.createPanner();
            Object.keys(PANNER).forEach((key) => {
                voice.panner[key] = PANNER[key];
            });
            voice.source.connect(voice.analyser);
            voice.analyser.connect(voice.gain);
            voice.attached = true;
            this.route(voice);
            this.applyPeerVolume(voice);
            Promise.resolve(voice.audio.play()).catch(() => undefined);
        } catch (e) {
            window.console.error(e);
        }
    }

    /**
     * Send a voice through the panner (spatial) or straight to the speakers.
     *
     * @param {Object} voice The voice record.
     */
    route(voice) {
        if (!voice.gain || !this.ctx) {
            return;
        }
        this.disconnect(voice.gain);
        this.disconnect(voice.panner);
        if (this.spatial && voice.panner) {
            voice.gain.connect(voice.panner);
            voice.panner.connect(this.ctx.destination);
        } else {
            voice.gain.connect(this.ctx.destination);
        }
    }

    /**
     * Apply "mute for me" to one voice.
     *
     * @param {Object} voice The voice record.
     */
    applyPeerVolume(voice) {
        const muted = this.mutedpeers.has(voice.peerid);
        if (voice.gain && this.ctx) {
            voice.gain.gain.setValueAtTime(muted ? 0 : 1, this.ctx.currentTime);
        } else if (voice.attached) {
            voice.audio.muted = muted;
        }
    }

    /**
     * Release one remote voice.
     *
     * @param {string} peerid Peer id.
     */
    removeVoice(peerid) {
        const voice = this.voices.get(peerid);
        if (!voice) {
            return;
        }
        this.voices.delete(peerid);
        this.disconnect(voice.source);
        this.disconnect(voice.analyser);
        this.disconnect(voice.gain);
        this.disconnect(voice.panner);
        try {
            voice.audio.pause();
            voice.audio.srcObject = null;
        } catch (e) {
            // Nothing to release.
        }
    }

    /**
     * Disconnect an audio node, ignoring nodes that are not connected.
     *
     * @param {AudioNode|null} node The node.
     */
    disconnect(node) {
        if (!node) {
            return;
        }
        try {
            node.disconnect();
        } catch (e) {
            // Not connected.
        }
    }

    /**
     * Measure the level of a voice (RMS of the waveform, smoothed), at most every 30 ms.
     *
     * @param {Object} voice A voice or the local analyser record.
     * @returns {number} 0..1
     */
    measure(voice) {
        if (!voice.analyser) {
            return 0;
        }
        const now = Date.now();
        if (now - voice.measured < LEVEL_INTERVAL) {
            return voice.level;
        }
        voice.measured = now;
        const analyser = voice.analyser;
        let sum = 0;
        let count = 0;
        if (typeof analyser.getFloatTimeDomainData === 'function') {
            if (!voice.data || voice.data.length !== analyser.fftSize) {
                voice.data = new Float32Array(analyser.fftSize);
            }
            analyser.getFloatTimeDomainData(voice.data);
            for (let i = 0; i < voice.data.length; i++) {
                sum += voice.data[i] * voice.data[i];
            }
            count = voice.data.length;
        } else {
            if (!voice.data || voice.data.length !== analyser.fftSize) {
                voice.data = new Uint8Array(analyser.fftSize);
            }
            analyser.getByteTimeDomainData(voice.data);
            for (let i = 0; i < voice.data.length; i++) {
                const v = (voice.data[i] - 128) / 128;
                sum += v * v;
            }
            count = voice.data.length;
        }
        const rms = count ? Math.sqrt(sum / count) : 0;
        const target = Math.min(1, rms * LEVEL_SCALE);
        // Fast attack, slow release.
        voice.level = target > voice.level ? voice.level + (target - voice.level) * 0.6 : voice.level * 0.85 + target * 0.15;
        if (voice.level > SPEAKING_LEVEL) {
            voice.speakinguntil = now + SPEAKING_HOLD;
        }
        return voice.level;
    }
}

/**
 * Create the voice chat (and webcams) of a live room.
 *
 * @param {Object} transport A live/transport Transport.
 * @param {Object} [options] {video: boolean} webcams are allowed in this room.
 * @returns {Voice}
 */
export const createVoice = (transport, options) => new Voice(transport, options);
