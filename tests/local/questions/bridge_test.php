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

use core_external\external_api;
use mod_mavr\external\start_attempt;
use mod_mavr\external\submit_question;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\instance_manager;

/**
 * Tests of the plain-text question view for in-world panels.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(bridge::class)]
final class bridge_test extends \advanced_testcase {

    /**
     * A gallery with one station per question, a started attempt with every station visited.
     *
     * Stations (and graph slots, in order): truefalse, multichoice one of four, multichoice two
     * of four, truefalse whose text holds an image, shortanswer.
     *
     * @return \stdClass course, module, student, attempt, context, slots (name => graph slot)
     */
    private function setup_attempt(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);

        $questions = [
            'truefalse' => $qgen->create_question('truefalse', null, ['category' => $category->id]),
            'single' => $qgen->create_question('multichoice', 'one_of_four', ['category' => $category->id]),
            'multi' => $qgen->create_question('multichoice', 'two_of_four', ['category' => $category->id]),
            'image' => $qgen->create_question('truefalse', null, [
                'category' => $category->id,
                'questiontext' => ['text' => '<p>Is this a cat? <img src="cat.png" alt="A cat"></p>', 'format' => FORMAT_HTML],
            ]),
            'shortanswer' => $qgen->create_question('shortanswer', null, ['category' => $category->id]),
        ];
        $stations = [];
        foreach ($questions as $name => $question) {
            $stations[] = ['title' => ucfirst($name), 'text' => '<p>' . $name . '</p>',
                'questionbankentryid' => (int)$question->questionbankentryid, 'required' => 1, 'points' => 1,
                'mediatype' => 'none'];
        }
        $module = $generator->create_module('mavr', ['course' => $course->id, 'stations' => $stations]);
        $student = $generator->create_and_enrol($course, 'student');

        $graphslots = [];
        foreach (instance_manager::get_graph((int)$module->id)['objectives'] as $objective) {
            if ($objective['type'] === 'answer_question') {
                $graphslots[] = (int)$objective['slot'];
            }
        }
        $this->assertCount(count($questions), $graphslots);
        $slots = array_combine(array_keys($questions), $graphslots);

        $this->setUser($student);
        $start = start_attempt::execute((int)$module->cmid);
        $attempt = attempt_manager::get_attempt((int)$start['attemptid']);

        $scene = instance_manager::get_scenes((int)$module->id)[0];
        foreach ($scene['zones'] as $zone) {
            event_store::insert((int)$attempt->id, \core\uuid::generate(),
                ['verb' => 'entered', 'type' => 'zone', 'id' => $zone['id'], 'scene' => $scene['id'], 'fraction' => null],
                true, null, time());
        }

        return (object) [
            'course' => $course,
            'module' => $module,
            'student' => $student,
            'attempt' => $attempt,
            'context' => \context_module::instance((int)$module->cmid),
            'slots' => $slots,
        ];
    }

    /**
     * Field prefix of a graph slot's question attempt.
     *
     * @param \stdClass $fx fixture
     * @param string $name question name in the fixture
     * @return string
     */
    private function prefix(\stdClass $fx, string $name): string {
        $usageslot = usage::get_usage_slot($fx->attempt, $fx->slots[$name]);
        return 'q' . $fx->attempt->uniqueid . ':' . $usageslot . '_';
    }

    /**
     * Assert that a view carries neither feedback nor the right answer.
     *
     * @param array $view the view
     * @param string[] $secrets texts that must not appear
     * @return void
     */
    private function assert_no_secrets(array $view, array $secrets): void {
        $json = json_encode($view);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertArrayNotHasKey('rightanswer', $view);
        foreach ($view['choices'] as $choice) {
            $this->assertSame(['name', 'value', 'label'], array_keys($choice));
        }
    }

    /**
     * True/false: two choices, the "answer" field, values 1 and 0, no feedback.
     *
     * @return void
     */
    public function test_truefalse_view(): void {
        $fx = $this->setup_attempt();
        $view = bridge::get_view($fx->attempt, $fx->slots['truefalse'], $fx->context, false);

        $prefix = $this->prefix($fx, 'truefalse');
        $this->assertTrue($view['supported']);
        $this->assertSame('truefalse', $view['qtype']);
        $this->assertFalse($view['multiple']);
        $this->assertSame('The answer is true.', $view['questiontext']);
        $this->assertSame([
            ['name' => $prefix . 'answer', 'value' => '1', 'label' => get_string('true', 'qtype_truefalse')],
            ['name' => $prefix . 'answer', 'value' => '0', 'label' => get_string('false', 'qtype_truefalse')],
        ], $view['choices']);
        $this->assertFalse($view['answered']);
        $this->assertSame('todo', $view['state']);
        $this->assertNull($view['fraction']);
        $this->assert_no_secrets($view, ['You should have selected true', 'This is the right answer',
            'This is the wrong answer']);

        $returned = external_api::clean_returnvalue(\mod_mavr\external\get_question_view::execute_returns(), $view);
        $this->assertSame($view['choices'], $returned['choices']);
    }

    /**
     * Single-answer multiple choice: "answer" = index into the attempt's order, labels in that order.
     *
     * @return void
     */
    public function test_multichoice_single_view(): void {
        $fx = $this->setup_attempt();
        $view = bridge::get_view($fx->attempt, $fx->slots['single'], $fx->context, false);

        $prefix = $this->prefix($fx, 'single');
        $this->assertTrue($view['supported']);
        $this->assertSame('multichoice', $view['qtype']);
        $this->assertFalse($view['multiple']);
        $this->assertSame('Which is the oddest number?', $view['questiontext']);
        $this->assertCount(4, $view['choices']);
        $labels = array_column($view['choices'], 'label');
        sort($labels);
        $this->assertSame(['Four', 'One', 'Three', 'Two'], $labels);
        foreach ($view['choices'] as $index => $choice) {
            $this->assertSame($prefix . 'answer', $choice['name']);
            $this->assertSame((string)$index, $choice['value']);
        }
        $this->assert_no_secrets($view, ['The oddest number is One', 'is odd', 'is even']);
    }

    /**
     * Multiple-answer multiple choice: one "choice<key>" field per choice, value 1.
     *
     * @return void
     */
    public function test_multichoice_multi_view(): void {
        $fx = $this->setup_attempt();
        $view = bridge::get_view($fx->attempt, $fx->slots['multi'], $fx->context, false);

        $prefix = $this->prefix($fx, 'multi');
        $this->assertTrue($view['supported']);
        $this->assertTrue($view['multiple']);
        $this->assertSame('Which are the odd numbers?', $view['questiontext']);
        $this->assertCount(4, $view['choices']);
        foreach ($view['choices'] as $index => $choice) {
            $this->assertSame($prefix . 'choice' . $index, $choice['name']);
            $this->assertSame('1', $choice['value']);
        }
        $this->assert_no_secrets($view, ['The odd numbers are One and Three', 'is odd', 'is even']);
    }

    /**
     * A question with an image in its text, and a short-answer question, are unsupported.
     *
     * @return void
     */
    public function test_unsupported_views(): void {
        $fx = $this->setup_attempt();

        $view = bridge::get_view($fx->attempt, $fx->slots['image'], $fx->context, false);
        $this->assertFalse($view['supported']);
        $this->assertSame('truefalse', $view['qtype']);
        $this->assertSame('', $view['questiontext']);
        $this->assertSame([], $view['choices']);

        $view = bridge::get_view($fx->attempt, $fx->slots['shortanswer'], $fx->context, false);
        $this->assertFalse($view['supported']);
        $this->assertSame('shortanswer', $view['qtype']);
        $this->assertSame('', $view['questiontext']);
        $this->assertSame([], $view['choices']);
        $this->assertSame('todo', $view['state']);
    }

    /**
     * The field names and values of each view are graded correctly by mod_mavr_submit_question.
     *
     * @return void
     */
    public function test_view_fields_submit(): void {
        $fx = $this->setup_attempt();
        $attemptid = (int)$fx->attempt->id;

        // True/false: the "true" choice is right.
        $view = bridge::get_view($fx->attempt, $fx->slots['truefalse'], $fx->context, false);
        $true = $view['choices'][0];
        $result = external_api::clean_returnvalue(submit_question::execute_returns(), submit_question::execute(
            $attemptid, $fx->slots['truefalse'], [['name' => $true['name'], 'value' => $true['value']]]));
        $this->assertSame('gradedright', $result['status']);
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1e-6);

        // Single answer: "One" is right, wherever the shuffle put it.
        $view = bridge::get_view($fx->attempt, $fx->slots['single'], $fx->context, false);
        $one = null;
        foreach ($view['choices'] as $choice) {
            if ($choice['label'] === 'One') {
                $one = $choice;
            }
        }
        $this->assertNotNull($one);
        $result = external_api::clean_returnvalue(submit_question::execute_returns(), submit_question::execute(
            $attemptid, $fx->slots['single'], [['name' => $one['name'], 'value' => $one['value']]]));
        $this->assertSame('gradedright', $result['status']);

        // Multiple answers: "One" and "Three" are right.
        $view = bridge::get_view($fx->attempt, $fx->slots['multi'], $fx->context, false);
        $responses = [];
        foreach ($view['choices'] as $choice) {
            if (in_array($choice['label'], ['One', 'Three'], true)) {
                $responses[] = ['name' => $choice['name'], 'value' => $choice['value']];
            }
        }
        $this->assertCount(2, $responses);
        $result = external_api::clean_returnvalue(submit_question::execute_returns(), submit_question::execute(
            $attemptid, $fx->slots['multi'], $responses));
        $this->assertSame('gradedright', $result['status']);
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1e-6);

        // Once answered, the view reports the state and the fraction, still without feedback.
        $attempt = attempt_manager::get_attempt($attemptid);
        $view = bridge::get_view($attempt, $fx->slots['multi'], $fx->context, true);
        $this->assertTrue($view['answered']);
        $this->assertSame('gradedright', $view['state']);
        $this->assertEqualsWithDelta(1.0, $view['fraction'], 1e-6);
        $this->assert_no_secrets($view, ['The odd numbers are One and Three', 'is odd', 'is even']);
    }

    /**
     * to_plain(): whitespace, entities, truncation, and content plain text cannot show.
     *
     * @return void
     */
    public function test_to_plain(): void {
        $this->resetAfterTest();
        $this->assertSame('', bridge::to_plain(null, 10));
        $this->assertSame('', bridge::to_plain('', 10));
        $this->assertSame('Fish & chips', bridge::to_plain("<p>Fish   &amp;\n chips</p>", 100));
        $this->assertSame('abcd…', bridge::to_plain('<p>abcdefghij</p>', 5));
        $this->assertSame(5, \core_text::strlen(bridge::to_plain('<p>abcdefghij</p>', 5)));

        foreach (['<img src="a.png" alt="">', '<video src="a.mp4"></video>', '<audio src="a.mp3"></audio>',
                '<iframe src="https://example.com"></iframe>', '<object data="a"></object>', '<embed src="a">',
                '<svg></svg>', '<math><mi>x</mi></math>', '<canvas></canvas>', 'Solve $$x^2$$',
                'Solve \\(x^2\\)', 'Solve \\[x^2\\]', 'Solve [tex]x^2[/tex]'] as $html) {
            $this->assertNull(bridge::to_plain('<p>' . $html . '</p>', 100), $html);
        }
    }
}
