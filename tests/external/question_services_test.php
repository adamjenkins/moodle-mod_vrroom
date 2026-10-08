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
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\instance_manager;

/**
 * Tests of mod_mavr_get_question and mod_mavr_submit_question.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_question::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(submit_question::class)]
final class question_services_test extends \advanced_testcase {
    /**
     * Course, gallery with a true/false question at station "Vase", a started attempt.
     *
     * @return \stdClass course, module, student, attempt, slot, sceneid, vase (zone id)
     */
    private function setup_attempt(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => (int)$question->questionbankentryid,
                    'required' => 1, 'points' => 2, 'mediatype' => 'none'],
            ],
        ]);
        $student = $generator->create_and_enrol($course, 'student');

        $slot = 0;
        foreach (instance_manager::get_graph((int)$module->id)['objectives'] as $objective) {
            if ($objective['type'] === 'answer_question') {
                $slot = (int)$objective['slot'];
            }
        }
        $scene = instance_manager::get_scenes((int)$module->id)[0];

        $this->setUser($student);
        $start = start_attempt::execute((int)$module->cmid);
        return (object) [
            'course' => $course,
            'module' => $module,
            'student' => $student,
            'attempt' => attempt_manager::get_attempt((int)$start['attemptid']),
            'slot' => $slot,
            'sceneid' => $scene['id'],
            'vase' => $scene['zones'][0]['id'],
        ];
    }

    /**
     * Visit the station (server-side insert, as an accepted statement would).
     *
     * @param \stdClass $fx fixture
     * @return void
     */
    private function visit(\stdClass $fx): void {
        event_store::insert(
            (int)$fx->attempt->id,
            \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $fx->vase, 'scene' => $fx->sceneid, 'fraction' => null],
            true,
            null,
            time()
        );
    }

    /**
     * True/false response of the attempt's usage slot.
     *
     * @param \stdClass $fx fixture
     * @param string $value '1' true, '0' false, '' none
     * @return array
     */
    private function responses(\stdClass $fx, string $value): array {
        $slotmap = json_decode($fx->attempt->slotmap, true);
        $usageslot = (int)$slotmap[(string)$fx->slot];
        $prefix = 'q' . $fx->attempt->uniqueid . ':' . $usageslot . '_';
        return $value === '' ? [] : [['name' => $prefix . 'answer', 'value' => $value]];
    }

    /**
     * Assert a moodle_exception error code.
     *
     * @param string $errorcode
     * @param callable $call
     * @return void
     */
    private function assert_error(string $errorcode, callable $call): void {
        try {
            $call();
            $this->fail("Expected $errorcode");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * A question is locked until its station is visited, then renders.
     *
     * @return void
     */
    public function test_get_question(): void {
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;

        $this->assert_error('errorquestionlocked', fn() => get_question::execute($attemptid, $fx->slot));
        $this->assert_error('errorinvalidslot', fn() => get_question::execute($attemptid, $fx->slot + 5));
        $this->assert_error('errorinvalidslot', fn() => get_question::execute($attemptid, 0));

        $this->visit($fx);
        $result = external_api::clean_returnvalue(
            get_question::execute_returns(),
            get_question::execute($attemptid, $fx->slot)
        );
        $this->assertSame($fx->slot, $result['slot']);
        $this->assertFalse($result['answered']);
        $this->assertStringContainsString('q' . $fx->attempt->uniqueid . ':', $result['html']);
    }

    /**
     * Submitting is refused while locked; a correct answer is graded and recorded once; a second
     * submission is refused.
     *
     * @return void
     */
    public function test_submit_question(): void {
        global $DB;
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;

        $this->assert_error(
            'errorquestionlocked',
            fn() => submit_question::execute($attemptid, $fx->slot, $this->responses($fx, '1'))
        );
        $this->assertFalse($DB->record_exists('mavr_events', ['attemptid' => $attemptid, 'verb' => 'answered']));

        $this->visit($fx);

        // No answer: not graded, nothing recorded.
        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($attemptid, $fx->slot, $this->responses($fx, ''))
        );
        $this->assertNull($result['fraction'] ?? null);
        $this->assertFalse($DB->record_exists('mavr_events', ['attemptid' => $attemptid, 'verb' => 'answered']));
        $this->assertEqualsWithDelta(0.0, $result['state']['earnedpoints'], 1e-6);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($attemptid, $fx->slot, $this->responses($fx, '1'))
        );
        $this->assertSame('gradedright', $result['status']);
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $result['state']['earnedpoints'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $result['state']['score'], 1e-6);

        $answered = $DB->get_records('mavr_events', ['attemptid' => $attemptid, 'verb' => 'answered']);
        $this->assertCount(1, $answered);
        $row = reset($answered);
        $this->assertEquals(0, $row->clientasserted);
        $this->assertSame('question', $row->objecttype);
        $this->assertSame((string)$fx->slot, $row->objectid);
        $this->assertEqualsWithDelta(1.0, json_decode($row->resultjson, true)['fraction'], 1e-6);

        $this->assert_error(
            'errorquestionanswered',
            fn() => submit_question::execute($attemptid, $fx->slot, $this->responses($fx, '0'))
        );
        $this->assertCount(1, $DB->get_records('mavr_events', ['attemptid' => $attemptid, 'verb' => 'answered']));

        // The answered question renders with feedback.
        $result = get_question::execute($attemptid, $fx->slot);
        $this->assertTrue($result['answered']);
    }

    /**
     * Another user's attempt and a finished attempt are refused.
     *
     * @return void
     */
    public function test_ownership_and_state(): void {
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;
        $this->visit($fx);

        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        $this->setUser($other);
        $this->assert_error('errornotyourattempt', fn() => get_question::execute($attemptid, $fx->slot));
        $this->assert_error(
            'errornotyourattempt',
            fn() => submit_question::execute($attemptid, $fx->slot, $this->responses($fx, '1'))
        );

        $this->setUser($fx->student);
        finish_attempt::execute($attemptid);
        $this->assert_error('errorattemptclosed', fn() => get_question::execute($attemptid, $fx->slot));
        $this->assert_error(
            'errorattemptclosed',
            fn() => submit_question::execute($attemptid, $fx->slot, $this->responses($fx, '1'))
        );
    }
}
