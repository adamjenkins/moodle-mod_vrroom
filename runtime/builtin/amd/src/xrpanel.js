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
 * In-world panels for immersive XR sessions, where the page DOM cannot be seen: hotspot content,
 * true/false and multiple-choice questions, hints and short messages.
 *
 * A panel is a plane with a canvas texture (text drawn with fillText, so no markup is ever
 * interpreted); every button is a separate plane in front of it whose userData.mavrPanelAction
 * names its action (close, question, choice:<i>, submit, retry, hint). The XR input module
 * raycasts against getTargets() and passes a hit to select().
 *
 * Questions come from mod_mavr_get_question_view (plain text, field names and values, never the
 * right answer) and are answered through session.submitResponses(), so the question engine grades
 * them exactly as in the HTML panel. Question types the view does not support show a card that
 * asks the student to leave VR to answer.
 *
 * @module     mavrruntime_builtin/xrpanel
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {getStrings} from 'core/str';
import {plainText, startScene} from 'mavrruntime_builtin/util';

/** Canvas width of a panel in pixels. */
const CANVAS_WIDTH = 1024;

/** Panel width in metres. */
const PANEL_WIDTH = 0.9;

/** Metres per canvas pixel. */
const PX = PANEL_WIDTH / CANVAS_WIDTH;

/** Distance of the panel in front of the camera when it opens (m). */
const DISTANCE = 1.2;

/** A panel further than this from the camera is placed again in front of it (m). */
const REPLACE_DISTANCE = 4;

/** Inner padding of a panel (px). */
const PAD = 40;

/** Gap between elements (px). */
const GAP = 20;

/** Most lines of body text drawn on one panel. */
const MAX_BODY_LINES = 16;

/** Most lines of one choice label. */
const MAX_CHOICE_LINES = 2;

/** Most choices one panel can show; a question with more is answered outside VR. */
const MAX_CHOICES = 8;

/** Height of an action button (px). */
const BUTTON_HEIGHT = 84;

/** Font stack for canvas text. */
const FONT = '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

/** Text styles: font size (px), weight, colour. */
const STYLES = {
    title: {size: 46, weight: 'bold', colour: '#1d2125'},
    body: {size: 34, weight: 'normal', colour: '#1d2125'},
    note: {size: 30, weight: 'normal', colour: '#495057'},
    hint: {size: 30, weight: 'normal', colour: '#664d03'},
    success: {size: 36, weight: 'bold', colour: '#0f5132'},
    warning: {size: 36, weight: 'bold', colour: '#664d03'},
    danger: {size: 36, weight: 'bold', colour: '#842029'},
};

/** Button looks. */
const LOOKS = {
    primary: {fill: '#0f6cbf', stroke: '#0f6cbf', text: '#ffffff'},
    secondary: {fill: '#e9ecef', stroke: '#adb5bd', text: '#1d2125'},
    choice: {fill: '#ffffff', stroke: '#adb5bd', text: '#1d2125'},
    selected: {fill: '#cfe2ff', stroke: '#0f6cbf', text: '#052c65'},
    disabled: {fill: '#e9ecef', stroke: '#dee2e6', text: '#6c757d'},
};

/** Line height factor. */
const LINE = 1.3;

/** Strings the panels use, all from mavrruntime_builtin. */
const STRINGKEYS = [
    'xropenquestion', 'xrsubmit', 'xrclose', 'xrexittoanswer', 'xrcorrect', 'xrpartiallycorrect',
    'xrincorrect', 'xrchooseone', 'xrchoosemany', 'xrloading', 'xranswered', 'gethint', 'tryagain',
    'nomorehints', 'retrynotice', 'questionlocked', 'questionpreview',
];

/**
 * Whether the page is written right to left.
 *
 * @returns {boolean}
 */
const isRtl = () => document.documentElement.getAttribute('dir') === 'rtl';

/**
 * Canvas font string of a style.
 *
 * @param {Object} style A STYLES entry.
 * @returns {string}
 */
const fontOf = (style) => `${style.weight} ${style.size}px ${FONT}`;

