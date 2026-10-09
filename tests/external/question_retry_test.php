<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_vrroom\external;

use core_external\external_api;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\questions\slots;
use mod_vrroom\local\questions\usage;
use mod_vrroom\local\template\compiled_activity;

/**
 * Tests of retries: an answer below the objective's pass_fraction, or not fully correct in the
 * activity's retry question mode, restarts the question in place (mod_vrroom_submit_question
 * returns retry).
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(submit_question::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(usage::class)]
final class question_retry_test extends \advanced_testcase {
    /**
     * A gallery with a true/false question (True is right) whose graph is replaced: v-1 visits the
     * station, q-1 (2 points) answers the question after it, with or without pass_fraction 1.
     *
     * @param bool $passfraction whether q-1 needs a fully correct answer
     * @return \stdClass student, attempt, attemptid, slot, zone, sceneid
     */
    private function setup_attempt(bool $passfraction): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'template' => 'gallery',
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => (int)$question->questionbankentryid,
                    'required' => 1, 'points' => 2, 'mediatype' => 'none'],
            ],
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance((int)$module->cmid);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $zone = (string)$scene['zones'][0]['id'];

        $bindings = [];
        foreach (slots::get_slots((int)$vrroom->id, $context) as $slot) {
            $bindings[] = ['slot' => $slot->slot, 'questionbankentryid' => $slot->questionbankentryid,
                'maxmark' => 2.0, 'objectiveid' => 'q-1'];
        }
        $this->assertCount(1, $bindings);
        $slot = (int)$bindings[0]['slot'];
        $objective = ['id' => 'q-1', 'type' => 'answer_question', 'title' => 'Open the safe', 'slot' => $slot,
            'points' => 2, 'required' => true, 'unlock' => ['after' => ['v-1']]];
        if ($passfraction) {
            $objective['pass_fraction'] = 1;
        }
        $graph = [
            'schema' => 'vrroom-graph/1',
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => 'Visit', 'zone' => $zone, 'points' => 0,
                    'required' => true],
                $objective,
            ],
            'rules' => [],
        ];
        instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$scene], $graph, $bindings));

        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $start = start_attempt::execute((int)$module->cmid);
        $attemptid = (int)$start['attemptid'];
        event_store::insert(
            $attemptid,
            \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $zone, 'scene' => (string)$scene['id'], 'fraction' => null],
            true,
            null,
            time()
        );
        return (object) [
            'student' => $student,
            'attemptid' => $attemptid,
            'attempt' => attempt_manager::get_attempt($attemptid),
            'slot' => $slot,
        ];
    }

    /**
     * True/false response for the attempt's usage slot.
     *
     * @param \stdClass $fx fixture
     * @param string $value '1' true, '0' false
     * @return array
     */
    private function responses(\stdClass $fx, string $value): array {
        $slotmap = json_decode($fx->attempt->slotmap, true);
        $prefix = 'q' . $fx->attempt->uniqueid . ':' . (int)$slotmap[(string)$fx->slot] . '_';
        return [['name' => $prefix . 'answer', 'value' => $value]];
    }

    /**
     * Status of an objective in a state.
     *
     * @param array $state attempt state
     * @param string $id objective id
     * @return string
     */
    private function objective_status(array $state, string $id): string {
        foreach ($state['objectives'] as $objective) {
            if ($objective['id'] === $id) {
                return $objective['status'];
            }
        }
        return '';
    }

    /**
     * A wrong answer keeps the objective available and restarts the question; the right answer
     * then completes it with full points.
     */
    public function test_wrong_then_right(): void {
        global $DB;
        $fx = $this->setup_attempt(true);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '0'))
        );
        $this->assertTrue($result['retry']);
        $this->assertSame('gradedwrong', $result['status']);
        $this->assertEqualsWithDelta(0.0, $result['fraction'], 1e-6);
        $this->assertSame('available', $this->objective_status($result['state'], 'q-1'));
        $this->assertEqualsWithDelta(0.0, $result['state']['earnedpoints'], 1e-6);
        // The feedback of the graded try comes back before the restart, without giving the answer away.
        $this->assertStringContainsString('q' . $fx->attempt->uniqueid . ':', $result['html']);
        $this->assertStringNotContainsString('rightanswer', $result['html']);
        $this->assertStringNotContainsString('generalfeedback', $result['html']);
        $this->assertCount(1, $DB->get_records('vrroom_events', ['attemptid' => $fx->attemptid, 'verb' => 'answered']));

        // The slot map is unchanged; the usage slot now holds a fresh attempt, the old try moved.
        $attempt = attempt_manager::get_attempt($fx->attemptid);
        $this->assertSame($fx->attempt->slotmap, $attempt->slotmap);
        $qa = usage::get_question_attempt($attempt, $fx->slot);
        $this->assertFalse($qa->get_state()->is_finished());

        // The fresh question renders unanswered.
        $view = get_question::execute($fx->attemptid, $fx->slot);
        $this->assertFalse($view['answered']);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '1'))
        );
        $this->assertFalse($result['retry']);
        $this->assertSame('gradedright', $result['status']);
        $this->assertSame('completed', $this->objective_status($result['state'], 'q-1'));
        $this->assertEqualsWithDelta(2.0, $result['state']['earnedpoints'], 1e-6);
        $this->assertCount(2, $DB->get_records('vrroom_events', ['attemptid' => $fx->attemptid, 'verb' => 'answered']));

        // Completed: no further submission.
        try {
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '1'));
            $this->fail('Expected errorquestionanswered');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorquestionanswered', $e->errorcode);
        }
    }

    /**
     * The activity's retry question mode: without pass_fraction a wrong answer still restarts the
     * question, and the right answer then completes it less the penalty for the wrong try.
     */
    public function test_retry_question_mode(): void {
        global $DB;
        $fx = $this->setup_attempt(false);
        $DB->update_record('vrroom', (object)['id' => $fx->attempt->vrroomid, 'questionmode' => 'retry',
            'retrypenalty' => 0.5]);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '0'))
        );
        $this->assertTrue($result['retry']);
        $this->assertSame('available', $this->objective_status($result['state'], 'q-1'));
        $this->assertEqualsWithDelta(0.0, $result['state']['earnedpoints'], 1e-6);
        $this->assertStringNotContainsString('rightanswer', $result['html']);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '1'))
        );
        $this->assertFalse($result['retry']);
        $this->assertSame('completed', $this->objective_status($result['state'], 'q-1'));
        // 2 points less 0.5 x 2 for the one wrong try.
        $this->assertEqualsWithDelta(1.0, $result['state']['earnedpoints'], 1e-6);
    }

    /**
     * Without pass_fraction a wrong answer completes the objective with no points, as before.
     */
    public function test_without_pass_fraction(): void {
        $fx = $this->setup_attempt(false);

        $result = external_api::clean_returnvalue(
            submit_question::execute_returns(),
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '0'))
        );
        $this->assertFalse($result['retry']);
        $this->assertSame('completed', $this->objective_status($result['state'], 'q-1'));
        $this->assertEqualsWithDelta(0.0, $result['state']['earnedpoints'], 1e-6);
        // A final answer in once mode may show the right answer.
        $this->assertStringContainsString('rightanswer', $result['html']);
        $qa = usage::get_question_attempt(attempt_manager::get_attempt($fx->attemptid), $fx->slot);
        $this->assertTrue($qa->get_state()->is_finished());

        try {
            submit_question::execute($fx->attemptid, $fx->slot, $this->responses($fx, '1'));
            $this->fail('Expected errorquestionanswered');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorquestionanswered', $e->errorcode);
        }
    }

    /**
     * restart() does nothing to a question that has not been answered yet.
     */
    public function test_restart_unanswered_is_noop(): void {
        $fx = $this->setup_attempt(true);
        $before = usage::get_question_attempt($fx->attempt, $fx->slot);
        usage::restart($fx->attempt, $fx->slot, time());
        $after = usage::get_question_attempt($fx->attempt, $fx->slot);
        $this->assertSame($before->get_database_id(), $after->get_database_id());
        $this->assertFalse($after->get_state()->is_finished());
    }
}
