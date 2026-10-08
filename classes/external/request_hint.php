<?php
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

namespace mod_mavr\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\attempt\hint_manager;

/**
 * Reveal the next hint of an objective in the current user's open attempt.
 *
 * The request is recorded by the server (verb `requested`, object type `hint`) and costs the
 * hint's penalty when the objective is completed.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_hint extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'objectiveid' => new external_value(PARAM_ALPHANUMEXT, 'Objective id'),
        ]);
    }

    /**
     * Reveal and record the next hint.
     *
     * @param int $attemptid attempt id
     * @param string $objectiveid objective id
     * @return array
     */
    public static function execute(int $attemptid, string $objectiveid): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'objectiveid' => $objectiveid]
        );
        $attemptid = $params['attemptid'];
        $objectiveid = $params['objectiveid'];

        $context = helper::context_for_attempt($attemptid);
        self::validate_context($context);
        [$attempt, , , $context] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        $hint = hint_manager::request($attempt, $objectiveid, $context);
        $events = event_store::get_events((int)$attempt->id);
        $state = attempt_manager::build_state(
            (int)$attempt->id,
            (string)$attempt->state,
            (int)$attempt->graphversion,
            $hint['result'],
            count($events),
            (int)($attempt->timedue ?? 0)
        );

        return [
            'hintid' => $hint['hintid'],
            'text' => $hint['text'],
            'hintsused' => $hint['hintsused'],
            'hintstotal' => $hint['hintstotal'],
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
            'hintid' => new external_value(PARAM_ALPHANUMEXT, 'Id of the hint revealed'),
            'text' => new external_value(PARAM_RAW, 'Hint text as plain text (shown with textContent)'),
            'hintsused' => new external_value(PARAM_INT, 'Hints of the objective requested so far'),
            'hintstotal' => new external_value(PARAM_INT, 'Hints the objective has'),
            'state' => helper::attempt_state_structure(),
        ]);
    }
}
