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
use core_external\external_single_structure;
use core_external\external_value;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\questions\usage;

/**
 * Render one question of the current user's open attempt, once the server has unlocked it.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'slot' => new external_value(PARAM_INT, 'Graph slot number'),
        ]);
    }

    /**
     * Render the question.
     *
     * @param int $attemptid attempt id
     * @param int $slot graph slot
     * @return array
     */
    public static function execute(int $attemptid, int $slot): array {
        global $USER;
        ['attemptid' => $attemptid, 'slot' => $slot] = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'slot' => $slot]
        );

        $context = helper::context_for_attempt($attemptid);
        self::validate_context($context);
        [$attempt] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        [$status] = helper::question_status($attempt, $slot);
        if ($status !== evaluator::OBJECTIVE_AVAILABLE && $status !== evaluator::OBJECTIVE_COMPLETED) {
            throw new \moodle_exception('errorquestionlocked', 'mod_vrroom');
        }
        $answered = $status === evaluator::OBJECTIVE_COMPLETED;
        [$html, $javascript] = usage::render($attempt, $slot, $answered);

        return [
            'slot' => $slot,
            'html' => $html,
            'javascript' => $javascript,
            'answered' => $answered,
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
            'html' => new external_value(PARAM_RAW, 'Question HTML rendered by the question engine'),
            'javascript' => new external_value(PARAM_RAW, 'JavaScript the question needs'),
            'answered' => new external_value(PARAM_BOOL, 'Whether the question has been answered'),
        ]);
    }
}
