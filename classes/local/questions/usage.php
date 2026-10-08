<?php
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

namespace mod_mavr\local\questions;

/**
 * The question usage behind one MAVR attempt.
 *
 * Every attempt owns one question_usage_by_activity. Questions are rendered and
 * graded only by the core question engine; the room just shows the HTML this
 * class returns and posts the responses back. The graph refers to questions by
 * graph slot number, and the attempt's slotmap translates that to the usage's
 * own slot number (a slot whose question has gone is simply absent from the
 * usage).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class usage {
    /** @var string The question behaviour every MAVR usage uses. */
    public const BEHAVIOUR = 'immediatefeedback';

    /**
     * Load the question engine, which is not autoloaded.
     */
    private static function require_engine(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');
    }

    /**
     * Create and start the question usage for a new attempt.
     *
     * Slots whose entry no longer resolves to a usable question (deleted, only
     * drafts, or a type that is not allowed) are skipped and left out of the
     * slot map, so the rest of the attempt still works. A usage with no
     * questions at all is valid.
     *
     * @param \context_module $context The activity's module context.
     * @param \stdClass[] $slots slots::get_slots() output, keyed by graph slot.
     * @return array{0: int, 1: array} [question usage id, slot map graph slot => usage slot].
     */
    public static function create(\context_module $context, array $slots): array {
        self::require_engine();

        $quba = \question_engine::make_questions_usage_by_activity('mod_mavr', $context);
        $quba->set_preferred_behaviour(self::BEHAVIOUR);

        ksort($slots);
        $slotmap = [];
        foreach ($slots as $graphslot => $slot) {
            $qbeid = (int)($slot->questionbankentryid ?? 0);
            $version = isset($slot->version) && $slot->version !== null ? (int)$slot->version : null;
            $questionid = bank::resolve_question_id($qbeid, $version);
            if ($questionid === null) {
                continue;
            }
            try {
                $question = \question_bank::load_question($questionid);
            } catch (\dml_exception $e) {
                continue;
            }
            if (!bank::allowed_qtype($question->get_type_name())) {
                continue;
            }
            $maxmark = (float)($slot->maxmark ?? 1);
            if ($maxmark <= 0) {
                $maxmark = 1.0;
            }
            $slotmap[(int)$graphslot] = (int)$quba->add_question($question, $maxmark);
        }

        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        return [(int)$quba->get_id(), $slotmap];
    }

    /**
     * Render one question for the in-world panel, with the JavaScript it needs.
     *
     * Rendering happens off-page (inside a web service), which has traps: the
     * question engine must be required explicitly; the theme and output must be
     * initialised before JavaScript requirements can be collected; collection
     * may be started only once per request; and the collected JavaScript comes
     * back wrapped in script tags, which the client unwraps with
     * core/fragment processCollectedJavascript. The client also wraps the HTML
     * in a form, because core's submit button code needs one.
     *
     * @param \stdClass $attempt The mavr_attempts record (uniqueid, slotmap).
     * @param int $graphslot The graph slot number.
     * @param bool $feedback True for the graded feedback view (read-only).
     * @return array{0: string, 1: string} [html, javascript].
     */
    public static function render(\stdClass $attempt, int $graphslot, bool $feedback): array {
        global $PAGE;

        self::require_engine();
        $quba = self::load($attempt);
        $slot = self::usage_slot($attempt, $quba, $graphslot);

        $context = $quba->get_owning_context();
        $PAGE->set_context($context);
        if ($context instanceof \context_module && !$PAGE->has_set_url()) {
            $PAGE->set_url('/mod/mavr/view.php', ['id' => $context->instanceid]);
        }
        $PAGE->initialise_theme_and_output();
        self::start_collecting_javascript();

        $html = $quba->render_question($slot, self::display_options($feedback));
        $javascript = $PAGE->requires->get_end_code();

        return [$html, $javascript];
    }

    /**
     * Process a response to one question.
     *
     * The response array comes from the browser, so it is scoped to the one
     * slot being answered before it reaches the engine: keys that do not carry
     * that question's field prefix are dropped (so a forged submit for another
     * question is never seen), the request names that slot alone in 'slots'
     * (otherwise process_all_actions() processes every slot in the usage), and
     * the behaviour's submit action and the sequence check are supplied when
     * the client left them out (without the sequence check the engine silently
     * skips the slot).
     *
     * An empty or invalid response leaves the question unfinished; that step is
     * still saved so the re-rendered question shows the engine's own validation
     * message, and the result reports finished = false.
     *
     * @param \stdClass $attempt The mavr_attempts record (uniqueid, slotmap).
     * @param int $graphslot The graph slot number.
     * @param array $responses Either [['name' => ..., 'value' => ...], ...] (web service shape) or name => value.
     * @param int $timenow The time of the submission.
     * @return array ['finished' => bool, 'status' => string, 'fraction' => ?float, 'mark' => ?float, 'maxmark' => float].
     */
    public static function submit(\stdClass $attempt, int $graphslot, array $responses, int $timenow): array {
        self::require_engine();
        $quba = self::load($attempt);
        $slot = self::usage_slot($attempt, $quba, $graphslot);
        $qa = $quba->get_question_attempt($slot);

        if ($qa->get_state()->is_finished()) {
            throw new \moodle_exception('errorquestionanswered', 'mod_mavr');
        }

        $prefix = $quba->get_field_prefix($slot);
        $postdata = [];
        foreach (self::normalise_responses($responses) as $name => $value) {
            if (strpos($name, $prefix) === 0) {
                $postdata[$name] = $value;
            }
        }
        $postdata['slots'] = (string)$slot;
        $postdata[$prefix . '-submit'] = 1;
        $sequencekey = $prefix . ':sequencecheck';
        if (!array_key_exists($sequencekey, $postdata)) {
            $postdata[$sequencekey] = (string)$qa->get_sequence_check_count();
        }

        try {
            $quba->process_all_actions($timenow, $postdata);
        } catch (\question_out_of_sequence_exception $e) {
            // A stale form (another tab answered first). Nothing was changed.
            throw new \moodle_exception('errorquestionanswered', 'mod_mavr');
        }
        \question_engine::save_questions_usage_by_activity($quba);

        $qa = $quba->get_question_attempt($slot);
        $state = $qa->get_state();
        $finished = $state->is_finished();
        $fraction = $finished ? $qa->get_fraction() : null;
        $mark = $finished ? $qa->get_mark() : null;

        return [
            'finished' => $finished,
            'status' => (string)$state,
            'fraction' => $fraction === null ? null : (float)$fraction,
            'mark' => $mark === null ? null : (float)$mark,
            'maxmark' => (float)$qa->get_max_mark(),
        ];
    }

    /**
     * Close every question in an attempt's usage (unanswered ones become "gave up").
     *
     * @param \stdClass $attempt The mavr_attempts record (uniqueid).
     * @param int $timenow The finish time.
     */
    public static function finish(\stdClass $attempt, int $timenow): void {
        self::require_engine();
        if (empty($attempt->uniqueid)) {
            return;
        }
        $quba = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        $quba->finish_all_questions($timenow);
        \question_engine::save_questions_usage_by_activity($quba);
    }

    /**
     * Delete question usages.
     *
     * @param int[] $qubaids Question usage ids (zeros are ignored).
     */
    public static function delete(array $qubaids): void {
        self::require_engine();
        $qubaids = array_values(array_unique(array_filter(array_map('intval', $qubaids))));
        if (!$qubaids) {
            return;
        }
        \question_engine::delete_questions_usage_by_activities(new \qubaid_list($qubaids));
    }

    /**
     * Display options for the in-world panel.
     *
     * The attempt view shows the question alone; the feedback view (after an
     * answer, or for a reviewer) shows correctness, marks, feedback and the
     * right answer, read-only. Flags are never shown, and version information
     * is switched off because looking it up needs a page context that may not
     * exist off-page. The caller (or render_question()) sets the context.
     *
     * @param bool $feedback True for the feedback view.
     * @return \question_display_options The options.
     */
    public static function display_options(bool $feedback): \question_display_options {
        self::require_engine();
        $options = new \question_display_options();
        $options->flags = \question_display_options::HIDDEN;
        $options->manualcomment = \question_display_options::HIDDEN;
        $options->manualcommentlink = \question_display_options::HIDDEN;
        $options->history = \question_display_options::HIDDEN;
        $options->versioninfo = false;

        if ($feedback) {
            $options->readonly = true;
            $options->correctness = \question_display_options::VISIBLE;
            $options->marks = \question_display_options::MARK_AND_MAX;
            $options->feedback = \question_display_options::VISIBLE;
            $options->numpartscorrect = \question_display_options::VISIBLE;
            $options->generalfeedback = \question_display_options::VISIBLE;
            $options->rightanswer = \question_display_options::VISIBLE;
        } else {
            $options->readonly = false;
            $options->correctness = \question_display_options::HIDDEN;
            $options->marks = \question_display_options::HIDDEN;
            $options->feedback = \question_display_options::HIDDEN;
            $options->numpartscorrect = \question_display_options::HIDDEN;
            $options->generalfeedback = \question_display_options::HIDDEN;
            $options->rightanswer = \question_display_options::HIDDEN;
        }
        return $options;
    }

    /**
     * Translate a graph slot to the usage slot through the attempt's slot map.
     *
     * @param \stdClass $attempt The attempt (slotmap JSON).
     * @param int $graphslot The graph slot.
     * @return int|null The usage slot, or null when the slot has no question in this attempt.
     */
    public static function get_usage_slot(\stdClass $attempt, int $graphslot): ?int {
        $map = json_decode((string)($attempt->slotmap ?? ''), true);
        if (!is_array($map) || !array_key_exists($graphslot, $map)) {
            return null;
        }
        $slot = (int)$map[$graphslot];
        return $slot > 0 ? $slot : null;
    }

    /**
     * Load an attempt's usage.
     *
     * @param \stdClass $attempt The attempt (uniqueid).
     * @return \question_usage_by_activity The usage.
     */
    private static function load(\stdClass $attempt): \question_usage_by_activity {
        if (empty($attempt->uniqueid)) {
            throw new \moodle_exception('questionmissing', 'mod_mavr');
        }
        $quba = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        if ($quba->get_owning_component() !== 'mod_mavr') {
            throw new \coding_exception('Question usage ' . $attempt->uniqueid . ' does not belong to mod_mavr.');
        }
        return $quba;
    }

    /**
     * The usage slot for a graph slot, which must exist in the loaded usage.
     *
     * @param \stdClass $attempt The attempt.
     * @param \question_usage_by_activity $quba Its usage.
     * @param int $graphslot The graph slot.
     * @return int The usage slot.
     */
    private static function usage_slot(\stdClass $attempt, \question_usage_by_activity $quba, int $graphslot): int {
        $slot = self::get_usage_slot($attempt, $graphslot);
        if ($slot === null || !in_array($slot, $quba->get_slots(), false)) {
            throw new \moodle_exception('questionmissing', 'mod_mavr');
        }
        return $slot;
    }

    /**
     * Start collecting JavaScript requirements once per request.
     *
     * moodle_page guards against a second start by comparing get_class() with
     * the un-namespaced class name, which no longer matches the namespaced
     * class in Moodle 5.2 (lib/pagelib.php:1051), so the guard is done here.
     */
    private static function start_collecting_javascript(): void {
        global $PAGE;
        if ($PAGE->requires instanceof \core\output\requirements\fragment_requirements_manager) {
            return;
        }
        $PAGE->start_collecting_javascript_requirements();
    }

    /**
     * Flatten client responses into a name => value array of strings.
     *
     * @param array $responses [['name' => ..., 'value' => ...], ...] or name => value.
     * @return array<string, string> Field name => value.
     */
    private static function normalise_responses(array $responses): array {
        $result = [];
        foreach ($responses as $key => $item) {
            if (is_array($item) && array_key_exists('name', $item)) {
                $name = (string)$item['name'];
                $value = $item['value'] ?? '';
            } else if (is_object($item) && property_exists($item, 'name')) {
                $name = (string)$item->name;
                $value = $item->value ?? '';
            } else {
                $name = (string)$key;
                $value = $item;
            }
            if ($name === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $result[$name] = (string)$value;
        }
        return $result;
    }
}
