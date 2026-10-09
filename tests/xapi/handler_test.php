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
use core_xapi\local\statement\item_activity;
use core_xapi\local\statement\item_agent;
use core_xapi\xapi_exception;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\xapi\iri;

/**
 * Trust tests of the VR room xAPI handler (DESIGN §7).
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(handler::class)]
final class handler_test extends \advanced_testcase {
    /** @var int|null Attempt that inject_rows() adds rows to, null when it should do nothing. */
    private static ?int $injectattempt = null;

    /** @var int Number of rows inject_rows() adds, once. */
    private static int $injectcount = 0;

    /**
     * Create a course, a two-station gallery and an enrolled student.
     *
     * @param array $record extra module fields
     * @return \stdClass course, vrroom, cm, context, student, sceneid, zones (name => id), objects (name => hotspot id)
     */
    private function setup_activity(array $record = []): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', $record + [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => 0, 'required' => 1, 'points' => 1,
                    'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>Portrait</p>', 'questionbankentryid' => 0, 'required' => 1,
                    'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $student = $generator->create_and_enrol($course, 'student');

        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $zones = [];
        foreach ($scene['zones'] as $zone) {
            $zones[$zone['name']] = $zone['id'];
        }
        $objects = [];
        foreach ($scene['interactables'] as $item) {
            if ($item['kind'] === 'hotspot') {
                $objects[$item['name']] = $item['id'];
            }
        }
        return (object) [
            'course' => $course,
            'vrroom' => $vrroom,
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'student' => $student,
            'sceneid' => $scene['id'],
            'zones' => $zones,
            'objects' => $objects,
        ];
    }

    /**
     * Build a client statement.
     *
     * @param \stdClass $user actor
     * @param string $verb short verb
     * @param string $objectiri object IRI
     * @param int|string|null $attemptid attempt id extension (null = absent)
     * @param string|null $statementid statement id extension (null = fresh uuid)
     * @return statement
     */
    private function statement(
        \stdClass $user,
        string $verb,
        string $objectiri,
        $attemptid,
        ?string $statementid = null
    ): statement {
        global $CFG;
        $extensions = [
            iri::EXT_STATEMENT => $statementid ?? \core\uuid::generate(),
            iri::EXT_VOCAB => iri::VOCAB,
            iri::EXT_CHANNEL => 'builtin-3d',
        ];
        if ($attemptid !== null) {
            $extensions[iri::EXT_ATTEMPT] = $attemptid;
        }
        return statement::create_from_data((object) [
            'actor' => (object) [
                'objectType' => 'Agent',
                'account' => (object) ['homePage' => $CFG->wwwroot, 'name' => (string)$user->id],
            ],
            'verb' => (object) ['id' => iri::verb_iri($verb), 'display' => (object) ['en' => $verb]],
            'object' => (object) ['objectType' => 'Activity', 'id' => $objectiri],
            'context' => (object) ['extensions' => (object)$extensions],
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Zone IRI helper.
     *
     * @param \stdClass $fx fixture
     * @param string $name zone name
     * @return string
     */
    private function zone_iri(\stdClass $fx, string $name): string {
        return iri::for_object((int)$fx->cm->id, 'zone', $fx->sceneid, $fx->zones[$name]);
    }

    /**
     * Number of client rows of an attempt.
     *
     * @param int $attemptid
     * @return int
     */
    private function client_rows(int $attemptid): int {
        global $DB;
        return $DB->count_records('vrroom_events', ['attemptid' => $attemptid, 'clientasserted' => 1]);
    }

    /**
     * A valid statement is stored, labelled client-asserted, logged, and replayed.
     *
     * @return void
     */
    public function test_valid_statement_is_stored_and_replayed(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);

        $sid = \core\uuid::generate();
        $sink = $this->redirectEvents();
        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id, $sid),
        ]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([0 => true], $result);
        $row = $DB->get_record('vrroom_events', ['statementid' => strtolower($sid)], '*', MUST_EXIST);
        $this->assertEquals(1, $row->clientasserted);
        $this->assertSame('entered', $row->verb);
        $this->assertSame('zone', $row->objecttype);
        $this->assertSame($fx->zones['Vase'], $row->objectid);
        $this->assertSame($fx->sceneid, $row->sceneid);
        $this->assertNull($row->resultjson);

        $recorded = array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\statement_recorded);
        $this->assertCount(1, $recorded);

        // The replay recorded the completed visit objective once, as a server statement.
        $completed = $DB->get_records('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'completed',
            'objecttype' => 'objective']);
        $this->assertCount(1, $completed);
        $this->assertEquals(0, reset($completed)->clientasserted);
        $state = attempt_manager::get_state(attempt_manager::get_attempt((int)$attempt->id));
        $this->assertEqualsWithDelta(0.5, $state['score'], 1e-6);
    }

    /**
     * Core refuses a statement whose actor is not the current user.
     *
     * @return void
     */
    public function test_forged_actor_is_refused_by_core(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        $this->setUser($other);

        $statement = $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id);
        $this->expectException(xapi_exception::class);
        \core_xapi\external\post_statement::execute('mod_vrroom', json_encode([$statement]));
    }

    /**
     * A statement about another activity is rejected.
     *
     * @return void
     */
    public function test_wrong_activity_is_rejected(): void {
        global $DB;
        $fx = $this->setup_activity();
        $other = $this->getDataGenerator()->create_module('vrroom', ['course' => $fx->course->id]);
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);

        $iri = iri::for_object((int)$other->cmid, 'zone', $fx->sceneid, $fx->zones['Vase']);
        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $iri, (int)$attempt->id),
            $this->statement($fx->student, 'entered', 'http://example.com/mod/vrroom/xapi/' . $fx->cm->id, (int)$attempt->id),
        ]);
        $this->assertSame([0 => false, 1 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));
    }

    /**
     * Statements for a finished attempt are rejected.
     *
     * @return void
     */
    public function test_closed_attempt_is_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        attempt_manager::finish($attempt);
        $this->setUser($fx->student);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));
    }

    /**
     * A user cannot post into another user's attempt.
     *
     * @return void
     */
    public function test_other_users_attempt_is_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $intruder = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$intruder->id);
        $this->setUser($intruder);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($intruder, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));
    }

    /**
     * An event whose objective is locked on the server is rejected, then accepted once unlocked.
     *
     * @return void
     */
    public function test_locked_objective_is_rejected(): void {
        $fx = $this->setup_activity(['gallery_ordered' => 1]);
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);
        $handler = handler::create('mod_vrroom');

        // Station 2 opens only after station 1 has been visited.
        $result = $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Portrait'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));

        // In one batch: the first visit unlocks the second, so both are accepted.
        $result = $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Portrait'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertSame(2, $this->client_rows((int)$attempt->id));
    }

    /**
     * A resent statement id is accepted but stored once, in the same batch and across batches.
     *
     * @return void
     */
    public function test_resent_statement_is_counted_once(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);
        $handler = handler::create('mod_vrroom');
        $sid = \core\uuid::generate();

        $result = $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id, $sid),
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id, $sid),
        ]);
        $this->assertSame([0 => true, 1 => true], $result);
        $result = $handler->process_statements([
            $this->statement(
                $fx->student,
                'entered',
                $this->zone_iri($fx, 'Vase'),
                (int)$attempt->id,
                strtoupper($sid)
            ),
        ]);
        $this->assertSame([0 => true], $result);
        $this->assertSame(1, $this->client_rows((int)$attempt->id));

        // Through core's web service the resend is not an error either.
        $statement = $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id, $sid);
        $this->assertSame([true], \core_xapi\external\post_statement::execute('mod_vrroom', json_encode([$statement])));
        $this->assertSame(1, $this->client_rows((int)$attempt->id));
    }

    /**
     * A resent statement id posted by a different user is not accepted.
     *
     * @return void
     */
    public function test_resent_statement_of_another_user_is_not_accepted(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        [$otherattempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$other->id);
        $handler = handler::create('mod_vrroom');
        $sid = \core\uuid::generate();

        $this->setUser($fx->student);
        $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id, $sid),
        ]);
        $this->setUser($other);
        $result = $handler->process_statements([
            $this->statement($other, 'entered', $this->zone_iri($fx, 'Vase'), (int)$otherattempt->id, $sid),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$otherattempt->id));
    }

    /**
     * A batch over the maxbatch setting is refused as a whole.
     *
     * @return void
     */
    public function test_over_limit_batch_is_refused(): void {
        $fx = $this->setup_activity();
        set_config('maxbatch', 2, 'mod_vrroom');
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);

        $statements = [];
        for ($i = 0; $i < 3; $i++) {
            $statements[] = $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id);
        }
        try {
            handler::create('mod_vrroom')->process_statements($statements);
            $this->fail('An over-limit batch must throw');
        } catch (xapi_exception $e) {
            $this->assertSame(0, $this->client_rows((int)$attempt->id));
        }
    }

    /**
     * The per-attempt volume limit stops storing further statements.
     *
     * @return void
     */
    public function test_max_attempt_events(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $stored = $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]);
        set_config('maxattemptevents', $stored + 1, 'mod_vrroom');
        $this->setUser($fx->student);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'exited', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
            $this->statement($fx->student, 'exited', $this->zone_iri($fx, 'Portrait'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => true, 1 => false], $result);
        $this->assertSame(1, $this->client_rows((int)$attempt->id));
    }

    /**
     * Test observer: after the first stored statement, store rows for the attempt as another
     * request of the same attempt would.
     *
     * @param \mod_vrroom\event\statement_recorded $event
     * @return void
     */
    public static function inject_rows(\mod_vrroom\event\statement_recorded $event): void {
        if (self::$injectattempt === null) {
            return;
        }
        $attemptid = self::$injectattempt;
        self::$injectattempt = null;
        for ($i = 0; $i < self::$injectcount; $i++) {
            \mod_vrroom\local\attempt\event_store::insert(
                $attemptid,
                \core\uuid::generate(),
                ['verb' => 'exited', 'type' => 'activity', 'id' => '', 'scene' => null],
                false,
                null,
                time()
            );
        }
    }

    /**
     * The volume limit counts rows stored meanwhile by another request, not a count read once per batch.
     *
     * @return void
     */
    public function test_max_attempt_events_counts_rows_stored_meanwhile(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $stored = $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]);
        set_config('maxattemptevents', $stored + 3, 'mod_vrroom');
        $this->setUser($fx->student);

        // Two rows land between the batch's first and second statement.
        self::$injectattempt = (int)$attempt->id;
        self::$injectcount = 2;
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_vrroom\event\statement_recorded',
            'callback' => '\\' . self::class . '::inject_rows',
        ]]);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'exited', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
            $this->statement($fx->student, 'exited', $this->zone_iri($fx, 'Portrait'), (int)$attempt->id),
        ]);
        $this->assertNull(self::$injectattempt, 'The observer must have run');
        $this->assertSame([0 => true, 1 => false], $result);
        $this->assertSame(1, $this->client_rows((int)$attempt->id));
        $this->assertSame($stored + 3, $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]));
    }

    /**
     * Statements and the resume state are refused once the activity is hidden from the student.
     *
     * @return void
     */
    public function test_hidden_activity_is_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        set_coursemodule_visible((int)$fx->cm->id, 0);
        $this->assertTrue(has_capability('mod/vrroom:attempt', $fx->context, $fx->student));
        $this->setUser($fx->student);

        $handler = handler::create('mod_vrroom');
        $result = $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));

        $this->assert_state_refused($handler, $fx);
    }

    /**
     * Statements and the resume state are refused once the student's enrolment is suspended,
     * although the role (and so the capability) is kept.
     *
     * @return void
     */
    public function test_suspended_enrolment_is_rejected(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $instance = $DB->get_record('enrol', ['courseid' => $fx->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, (int)$fx->student->id, ENROL_USER_SUSPENDED);
        $this->assertTrue(has_capability('mod/vrroom:attempt', $fx->context, $fx->student));
        $this->setUser($fx->student);

        $handler = handler::create('mod_vrroom');
        $result = $handler->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));

        $this->assert_state_refused($handler, $fx);
    }

    /**
     * Assert that the current user's resume state is refused.
     *
     * @param handler $handler
     * @param \stdClass $fx fixture
     * @return void
     */
    private function assert_state_refused(handler $handler, \stdClass $fx): void {
        global $USER;
        $state = new state(
            item_agent::create_from_user($USER),
            item_activity::create_from_id((string)$fx->context->id),
            'vrroom-resume',
            (object) ['scene' => $fx->sceneid, 'position' => [0, 0, 1], 'yaw' => 0],
            null
        );
        try {
            $handler->save_state($state);
            $this->fail('State must be refused');
        } catch (xapi_exception $e) {
            $this->assertStringContainsString('not accepted', $e->getMessage());
        }
    }

    /**
     * Ids that do not exist in the activity's scenes are rejected.
     *
     * @return void
     */
    public function test_unknown_ids_are_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);
        $cmid = (int)$fx->cm->id;

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement(
                $fx->student,
                'entered',
                iri::for_object($cmid, 'zone', $fx->sceneid, 'nosuchzone'),
                (int)$attempt->id
            ),
            $this->statement(
                $fx->student,
                'entered',
                iri::for_object($cmid, 'scene', 'nosuchscene', null),
                (int)$attempt->id
            ),
            $this->statement(
                $fx->student,
                'interacted',
                iri::for_object($cmid, 'object', $fx->sceneid, 'nosuchobject'),
                (int)$attempt->id
            ),
            $this->statement($fx->student, 'interacted', iri::for_object(
                $cmid,
                'object',
                $fx->sceneid,
                $fx->objects['Vase']
            ), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false, 1 => false, 2 => false, 3 => true], $result);
    }

    /**
     * Server-only verbs, wrong object types and missing extensions are rejected.
     *
     * @return void
     */
    public function test_server_verbs_and_malformed_statements_are_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->setUser($fx->student);
        $cmid = (int)$fx->cm->id;
        $activity = iri::activity($cmid);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'answered', iri::for_object($cmid, 'question', null, '1'), (int)$attempt->id),
            $this->statement($fx->student, 'completed', $activity, (int)$attempt->id),
            $this->statement($fx->student, 'passed', $activity, (int)$attempt->id),
            $this->statement($fx->student, 'launched', $activity, (int)$attempt->id),
            $this->statement($fx->student, 'initialized', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
            $this->statement($fx->student, 'initialized', $activity, null),
            $this->statement($fx->student, 'initialized', $activity, (int)$attempt->id, 'not-a-uuid'),
            $this->statement($fx->student, 'initialized', $activity, (int)$attempt->id),
        ]);
        $this->assertSame([0 => false, 1 => false, 2 => false, 3 => false, 4 => false, 5 => false, 6 => false,
            7 => true], $result);
        $this->assertSame(1, $this->client_rows((int)$attempt->id));
    }

    /**
     * A student without the attempt capability cannot post.
     *
     * @return void
     */
    public function test_missing_capability_is_rejected(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $roleid = $this->getDataGenerator()->create_role();
        $this->getDataGenerator()->role_assign($roleid, (int)$fx->student->id, $fx->context->id);
        assign_capability('mod/vrroom:attempt', CAP_PROHIBIT, $roleid, $fx->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/vrroom:attempt', $fx->context, $fx->student));
        $this->setUser($fx->student);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => false], $result);
    }

    /**
     * The resume state is accepted only with its state id, on a VR room module, for a user who can attempt.
     *
     * @return void
     */
    public function test_validate_state(): void {
        $fx = $this->setup_activity();
        $handler = handler::create('mod_vrroom');
        $data = (object) ['scene' => $fx->sceneid, 'position' => [0, 0, 1], 'yaw' => 12.5];

        $this->setUser($fx->student);
        $agent = item_agent::create_from_user($fx->student);
        $ok = new state($agent, item_activity::create_from_id((string)$fx->context->id), 'vrroom-resume', $data, null);
        $this->assertTrue($handler->save_state($ok));
        $loaded = $handler->load_state($ok);
        $this->assertEquals(12.5, $loaded->get_state_data()->yaw);

        $rejected = [
            new state($agent, item_activity::create_from_id((string)$fx->context->id), 'other', $data, null),
            new state(
                $agent,
                item_activity::create_from_id((string)\context_course::instance($fx->course->id)->id),
                'vrroom-resume',
                $data,
                null
            ),
            new state($agent, item_activity::create_from_id('999999999'), 'vrroom-resume', $data, null),
        ];
        foreach ($rejected as $state) {
            try {
                $handler->save_state($state);
                $this->fail('State must be refused');
            } catch (xapi_exception $e) {
                $this->assertStringContainsString('not accepted', $e->getMessage());
            }
        }

        // A teacher cannot attempt, so has no resume state.
        $teacher = $this->getDataGenerator()->create_and_enrol($fx->course, 'editingteacher');
        $this->setUser($teacher);
        $state = new state(
            item_agent::create_from_user($teacher),
            item_activity::create_from_id((string)$fx->context->id),
            'vrroom-resume',
            $data,
            null
        );
        $this->expectException(xapi_exception::class);
        $handler->save_state($state);
    }

    /**
     * A statement that arrives after the attempt's deadline is refused, and the attempt is
     * finished as of the deadline with a "timeup" completed statement.
     *
     * @return void
     */
    public function test_late_statement_is_refused_and_attempt_finished(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $deadline = time() - attempt_manager::DEADLINE_GRACE - 60;
        $DB->set_field('vrroom_attempts', 'timedue', $deadline, ['id' => $attempt->id]);
        $this->setUser($fx->student);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Portrait'), (int)$attempt->id),
        ]);

        $this->assertSame([0 => false, 1 => false], $result);
        $this->assertSame(0, $this->client_rows((int)$attempt->id));
        $row = $DB->get_record('vrroom_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(attempt_manager::STATE_FINISHED, $row->state);
        $this->assertEquals($deadline, $row->timefinish);
        $completed = $DB->get_records('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'completed',
            'objecttype' => 'activity']);
        $this->assertCount(1, $completed);
        $this->assertTrue(json_decode(reset($completed)->resultjson)->timeup);
    }

    /**
     * Within the grace period after the deadline a statement is still accepted.
     *
     * @return void
     */
    public function test_statement_within_grace_is_accepted(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        // Two seconds past the deadline is inside the grace period.
        $DB->set_field('vrroom_attempts', 'timedue', time() - 2, ['id' => $attempt->id]);
        $this->setUser($fx->student);

        $result = handler::create('mod_vrroom')->process_statements([
            $this->statement($fx->student, 'entered', $this->zone_iri($fx, 'Vase'), (int)$attempt->id),
        ]);
        $this->assertSame([0 => true], $result);
        $this->assertSame(
            attempt_manager::STATE_INPROGRESS,
            $DB->get_field('vrroom_attempts', 'state', ['id' => $attempt->id])
        );
    }
}