/**
 * Plain text of server-formatted HTML with paragraph breaks kept.
 *
 * DOMParser documents are inert: no script runs and no resource loads. The result is only drawn
 * on a canvas.
 *
 * @param {string} html HTML.
 * @returns {string}
 */
const htmlToText = (html) => {
    if (!html) {
        return '';
    }
    const doc = new DOMParser().parseFromString(`<!DOCTYPE html><body>${html}`, 'text/html');
    doc.body.querySelectorAll('script, style, template').forEach((node) => node.remove());
    doc.body.querySelectorAll('br').forEach((node) => node.replaceWith('\n'));
    doc.body.querySelectorAll('p, div, li, h1, h2, h3, h4, h5, h6, tr, blockquote, pre, dt, dd').forEach((node) => {
        node.append('\n');
    });
    return (doc.body.textContent || '')
        .split('\n')
        .map((line) => line.replace(/\s+/g, ' ').trim())
        .filter((line, index, lines) => line !== '' || (index > 0 && lines[index - 1] !== ''))
        .join('\n')
        .trim();
};

/**
 * Break text into lines that fit a width.
 *
 * Explicit line breaks are kept; a word wider than the width is split between characters.
 *
 * @param {CanvasRenderingContext2D} ctx Context with the font set.
 * @param {string} text Text.
 * @param {number} width Maximum line width (px).
 * @returns {string[]}
 */
const wrapLines = (ctx, text, width) => {
    const lines = [];
    String(text).split('\n').forEach((paragraph) => {
        const words = paragraph.split(' ').filter((w) => w !== '');
        if (!words.length) {
            lines.push('');
            return;
        }
        let line = '';
        words.forEach((word) => {
            const candidate = line ? `${line} ${word}` : word;
            if (ctx.measureText(candidate).width <= width) {
                line = candidate;
                return;
            }
            if (line) {
                lines.push(line);
                line = '';
            }
            if (ctx.measureText(word).width <= width) {
                line = word;
                return;
            }
            let part = '';
            Array.from(word).forEach((char) => {
                if (ctx.measureText(part + char).width > width && part) {
                    lines.push(part);
                    part = '';
                }
                part += char;
            });
            line = part;
        });
        if (line) {
            lines.push(line);
        }
    });
    return lines;
};

/**
 * Keep at most max lines, ending the last kept line with an ellipsis when lines were dropped.
 *
 * @param {CanvasRenderingContext2D} ctx Context with the font set.
 * @param {string[]} lines Lines.
 * @param {number} max Maximum number of lines.
 * @param {number} width Line width (px).
 * @returns {string[]}
 */
const clampLines = (ctx, lines, max, width) => {
    if (lines.length <= max) {
        return lines;
    }
    const kept = lines.slice(0, max);
    let last = kept[max - 1];
    while (last && ctx.measureText(`${last}…`).width > width) {
        last = last.slice(0, -1);
    }
    kept[max - 1] = `${last}…`;
    return kept;
};

/**
 * Draw a rounded rectangle path.
 *
 * @param {CanvasRenderingContext2D} ctx Context.
 * @param {number} x Left.
 * @param {number} y Top.
 * @param {number} w Width.
 * @param {number} h Height.
 * @param {number} r Corner radius.
 */
