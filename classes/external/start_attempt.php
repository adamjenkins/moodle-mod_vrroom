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
use mod_mavr\local\runtime\registry;
use mod_mavr\local\token;

/**
 * Start (or resume) the current user's attempt and return its launch descriptor.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_attempt extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Start or resume.
     *
     * @param int $cmid course module id
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;
        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'mavr');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/mavr:attempt', $context);

        $mavr = $DB->get_record('mavr', ['id' => $cm->instance], '*', MUST_EXIST);
        $runtime = registry::get((string)$mavr->runtime);
        $readiness = $runtime->readiness();
        if (!$readiness->ready) {
            throw new \moodle_exception('runtimenotready', 'mod_mavr', '', s($readiness->message));
        }

        [$attempt, $resumed] = attempt_manager::start_or_resume($cm, $mavr, (int)$USER->id);
        $descriptor = $runtime->launch($cm, $attempt, $USER);

        return [
            'attemptid' => (int)$attempt->id,
            'attempt' => (int)$attempt->attempt,
            'resumed' => (bool)$resumed,
            'token' => token::issue((int)$USER->id, (int)$cm->id, (int)$attempt->id),
            'launchjson' => json_encode($descriptor->to_array()),
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
            'attempt' => new external_value(PARAM_INT, 'Attempt number'),
            'resumed' => new external_value(PARAM_BOOL, 'Whether an attempt in progress was resumed'),
            'token' => new external_value(PARAM_RAW, 'Launch token for server-channel run modes'),
            'launchjson' => new external_value(PARAM_RAW, 'JSON launch descriptor'),
        ]);
    }
}
