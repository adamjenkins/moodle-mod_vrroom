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
use core_external\external_single_structure;
use core_external\external_value;
use mod_mavr\local\attempt\attempt_manager;

/**
 * Finish the current user's open attempt: final replay, grade and completion.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_attempt extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
        ]);
    }

    /**
     * Finish the attempt.
     *
     * @param int $attemptid attempt id
     * @return array
     */
    public static function execute(int $attemptid): array {
        global $USER;
        ['attemptid' => $attemptid] = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);

        $context = helper::context_for_attempt($attemptid);
        self::validate_context($context);
        [$attempt, , $cm] = attempt_manager::require_own_open_attempt($attemptid, (int)$USER->id);

        $attempt = attempt_manager::finish($attempt);

        return [
            'attemptid' => (int)$attempt->id,
            'state' => (string)$attempt->state,
            'score' => $attempt->score === null ? 0.0 : (float)$attempt->score,
            'redirecturl' => (new \moodle_url('/mod/mavr/view.php', ['id' => $cm->id]))->out(false),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'state' => new external_value(PARAM_ALPHA, 'Attempt state'),
            'score' => new external_value(PARAM_FLOAT, 'Score fraction 0..1'),
            'redirecturl' => new external_value(PARAM_URL, 'Where to go next'),
        ]);
    }
}
