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
use mod_mavr\local\graph\evaluator;
use mod_mavr\local\questions\bridge;

/**
 * Plain-text view of a true/false or multiple-choice question of the current user's open attempt,
 * for the in-world panels of immersive VR.
 *
 * The same checks as mod_mavr_get_question apply: the attempt must be the user's own open attempt
 * (within its time limit), and the question's objective must be unlocked.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question_view extends external_api {
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
     * Build the view.
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
        [$attempt, , , $context] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        [$status] = helper::question_status($attempt, $slot);
        if ($status !== evaluator::OBJECTIVE_AVAILABLE && $status !== evaluator::OBJECTIVE_COMPLETED) {
            throw new \moodle_exception('errorquestionlocked', 'mod_mavr');
        }

        return bridge::get_view($attempt, $slot, $context, $status === evaluator::OBJECTIVE_COMPLETED);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slot' => new external_value(PARAM_INT, 'Graph slot number'),
            'supported' => new external_value(PARAM_BOOL, 'Whether the question can be answered on an in-world panel'),
            'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type name'),
            'multiple' => new external_value(PARAM_BOOL, 'Whether more than one choice can be selected'),
            'questiontext' => new external_value(PARAM_RAW, 'Question text as plain text (empty when unsupported)'),
            'choices' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Response field name to post'),
                    'value' => new external_value(PARAM_RAW, 'Response value to post when the choice is selected'),
                    'label' => new external_value(PARAM_RAW, 'Choice label as plain text'),
                ]),
                'Choices in the attempt\'s order (empty when unsupported)'
            ),
            'answered' => new external_value(PARAM_BOOL, 'Whether the question has been answered'),
            'state' => new external_value(PARAM_ALPHA, 'Question engine state of the question attempt'),
            'fraction' => new external_value(
                PARAM_FLOAT,
                'Fraction awarded, only once answered',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
