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

namespace mod_mavr;

use mod_mavr\local\instance_manager;

/**
 * Tests for the mod_mavr data generator.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_mavr_generator::class)]
final class generator_test extends \advanced_testcase {

    /**
     * The default activity has two text-only stations and a compiled scene and graph.
     */
    public function test_create_instance_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(0, $DB->count_records('mavr'));
        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);

        $record = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertSame('builtin', $record->runtime);
        $this->assertSame('gallery', $record->template);
        $this->assertEquals(100, $record->grade);
        $this->assertEquals(1, $record->graphversion);
        $this->assertNotEmpty($mavr->cmid);

        $config = json_decode($record->templateconfig);
        $this->assertCount(2, $config->stations);
        $this->assertSame('Station 1', $config->stations[0]->title);
        $this->assertSame('gallery-hall', $config->environment);

        $scenes = instance_manager::get_scenes((int)$mavr->id);
        $this->assertCount(1, $scenes);
        $this->assertSame(['Station 1', 'Station 2'], array_column($scenes[0]['zones'], 'name'));
        $this->assertSame(0, $DB->count_records('mavr_slots', ['mavrid' => $mavr->id]));
    }

    /**
     * Behat-style strings name stations and their questions; a question becomes a slot with a reference.
     */
    public function test_create_instance_from_strings_with_question(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $mavr = $generator->create_module('mavr', [
            'course' => $course->id,
            'stationtitles' => 'Vase|Portrait',
            'stationquestions' => 'Q1|',
            'stationpoints' => '2|1',
        ]);

        $config = json_decode($DB->get_field('mavr', 'templateconfig', ['id' => $mavr->id]));
        $this->assertSame(['Vase', 'Portrait'], array_column($config->stations, 'title'));
        $this->assertEquals((int)$question->questionbankentryid, $config->stations[0]->questionbankentryid);
        $this->assertEquals(0, $config->stations[1]->questionbankentryid);
        $this->assertEquals(2.0, $config->stations[0]->points);

        $slots = $DB->get_records('mavr_slots', ['mavrid' => $mavr->id]);
        $this->assertCount(1, $slots);
        $slot = reset($slots);
        $this->assertEquals(1, $slot->slot);

        $context = \context_module::instance($mavr->cmid);
        $reference = $DB->get_record('question_references', [
            'component' => 'mod_mavr',
            'questionarea' => 'slot',
            'itemid' => $slot->id,
        ], '*', MUST_EXIST);
        $this->assertEquals($context->id, $reference->usingcontextid);
        $this->assertEquals($question->questionbankentryid, $reference->questionbankentryid);
    }

    /**
     * An unknown question name is a test-setup error.
     */
    public function test_unknown_question_name_throws(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->expectException(\coding_exception::class);
        $this->getDataGenerator()->create_module('mavr', [
            'course' => $course->id,
            'stationtitles' => 'Vase',
            'stationquestions' => 'No such question',
        ]);
    }

    /**
     * Attempts and statements: a recorded visit completes its objective on recompute.
     */
    public function test_create_attempt_and_record_event(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $mavr = $generator->create_module('mavr', ['course' => $course->id, 'stationtitles' => 'Vase']);
        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');

        $attempt = $mavrgen->create_attempt((int)$mavr->id, (int)$student->id);
        $this->assertEquals(1, $attempt->attempt);
        $this->assertSame('inprogress', $attempt->state);
        $this->assertSame($attempt->id, $mavrgen->create_attempt((int)$mavr->id, (int)$student->id)->id);

        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $zoneid = $scene['zones'][0]['id'];
        $eventid = $mavrgen->record_event($attempt, 'entered', 'zone', $scene['id'], $zoneid);

        $event = $DB->get_record('mavr_events', ['id' => $eventid], '*', MUST_EXIST);
        $this->assertSame('entered', $event->verb);
        $this->assertEquals(1, $event->clientasserted);
        $row = $DB->get_record('mavr_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertEquals(1, $row->requireddone);
        $this->assertEqualsWithDelta(1.0, (float)$row->score, 1e-6);
        $this->assertTrue($DB->record_exists('mavr_events',
            ['attemptid' => $attempt->id, 'verb' => 'completed', 'objecttype' => 'objective']));
    }

    /**
     * The default escape chain: puzzle "Desk" with a key and "Safe" without a lock, in the escape
     * room, compiled into one room and a graph with an exit door.
     */
    public function test_create_escape_instance_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id, 'template' => 'escape']);

        $record = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertSame('escape', $record->template);
        $this->assertEquals(0, $record->customised);
        $config = json_decode($record->templateconfig);
        $this->assertSame('mavrtemplate_escape/1', $config->schema);
        $this->assertSame('escape-room', $config->environment);
        $this->assertSame(['Desk', 'Safe'], array_column($config->puzzles, 'title'));
        $this->assertSame(['key', 'none'], array_column($config->puzzles, 'locktype'));
        $this->assertSame('Key', $config->puzzles[0]->keyname);

        $scenes = instance_manager::get_scenes((int)$mavr->id);
        $this->assertCount(1, $scenes);
        $graph = instance_manager::get_graph((int)$mavr->id);
        $objectiveids = array_column($graph['objectives'], 'id');
        $this->assertContains('o-exit', $objectiveids);
        $this->assertContains('d-exit', $graph['hidden']);
        $this->assertSame(0, $DB->count_records('mavr_slots', ['mavrid' => $mavr->id]));
    }

    /**
     * Behat-style strings: a key puzzle with a hint and a question lock, with a time limit and a
     * hint penalty.
     */
    public function test_create_escape_instance_from_strings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $mavr = $generator->create_module('mavr', [
            'course' => $course->id,
            'template' => 'escape',
            'puzzletitles' => 'Desk|Safe',
            'puzzlelocks' => 'key|question',
            'puzzlequestions' => '|Q1',
            'puzzlehints' => 'Look on the desk.|',
            'timelimit' => 900,
            'hintpenalty' => 0.5,
            'story' => 'You are locked in.',
        ]);

        $config = json_decode($DB->get_field('mavr', 'templateconfig', ['id' => $mavr->id]));
        $this->assertSame(['key', 'question'], array_column($config->puzzles, 'locktype'));
        $this->assertSame(['Look on the desk.'], $config->puzzles[0]->hints);
        $this->assertSame([], $config->puzzles[1]->hints);
        $this->assertEquals($question->questionbankentryid, $config->puzzles[1]->questionbankentryid);
        $this->assertEquals(900, $config->timelimit);
        $this->assertEquals(0.5, $config->hintpenalty);
        $this->assertSame('You are locked in.', $config->story);

        $graph = instance_manager::get_graph((int)$mavr->id);
        $this->assertSame(900, $graph['timelimit']);
        $slots = $DB->get_records('mavr_slots', ['mavrid' => $mavr->id]);
        $this->assertCount(1, $slots);
        $this->assertEquals(1, reset($slots)->slot);

        // A new attempt gets its deadline from the graph's time limit.
        $student = $generator->create_and_enrol($course, 'student');
        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');
        $attempt = $mavrgen->create_attempt((int)$mavr->id, (int)$student->id);
        $this->assertEquals((int)$attempt->timestart + 900, (int)$attempt->timedue);
    }

    /**
     * The empty room: one room without objects and an empty graph.
     */
    public function test_create_blank_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id, 'template' => 'blank']);

        $record = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertSame('blank', $record->template);
        $config = json_decode($record->templateconfig);
        $this->assertSame('mavrtemplate_blank/1', $config->schema);
        $this->assertSame('gallery-hall', $config->environment);

        $scenes = instance_manager::get_scenes((int)$mavr->id);
        $this->assertCount(1, $scenes);
        $this->assertEmpty($scenes[0]['zones'] ?? []);
        $this->assertEmpty($scenes[0]['interactables'] ?? []);
        $graph = instance_manager::get_graph((int)$mavr->id);
        $this->assertSame([], $graph['objectives']);
        $this->assertSame([], $graph['rules']);
        $this->assertSame(0, $DB->count_records('mavr_slots', ['mavrid' => $mavr->id]));
    }
}
