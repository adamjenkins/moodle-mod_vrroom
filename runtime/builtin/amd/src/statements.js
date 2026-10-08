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
 * xAPI statements for the built-in viewer: building (vrroom-vocab/1), UUIDs, queueing, batching
 * and retrying, sent through core_xapi_statement_post.
 *
 * @module     vrroomruntime_builtin/statements
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Config from 'core/config';
import Log from 'core/log';

/** Vocabulary version carried in every statement. */
const VOCAB = 'vrroom-vocab/1';

/** Retry delays in seconds after network failures. */
const BACKOFF = [2, 4, 8, 16, 32];

/** Regular flush interval in milliseconds. */
const FLUSH_INTERVAL = 2000;

/** Queue length that triggers an immediate flush. */
const FLUSH_THRESHOLD = 10;

/**
 * Generate a lowercase version 4 UUID.
 *
 * crypto.randomUUID() exists only in secure contexts, so plain-HTTP sites use the
 * getRandomValues() fallback.
 *
 * @returns {string}
 */
export const uuid = () => {
    const cryptoobj = window.crypto;
    if (cryptoobj && typeof cryptoobj.randomUUID === 'function') {
        try {
            return cryptoobj.randomUUID().toLowerCase();
        } catch (e) {
            // Not a secure context: use the fallback below.
        }
    }
    const bytes = new Uint8Array(16);
    if (cryptoobj && typeof cryptoobj.getRandomValues === 'function') {
        cryptoobj.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    // Version 4 (0100xxxx) and RFC 4122 variant (10xxxxxx), without bitwise operators.
    bytes[6] = (bytes[6] % 16) + 0x40;
    bytes[8] = (bytes[8] % 64) + 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/**
 * Build the object IRI for a statement object (contract §4.3).
 *
 * @param {string} activityiri The activity IRI (config.activityiri).
 * @param {string} type activity, scene, zone or object.
 * @param {string|null} sceneid Scene id.
 * @param {string|null} id Object id.
 * @returns {string}
 */
export const objectIri = (activityiri, type, sceneid, id) => {
    const enc = (v) => encodeURIComponent(String(v));
    switch (type) {
        case 'scene':
            return `${activityiri}/scene/${enc(sceneid || id)}`;
        case 'zone':
            return `${activityiri}/scene/${enc(sceneid)}/zone/${enc(id)}`;
        case 'object':
            return `${activityiri}/scene/${enc(sceneid)}/object/${enc(id)}`;
        default:
            return activityiri;
    }
};

/**
 * Build one client statement. Clients never send a result.
 *
 * @param {Object} config Launch config.
 * @param {string} verb Short verb (initialized, terminated, entered, exited, interacted).
 * @param {string} type Object type.
 * @param {string|null} sceneid Scene id.
 * @param {string|null} id Object id.
 * @param {string} name Display name of the object (plain text), may be empty.
 * @param {string} channel builtin-3d or builtin-list.
 * @returns {Object}
 */
export const buildStatement = (config, verb, type, sceneid, id, name, channel) => {
    const statementid = uuid();
    const ext = config.extbase;
    const object = {
        objectType: 'Activity',
        id: objectIri(config.activityiri, type, sceneid, id),
    };
    if (name) {
        object.definition = {name: {en: String(name)}};
    }
    const verbiri = (config.verbs && config.verbs[verb]) || verb;
    return {
        id: statementid,
        actor: config.actor,
        verb: {id: verbiri, display: {en: verb}},
        object: object,
        context: {
            extensions: {
                [`${ext}attempt-id`]: config.attemptid,
                [`${ext}statement-id`]: statementid,
                [`${ext}vocab`]: VOCAB,
                [`${ext}channel`]: channel,
            },
        },
        timestamp: new Date().toISOString(),
    };
};

/**
 * Create the statement queue.
 *
 * One request in flight; flushes every 2 s, at 10 queued statements, at once for interacted and
 * when the page is hidden. A server error (an object with errorcode) drops the batch; a network
 * failure keeps it and retries after 2, 4, 8, 16 and 32 s, then reports failure and keeps trying
 * every 32 s.
 *
 * @param {Object} config Launch config.
 * @param {Function} onBatchDone Called with {batch, accepted, error}; may return a promise.
 * @param {Object} options {onFailure(failing: boolean)}.
 * @returns {Object} {push, flush, pending, flushBeacon, stop}
 */
export const createQueue = (config, onBatchDone, options = {}) => {
    const maxbatch = Math.max(1, parseInt(config.maxbatch, 10) || 50);
    const queue = [];
    let inflight = null;
    let failures = 0;
    let retryat = 0;
    let stopped = false;
    let reportedfailure = false;

    const removeBatch = (batch) => {
        batch.forEach((statement) => {
            const index = queue.indexOf(statement);
            if (index !== -1) {
                queue.splice(index, 1);
            }
        });
    };

    const recovered = () => {
        failures = 0;
        retryat = 0;
        if (reportedfailure) {
            reportedfailure = false;
            if (options.onFailure) {
                options.onFailure(false);
            }
        }
    };

    const failed = () => {
        failures++;
        const delay = BACKOFF[Math.min(failures, BACKOFF.length) - 1];
        retryat = Date.now() + delay * 1000;
        if (failures >= BACKOFF.length && !reportedfailure) {
            reportedfailure = true;
            if (options.onFailure) {
                options.onFailure(true);
            }
        }
    };

    const send = (force) => {
        if (inflight) {
            return inflight;
        }
        if (stopped || !queue.length) {
            return Promise.resolve();
        }
        if (!force && retryat > Date.now()) {
            return Promise.resolve();
        }
        const batch = queue.slice(0, maxbatch);
        const request = Ajax.call([{
            methodname: 'core_xapi_statement_post',
            args: {component: 'mod_vrroom', requestjson: JSON.stringify(batch)},
        }])[0];
        const promise = Promise.resolve(request)
            .then((accepted) => {
                removeBatch(batch);
                recovered();
                return onBatchDone({batch: batch, accepted: accepted, error: null});
            }, (error) => {
                if (error && error.errorcode) {
                    // The server answered and refused the whole batch: never resend it.
                    removeBatch(batch);
                    recovered();
                    return onBatchDone({batch: batch, accepted: null, error: error});
                }
                failed();
                return undefined;
            })
            .catch((e) => {
                Log.error(e);
            })
            .then(() => {
                inflight = null;
                return undefined;
            });
        inflight = promise;
        return promise;
    };

    const tick = () => {
        send(false);
    };
    const interval = window.setInterval(tick, FLUSH_INTERVAL);

    const flushBeacon = () => {
        if (!queue.length || !navigator.sendBeacon) {
            return;
        }
        const url = `${Config.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(Config.sesskey)}` +
            '&info=core_xapi_statement_post';
        // Statements already in flight are left alone; resending them is harmless (ids are idempotent).
        while (queue.length) {
            const batch = queue.slice(0, maxbatch);
            const body = JSON.stringify([{
                index: 0,
                methodname: 'core_xapi_statement_post',
                args: {component: 'mod_vrroom', requestjson: JSON.stringify(batch)},
            }]);
            if (!navigator.sendBeacon(url, body)) {
                return;
            }
            removeBatch(batch);
        }
    };

    const onVisibility = () => {
        if (document.visibilityState === 'hidden') {
            send(true);
        }
    };
    document.addEventListener('visibilitychange', onVisibility);

    return {
        /**
         * Queue a statement.
         *
         * @param {Object} statement The statement.
         */
        push(statement) {
            if (stopped) {
                return;
            }
            queue.push(statement);
            const isinteracted = statement.verb && statement.verb.display && statement.verb.display.en === 'interacted';
            if (isinteracted || queue.length >= FLUSH_THRESHOLD) {
                send(false);
            }
        },

        /**
         * Send everything queued now, ignoring the retry delay once.
         *
         * @returns {Promise<boolean>} true when the queue is empty afterwards.
         */
        flush() {
            const drain = () => {
                if (stopped || !queue.length) {
                    return Promise.resolve(!queue.length);
                }
                const before = failures;
                const wasinflight = !!inflight;
                return send(true).then(() => {
                    if (!wasinflight && failures > before) {
                        return false;
                    }
                    return drain();
                });
            };
            if (inflight) {
                return inflight.then(drain);
            }
            return drain();
        },

        /**
         * Number of statements waiting to be sent.
         *
         * @returns {number}
         */
        pending() {
            return queue.length;
        },

        /** Send what is left with navigator.sendBeacon (page is going away). */
        flushBeacon: flushBeacon,

        /** Stop timers and listeners. */
        stop() {
            stopped = true;
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibility);
        },
    };
};
