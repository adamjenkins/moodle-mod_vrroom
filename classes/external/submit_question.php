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

namespace mod_vrroom\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\questions\usage;

/**
 * Submit a response to one question; the question engine grades it and the server records the
 * `answered` statement and replays the attempt.
 *
 * When the answer leaves the objective open after the server's replay (below the objective's
 * pass_fraction, or not fully correct when the activity's question mode is retry), the question is
 * restarted in place so the student can try again (Phase 2 contract §4.2.3); the response then
 * carries retry = true and the feedback HTML of the try that fell short, without the right answer
 * or the general feedback (they would make the next try trivial).
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_question extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'slot' => new external_value(PARAM_INT, 'Graph slot number'),
            'responses' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Form field name'),
                    'value' => new external_value(PARAM_RAW, 'Form field value'),
                ]),
                'Form data of the question; the server keeps only this slot\'s fields'
            ),
        ]);
    }

    /**
     * Process the response.
     *
     * @param int $attemptid attempt id
     * @param int $slot graph slot
     * @param array $responses list of ['name' => string, 'value' => string]
     * @return array
     */
    public static function execute(int $attemptid, int $slot, array $responses): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'slot' => $slot, 'responses' => $responses]
        );
        $attemptid = $params['attemptid'];
        $slot = $params['slot'];
        $responses = $params['responses'];

        $context = helper::context_for_attempt($attemptid);
        self::validate_context($context);
        [$attempt] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        [$status, $objective, $result] = helper::question_status($attempt, $slot);
        if ($status === evaluator::OBJECTIVE_COMPLETED) {
            throw new \moodle_exception('errorquestionanswered', 'mod_vrroom');
        }
        if ($status !== evaluator::OBJECTIVE_AVAILABLE) {
            throw new \moodle_exception('errorquestionlocked', 'mod_vrroom');
        }

        $outcome = usage::submit($attempt, $slot, $responses, time());

        if (!empty($outcome['finished'])) {
            $fraction = $outcome['fraction'] === null ? null : (float)$outcome['fraction'];
            event_store::record_server($attempt, 'answered', 'question', null, (string)$slot, [
                'fraction' => $fraction,
                'state' => (string)$outcome['status'],
                'mark' => $outcome['mark'] === null ? null : (float)$outcome['mark'],
                'maxmark' => (float)$outcome['maxmark'],
            ]);
            $result = attempt_manager::recompute($attempt);
            $objectivestatus = (string)($result->objectives[(string)$objective['id']]['status'] ?? '');
            // The replay left the objective open (below its pass_fraction, or not fully correct in the
            // activity's retry question mode): the student tries again, so the feedback must not reveal
            // the right answer.
            $retry = $objectivestatus === evaluator::OBJECTIVE_AVAILABLE;
            [$html, $javascript] = usage::render($attempt, $slot, true, !$retry);
            if ($retry) {
                // Keep the graded try and start a fresh one in the same slot.
                usage::restart($attempt, $slot, time());
            }
            $events = event_store::get_events((int)$attempt->id);
            $state = attempt_manager::build_state(
                (int)$attempt->id,
                (string)$attempt->state,
                (int)$attempt->graphversion,
                $result,
                count($events),
                (int)($attempt->timedue ?? 0)
            );
            return [
                'slot' => $slot,
                'status' => (string)$outcome['status'],
                'fraction' => $fraction,
                'html' => $html,
                'javascript' => $javascript,
                'state' => $state,
                'retry' => $retry,
            ];
        }

        // Not graded (empty or invalid response): the engine's own message is in the HTML.
        [$html, $javascript] = usage::render($attempt, $slot, false);
        $events = event_store::get_events((int)$attempt->id);
        $state = attempt_manager::build_state(
            (int)$attempt->id,
            (string)$attempt->state,
            (int)$attempt->graphversion,
            $result,
            count($events),
            (int)($attempt->timedue ?? 0)
        );
        return [
            'slot' => $slot,
            'status' => (string)$outcome['status'],
            'fraction' => null,
            'html' => $html,
            'javascript' => $javascript,
            'state' => $state,
            'retry' => false,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slot' => new external_value(PARAM_INT, 'Graph slot number'),
            'status' => new external_value(PARAM_ALPHA, 'Question engine state after processing'),
            'fraction' => new external_value(PARAM_FLOAT, 'Fraction awarded, null when not graded', VALUE_OPTIONAL),
            'html' => new external_value(PARAM_RAW, 'Question HTML'),
            'javascript' => new external_value(PARAM_RAW, 'JavaScript the question needs'),
            'state' => helper::attempt_state_structure(),
            'retry' => new external_value(
                PARAM_BOOL,
                'Whether the answer fell short and the question was restarted',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }
}
