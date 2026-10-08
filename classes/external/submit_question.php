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

namespace mod_mavr\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\graph\evaluator;
use mod_mavr\local\questions\usage;

/**
 * Submit a response to one question; the question engine grades it and the server records the
 * `answered` statement and replays the attempt.
 *
 * @package    mod_mavr
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
        $params = self::validate_parameters(self::execute_parameters(),
            ['attemptid' => $attemptid, 'slot' => $slot, 'responses' => $responses]);
        $attemptid = $params['attemptid'];
        $slot = $params['slot'];
        $responses = $params['responses'];

        $context = helper::context_for_attempt($attemptid);
        self::validate_context($context);
        [$attempt] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        [$status, , $result] = helper::question_status($attempt, $slot);
        if ($status === evaluator::OBJECTIVE_COMPLETED) {
            throw new \moodle_exception('errorquestionanswered', 'mod_mavr');
        }
        if ($status !== evaluator::OBJECTIVE_AVAILABLE) {
            throw new \moodle_exception('errorquestionlocked', 'mod_mavr');
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
            [$html, $javascript] = usage::render($attempt, $slot, true);
            $events = event_store::get_events((int)$attempt->id);
            $state = attempt_manager::build_state((int)$attempt->id, (string)$attempt->state,
                (int)$attempt->graphversion, $result, count($events));
            return [
                'slot' => $slot,
                'status' => (string)$outcome['status'],
                'fraction' => $fraction,
                'html' => $html,
                'javascript' => $javascript,
                'state' => $state,
            ];
        }

        // Not graded (empty or invalid response): the engine's own message is in the HTML.
        [$html, $javascript] = usage::render($attempt, $slot, false);
        $events = event_store::get_events((int)$attempt->id);
        $state = attempt_manager::build_state((int)$attempt->id, (string)$attempt->state,
            (int)$attempt->graphversion, $result, count($events));
        return [
            'slot' => $slot,
            'status' => (string)$outcome['status'],
            'fraction' => null,
            'html' => $html,
            'javascript' => $javascript,
            'state' => $state,
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
        ]);
    }
}
