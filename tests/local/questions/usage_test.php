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

namespace mod_mavr\local\questions;

/**
 * Tests for the question usage behind a MAVR attempt.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage::class)]
final class usage_test extends \advanced_testcase {
    /** @var \stdClass The mavr record. */
    private \stdClass $mavr;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass[] Questions keyed A, B. */
    private array $questions = [];

    /**
     * Create an activity with graph slots 1 (A), 2 (B) and 3 (an entry that does not exist).
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        foreach (['A', 'B'] as $name) {
            $this->questions[$name] = $qgen->create_question('truefalse', null, [
                'category' => $category->id,
                'name' => 'Question ' . $name,
            ]);
        }

        $module = $generator->create_module('mavr', ['course' => $course->id]);
        $this->mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = \context_module::instance($module->cmid);

        slots::sync($this->mavr, $this->context, [
            ['slot' => 1, 'questionbankentryid' => (int)$this->questions['A']->questionbankentryid,
                'maxmark' => 2.0, 'objectiveid' => 'q-a'],
            ['slot' => 2, 'questionbankentryid' => (int)$this->questions['B']->questionbankentryid,
                'maxmark' => 1.0, 'objectiveid' => 'q-b'],
            ['slot' => 3, 'questionbankentryid' => 987654321, 'maxmark' => 1.0, 'objectiveid' => 'q-gone'],
        ]);
    }

    /**
     * Create a usage and wrap it in an attempt-shaped record.
     *
     * @return \stdClass Attempt with uniqueid and slotmap.
     */
    private function make_attempt(): \stdClass {
        [$qubaid, $slotmap] = usage::create($this->context, slots::get_slots((int)$this->mavr->id, $this->context));
        return (object)['uniqueid' => $qubaid, 'slotmap' => json_encode($slotmap)];
    }

    /**
     * Response data for one graph slot, as the client form would post it.
     *
     * @param \stdClass $attempt The attempt.
     * @param int $graphslot The graph slot.
     * @param string|null $answer The truefalse answer ('1' true, '0' false), null for none.
     * @return array[] [['name' => ..., 'value' => ...], ...].
     */
    private function responses(\stdClass $attempt, int $graphslot, ?string $answer): array {
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slot = usage::get_usage_slot($attempt, $graphslot);
        $qa = $quba->get_question_attempt($slot);
        $responses = [
            ['name' => $qa->get_control_field_name('sequencecheck'), 'value' => (string)$qa->get_sequence_check_count()],
        ];
        if ($answer !== null) {
            $responses[] = ['name' => $qa->get_qt_field_name('answer'), 'value' => $answer];
        }
        return $responses;
    }

    /**
     * The usage gets one question per resolvable slot; a missing entry is skipped.
     */
    public function test_create_skips_missing_questions(): void {
        $attempt = $this->make_attempt();
        $slotmap = json_decode($attempt->slotmap, true);

        $this->assertSame([1, 2], array_keys($slotmap));
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $this->assertSame('mod_mavr', $quba->get_owning_component());
        $this->assertSame((int)$this->context->id, (int)$quba->get_owning_context()->id);
        $this->assertSame('immediatefeedback', $quba->get_preferred_behaviour());
        $this->assertCount(2, $quba->get_slots());
        $this->assertEqualsWithDelta(2.0, $quba->get_question_max_mark($slotmap[1]), 1e-7);
        $this->assertSame((int)$this->questions['A']->id, (int)$quba->get_question($slotmap[1])->id);
        $this->assertNull(usage::get_usage_slot($attempt, 3));
    }

    /**
     * An activity with no questions still gets a (empty) usage.
     */
    public function test_create_with_no_questions(): void {
        [$qubaid, $slotmap] = usage::create($this->context, []);
        $this->assertGreaterThan(0, $qubaid);
        $this->assertSame([], $slotmap);
        $this->assertSame('[]', json_encode($slotmap));
    }

    /**
     * Rendering returns the question HTML and can run twice in one request.
     */
    public function test_render(): void {
        $attempt = $this->make_attempt();

        [$html, $javascript] = usage::render($attempt, 1, false);
        $this->assertStringContainsString('The answer is true.', $html);
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $this->assertStringContainsString($quba->get_field_prefix(usage::get_usage_slot($attempt, 1)), $html);
        $this->assertIsString($javascript);

        // A second render in the same request must not restart JavaScript collection.
        [$html2] = usage::render($attempt, 2, true);
        $this->assertStringContainsString('The answer is true.', $html2);
    }

    /**
     * Rendering a slot the attempt does not have fails cleanly.
     */
    public function test_render_unknown_slot(): void {
        $attempt = $this->make_attempt();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('questionmissing', 'mod_mavr'));
        usage::render($attempt, 3, false);
    }

    /**
     * A right answer is graded by the engine and reported.
     */
    public function test_submit_right_answer(): void {
        $attempt = $this->make_attempt();

        $result = usage::submit($attempt, 1, $this->responses($attempt, 1, '1'), time());

        $this->assertTrue($result['finished']);
        $this->assertSame('gradedright', $result['status']);
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1e-7);
        $this->assertEqualsWithDelta(2.0, $result['mark'], 1e-7);
        $this->assertEqualsWithDelta(2.0, $result['maxmark'], 1e-7);

        // Answering again is refused.
        $this->expectException(\moodle_exception::class);
        usage::submit($attempt, 1, $this->responses($attempt, 1, '0'), time());
    }

    /**
     * A wrong answer is graded wrong, and the feedback view renders.
     */
    public function test_submit_wrong_answer(): void {
        $attempt = $this->make_attempt();

        $result = usage::submit($attempt, 2, $this->responses($attempt, 2, '0'), time());

        $this->assertTrue($result['finished']);
        $this->assertSame('gradedwrong', $result['status']);
        $this->assertEqualsWithDelta(0.0, $result['fraction'], 1e-7);
        [$html] = usage::render($attempt, 2, true);
        $this->assertNotEmpty($html);
    }

    /**
     * An empty response leaves the question open and unscored.
     */
    public function test_submit_empty_response(): void {
        $attempt = $this->make_attempt();

        $result = usage::submit($attempt, 1, $this->responses($attempt, 1, null), time());

        $this->assertFalse($result['finished']);
        $this->assertSame('invalid', $result['status']);
        $this->assertNull($result['fraction']);
        $this->assertNull($result['mark']);

        // The question can still be answered.
        $again = usage::submit($attempt, 1, $this->responses($attempt, 1, '1'), time());
        $this->assertTrue($again['finished']);
    }

    /**
     * A client cannot grade another question by smuggling its fields into the request.
     *
     * The request answers slot 1 but also carries slot 2's answer, sequence check
     * and submit action, plus a forged 'slots' list naming both. Slot 2 must be
     * left exactly as it was.
     */
    public function test_hostile_submit_for_another_slot_is_ignored(): void {
        $attempt = $this->make_attempt();
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slot1 = usage::get_usage_slot($attempt, 1);
        $slot2 = usage::get_usage_slot($attempt, 2);
        $qa2 = $quba->get_question_attempt($slot2);

        $responses = $this->responses($attempt, 1, '1');
        $responses[] = ['name' => $qa2->get_control_field_name('sequencecheck'),
            'value' => (string)$qa2->get_sequence_check_count()];
        $responses[] = ['name' => $qa2->get_qt_field_name('answer'), 'value' => '1'];
        $responses[] = ['name' => $qa2->get_behaviour_field_name('submit'), 'value' => '1'];
        $responses[] = ['name' => 'slots', 'value' => $slot1 . ',' . $slot2];

        $result = usage::submit($attempt, 1, $responses, time());
        $this->assertTrue($result['finished']);

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $this->assertTrue($quba->get_question_state($slot1)->is_finished());
        $this->assertFalse($quba->get_question_state($slot2)->is_finished(), 'The other slot was graded.');
        $this->assertSame('todo', (string)$quba->get_question_state($slot2));
    }

    /**
     * finish() closes every question; delete() removes the usage.
     */
    public function test_finish_and_delete(): void {
        global $DB;
        $attempt = $this->make_attempt();
        usage::submit($attempt, 1, $this->responses($attempt, 1, '1'), time());

        usage::finish($attempt, time());

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        foreach ($quba->get_slots() as $slot) {
            $this->assertTrue($quba->get_question_state($slot)->is_finished());
        }
        $this->assertSame('gaveup', (string)$quba->get_question_state(usage::get_usage_slot($attempt, 2)));

        usage::delete([$attempt->uniqueid, 0]);
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertFalse($DB->record_exists('question_attempts', ['questionusageid' => $attempt->uniqueid]));
    }

    /**
     * The two display modes hide or show feedback as documented.
     */
    public function test_display_options(): void {
        $attempt = usage::display_options(false);
        $this->assertFalse($attempt->readonly);
        $this->assertSame(\question_display_options::HIDDEN, $attempt->correctness);
        $this->assertSame(\question_display_options::HIDDEN, $attempt->marks);
        $this->assertSame(\question_display_options::HIDDEN, $attempt->feedback);
        $this->assertSame(\question_display_options::HIDDEN, $attempt->flags);
        $this->assertFalse($attempt->versioninfo);

        $feedback = usage::display_options(true);
        $this->assertTrue($feedback->readonly);
        $this->assertSame(\question_display_options::VISIBLE, $feedback->correctness);
        $this->assertSame(\question_display_options::MARK_AND_MAX, $feedback->marks);
        $this->assertSame(\question_display_options::VISIBLE, $feedback->feedback);
        $this->assertSame(\question_display_options::VISIBLE, $feedback->generalfeedback);
        $this->assertSame(\question_display_options::VISIBLE, $feedback->rightanswer);
        $this->assertSame(\question_display_options::HIDDEN, $feedback->flags);
    }
}
