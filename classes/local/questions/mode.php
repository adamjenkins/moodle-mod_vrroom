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

namespace mod_vrroom\local\questions;

/**
 * The activity's question mode: one try per question, or retries until fully correct.
 *
 * In retry mode an answer that is not fully correct leaves its objective open, the question is
 * restarted in place, and every wrong try before the right answer costs retrypenalty of the
 * objective's points (never below zero). Both task-graph evaluators receive these settings as
 * options (see evaluator_options()), so the stored graphs never change with the setting.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mode {
    /** @var string One try per question in an attempt. */
    public const ONCE = 'once';

    /** @var string Try again until fully correct. */
    public const RETRY = 'retry';

    /** @var float Default share of the points lost per wrong try (as Moodle's interactive behaviour). */
    public const DEFAULT_PENALTY = 0.3333333;

    /** @var float[] The penalties a teacher can choose from. */
    public const PENALTIES = [0.0, 0.1, 0.2, 0.3333333, 0.5];

    /**
     * Clean a submitted or stored mode.
     *
     * @param mixed $mode The mode.
     * @return string ONCE or RETRY.
     */
    public static function clean($mode): string {
        return $mode === self::RETRY ? self::RETRY : self::ONCE;
    }

    /**
     * Clean a submitted or stored penalty to the nearest allowed choice.
     *
     * @param mixed $penalty The penalty.
     * @return float One of PENALTIES.
     */
    public static function clean_penalty($penalty): float {
        $value = is_numeric($penalty) ? (float)$penalty : self::DEFAULT_PENALTY;
        $best = self::DEFAULT_PENALTY;
        foreach (self::PENALTIES as $choice) {
            if (abs($choice - $value) < abs($best - $value)) {
                $best = $choice;
            }
        }
        return $best;
    }

    /**
     * Mode choices for the activity form.
     *
     * @return string[] mode => label
     */
    public static function mode_options(): array {
        return [
            self::ONCE => get_string('questionmodeonce', 'mod_vrroom'),
            self::RETRY => get_string('questionmoderetry', 'mod_vrroom'),
        ];
    }

    /**
     * Penalty choices for the activity form, keyed by the stored value.
     *
     * @return string[] penalty => label
     */
    public static function penalty_options(): array {
        $options = [];
        foreach (self::PENALTIES as $choice) {
            $options[(string)$choice] = format_float($choice * 100, $choice === self::DEFAULT_PENALTY ? 1 : 0) . '%';
        }
        return $options;
    }

    /**
     * Evaluator options of an activity: ['retry' => bool, 'retrypenalty' => float].
     *
     * @param \stdClass|null $vrroom The vrroom row (null or missing fields = one try per question).
     * @return array
     */
    public static function evaluator_options(?\stdClass $vrroom): array {
        $retry = $vrroom !== null && self::clean($vrroom->questionmode ?? null) === self::RETRY;
        return [
            'retry' => $retry,
            'retrypenalty' => $retry ? self::clean_penalty($vrroom->retrypenalty ?? self::DEFAULT_PENALTY) : 0.0,
        ];
    }

    /**
     * Evaluator options of an activity by id.
     *
     * @param int $vrroomid The activity id.
     * @return array See evaluator_options().
     */
    public static function evaluator_options_for(int $vrroomid): array {
        global $DB;
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroomid], 'id, questionmode, retrypenalty');
        return self::evaluator_options($vrroom ?: null);
    }
}
