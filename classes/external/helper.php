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

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\instance_manager;

/**
 * Shared pieces of VR room's external functions.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class helper {
    /**
     * External structure of the §4.6 attempt state.
     *
     * @param int $required VALUE_REQUIRED or VALUE_OPTIONAL
     * @return external_single_structure
     */
    public static function attempt_state_structure(int $required = VALUE_REQUIRED): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'state' => new external_value(PARAM_ALPHA, 'Attempt state'),
            'graphversion' => new external_value(PARAM_INT, 'Graph version the attempt is evaluated with'),
            'eventcount' => new external_value(PARAM_INT, 'Number of events evaluated'),
            'score' => new external_value(PARAM_FLOAT, 'Score fraction 0..1'),
            'earnedpoints' => new external_value(PARAM_FLOAT, 'Points earned'),
            'maxpoints' => new external_value(PARAM_FLOAT, 'Points available'),
            'finished' => new external_value(PARAM_BOOL, 'Whether a finish rule fired'),
            'allrequiredcompleted' => new external_value(PARAM_BOOL, 'Whether every required objective is completed'),
            'objectives' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_ALPHANUMEXT, 'Objective id'),
                    'status' => new external_value(PARAM_ALPHA, 'locked, available or completed'),
                    'earned' => new external_value(PARAM_FLOAT, 'Points earned'),
                    'points' => new external_value(PARAM_FLOAT, 'Points available'),
                    'required' => new external_value(PARAM_BOOL, 'Whether the objective is required'),
                    'hintsused' => new external_value(PARAM_INT, 'Number of hints requested for the objective'),
                ]),
                'Objectives in graph order'
            ),
            'hidden' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Hidden scene item id'),
                'Hidden scene item ids'
            ),
            'timedue' => new external_value(PARAM_INT, 'When the time of the attempt runs out; 0 = no limit'),
            'servertime' => new external_value(PARAM_INT, 'Server time when the state was built'),
        ], 'Attempt state', $required);
    }

    /**
     * Module context of an attempt (for validate_context before any ownership check).
     *
     * @param int $attemptid
     * @return \context_module
     */
    public static function context_for_attempt(int $attemptid): \context_module {
        global $DB;
        $vrroomid = $DB->get_field('vrroom_attempts', 'vrroomid', ['id' => $attemptid], MUST_EXIST);
        $cm = get_coursemodule_from_instance('vrroom', $vrroomid, 0, false, MUST_EXIST);
        return \context_module::instance($cm->id);
    }

    /**
     * Load an attempt of the user in any state, with the capability to attempt.
     *
     * An attempt in progress whose time ran out is finished first, so the row returned shows it
     * finished.
     *
     * @param int $attemptid
     * @param int $userid
     * @return array{0: \stdClass, 1: \stdClass, 2: \cm_info, 3: \context_module} [attempt, vrroom, cm, context]
     * @throws \moodle_exception errornotyourattempt or a missing capability
     */
    public static function require_own_attempt(int $attemptid, int $userid): array {
        $attempt = attempt_manager::get_attempt($attemptid);
        if ((int)$attempt->userid !== $userid) {
            throw new \moodle_exception('errornotyourattempt', 'mod_vrroom');
        }
        [$vrroom, $cm, $context] = attempt_manager::load_activity($attempt);
        require_capability('mod/vrroom:attempt', $context, $userid);
        if (attempt_manager::close_if_overdue($attempt)) {
            $attempt = attempt_manager::get_attempt($attemptid);
        }
        return [$attempt, $vrroom, $cm, $context];
    }

    /**
     * Find the answer_question objective of a graph slot and its status after a server replay.
     *
     * Throws errorinvalidslot when the slot is not in the attempt's usage or has no objective.
     *
     * @param \stdClass $attempt attempt row (open)
     * @param int $slot graph slot
     * @return array{0: string, 1: array, 2: \mod_vrroom\local\graph\evaluation_result} [status, objective, result]
     * @throws \moodle_exception errorinvalidslot
     */
    public static function question_status(\stdClass $attempt, int $slot): array {
        $slotmap = json_decode((string)$attempt->slotmap, true) ?: [];
        if ($slot < 1 || !array_key_exists((string)$slot, $slotmap)) {
            throw new \moodle_exception('errorinvalidslot', 'mod_vrroom');
        }
        $graph = instance_manager::get_graph((int)$attempt->vrroomid, (int)$attempt->graphversion);
        $objective = null;
        foreach ($graph['objectives'] ?? [] as $candidate) {
            if (($candidate['type'] ?? '') === 'answer_question' && (int)($candidate['slot'] ?? 0) === $slot) {
                $objective = $candidate;
                break;
            }
        }
        if ($objective === null) {
            throw new \moodle_exception('errorinvalidslot', 'mod_vrroom');
        }
        $result = attempt_manager::recompute($attempt);
        $status = (string)($result->objectives[(string)$objective['id']]['status'] ?? 'locked');
        return [$status, $objective, $result];
    }
}
