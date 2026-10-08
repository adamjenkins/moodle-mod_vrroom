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

namespace mod_mavr\tests;

use core_xapi\local\statement;
use mod_mavr\external\finish_attempt;
use mod_mavr\external\submit_question;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\grading\grader;
use mod_mavr\local\instance_manager;
use mod_mavr\local\runtime\launch_descriptor;
use mod_mavr\local\runtime\registry;
use mod_mavr\local\xapi\iri;

/**
 * Run-mode conformance test (DESIGN §7): every mavrruntime_* plugin extends this class.
 *
 * A reference activity (two stations, one question) and a scripted session must yield the
 * reference statements and the reference grade, whatever run mode delivers the room.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class runtime_conformance_testcase extends \advanced_testcase {
    /**
     * Short name of the run mode under test, e.g. 'builtin'.
     *
     * @return string
     */
    abstract protected function get_runtime_name(): string;

    /**
     * The reference session: start, statements, a question, finish.
     *
     * @return void
     */
    public function test_reference_session(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $runtime = registry::get($this->get_runtime_name());
        $this->assertSame($this->get_runtime_name(), $runtime->get_name());
        $this->assertContains($runtime->statement_channel(), ['browser', 'server']);
        $readiness = $runtime->readiness();
        if (!$readiness->ready) {
            $this->markTestSkipped('Run mode not ready: ' . $readiness->message);
        }

        // Reference activity.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'runtime' => $this->get_runtime_name(),
            'grade' => 100,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Made in 1720.</p>', 'questionbankentryid' => (int)$question->questionbankentryid,
                    'required' => 1, 'points' => 1, 'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>A portrait.</p>', 'questionbankentryid' => 0, 'required' => 1,
                    'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $student = $generator->create_and_enrol($course, 'student');

        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $vasezone = null;
        $vasehotspot = null;
        foreach ($scene['zones'] as $zone) {
            if ($zone['name'] === 'Vase') {
                $vasezone = $zone['id'];
            }
        }
        foreach ($scene['interactables'] as $item) {
            if ($item['kind'] === 'hotspot' && ($item['zone'] ?? null) === $vasezone) {
                $vasehotspot = $item['id'];
            }
        }
        $slot = 0;
        foreach (instance_manager::get_graph((int)$mavr->id)['objectives'] as $objective) {
            if ($objective['type'] === 'answer_question') {
                $slot = (int)$objective['slot'];
            }
        }
        $this->assertNotNull($vasezone);
        $this->assertNotNull($vasehotspot);
        $this->assertGreaterThan(0, $slot);

        // Launch.
        $this->setUser($student);
        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, (int)$student->id);
        $descriptor = $runtime->launch($cm, $attempt, $student)->to_array();
        $this->assertContains($descriptor['type'], [launch_descriptor::TYPE_INLINE, launch_descriptor::TYPE_EXTERNAL]);
        if ($descriptor['type'] === launch_descriptor::TYPE_INLINE) {
            $this->assertSame('mavr-launch/1', $descriptor['config']['schema']);
            $this->assertSame((int)$attempt->id, $descriptor['config']['attemptid']);
        } else {
            $this->assertNotEmpty($descriptor['url']);
        }

        if ($runtime->statement_channel() !== 'browser') {
            $this->markTestIncomplete('The server-channel part of the reference session arrives with the first server run mode.');
        }

        // Reference statements, as the client sends them.
        $cmid = (int)$cm->id;
        $handler = \core_xapi\handler::create('mod_mavr');
        $result = $handler->process_statements([
            $this->statement($student, 'initialized', iri::activity($cmid), (int)$attempt->id),
            $this->statement($student, 'entered', iri::for_object($cmid, 'scene', $scene['id'], null), (int)$attempt->id),
            $this->statement($student, 'entered', iri::for_object($cmid, 'zone', $scene['id'], $vasezone), (int)$attempt->id),
            $this->statement(
                $student,
                'interacted',
                iri::for_object($cmid, 'object', $scene['id'], $vasehotspot),
                (int)$attempt->id
            ),
        ]);
        $this->assertSame([true, true, true, true], array_values($result));

        // Answer the question correctly.
        $attempt = attempt_manager::get_attempt((int)$attempt->id);
        $slotmap = json_decode($attempt->slotmap, true);
        $prefix = 'q' . $attempt->uniqueid . ':' . (int)$slotmap[(string)$slot] . '_';
        $answer = submit_question::execute((int)$attempt->id, $slot, [['name' => $prefix . 'answer', 'value' => '1']]);
        $this->assertEqualsWithDelta(1.0, $answer['fraction'], 1e-6);

        $result = $handler->process_statements([
            $this->statement($student, 'exited', iri::for_object($cmid, 'scene', $scene['id'], null), (int)$attempt->id),
            $this->statement($student, 'terminated', iri::activity($cmid), (int)$attempt->id),
        ]);
        $this->assertSame([true, true], array_values($result));

        $finish = finish_attempt::execute((int)$attempt->id);
        $this->assertSame('finished', $finish['state']);

        // Reference statements stored, in order.
        $rows = event_store::get_rows((int)$attempt->id);
        $this->assertSame([
            'launched', 'initialized', 'entered', 'entered', 'interacted', 'completed', 'answered', 'completed',
            'exited', 'terminated', 'completed',
        ], array_map(fn($r) => $r->verb, $rows));
        $this->assertSame([0, 1, 1, 1, 1, 0, 0, 0, 1, 1, 0], array_map(fn($r) => (int)$r->clientasserted, $rows));

        // Reference grade: the question (1 point) of 2 points.
        $attempt = attempt_manager::get_attempt((int)$attempt->id);
        $this->assertSame('50.00', grader::format_grade($mavr, (float)$attempt->score));
        $grades = grade_get_grades($course->id, 'mod', 'mavr', $mavr->id, (int)$student->id);
        $this->assertEqualsWithDelta(50.0, (float)$grades->items[0]->grades[$student->id]->grade, 1e-6);
    }

    /**
     * Build a client statement.
     *
     * @param \stdClass $user actor
     * @param string $verb short verb
     * @param string $objectiri object IRI
     * @param int $attemptid attempt id
     * @return statement
     */
    protected function statement(\stdClass $user, string $verb, string $objectiri, int $attemptid): statement {
        global $CFG;
        return statement::create_from_data((object) [
            'actor' => (object) [
                'objectType' => 'Agent',
                'account' => (object) ['homePage' => $CFG->wwwroot, 'name' => (string)$user->id],
            ],
            'verb' => (object) ['id' => iri::verb_iri($verb), 'display' => (object) ['en' => $verb]],
            'object' => (object) ['objectType' => 'Activity', 'id' => $objectiri],
            'context' => (object) ['extensions' => (object) [
                iri::EXT_ATTEMPT => $attemptid,
                iri::EXT_STATEMENT => \core\uuid::generate(),
                iri::EXT_VOCAB => iri::VOCAB,
                iri::EXT_CHANNEL => 'builtin-3d',
            ]],
            'timestamp' => date('c'),
        ]);
    }
}
