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

use mod_vrroom\local\instance_manager;

/**
 * Tests of the attempt manager and the event store.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(event_store::class)]
final class attempt_manager_test extends \advanced_testcase {
    /**
     * Create a course, a gallery with one question station and one text station, and a student.
     *
     * @param array $record extra module fields
     * @return \stdClass course, vrroom, cm, context, student, sceneid, zones (name => id), slot
     */
    private function setup_activity(array $record = []): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        $module = $generator->create_module('vrroom', $record + [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => (int)$question->questionbankentryid,
                    'required' => 1, 'points' => 1, 'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>Portrait</p>', 'questionbankentryid' => 0, 'required' => 1,
                    'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $zones = [];
        foreach ($scene['zones'] as $zone) {
            $zones[$zone['name']] = $zone['id'];
        }
        $slot = 0;
        foreach (instance_manager::get_graph((int)$vrroom->id)['objectives'] as $objective) {
            if ($objective['type'] === 'answer_question') {
                $slot = (int)$objective['slot'];
            }
        }
        return (object) [
            'course' => $course,
            'vrroom' => $vrroom,
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'student' => $generator->create_and_enrol($course, 'student'),
            'sceneid' => $scene['id'],
            'zones' => $zones,
            'slot' => $slot,
        ];
    }

    /**
     * Store a client event directly.
     *
     * @param \stdClass $attempt
     * @param \stdClass $fx fixture
     * @param string $zone zone name
     * @return void
     */
    private function enter(\stdClass $attempt, \stdClass $fx, string $zone): void {
        event_store::insert(
            (int)$attempt->id,
            \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $fx->zones[$zone], 'scene' => $fx->sceneid, 'fraction' => null],
            true,
            null,
            time()
        );
    }

    /**
     * Start creates an attempt with a usage, a launched statement and an event; a second call resumes it.
     *
     * @return void
     */
    public function test_start_and_resume(): void {
        global $DB;
        $fx = $this->setup_activity();
        $sink = $this->redirectEvents();
        [$attempt, $resumed] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($resumed);
        $this->assertSame(1, (int)$attempt->attempt);
        $this->assertSame('inprogress', $attempt->state);
        $this->assertGreaterThan(0, (int)$attempt->uniqueid);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertSame((int)$fx->vrroom->graphversion, (int)$attempt->graphversion);
        $slotmap = json_decode($attempt->slotmap, true);
        $this->assertArrayHasKey((string)$fx->slot, $slotmap);
        $this->assertTrue($DB->record_exists('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'launched',
            'objecttype' => 'activity', 'clientasserted' => 0]));
        $started = array_values(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\attempt_started));
        $this->assertCount(1, $started);

        [$again, $resumed] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->assertTrue($resumed);
        $this->assertEquals($attempt->id, $again->id);
        $this->assertSame(1, $DB->count_records('vrroom_attempts', ['vrroomid' => $fx->vrroom->id]));
    }

    /**
     * The attempt limit is enforced, and numbering continues after a deleted attempt.
     *
     * @return void
     */
    public function test_max_attempts(): void {
        global $DB;
        $fx = $this->setup_activity(['maxattempts' => 2]);
        $userid = (int)$fx->student->id;
        [$first] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, $userid);
        attempt_manager::finish($first);
        [$second] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, $userid);
        $this->assertSame(2, (int)$second->attempt);
        attempt_manager::finish($second);

        try {
            attempt_manager::start_or_resume($fx->cm, $fx->vrroom, $userid);
            $this->fail('A third attempt must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornomoreattempts', $e->errorcode);
        }

        attempt_manager::delete_attempt(attempt_manager::get_attempt((int)$first->id));
        [$third] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, $userid);
        $this->assertSame(3, (int)$third->attempt);
        $this->assertSame(2, $DB->count_records('vrroom_attempts', ['vrroomid' => $fx->vrroom->id, 'userid' => $userid]));
    }

    /**
     * Recompute persists score and requireddone and records each completed objective once.
     *
     * @return void
     */
    public function test_recompute(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);

        $this->enter($attempt, $fx, 'Portrait');
        $result = attempt_manager::recompute($attempt);
        $this->assertEqualsWithDelta(0.5, $result->score, 1e-6);
        $this->assertEqualsWithDelta(0.5, (float)$DB->get_field('vrroom_attempts', 'score', ['id' => $attempt->id]), 1e-6);
        $this->assertEquals(0, $DB->get_field('vrroom_attempts', 'requireddone', ['id' => $attempt->id]));

        $this->enter($attempt, $fx, 'Vase');
        $this->enter($attempt, $fx, 'Vase');
        attempt_manager::recompute($attempt);
        attempt_manager::recompute($attempt);
        $completed = $DB->get_records('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'completed',
            'objecttype' => 'objective'], 'id ASC');
        $this->assertCount(2, $completed);
        $ids = array_map(fn($r) => $r->objectid, $completed);
        $this->assertCount(2, array_unique($ids));

        // Derived rows are never fed back into the replay.
        foreach (event_store::get_events((int)$attempt->id) as $event) {
            $this->assertNotContains($event['verb'], event_store::DERIVED_VERBS);
        }

        $state = attempt_manager::get_state(attempt_manager::get_attempt((int)$attempt->id));
        $this->assertSame((int)$attempt->id, $state['attemptid']);
        $this->assertSame('inprogress', $state['state']);
        $this->assertCount(count(instance_manager::get_graph((int)$fx->vrroom->id)['objectives']), $state['objectives']);
        $this->assertFalse($state['allrequiredcompleted']);
    }

    /**
     * Finishing closes the attempt, records server statements, grades and is refused twice.
     *
     * @return void
     */
    public function test_finish(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->enter($attempt, $fx, 'Portrait');

        $sink = $this->redirectEvents();
        $finished = attempt_manager::finish($attempt);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame('finished', $finished->state);
        $this->assertGreaterThan(0, (int)$finished->timefinish);
        $this->assertEqualsWithDelta(0.5, (float)$finished->score, 1e-6);
        $row = $DB->get_record('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'completed',
            'objecttype' => 'activity'], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(50.0, json_decode($row->resultjson, true)['grade'], 1e-6);
        $this->assertNotEmpty(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\attempt_finished));

        $grades = grade_get_grades($fx->course->id, 'mod', 'vrroom', $fx->vrroom->id, (int)$fx->student->id);
        $this->assertEqualsWithDelta(50.0, (float)$grades->items[0]->grades[$fx->student->id]->grade, 1e-6);

        $this->expectException(\moodle_exception::class);
        attempt_manager::finish($finished);
    }

    /**
     * Pass and fail statements are recorded when the grade item has a pass grade.
     *
     * @return void
     */
    public function test_finish_records_passed_or_failed(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $fx = $this->setup_activity();
        $item = \grade_item::fetch(['courseid' => $fx->course->id, 'itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $fx->vrroom->id, 'itemnumber' => 0]);
        $item->gradepass = 40;
        $item->update();

        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->enter($attempt, $fx, 'Portrait');
        attempt_manager::finish($attempt);
        $this->assertTrue($DB->record_exists('vrroom_events', ['attemptid' => $attempt->id, 'verb' => 'passed']));

        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        [$attempt2] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$other->id);
        attempt_manager::finish($attempt2);
        $this->assertTrue($DB->record_exists('vrroom_events', ['attemptid' => $attempt2->id, 'verb' => 'failed']));
    }

    /**
     * The ownership and state checks of require_own_open_attempt.
     *
     * @return void
     */
    public function test_require_own_open_attempt(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        [$loaded, $vrroom, $cm, $context] = attempt_manager::require_own_open_attempt(
            (int)$attempt->id,
            (int)$fx->student->id
        );
        $this->assertEquals($attempt->id, $loaded->id);
        $this->assertEquals($fx->vrroom->id, $vrroom->id);
        $this->assertEquals($fx->cm->id, $cm->id);
        $this->assertEquals($fx->context->id, $context->id);

        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        try {
            attempt_manager::require_own_open_attempt((int)$attempt->id, (int)$other->id);
            $this->fail('Another user\'s attempt must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotyourattempt', $e->errorcode);
        }

        attempt_manager::finish($attempt);
        try {
            attempt_manager::require_own_open_attempt((int)$attempt->id, (int)$fx->student->id);
            $this->fail('A finished attempt must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptclosed', $e->errorcode);
        }
    }

    /**
     * Deleting one attempt removes its usage and events, logs it and regrades.
     *
     * @return void
     */
    public function test_delete_attempt(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $this->enter($attempt, $fx, 'Portrait');
        $attempt = attempt_manager::finish($attempt);

        $sink = $this->redirectEvents();
        attempt_manager::delete_attempt($attempt);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($DB->record_exists('vrroom_attempts', ['id' => $attempt->id]));
        $this->assertFalse($DB->record_exists('vrroom_events', ['attemptid' => $attempt->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertNotEmpty(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\attempt_deleted));
        $grades = grade_get_grades($fx->course->id, 'mod', 'vrroom', $fx->vrroom->id, (int)$fx->student->id);
        $this->assertNull($grades->items[0]->grades[$fx->student->id]->grade);
    }

    /**
     * Deleting all attempts of one user or of everyone.
     *
     * @return void
     */
    public function test_delete_all_for_instance(): void {
        global $DB;
        $fx = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        [$a1] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        [$a2] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$other->id);
        $this->enter($a1, $fx, 'Vase');
        $this->enter($a2, $fx, 'Vase');

        attempt_manager::delete_all_for_instance($fx->vrroom, (int)$fx->student->id);
        $this->assertFalse($DB->record_exists('vrroom_attempts', ['id' => $a1->id]));
        $this->assertFalse($DB->record_exists('vrroom_events', ['attemptid' => $a1->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $a1->uniqueid]));
        $this->assertTrue($DB->record_exists('vrroom_attempts', ['id' => $a2->id]));

        attempt_manager::delete_all_for_instance($fx->vrroom);
        $this->assertFalse($DB->record_exists('vrroom_attempts', ['vrroomid' => $fx->vrroom->id]));
        $this->assertFalse($DB->record_exists('vrroom_events', ['attemptid' => $a2->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $a2->uniqueid]));
    }

    /**
     * Event store basics: exists is case-insensitive, rows keep insertion order, scene rows keep both ids.
     *
     * @return void
     */
    public function test_event_store(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->vrroom, (int)$fx->student->id);
        $sid = strtoupper(\core\uuid::generate());
        event_store::insert(
            (int)$attempt->id,
            $sid,
            ['verb' => 'entered', 'type' => 'scene', 'id' => $fx->sceneid, 'scene' => null, 'fraction' => null],
            true,
            null,
            123
        );
        $this->assertTrue(event_store::exists($sid));
        $this->assertTrue(event_store::exists(strtolower($sid)));
        $rows = event_store::get_rows((int)$attempt->id);
        $last = end($rows);
        $this->assertSame(strtolower($sid), $last->statementid);
        $this->assertSame($fx->sceneid, $last->sceneid);
        $this->assertSame($fx->sceneid, $last->objectid);
        $this->assertEquals(123, $last->timestamp);
        $this->assertSame(count($rows), event_store::count((int)$attempt->id));

        event_store::delete_for_attempts([(int)$attempt->id]);
        $this->assertSame(0, event_store::count((int)$attempt->id));
    }
}