const roundRect = (ctx, x, y, w, h, r) => {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + w - radius, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
    ctx.lineTo(x + w, y + h - radius);
    ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
    ctx.lineTo(x + radius, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
};

/**
 * Result style and string key of a graded question state.
 *
 * @param {string} state Question engine state (gradedright, gradedpartial, gradedwrong, ...).
 * @param {number|null} fraction Fraction awarded.
 * @returns {{key: string, style: string}}
 */
const resultOf = (state, fraction) => {
    if (state === 'gradedright') {
        return {key: 'xrcorrect', style: 'success'};
    }
    if (state === 'gradedpartial') {
        return {key: 'xrpartiallycorrect', style: 'warning'};
    }
    if (state === 'gradedwrong') {
        return {key: 'xrincorrect', style: 'danger'};
    }
    const f = Number(fraction);
    if (fraction === null || fraction === undefined || !Number.isFinite(f)) {
        return {key: 'xranswered', style: 'note'};
    }
    if (f >= 1 - 1e-7) {
        return {key: 'xrcorrect', style: 'success'};
    }
    if (f > 1e-7) {
        return {key: 'xrpartiallycorrect', style: 'warning'};
    }
    return {key: 'xrincorrect', style: 'danger'};
};

/**
 * The in-world panels of one XR session.
 */
class XrPanels {
    /**
     * Constructor.
     *
     * @param {Object} THREE The three.js module.
     * @param {Object} options {parent: Object3D the panel is added to, camera: the XR camera, session}.
     * @param {Object} strings Loaded strings by key.
     */
    constructor(THREE, options, strings) {
        this.THREE = THREE;
        this.parent = options.parent;
        this.camera = options.camera;
        this.session = options.session;
        this.strings = strings;
        this.group = new THREE.Group();
        this.group.name = 'mavr-xr-panel';
        this.group.visible = false;
        this.group.userData.mavrPanel = true;
        this.parent.add(this.group);
        this.meshes = [];
        this.targets = [];
        this.card = null;
        this.context = null;
        this.generation = 0;
        this.appear = 1;
        this.measure = document.createElement('canvas').getContext('2d');
        this.camerapos = new THREE.Vector3();
        this.panelpos = new THREE.Vector3();
        this.unsubscribe = this.session.on((message) => this.onSessionChange(message));
    }

    /**
     * A loaded string.
     *
     * @param {string} key String key.
     * @returns {string}
     */
    str(key) {
        return this.strings[key] || key;
    }

    /**
     * Whether a panel is open.
     *
     * @returns {boolean}
     */
    isOpen() {
        return this.card !== null;
    }

    /**
     * The meshes the controller rays test: the panel and its buttons, while a panel is open.
     *
     * @returns {Object[]}
     */
    getTargets() {
        return this.card ? this.targets.slice() : [];
    }

    /**
     * Open the panel of a hotspot: its title, its text and, when it carries a question, a button
     * that opens it.
     *
     * @param {Object} hotspot A hotspot interactable of the scene document.
     */
    openInteractable(hotspot) {
        const content = (hotspot && hotspot.content) || {};
        const slot = content.question === null || content.question === undefined ? null : Number(content.question);
        const title = plainText(content.title || (hotspot && hotspot.name) || '');
        const buttons = [];
        if (slot !== null) {
            buttons.push({action: 'question', label: this.str('xropenquestion'), look: 'primary'});
        }
        buttons.push({action: 'close', label: this.str('xrclose'), look: 'secondary'});
        this.generation++;
        this.context = {kind: 'hotspot', slot: slot, title: title};
        this.show({
            title: title,
            paragraphs: [{text: htmlToText(content.html || ''), style: 'body'}],
            buttons: buttons,
        });
    }

    /**
     * Open a question by slot.
     *
     * @param {number|string} slot Graph slot number.
     * @returns {Promise<void>}
     */
    openQuestion(slot) {
        const n = Number(slot);
        const generation = ++this.generation;
        const objective = this.session.getObjectiveForSlot(n);
        const title = this.questionTitle(n, objective);
        this.context = {kind: 'question', slot: n, title: title, view: null, selected: new Set(), result: null,
            message: null, busy: false};

        if (this.session.preview) {
            this.showNotice(title, this.str('questionpreview'));
            return Promise.resolve();
        }
        if (!this.session.isSlotAvailable(n)) {
            this.context.locked = true;
            this.showNotice(title, this.str('questionlocked'));
            return Promise.resolve();
        }
        this.showNotice(title, this.str('xrloading'), false);
        return this.loadView(n, generation);
    }

    /**
     * Show a short message with a close button.
     *
     * @param {string} text Plain text.
     */
    showMessage(text) {
        this.generation++;
        this.context = {kind: 'message'};
        this.showNotice('', String(text || ''));
    }

    /**
     * Close the panel.
     */
    close() {
        this.generation++;
        this.card = null;
        this.context = null;
        this.clearMeshes();
        this.group.visible = false;
    }

    /**
     * Handle a controller hit.
     *
     * @param {Object} object3d The object the ray hit.
     * @returns {boolean} Whether the hit belonged to the panel (and was handled).
     */
    select(object3d) {
        if (!this.card) {
            return false;
        }
        let node = object3d;
        let action = null;
        let inside = false;
        while (node) {
            if (action === null && node.userData && typeof node.userData.mavrPanelAction === 'string') {
                action = node.userData.mavrPanelAction;
            }
            if (node === this.group) {
                inside = true;
                break;
            }
            node = node.parent;
        }
        if (!inside) {
            return false;
        }
        if (action && action !== 'panel') {
            this.act(action);
        }
        return true;
    }

    /**
     * Per-frame update: the opening animation and re-placing a panel the user walked away from.
     *
     * @param {number} dt Seconds since the last frame.
     */
    update(dt) {
        if (!this.card) {
            return;
        }
        if (this.appear < 1) {
            this.appear = Math.min(1, this.appear + (Number(dt) || 0) / 0.15);
            const scale = 0.9 + 0.1 * this.appear;
            this.group.scale.set(scale, scale, scale);
        }
        this.camera.getWorldPosition(this.camerapos);
        this.group.getWorldPosition(this.panelpos);
        if (this.camerapos.distanceTo(this.panelpos) > REPLACE_DISTANCE) {
            this.place();
        }
    }

    /**
     * Release everything.
     */
    dispose() {
        this.close();
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
        if (this.group.parent) {
            this.group.parent.remove(this.group);
        }
    }

    /**
     * Title of a question panel.
     *
     * @param {number} slot Slot.
     * @param {Object|null} objective The slot's objective.
     * @returns {string}
     */
    questionTitle(slot, objective) {
        if (this.context && this.context.kind === 'hotspot' && this.context.slot === slot && this.context.title) {
            return this.context.title;
        }
        if (objective && objective.title) {
            return objective.title;
        }
        const scene = this.session.getScene(this.session.sceneid) || startScene(this.session.getConfig());
        const items = scene && Array.isArray(scene.interactables) ? scene.interactables : [];
        const hotspot = items.find((i) => i.kind === 'hotspot' && i.content && Number(i.content.question) === slot);
        if (hotspot) {
            return plainText(hotspot.content.title || hotspot.name || '');
        }
        return this.str('xropenquestion');
    }

    /**
     * Show a card with a text and a close button.
     *
     * @param {string} title Title (may be empty).
     * @param {string} text Text.
     * @param {boolean} closable Whether a close button is shown.
     */
    showNotice(title, text, closable = true) {
        this.show({
            title: title,
            paragraphs: [{text: text, style: 'body'}],
            buttons: closable ? [{action: 'close', label: this.str('xrclose'), look: 'secondary'}] : [],
        });
    }

    /**
     * Fetch the question view (after the queued statements, so the server has seen the visit
     * that unlocks the question) and show it.
     *
     * @param {number} slot Slot.
     * @param {number} generation Panel generation the request belongs to.
     * @returns {Promise<void>}
     */
    loadView(slot, generation) {
        const config = this.session.getConfig();
        return Promise.resolve(this.session.flush())
            .then(() => Promise.resolve(Ajax.call([{
                methodname: 'mod_mavr_get_question_view',
                args: {attemptid: Number(config.attemptid), slot: slot},
            }])[0]))
            .then((view) => {
                if (generation !== this.generation || !this.context) {
                    return;
                }
                this.context.view = view;
                this.context.selected = new Set();
                this.context.result = null;
                this.context.message = null;
                this.renderQuestion();
            })
            .catch((error) => {
                if (generation !== this.generation) {
                    return;
                }
                this.showError(error);
            });
    }

    /**
     * Show a failed request.
     *
     * @param {Object} error The exception.
     */
    showError(error) {
        Log.debug(error);
        const title = this.context && this.context.title ? this.context.title : '';
        if (error && error.errorcode === 'errorquestionlocked') {
            if (this.context) {
                this.context.locked = true;
            }
            this.showNotice(title, this.str('questionlocked'));
            return;
        }
        if (error && error.errorcode === 'errornomorehints') {
            if (this.context) {
                this.context.message = {text: this.str('nomorehints'), style: 'note'};
                this.context.busy = false;
                this.renderQuestion();
            }
            return;
        }
        const text = error && error.message ? plainText(error.message) : '';
        this.showNotice(title, text || this.str('questionlocked'));
    }

    /**
     * The hints of the open question's objective: {objective, used, left}.
     *
     * @returns {Object|null}
     */
    hintInfo() {
        const objective = this.session.getObjectiveForSlot(this.context.slot);
        if (!objective) {
            return null;
        }
        const hints = this.session.getHints(objective.id);
        if (!hints || !hints.total) {
            return null;
        }
        const used = Array.isArray(hints.used) ? hints.used : [];
        return {
            objective: objective,
            used: used,
            left: objective.status === 'available' && used.length < Number(hints.total),
        };
    }

    /**
     * Draw the open question from the context (view, selection, result, message).
     */
    renderQuestion() {
        const context = this.context;
        const view = context.view;
        if (!view) {
            return;
        }
        const close = {action: 'close', label: this.str('xrclose'), look: 'secondary'};
        if (!view.supported || !Array.isArray(view.choices) || view.choices.length > MAX_CHOICES) {
            this.showNotice(context.title, this.str('xrexittoanswer'));
            return;
        }

        const paragraphs = [{text: view.questiontext, style: 'body'}];
        const hints = this.hintInfo();
        if (hints) {
            hints.used.forEach((hint) => paragraphs.push({text: String(hint.text || ''), style: 'hint'}));
        }

        if (view.answered || context.result) {
            const graded = context.result || {state: view.state, fraction: view.fraction, retry: false};
            const outcome = resultOf(graded.state, graded.fraction);
            paragraphs.push({text: this.str(outcome.key), style: outcome.style});
            const buttons = [];
            if (graded.retry) {
                paragraphs.push({text: this.str('retrynotice'), style: 'note'});
                buttons.push({action: 'retry', label: this.str('tryagain'), look: 'primary'});
            }
            buttons.push(close);
            this.show({title: context.title, paragraphs: paragraphs, buttons: buttons});
            return;
        }

        paragraphs.push({text: this.str(view.multiple ? 'xrchoosemany' : 'xrchooseone'), style: 'note'});
        if (context.message) {
            paragraphs.push(context.message);
        }
        const choices = view.choices.map((choice, index) => ({
            action: `choice:${index}`,
            label: String(choice.label || ''),
            look: context.busy ? 'disabled' : (context.selected.has(index) ? 'selected' : 'choice'),
            selected: context.selected.has(index),
        }));
        const buttons = [{
            action: 'submit',
            label: this.str('xrsubmit'),
            look: context.busy || context.selected.size === 0 ? 'disabled' : 'primary',
        }];
        if (hints && hints.left) {
            buttons.push({action: 'hint', label: this.str('gethint'), look: context.busy ? 'disabled' : 'secondary'});
        }
        buttons.push(close);
        this.show({title: context.title, paragraphs: paragraphs, choices: choices, buttons: buttons});
    }

    /**
     * Run a button action.
     *
     * @param {string} action The action.
     */
    act(action) {
        const context = this.context;
        if (action === 'close') {
            this.close();
            return;
        }
        if (!context) {
            return;
        }
        if (action === 'question' && context.slot !== null && context.slot !== undefined) {
            this.openQuestion(context.slot);
            return;
        }
        if (context.kind !== 'question' || !context.view || context.busy) {
            return;
        }
        if (action.startsWith('choice:')) {
            if (context.view.answered || context.result) {
                return;
            }
            const index = Number(action.slice(7));
            if (!Number.isInteger(index) || index < 0 || index >= context.view.choices.length) {
                return;
            }
            if (context.view.multiple) {
                if (context.selected.has(index)) {
                    context.selected.delete(index);
                } else {
                    context.selected.add(index);
                }
            } else {
                context.selected = new Set([index]);
            }
            context.message = null;
            this.renderQuestion();
            return;
        }
        if (action === 'submit') {
            this.submit();
            return;
        }
        if (action === 'retry') {
            const generation = ++this.generation;
            context.busy = false;
            this.showNotice(context.title, this.str('xrloading'), false);
            this.loadView(context.slot, generation);
            return;
        }
        if (action === 'hint') {
            this.requestHint();
        }
    }

    /**
     * Submit the selected choices.
     */
    submit() {
        const context = this.context;
        if (context.view.answered || context.result) {
            return;
        }
        if (context.selected.size === 0) {
            context.message = {text: this.str(context.view.multiple ? 'xrchoosemany' : 'xrchooseone'), style: 'warning'};
            this.renderQuestion();
            return;
        }
        const responses = Array.from(context.selected).sort((a, b) => a - b).map((index) => {
            const choice = context.view.choices[index];
            return {name: String(choice.name), value: String(choice.value)};
        });
        const generation = this.generation;
        context.busy = true;
        this.renderQuestion();
        Promise.resolve(this.session.submitResponses(context.slot, responses))
            .then((result) => {
                if (generation !== this.generation || this.context !== context) {
                    return;
                }
                context.busy = false;
                if (!result || !result.answered) {
                    context.message = {
                        text: this.str(context.view.multiple ? 'xrchoosemany' : 'xrchooseone'),
                        style: 'warning',
                    };
                    this.renderQuestion();
                    return;
                }
                context.result = {state: result.status, fraction: result.fraction, retry: !!result.retry};
                this.renderQuestion();
            })
            .catch((error) => {
                if (generation !== this.generation || this.context !== context) {
                    return;
                }
                context.busy = false;
                this.showError(error);
            });
    }

    /**
     * Ask for the next hint of the open question's objective and show it.
     */
    requestHint() {
        const context = this.context;
        const hints = this.hintInfo();
        if (!hints || !hints.left) {
            context.message = {text: this.str('nomorehints'), style: 'note'};
            this.renderQuestion();
            return;
        }
        const generation = this.generation;
        context.busy = true;
        this.renderQuestion();
        Promise.resolve(this.session.requestHint(hints.objective.id))
            .then(() => {
                if (generation !== this.generation || this.context !== context) {
                    return;
                }
                context.busy = false;
                context.message = null;
                this.renderQuestion();
            })
            .catch((error) => {
                if (generation !== this.generation || this.context !== context) {
                    return;
                }
                context.busy = false;
                this.showError(error);
            });
    }

    /**
     * Re-open a locked question once its objective becomes available.
     *
     * @param {Object} message A session message.
     */
    onSessionChange(message) {
        if (!message || message.type !== 'change' || !this.card || !this.context) {
            return;
        }
        const context = this.context;
        if (context.kind === 'question' && context.locked && this.session.isSlotAvailable(context.slot)) {
            this.openQuestion(context.slot);
        }
    }

    /**
     * Put the panel 1.2 m in front of the camera at eye height, facing it.
     */
    place() {
        const THREE = this.THREE;
        const camerapos = new THREE.Vector3();
        const direction = new THREE.Vector3();
        this.camera.getWorldPosition(camerapos);
        this.camera.getWorldDirection(direction);
        direction.y = 0;
        if (direction.lengthSq() < 1e-6) {
            direction.set(0, 0, -1);
        }
        direction.normalize();
        const target = camerapos.clone().addScaledVector(direction, DISTANCE);
        target.y = camerapos.y;
        this.parent.updateWorldMatrix(true, false);
        this.group.position.copy(this.parent.worldToLocal(target.clone()));
        this.group.updateWorldMatrix(true, false);
        this.group.lookAt(camerapos.x, camerapos.y, camerapos.z);
    }

    /**
     * Show a card: lay it out, draw the panel and build the buttons.
     *
     * @param {Object} card {title, paragraphs: [{text, style}], choices?: [], buttons: []}
     */
    show(card) {
        const wasopen = this.card !== null;
        this.card = card;
        this.clearMeshes();
        this.build(card);
        this.group.visible = true;
        if (!wasopen) {
            this.place();
            this.appear = 0;
            this.group.scale.set(0.9, 0.9, 0.9);
        }
    }

    /**
     * Lay out a card and create its meshes.
     *
     * @param {Object} card The card.
     */
    build(card) {
        const ctx = this.measure;
        const rtl = isRtl();
        const inner = CANVAS_WIDTH - 2 * PAD;
        const blocks = [];
        let y = PAD;

        if (card.title) {
            ctx.font = fontOf(STYLES.title);
            const lines = clampLines(ctx, wrapLines(ctx, card.title, inner), 2, inner);
            blocks.push({style: STYLES.title, lines: lines, y: y});
            y += lines.length * STYLES.title.size * LINE + GAP;
        }
        let budget = MAX_BODY_LINES;
        (card.paragraphs || []).forEach((paragraph) => {
            if (!paragraph.text || budget <= 0) {
                return;
            }
            const style = STYLES[paragraph.style] || STYLES.body;
            ctx.font = fontOf(style);
            const lines = clampLines(ctx, wrapLines(ctx, paragraph.text, inner), budget, inner);
            budget -= lines.length;
            blocks.push({style: style, lines: lines, y: y});
            y += lines.length * style.size * LINE + GAP;
        });

        const rects = [];
        (card.choices || []).forEach((choice) => {
            ctx.font = fontOf(STYLES.body);
            const lines = clampLines(ctx, wrapLines(ctx, choice.label, inner - 2 * 28), MAX_CHOICE_LINES, inner - 2 * 28);
            const height = Math.round(lines.length * STYLES.body.size * LINE + 2 * 18);
            rects.push({button: choice, lines: lines, x: PAD, y: y, w: inner, h: height});
            y += height + 12;
        });
        if (card.choices && card.choices.length) {
            y += GAP - 12;
        }

        const buttons = card.buttons || [];
        if (buttons.length) {
            ctx.font = fontOf(STYLES.body);
            let x = 0;
            let row = [];
            const flush = () => {
                let left = rtl ? CANVAS_WIDTH - PAD : PAD;
                row.forEach((r) => {
                    r.x = rtl ? left - r.w : left;
                    r.y = y;
                    left += rtl ? -(r.w + GAP) : r.w + GAP;
                    rects.push(r);
                });
                y += BUTTON_HEIGHT + GAP;
                row = [];
                x = 0;
            };
            buttons.forEach((button) => {
                const lines = clampLines(ctx, wrapLines(ctx, button.label, inner - 2 * 30), 1, inner - 2 * 30);
                const w = Math.min(inner, Math.ceil(ctx.measureText(lines[0] || '').width) + 2 * 30);
                if (row.length && x + w > inner) {
                    flush();
                }
                row.push({button: button, lines: lines, w: w, h: BUTTON_HEIGHT});
                x += w + GAP;
            });
            flush();
            y -= GAP;
        }
        const height = Math.max(160, Math.ceil(y + PAD));

        this.buildBackground(blocks, height, rtl);
        rects.forEach((rect) => this.buildButton(rect, height, rtl));
    }

    /**
     * Create the panel plane with its text.
     *
     * @param {Object[]} blocks Text blocks {style, lines, y}.
     * @param {number} height Canvas height (px).
     * @param {boolean} rtl Right to left.
     */
    buildBackground(blocks, height, rtl) {
        const canvas = document.createElement('canvas');
        canvas.width = CANVAS_WIDTH;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.direction = rtl ? 'rtl' : 'ltr';
        roundRect(ctx, 2, 2, CANVAS_WIDTH - 4, height - 4, 28);
        ctx.fillStyle = 'rgba(248, 249, 250, 0.97)';
        ctx.fill();
        ctx.lineWidth = 4;
        ctx.strokeStyle = '#495057';
        ctx.stroke();
        ctx.textBaseline = 'top';
        ctx.textAlign = rtl ? 'right' : 'left';
        const x = rtl ? CANVAS_WIDTH - PAD : PAD;
        blocks.forEach((block) => {
            ctx.font = fontOf(block.style);
            ctx.fillStyle = block.style.colour;
            block.lines.forEach((line, index) => {
                ctx.fillText(line, x, block.y + index * block.style.size * LINE);
            });
        });
        const mesh = this.planeMesh(canvas, CANVAS_WIDTH, height, 1000);
        mesh.userData.mavrPanelAction = 'panel';
        this.group.add(mesh);
        this.meshes.push(mesh);
        this.targets.push(mesh);
    }

    /**
     * Create one button plane in front of the panel.
     *
     * @param {Object} rect {button: {action, label, look}, lines, x, y, w, h} in panel pixels.
     * @param {number} height Panel canvas height (px).
     * @param {boolean} rtl Right to left.
     */
    buildButton(rect, height, rtl) {
        const look = LOOKS[rect.button.look] || LOOKS.secondary;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(2, Math.round(rect.w));
        canvas.height = Math.max(2, Math.round(rect.h));
        const ctx = canvas.getContext('2d');
        ctx.direction = rtl ? 'rtl' : 'ltr';
        roundRect(ctx, 3, 3, canvas.width - 6, canvas.height - 6, 16);
        ctx.fillStyle = look.fill;
        ctx.fill();
        ctx.lineWidth = rect.button.selected ? 6 : 3;
        ctx.strokeStyle = look.stroke;
        ctx.stroke();
        ctx.font = fontOf(STYLES.body);
        ctx.fillStyle = look.text;
        ctx.textBaseline = 'middle';
        const isChoice = rect.button.action.startsWith('choice:');
        let x = canvas.width / 2;
        if (isChoice) {
            ctx.textAlign = rtl ? 'right' : 'left';
            x = rtl ? canvas.width - 28 : 28;
        } else {
            ctx.textAlign = 'center';
        }
        const lineheight = STYLES.body.size * LINE;
        const top = canvas.height / 2 - ((rect.lines.length - 1) * lineheight) / 2;
        rect.lines.forEach((line, index) => {
            ctx.fillText(line, x, top + index * lineheight);
        });
        const mesh = this.planeMesh(canvas, canvas.width, canvas.height, 1001);
        mesh.position.set(
            (rect.x + rect.w / 2 - CANVAS_WIDTH / 2) * PX,
            (height / 2 - (rect.y + rect.h / 2)) * PX,
            0.004
        );
        mesh.userData.mavrPanelAction = rect.button.action;
        mesh.name = `mavr-xr-panel-${rect.button.action}`;
        this.group.add(mesh);
        this.meshes.push(mesh);
        this.targets.push(mesh);
    }

    /**
     * A plane showing a canvas, drawn over the room so walls never cut through the panel.
     *
     * @param {HTMLCanvasElement} canvas The canvas.
     * @param {number} w Width (px).
     * @param {number} h Height (px).
     * @param {number} order Render order.
     * @returns {Object} THREE.Mesh
     */
    planeMesh(canvas, w, h, order) {
        const THREE = this.THREE;
        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        const material = new THREE.MeshBasicMaterial({
            map: texture,
            transparent: true,
            depthTest: false,
            depthWrite: false,
            toneMapped: false,
        });
        const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w * PX, h * PX), material);
        mesh.renderOrder = order;
        return mesh;
    }

    /**
     * Remove and dispose the current meshes.
     */
    clearMeshes() {
        this.meshes.forEach((mesh) => {
            this.group.remove(mesh);
            if (mesh.material) {
                if (mesh.material.map) {
                    mesh.material.map.dispose();
                }
                mesh.material.dispose();
            }
            if (mesh.geometry) {
                mesh.geometry.dispose();
            }
        });
        this.meshes = [];
        this.targets = [];
    }
}

/**
 * Create the in-world panels of an XR session.
 *
 * @param {Object} THREE The three.js module.
 * @param {Object} options {parent: Object3D to add the panel to, camera: the XR camera, session}.
 * @returns {Promise<XrPanels>}
 */
export const createXrPanels = (THREE, options) => Promise.resolve(
    getStrings(STRINGKEYS.map((key) => ({key: key, component: 'mavrruntime_builtin'})))
).then((values) => {
    const strings = {};
    STRINGKEYS.forEach((key, index) => {
        strings[key] = values[index];
    });
    return new XrPanels(THREE, options, strings);
});
