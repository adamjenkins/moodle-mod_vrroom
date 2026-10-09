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

namespace mod_vrroom\xapi;

use core_xapi\local\state;
use core_xapi\local\statement;
use core_xapi\xapi_exception;
use mod_vrroom\event\statement_recorded;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\launch\config_builder;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\xapi\iri;

/**
 * xAPI handler of mod_vrroom: validates and stores browser statements (core_xapi_statement_post)
 * and guards the resume state (core_xapi_post_state and friends).
 *
 * Trust rules (DESIGN §5.3): the actor is the logged-in user (core checks it, and the attempt
 * must be theirs); the object must belong to the attempt's activity and exist in its scenes;
 * the attempt must be open; only client verbs are accepted; an event whose objective is still
 * locked on the server is rejected; a resent statement id is accepted once and ignored after;
 * batch size and per-attempt volume are limited (the volume under a per-attempt lock); a statement
 * for an attempt whose time ran out is refused and the attempt is finished; a user who can no
 * longer see the activity or is no longer actively enrolled is refused, as validate_context would.
 * Accepted statements are labelled client-asserted. Results sent by a client are ignored.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handler extends \core_xapi\handler {
    /** @var string Pattern of a lowercase uuid. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var int Seconds a client timestamp may lie outside the attempt window. */
    private const CLOCK_SKEW = 60;

    /** @var int Seconds to wait for the per-attempt events lock. */
    private const LOCK_TIMEOUT = 5;

    /** @var array Per-batch cache: attempt id => attempt row or false. */
    private array $attempts = [];

    /** @var array Per-batch cache: vrroom id => activity data. */
    private array $activities = [];

    /** @var array Per-batch cache: "vrroomid:version" => evaluator. */
    private array $evaluators = [];

    /** @var array Per-batch cache: attempt id => §4.4.1 events so far. */
    private array $events = [];

    /** @var array Per-request cache: "cmid:userid" => whether the user may still use the activity. */
    private array $access = [];

    /**
     * Not used: process_statements() is overridden and stores statements itself.
     *
     * @param statement $statement
     * @return \core\event\base|null always null
     */
    public function statement_to_event(statement $statement): ?\core\event\base {
        return null;
    }

    /**
     * Validate and store a batch of statements.
     *
     * @param statement[] $statements statements keyed as posted
     * @return array key => bool (true = stored, or already stored earlier)
     * @throws xapi_exception when the batch is larger than the maxbatch setting
     */
    public function process_statements(array $statements): array {
        if (count($statements) > config_builder::get_maxbatch()) {
            throw new xapi_exception('Too many statements in one batch');
        }
        $this->reset_caches();

        $results = [];
        $touched = [];
        foreach ($statements as $key => $statement) {
            try {
                $results[$key] = $this->process_statement($statement, $touched);
            } catch (\Throwable $e) {
                debugging('VR room statement rejected: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $results[$key] = false;
            }
        }

        foreach ($touched as $attempt) {
            attempt_manager::recompute($attempt);
        }
        $this->reset_caches();
        return $results;
    }

    /**
     * Validate a state request: only the resume state of a VR room module the user can attempt.
     *
     * @param state $state
     * @return bool
     */
    protected function validate_state(state $state): bool {
        if ($state->get_state_id() !== config_builder::RESUME_STATEID) {
            return false;
        }
        $activityid = $state->get_activity_id();
        if (!preg_match('/^[1-9]\d*$/', $activityid)) {
            return false;
        }
        $context = \context::instance_by_id((int)$activityid, IGNORE_MISSING);
        if (!$context instanceof \context_module) {
            return false;
        }
        $cm = get_coursemodule_from_id('vrroom', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $userid = (int)$state->get_user()->id;
        return has_capability('mod/vrroom:attempt', $context, $userid) && $this->can_access($cm, $userid);
    }

    /**
     * Validate and store one statement.
     *
     * @param statement $statement
     * @param array $touched attempt id => attempt, filled with attempts that got a new row
     * @return bool
     */
    private function process_statement(statement $statement, array &$touched): bool {
        $userid = (int)$statement->get_user()->id;

        // Ids travel in context extensions: core drops the statement's top-level id.
        $context = $statement->get_context();
        $ext = $context ? (array)($context->get_data()->extensions ?? []) : [];
        $rawattempt = $ext[iri::EXT_ATTEMPT] ?? null;
        $rawsid = $ext[iri::EXT_STATEMENT] ?? null;
        if (is_int($rawattempt)) {
            $attemptid = $rawattempt;
        } else if (is_string($rawattempt) && preg_match('/^[1-9]\d{0,9}$/', $rawattempt)) {
            $attemptid = (int)$rawattempt;
        } else {
            return false;
        }
        if (!is_string($rawsid)) {
            return false;
        }
        $statementid = strtolower($rawsid);
        if (!preg_match(self::UUID_PATTERN, $statementid)) {
            return false;
        }

        // Idempotent resend: accepted (true) but not stored again, only for the same user.
        if (event_store::exists($statementid)) {
            return $this->owns_statement($statementid, $userid);
        }

        $attempt = $this->get_attempt($attemptid);
        if (!$attempt || $attempt->state !== attempt_manager::STATE_INPROGRESS || (int)$attempt->userid !== $userid) {
            return false;
        }
        if (attempt_manager::is_overdue($attempt)) {
            // Nothing is accepted after the deadline; the attempt is finished instead.
            attempt_manager::close_if_overdue($attempt);
            unset($this->attempts[$attemptid], $this->events[$attemptid], $touched[$attemptid]);
            return false;
        }
        $activity = $this->get_activity($attempt);
        if (!$activity || !has_capability('mod/vrroom:attempt', $activity['context'], $userid)) {
            return false;
        }
        if (!$this->can_access($activity['cm'], $userid)) {
            return false;
        }

        $verb = iri::verb_short($statement->get_verb_id());
        if ($verb === null || !array_key_exists($verb, iri::CLIENT_VERBS)) {
            return false;
        }
        $object = iri::parse($statement->get_activity_id());
        if ($object === null || $object['cmid'] !== (int)$activity['cm']->id) {
            return false;
        }
        if (!in_array($object['type'], iri::CLIENT_VERBS[$verb], true)) {
            return false;
        }
        if (!$this->object_exists($activity['index'], $object)) {
            return false;
        }

        $limit = (int)get_config('mod_vrroom', 'maxattemptevents');
        if ($limit <= 0) {
            $limit = 5000;
        }

        $event = [
            'verb' => $verb,
            'type' => $object['type'],
            'id' => $object['type'] === 'activity' ? '' : (string)$object['id'],
            'scene' => $object['sceneid'],
            'fraction' => null,
        ];

        // Trust rule 3: an event whose objectives are all still locked on the server is rejected.
        if (!isset($this->events[$attemptid])) {
            $this->events[$attemptid] = event_store::get_events($attemptid);
        }
        $evaluator = $this->get_evaluator((int)$attempt->vrroomid, (int)$attempt->graphversion);
        if ($evaluator->classify($this->events[$attemptid], $event) === evaluator::STATUS_LOCKED) {
            return false;
        }

        $timestamp = $this->clamp_timestamp($statement->get_timestamp(), (int)$attempt->timestart);

        // The volume limit is a hard cap: count and insert under a per-attempt lock, so parallel
        // requests of the same attempt cannot each pass the count with a stale value.
        $lock = \core\lock\lock_config::get_lock_factory('mod_vrroom')->get_lock('events_' . $attemptid, self::LOCK_TIMEOUT);
        if (!$lock) {
            return false;
        }
        try {
            if (event_store::count($attemptid) >= $limit) {
                return false;
            }
            $rowid = event_store::insert($attemptid, $statementid, $event, true, null, $timestamp);
        } catch (\dml_exception $e) {
            // A concurrent request stored the same statement id first.
            return event_store::exists($statementid) && $this->owns_statement($statementid, $userid);
        } finally {
            $lock->release();
        }

        $this->events[$attemptid][] = $event;
        $touched[$attemptid] = $attempt;

        statement_recorded::create([
            'objectid' => $rowid,
            'relateduserid' => $userid,
            'context' => $activity['context'],
            'other' => [
                'attemptid' => $attemptid,
                'verb' => $verb,
                'objecttype' => $object['type'],
                'objectid' => $object['type'] === 'activity' ? null : (string)$object['id'],
            ],
        ])->trigger();

        return true;
    }

    /**
     * Whether a stored statement belongs to an attempt of the user.
     *
     * @param string $statementid
     * @param int $userid
     * @return bool
     */
    private function owns_statement(string $statementid, int $userid): bool {
        global $DB;
        return $DB->record_exists_sql(
            'SELECT 1 FROM {vrroom_events} e JOIN {vrroom_attempts} a ON a.id = e.attemptid
              WHERE e.statementid = :sid AND a.userid = :userid',
            ['sid' => $statementid, 'userid' => $userid]
        );
    }

    /**
     * Whether the user may still use the activity, as validate_context would decide (cached per request).
     *
     * The capability alone ignores a hidden or restricted activity and a suspended or ended
     * enrolment, so the activity must be visible to the user and the user must be actively
     * enrolled (or viewing the course without enrolment) in a course they can see.
     *
     * @param \stdClass $cm course module row (id, course)
     * @param int $userid
     * @return bool
     */
    private function can_access(\stdClass $cm, int $userid): bool {
        $key = (int)$cm->id . ':' . $userid;
        if (!array_key_exists($key, $this->access)) {
            $this->access[$key] = $this->check_access((int)$cm->course, (int)$cm->id, $userid);
        }
        return $this->access[$key];
    }

    /**
     * Uncached access check of can_access().
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    private function check_access(int $courseid, int $cmid, int $userid): bool {
        try {
            $modinfo = get_fast_modinfo($courseid, $userid);
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return false;
        }
        if (!$cm->uservisible) {
            return false;
        }
        if ($courseid === (int)SITEID) {
            // Front page activities need no enrolment.
            return true;
        }
        $course = $modinfo->get_course();
        $coursecontext = \context_course::instance($courseid);
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $coursecontext, $userid)) {
            return false;
        }
        return is_enrolled($coursecontext, $userid, '', true) || is_viewing($coursecontext, $userid);
    }

    /**
     * Load an attempt (cached per batch).
     *
     * @param int $attemptid
     * @return \stdClass|null
     */
    private function get_attempt(int $attemptid): ?\stdClass {
        global $DB;
        if (!array_key_exists($attemptid, $this->attempts)) {
            $this->attempts[$attemptid] = $DB->get_record('vrroom_attempts', ['id' => $attemptid]) ?: false;
        }
        return $this->attempts[$attemptid] ?: null;
    }

    /**
     * Load the activity of an attempt with an index of its scene ids (cached per batch).
     *
     * @param \stdClass $attempt
     * @return array|null ['cm' => \stdClass, 'context' => \context_module, 'index' => array]
     */
    private function get_activity(\stdClass $attempt): ?array {
        $vrroomid = (int)$attempt->vrroomid;
        if (!array_key_exists($vrroomid, $this->activities)) {
            $this->activities[$vrroomid] = false;
            $cm = get_coursemodule_from_instance('vrroom', $vrroomid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $index = [];
                foreach (instance_manager::get_scenes($vrroomid) as $scene) {
                    $sceneid = (string)($scene['id'] ?? '');
                    if ($sceneid === '') {
                        continue;
                    }
                    $entry = ['zones' => [], 'objects' => []];
                    foreach ($scene['zones'] ?? [] as $zone) {
                        if (isset($zone['id'])) {
                            $entry['zones'][(string)$zone['id']] = true;
                        }
                    }
                    foreach ($scene['interactables'] ?? [] as $item) {
                        if (isset($item['id'])) {
                            $entry['objects'][(string)$item['id']] = true;
                        }
                    }
                    $index[$sceneid] = $entry;
                }
                $this->activities[$vrroomid] = [
                    'cm' => $cm,
                    'context' => \context_module::instance($cm->id),
                    'index' => $index,
                ];
            }
        }
        return $this->activities[$vrroomid] ?: null;
    }

    /**
     * Whether the parsed object exists in the activity's scenes.
     *
     * @param array $index scene id => ['zones' => set, 'objects' => set]
     * @param array $object parsed IRI
     * @return bool
     */
    private function object_exists(array $index, array $object): bool {
        switch ($object['type']) {
            case 'activity':
                return true;
            case 'scene':
                return isset($index[$object['sceneid']]);
            case 'zone':
                return isset($index[$object['sceneid']]['zones'][$object['id']]);
            case 'object':
                return isset($index[$object['sceneid']]['objects'][$object['id']]);
        }
        return false;
    }

    /**
     * Evaluator for a graph version (cached per batch).
     *
     * @param int $vrroomid
     * @param int $version
     * @return evaluator
     */
    private function get_evaluator(int $vrroomid, int $version): evaluator {
        $key = $vrroomid . ':' . $version;
        if (!isset($this->evaluators[$key])) {
            $this->evaluators[$key] = new evaluator(
                instance_manager::get_graph($vrroomid, $version),
                mode::evaluator_options_for($vrroomid)
            );
        }
        return $this->evaluators[$key];
    }

    /**
     * Client time, or server time when absent, unparsable or outside the attempt window.
     *
     * @param string|null $timestamp ISO 8601
     * @param int $timestart attempt start
     * @return int
     */
    private function clamp_timestamp(?string $timestamp, int $timestart): int {
        $now = time();
        if ($timestamp === null || $timestamp === '') {
            return $now;
        }
        $time = strtotime($timestamp);
        if ($time === false || $time < $timestart - self::CLOCK_SKEW || $time > $now + self::CLOCK_SKEW) {
            return $now;
        }
        return $time;
    }

    /**
     * Empty the per-batch caches.
     *
     * @return void
     */
    private function reset_caches(): void {
        $this->attempts = [];
        $this->activities = [];
        $this->evaluators = [];
        $this->events = [];
        $this->access = [];
    }
}
