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

namespace mod_vrroom\local\attempt;

use mod_vrroom\event\attempt_deleted;
use mod_vrroom\event\attempt_finished;
use mod_vrroom\event\attempt_started;
use mod_vrroom\local\grading\grader;
use mod_vrroom\local\graph\evaluation_result;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\launch\config_builder;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\questions\slots;
use mod_vrroom\local\questions\usage;

/**
 * Attempts: start, resume, server replay of the task graph, finish and deletion.
 *
 * The server never accepts scores from a client: every recompute replays the attempt's stored
 * events through the graph version the attempt was started under.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_manager {
    /** @var string Attempt in progress. */
    public const STATE_INPROGRESS = 'inprogress';

    /** @var string Attempt finished. */
    public const STATE_FINISHED = 'finished';

    /** @var string Attempt abandoned (reserved). */
    public const STATE_ABANDONED = 'abandoned';

    /** @var int Seconds after the deadline during which an attempt still counts as on time. */
    public const DEADLINE_GRACE = 5;

    /**
     * @var int Seconds without activity after which an attempt whose required objectives are all done
     * counts as left complete (the student closed the tab instead of finishing) and is finished.
     */
    public const ABANDON_AFTER = DAYSECS;

    /** @var int Seconds to wait for another request of the same user that is starting an attempt. */
    private const START_LOCK_TIMEOUT = 10;

    /**
     * Return the user's attempt in progress, or start a new one.
     *
     * An attempt in progress whose time ran out is finished first and no longer counts as open.
     * A new attempt gets its deadline from the time limit of the current graph.
     *
     * Starting is serialised per activity and user with a lock: several windows or devices that
     * start at the same moment get the one attempt the first of them created, instead of racing
     * for the next attempt number.
     *
     * @param \cm_info $cm course module
     * @param \stdClass $vrroom vrroom row
     * @param int $userid user id
     * @param int|null $timenow current time
     * @return array{0: \stdClass, 1: bool} [attempt, resumed]
     * @throws \moodle_exception errornomoreattempts, or errorattemptbusy when the lock cannot be had
     */
    public static function start_or_resume(\cm_info $cm, \stdClass $vrroom, int $userid, ?int $timenow = null): array {
        $lock = \core\lock\lock_config::get_lock_factory('mod_vrroom')->get_lock(
            'start_' . (int)$vrroom->id . '_' . $userid,
            self::START_LOCK_TIMEOUT
        );
        if (!$lock) {
            throw new \moodle_exception('errorattemptbusy', 'mod_vrroom');
        }
        try {
            $open = self::get_open_attempt((int)$vrroom->id, $userid);
            if ($open && !self::close_if_overdue($open, $timenow)) {
                return [$open, true];
            }
            try {
                $attempt = self::create_attempt($cm, $vrroom, $userid, $timenow ?? time());
            } catch (\dml_write_exception $e) {
                // A request that did not go through the lock (a lock backend that is not shared by
                // every web node) created the attempt first: resume that one.
                $open = self::get_open_attempt((int)$vrroom->id, $userid);
                if ($open) {
                    return [$open, true];
                }
                throw $e;
            }
        } finally {
            $lock->release();
        }

        $event = attempt_started::create([
            'objectid' => $attempt->id,
            'relateduserid' => $userid,
            'context' => \context_module::instance($cm->id),
            'other' => ['vrroomid' => (int)$vrroom->id],
        ]);
        $event->add_record_snapshot('vrroom_attempts', $attempt);
        $event->trigger();

        return [$attempt, false];
    }

    /**
     * The user's latest attempt in progress of an activity.
     *
     * @param int $vrroomid vrroom id
     * @param int $userid user id
     * @return \stdClass|null vrroom_attempts row, or null when none is in progress
     */
    private static function get_open_attempt(int $vrroomid, int $userid): ?\stdClass {
        global $DB;
        $open = $DB->get_records(
            'vrroom_attempts',
            ['vrroomid' => $vrroomid, 'userid' => $userid, 'state' => self::STATE_INPROGRESS],
            'attempt DESC',
            '*',
            0,
            1
        );
        return $open ? reset($open) : null;
    }

    /**
     * Create a new attempt with its question usage (the caller holds the start lock).
     *
     * The usage, the attempt row and its first statement are written in one transaction, which is
     * rolled back on any error, so a failed start never leaves a transaction open or half an attempt.
     *
     * @param \cm_info $cm course module
     * @param \stdClass $vrroom vrroom row
     * @param int $userid user id
     * @param int $timenow current time
     * @return \stdClass the new vrroom_attempts row
     * @throws \moodle_exception errornomoreattempts
     */
    private static function create_attempt(\cm_info $cm, \stdClass $vrroom, int $userid, int $timenow): \stdClass {
        global $DB;

        $count = $DB->count_records('vrroom_attempts', ['vrroomid' => $vrroom->id, 'userid' => $userid]);
        if ((int)$vrroom->maxattempts > 0 && $count >= (int)$vrroom->maxattempts) {
            throw new \moodle_exception('errornomoreattempts', 'mod_vrroom');
        }
        // Attempt numbers continue after the highest one, so a deleted attempt never causes a clash.
        $last = (int)$DB->get_field_sql(
            'SELECT MAX(attempt) FROM {vrroom_attempts} WHERE vrroomid = :vrroomid AND userid = :userid',
            ['vrroomid' => $vrroom->id, 'userid' => $userid]
        );

        $graph = instance_manager::get_graph((int)$vrroom->id, (int)$vrroom->graphversion);
        $timelimit = max(0, (int)($graph['timelimit'] ?? 0));

        $context = \context_module::instance($cm->id);
        $transaction = $DB->start_delegated_transaction();
        try {
            [$qubaid, $slotmap] = usage::create($context, slots::get_slots((int)$vrroom->id, $context));
            $attempt = (object) [
                'vrroomid' => (int)$vrroom->id,
                'userid' => $userid,
                'attempt' => $last + 1,
                'state' => self::STATE_INPROGRESS,
                'uniqueid' => (int)$qubaid,
                'slotmap' => json_encode((object)$slotmap),
                'graphversion' => (int)$vrroom->graphversion,
                'score' => null,
                'requireddone' => 0,
                'timestart' => $timenow,
                'timefinish' => 0,
                'timedue' => $timelimit > 0 ? $timenow + $timelimit : 0,
                'timemodified' => $timenow,
            ];
            $attempt->id = (int)$DB->insert_record('vrroom_attempts', $attempt);
            event_store::record_server($attempt, 'launched', 'activity', null, null);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Rolls back and throws $e again.
            $transaction->rollback($e);
        }
        return $attempt;
    }

    /**
     * Load an attempt.
     *
     * An attempt that does not exist (any more: a teacher deleted it while the student was in the
     * room) fails with a message the student can act on, not with the database error.
     *
     * @param int $attemptid
     * @return \stdClass
     * @throws \moodle_exception errorattemptmissing
     */
    public static function get_attempt(int $attemptid): \stdClass {
        global $DB;
        $attempt = $DB->get_record('vrroom_attempts', ['id' => $attemptid]);
        if (!$attempt) {
            throw new \moodle_exception('errorattemptmissing', 'mod_vrroom');
        }
        return $attempt;
    }

    /**
     * Load an attempt that must belong to $userid, be in progress, within its time, and be
     * attemptable by them.
     *
     * An attempt whose time ran out is finished here, and the call fails with errorattempttimeup.
     *
     * @param int $attemptid
     * @param int $userid
     * @return array{0: \stdClass, 1: \stdClass, 2: \cm_info, 3: \context_module} [attempt, vrroom, cm, context]
     * @throws \moodle_exception errorattemptmissing, errornotyourattempt, errorattempttimeup, errorattemptclosed,
     *     or a missing capability
     */
    public static function require_own_open_attempt(int $attemptid, int $userid): array {
        $attempt = self::get_attempt($attemptid);
        if ((int)$attempt->userid !== $userid) {
            throw new \moodle_exception('errornotyourattempt', 'mod_vrroom');
        }
        if (self::is_overdue($attempt)) {
            self::close_if_overdue($attempt);
            throw new \moodle_exception('errorattempttimeup', 'mod_vrroom');
        }
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new \moodle_exception('errorattemptclosed', 'mod_vrroom');
        }
        [$vrroom, $cm, $context] = self::load_activity($attempt);
        require_capability('mod/vrroom:attempt', $context, $userid);
        return [$attempt, $vrroom, $cm, $context];
    }

    /**
     * Whether an attempt in progress has passed its deadline (plus the grace period).
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @param int|null $timenow current time
     * @return bool
     */
    public static function is_overdue(\stdClass $attempt, ?int $timenow = null): bool {
        $timenow = $timenow ?? time();
        $timedue = (int)($attempt->timedue ?? 0);
        return ($attempt->state ?? '') === self::STATE_INPROGRESS && $timedue > 0
            && $timenow > $timedue + self::DEADLINE_GRACE;
    }

    /**
     * Finish an attempt whose time ran out, as of its deadline.
     *
     * The row is read again first, so a stale copy never finishes an attempt twice.
     *
     * @param \stdClass $attempt vrroom_attempts row (only the id is used)
     * @param int|null $timenow current time
     * @return bool true when this call finished the attempt
     */
    public static function close_if_overdue(\stdClass $attempt, ?int $timenow = null): bool {
        global $DB;
        $row = $DB->get_record('vrroom_attempts', ['id' => (int)$attempt->id]);
        if (!$row || !self::is_overdue($row, $timenow)) {
            return false;
        }
        try {
            self::finish($row, (int)$row->timedue, true);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'errorattemptclosed') {
                // Another request finished it in the meantime.
                return false;
            }
            throw $e;
        }
        return true;
    }

    /**
     * Whether an attempt in progress was left complete: every required objective is done, and
     * nothing has happened in it for ABANDON_AFTER seconds.
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @param int|null $timenow current time
     * @return bool
     */
    public static function is_abandoned_complete(\stdClass $attempt, ?int $timenow = null): bool {
        $timenow = $timenow ?? time();
        return ($attempt->state ?? '') === self::STATE_INPROGRESS && (int)($attempt->requireddone ?? 0) === 1
            && (int)($attempt->timemodified ?? 0) < $timenow - self::ABANDON_AFTER;
    }

    /**
     * Finish an attempt that was left complete (see is_abandoned_complete()), as of its last activity.
     *
     * The student did everything that is required and closed the tab instead of finishing, so the
     * attempt is finished and graded like a normal finish. The row is read again first, so a stale
     * copy never finishes an attempt twice.
     *
     * @param \stdClass $attempt vrroom_attempts row (only the id is used)
     * @param int|null $timenow current time
     * @return bool true when this call finished the attempt
     */
    public static function close_if_abandoned(\stdClass $attempt, ?int $timenow = null): bool {
        global $DB;
        $row = $DB->get_record('vrroom_attempts', ['id' => (int)$attempt->id]);
        if (!$row || !self::is_abandoned_complete($row, $timenow)) {
            return false;
        }
        try {
            self::finish($row, (int)$row->timemodified);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'errorattemptclosed' || $e->errorcode === 'errorattemptmissing') {
                // Another request finished or deleted it in the meantime.
                return false;
            }
            throw $e;
        }
        return true;
    }

    /**
     * Load the activity of an attempt.
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @return array{0: \stdClass, 1: \cm_info, 2: \context_module} [vrroom, cm, context]
     */
    public static function load_activity(\stdClass $attempt): array {
        global $DB;
        $vrroom = $DB->get_record('vrroom', ['id' => $attempt->vrroomid], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_instance($vrroom->id, 'vrroom', $vrroom->course);
        return [$vrroom, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Replay the attempt's events; persist score and requireddone; record server `completed`
     * statements for objectives completed for the first time.
     *
     * @param \stdClass $attempt vrroom_attempts row (score/requireddone/timemodified updated in place)
     * @return evaluation_result
     */
    public static function recompute(\stdClass $attempt): evaluation_result {
        global $DB;
        $result = self::evaluate($attempt);
        $now = time();

        $update = (object) [
            'id' => (int)$attempt->id,
            'score' => $result->score,
            'requireddone' => $result->allrequiredcompleted ? 1 : 0,
            'timemodified' => $now,
        ];
        $DB->update_record('vrroom_attempts', $update);
        $attempt->score = $update->score;
        $attempt->requireddone = $update->requireddone;
        $attempt->timemodified = $now;

        $recorded = $DB->get_fieldset_select(
            'vrroom_events',
            'objectid',
            'attemptid = :attemptid AND verb = :verb AND objecttype = :type',
            ['attemptid' => $attempt->id, 'verb' => 'completed', 'type' => 'objective']
        );
        $recorded = array_flip(array_map('strval', $recorded));
        foreach ($result->completed_ids() as $objectiveid) {
            $objectiveid = (string)$objectiveid;
            if (isset($recorded[$objectiveid])) {
                continue;
            }
            $earned = (float)($result->objectives[$objectiveid]['earned'] ?? 0);
            event_store::record_server($attempt, 'completed', 'objective', null, $objectiveid, ['earned' => $earned]);
            $recorded[$objectiveid] = true;
        }
        return $result;
    }

    /**
     * Evaluate an attempt without persisting anything.
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @return evaluation_result
     */
    public static function evaluate(\stdClass $attempt): evaluation_result {
        $graph = instance_manager::get_graph((int)$attempt->vrroomid, (int)$attempt->graphversion);
        $options = mode::evaluator_options_for((int)$attempt->vrroomid);
        return (new evaluator($graph, $options))->evaluate(event_store::get_events((int)$attempt->id));
    }

    /**
     * The §4.6 state of an attempt (evaluated, not persisted).
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @return array
     */
    public static function get_state(\stdClass $attempt): array {
        $events = event_store::get_events((int)$attempt->id);
        $graph = instance_manager::get_graph((int)$attempt->vrroomid, (int)$attempt->graphversion);
        $result = (new evaluator($graph, mode::evaluator_options_for((int)$attempt->vrroomid)))->evaluate($events);
        return self::build_state(
            (int)$attempt->id,
            (string)$attempt->state,
            (int)$attempt->graphversion,
            $result,
            count($events),
            (int)($attempt->timedue ?? 0)
        );
    }

    /**
     * Shape an evaluation result as the §4.6 attempt state.
     *
     * @param int $attemptid attempt id (0 for a preview)
     * @param string $state attempt state ('preview' for a preview)
     * @param int $graphversion graph version evaluated
     * @param evaluation_result $result evaluation
     * @param int $eventcount number of events evaluated
     * @param int $timedue deadline of the attempt (0 = none, and always 0 for a preview)
     * @return array
     */
    public static function build_state(
        int $attemptid,
        string $state,
        int $graphversion,
        evaluation_result $result,
        int $eventcount,
        int $timedue = 0
    ): array {
        $objectives = [];
        foreach ($result->objectives as $id => $objective) {
            $objectives[] = [
                'id' => (string)$id,
                'status' => (string)$objective['status'],
                'earned' => (float)$objective['earned'],
                'points' => (float)$objective['points'],
                'required' => (bool)$objective['required'],
                'hintsused' => (int)($objective['hintsused'] ?? 0),
            ];
        }
        return [
            'attemptid' => $attemptid,
            'state' => $state,
            'graphversion' => $graphversion,
            'eventcount' => $eventcount,
            'score' => (float)$result->score,
            'earnedpoints' => (float)$result->earnedpoints,
            'maxpoints' => (float)$result->maxpoints,
            'finished' => (bool)$result->finished,
            'allrequiredcompleted' => (bool)$result->allrequiredcompleted,
            'objectives' => $objectives,
            'hidden' => array_values(array_map('strval', $result->hidden)),
            'timedue' => max(0, $timedue),
            'servertime' => time(),
        ];
    }

    /**
     * Finish an attempt: close the question usage, replay, grade and update completion.
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @param int|null $timenow current time (the deadline when the time ran out)
     * @param bool $timeup whether the attempt is finished because its time ran out
     * @return \stdClass the updated row
     * @throws \moodle_exception errorattemptclosed when the attempt is not in progress
     */
    public static function finish(\stdClass $attempt, ?int $timenow = null, bool $timeup = false): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $timenow = $timenow ?? time();

        $attempt = self::get_attempt((int)$attempt->id);
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new \moodle_exception('errorattemptclosed', 'mod_vrroom');
        }
        [$vrroom, $cm, $context] = self::load_activity($attempt);

        usage::finish($attempt, $timenow);
        $result = self::recompute($attempt);

        $DB->update_record('vrroom_attempts', (object) [
            'id' => (int)$attempt->id,
            'state' => self::STATE_FINISHED,
            'timefinish' => $timenow,
            'timemodified' => $timenow,
        ]);
        $attempt = self::get_attempt((int)$attempt->id);

        $score = (float)$result->score;
        $grade = round($score * (float)$vrroom->grade, 5);
        $completedresult = ['score' => $score, 'grade' => $grade];
        if ($timeup) {
            $completedresult['timeup'] = true;
        }
        event_store::record_server($attempt, 'completed', 'activity', null, null, $completedresult);
        if ((int)$vrroom->grade > 0) {
            $gradepass = grader::get_gradepass($vrroom);
            if ($gradepass > 0) {
                $verb = $grade >= $gradepass ? 'passed' : 'failed';
                event_store::record_server($attempt, $verb, 'activity', null, null, ['score' => $score, 'grade' => $grade]);
            }
        }

        $event = attempt_finished::create([
            'objectid' => $attempt->id,
            'relateduserid' => (int)$attempt->userid,
            'context' => $context,
            'other' => ['vrroomid' => (int)$vrroom->id, 'score' => $score],
        ]);
        $event->add_record_snapshot('vrroom_attempts', $attempt);
        $event->trigger();

        grader::update_grades($vrroom, (int)$attempt->userid);

        $completion = new \completion_info($cm->get_course());
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
        }

        // The 3D position belongs to this attempt only; the next attempt starts at the start point.
        self::delete_resume_states($context, (int)$attempt->userid, (int)$attempt->id);
        return $attempt;
    }

    /**
     * Delete one attempt with its question usage and events, then regrade its user.
     *
     * @param \stdClass $attempt vrroom_attempts row
     * @return void
     */
    public static function delete_attempt(\stdClass $attempt): void {
        global $DB;
        [$vrroom, , $context] = self::load_activity($attempt);

        $transaction = $DB->start_delegated_transaction();
        if (!empty($attempt->uniqueid)) {
            usage::delete([(int)$attempt->uniqueid]);
        }
        event_store::delete_for_attempts([(int)$attempt->id]);
        $DB->delete_records('vrroom_attempts', ['id' => $attempt->id]);
        $transaction->allow_commit();

        // The resume position belonged to the deleted attempt.
        self::delete_resume_states($context, (int)$attempt->userid, (int)$attempt->id);

        $event = attempt_deleted::create([
            'objectid' => $attempt->id,
            'relateduserid' => (int)$attempt->userid,
            'context' => $context,
            'other' => ['vrroomid' => (int)$vrroom->id],
        ]);
        $event->add_record_snapshot('vrroom_attempts', $attempt);
        $event->trigger();

        grader::update_grades($vrroom, (int)$attempt->userid);
        self::update_completion($vrroom, (int)$attempt->userid);
    }

    /**
     * Delete the attempts of an activity (all users, or one), their usages, events and xAPI states;
     * then regrade.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int|null $userid one user, or null for everyone
     * @return void
     */
    public static function delete_all_for_instance(\stdClass $vrroom, ?int $userid = null): void {
        global $DB;
        $params = ['vrroomid' => $vrroom->id];
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        $attempts = $DB->get_records('vrroom_attempts', $params, '', 'id, userid, uniqueid');
        if ($attempts) {
            $transaction = $DB->start_delegated_transaction();
            $qubaids = [];
            foreach ($attempts as $attempt) {
                if (!empty($attempt->uniqueid)) {
                    $qubaids[] = (int)$attempt->uniqueid;
                }
            }
            if ($qubaids) {
                usage::delete($qubaids);
            }
            event_store::delete_for_attempts(array_keys($attempts));
            $DB->delete_records('vrroom_attempts', $params);
            $transaction->allow_commit();
        }

        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $vrroom->course, false, IGNORE_MISSING);
        if ($cm) {
            // Every state id of the module (the legacy per-module resume position and the per-attempt
            // ones), for the user or for everyone.
            self::wipe_xapi_states(\context_module::instance($cm->id), $userid);
        }

        if ($userid !== null) {
            grader::update_grades($vrroom, $userid);
        } else {
            grader::grade_item_update($vrroom, 'reset');
        }
    }

    /**
     * Remove the resume positions of one attempt: its own state and the legacy per-module state
     * (written before resume positions were kept per attempt).
     *
     * @param \context_module $context module context
     * @param int $userid the attempt's user
     * @param int $attemptid attempt id
     * @return void
     */
    private static function delete_resume_states(\context_module $context, int $userid, int $attemptid): void {
        self::wipe_xapi_states($context, $userid, config_builder::RESUME_STATEID);
        self::wipe_xapi_states($context, $userid, config_builder::resume_stateid($attemptid));
    }

    /**
     * Remove VR room's xAPI states (resume positions) of a module context.
     *
     * @param \context_module $context module context
     * @param int|null $userid one user, or null for everyone
     * @param string|null $stateid one state id, or null for all of them
     * @return void
     */
    private static function wipe_xapi_states(\context_module $context, ?int $userid, ?string $stateid = null): void {
        try {
            \core_xapi\handler::create('mod_vrroom')->wipe_states((string)$context->id, $userid, $stateid);
        } catch (\Throwable $e) {
            debugging('Could not remove VR room xAPI states: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Re-evaluate automatic completion of a user.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int $userid
     * @return void
     */
    private static function update_completion(\stdClass $vrroom, int $userid): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $vrroom->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $completion = new \completion_info(get_course($vrroom->course));
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid);
        }
    }
}
