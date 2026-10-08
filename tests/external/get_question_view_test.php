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

use core_external\external_api;
use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\instance_manager;

/**
 * Tests of mod_vrroom_get_question_view.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_question_view::class)]
final class get_question_view_test extends \advanced_testcase {
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
        $module = $generator->create_module('vrroom', [
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
     * Locked until the station is visited, then the view; invalid slots are refused.
     *
     * @return void
     */
    public function test_locked_then_view(): void {
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;

        $this->assert_error('errorquestionlocked', fn() => get_question_view::execute($attemptid, $fx->slot));
        $this->assert_error('errorinvalidslot', fn() => get_question_view::execute($attemptid, $fx->slot + 5));
        $this->assert_error('errorinvalidslot', fn() => get_question_view::execute($attemptid, 0));

        $this->visit($fx);
        $result = external_api::clean_returnvalue(
            get_question_view::execute_returns(),
            get_question_view::execute($attemptid, $fx->slot)
        );
        $this->assertSame($fx->slot, $result['slot']);
        $this->assertTrue($result['supported']);
        $this->assertSame('truefalse', $result['qtype']);
        $this->assertFalse($result['multiple']);
        $this->assertFalse($result['answered']);
        $this->assertSame('todo', $result['state']);
        $this->assertNull($result['fraction']);
        $this->assertCount(2, $result['choices']);
        $this->assertStringStartsWith('q' . $fx->attempt->uniqueid . ':', $result['choices'][0]['name']);

        // Answered: the view says so and carries the fraction.
        submit_question::execute(
            $attemptid,
            $fx->slot,
            [['name' => $result['choices'][1]['name'], 'value' => $result['choices'][1]['value']]]
        );
        $result = external_api::clean_returnvalue(
            get_question_view::execute_returns(),
            get_question_view::execute($attemptid, $fx->slot)
        );
        $this->assertTrue($result['answered']);
        $this->assertSame('gradedwrong', $result['state']);
        $this->assertEqualsWithDelta(0.0, $result['fraction'], 1e-6);
    }

    /**
     * Another user's attempt is refused.
     *
     * @return void
     */
    public function test_other_users_attempt(): void {
        $fx = $this->setup_attempt();
        $this->visit($fx);

        $other = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        $this->setUser($other);
        $this->assert_error('errornotyourattempt', fn() => get_question_view::execute((int)$fx->attempt->id, $fx->slot));
    }

    /**
     * A finished attempt is refused.
     *
     * @return void
     */
    public function test_closed_attempt(): void {
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;
        $this->visit($fx);

        finish_attempt::execute($attemptid);
        $this->assert_error('errorattemptclosed', fn() => get_question_view::execute($attemptid, $fx->slot));
    }

    /**
     * Without mod/vrroom:attempt the view is refused.
     *
     * @return void
     */
    public function test_missing_capability(): void {
        global $DB;
        $fx = $this->setup_attempt();
        $this->visit($fx);

        $context = \context_module::instance((int)$fx->module->cmid);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/vrroom:attempt', CAP_PROHIBIT, $studentrole, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/vrroom:attempt', $context, $fx->student));

        $this->expectException(\required_capability_exception::class);
        get_question_view::execute((int)$fx->attempt->id, $fx->slot);
    }
}
