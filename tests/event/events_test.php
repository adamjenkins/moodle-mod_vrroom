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

namespace mod_mavr\event;

use core_xapi\local\statement;
use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\instance_manager;
use mod_mavr\local\xapi\iri;

/**
 * Tests of the MAVR events.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_module_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(course_module_instance_list_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_started::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_finished::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_deleted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(statement_recorded::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scene_updated::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(graph_updated::class)]
final class events_test extends \advanced_testcase {
    /**
     * Course, activity, student.
     *
     * @return \stdClass course, mavr, cm, context, student
     */
    private function setup_activity(): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        return (object) [
            'course' => $course,
            'mavr' => $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST),
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'student' => $generator->create_and_enrol($course, 'student'),
        ];
    }

    /**
     * Only the events of a class.
     *
     * @param array $events
     * @param string $class
     * @return array
     */
    private function only(array $events, string $class): array {
        return array_values(array_filter($events, fn($e) => $e instanceof $class));
    }

    /**
     * Attempt lifecycle events carry the module context and the attempt's user.
     *
     * @return void
     */
    public function test_attempt_events(): void {
        $fx = $this->setup_activity();
        $userid = (int)$fx->student->id;

        $sink = $this->redirectEvents();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, $userid);
        $attempt = attempt_manager::finish($attempt);
        $this->setAdminUser();
        attempt_manager::delete_attempt($attempt);
        $events = $sink->get_events();
        $sink->close();

        $started = $this->only($events, attempt_started::class);
        $this->assertCount(1, $started);
        $finished = $this->only($events, attempt_finished::class);
        $this->assertCount(1, $finished);
        $deleted = $this->only($events, attempt_deleted::class);
        $this->assertCount(1, $deleted);
        foreach ([$started[0], $finished[0], $deleted[0]] as $event) {
            $this->assertEquals($fx->context, $event->get_context());
            $this->assertEquals($userid, $event->relateduserid);
            $this->assertEquals($attempt->id, $event->objectid);
            $this->assertEquals($fx->mavr->id, $event->other['mavrid']);
            $this->assertInstanceOf(\moodle_url::class, $event->get_url());
            $this->assertNotEmpty($event->get_description());
            $this->assertNotEmpty($event::get_name());
        }
        $this->assertArrayHasKey('score', $finished[0]->other);
        $this->assertSame('d', $deleted[0]->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $deleted[0]->edulevel);
    }

    /**
     * statement_recorded is triggered per accepted statement.
     *
     * @return void
     */
    public function test_statement_recorded(): void {
        global $CFG;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $scene = instance_manager::get_scenes((int)$fx->mavr->id)[0];
        $zone = $scene['zones'][0]['id'];
        $this->setUser($fx->student);

        $statement = statement::create_from_data((object) [
            'actor' => (object) ['objectType' => 'Agent',
                'account' => (object) ['homePage' => $CFG->wwwroot, 'name' => (string)$fx->student->id]],
            'verb' => (object) ['id' => iri::verb_iri('entered')],
            'object' => (object) ['objectType' => 'Activity',
                'id' => iri::for_object((int)$fx->cm->id, 'zone', $scene['id'], $zone)],
            'context' => (object) ['extensions' => (object) [
                iri::EXT_ATTEMPT => (int)$attempt->id,
                iri::EXT_STATEMENT => \core\uuid::generate(),
            ]],
        ]);
        $sink = $this->redirectEvents();
        \mod_mavr\xapi\handler::create('mod_mavr')->process_statements([$statement]);
        $events = $this->only($sink->get_events(), statement_recorded::class);
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($fx->context, $event->get_context());
        $this->assertEquals($fx->student->id, $event->relateduserid);
        $this->assertEquals($attempt->id, $event->other['attemptid']);
        $this->assertSame('entered', $event->other['verb']);
        $this->assertSame('zone', $event->other['objecttype']);
        $this->assertSame($zone, $event->other['objectid']);
    }

    /**
     * The two course-module events can be triggered with the module and course contexts.
     *
     * @return void
     */
    public function test_course_module_events(): void {
        $fx = $this->setup_activity();
        $sink = $this->redirectEvents();
        course_module_viewed::create([
            'objectid' => $fx->mavr->id,
            'context' => $fx->context,
        ])->trigger();
        course_module_instance_list_viewed::create([
            'context' => \context_course::instance($fx->course->id),
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $viewed = $this->only($events, course_module_viewed::class);
        $this->assertCount(1, $viewed);
        $this->assertEquals($fx->context, $viewed[0]->get_context());
        $this->assertSame('mavr', $viewed[0]->objecttable);
        $this->assertCount(1, $this->only($events, course_module_instance_list_viewed::class));
    }

    /**
     * The two room editor events: context, object, other data, URL and backup mappings.
     *
     * @return void
     */
    public function test_editor_events(): void {
        global $DB;
        $fx = $this->setup_activity();
        $graphrow = $DB->get_record(
            'mavr_graphs',
            ['mavrid' => $fx->mavr->id, 'version' => $fx->mavr->graphversion],
            '*',
            MUST_EXIST
        );

        $sink = $this->redirectEvents();
        scene_updated::create([
            'objectid' => (int)$fx->mavr->id,
            'context' => $fx->context,
            'other' => ['scenecount' => 1],
        ])->trigger();
        graph_updated::create([
            'objectid' => (int)$graphrow->id,
            'context' => $fx->context,
            'other' => ['mavrid' => (int)$fx->mavr->id, 'version' => (int)$graphrow->version],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $scene = $this->only($events, scene_updated::class);
        $this->assertCount(1, $scene);
        $this->assertEquals($fx->context, $scene[0]->get_context());
        $this->assertSame('mavr', $scene[0]->objecttable);
        $this->assertEquals($fx->mavr->id, $scene[0]->objectid);
        $this->assertSame('u', $scene[0]->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $scene[0]->edulevel);
        $this->assertSame(1, $scene[0]->other['scenecount']);
        $this->assertStringEndsWith('/mod/mavr/edit.php', $scene[0]->get_url()->get_path(false));
        $this->assertEquals($fx->cm->id, $scene[0]->get_url()->get_param('id'));
        $this->assertNotEmpty($scene[0]->get_description());
        $this->assertSame(get_string('eventsceneupdated', 'mod_mavr'), scene_updated::get_name());
        $this->assertSame(['db' => 'mavr', 'restore' => 'mavr'], scene_updated::get_objectid_mapping());

        $graph = $this->only($events, graph_updated::class);
        $this->assertCount(1, $graph);
        $this->assertEquals($fx->context, $graph[0]->get_context());
        $this->assertSame('mavr_graphs', $graph[0]->objecttable);
        $this->assertEquals($graphrow->id, $graph[0]->objectid);
        $this->assertSame('u', $graph[0]->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $graph[0]->edulevel);
        $this->assertSame((int)$graphrow->version, $graph[0]->other['version']);
        $this->assertStringEndsWith('/mod/mavr/edit.php', $graph[0]->get_url()->get_path(false));
        $this->assertSame(get_string('eventgraphupdated', 'mod_mavr'), graph_updated::get_name());
        $this->assertSame(\core\event\base::NOT_MAPPED, graph_updated::get_objectid_mapping());
        $this->assertSame(['mavrid' => ['db' => 'mavr', 'restore' => 'mavr']], graph_updated::get_other_mapping());
    }

    /**
     * The editor events refuse to be created without their other data.
     *
     * @return void
     */
    public function test_editor_events_require_other_data(): void {
        $fx = $this->setup_activity();
        try {
            scene_updated::create(['objectid' => (int)$fx->mavr->id, 'context' => $fx->context]);
            $this->fail('scene_updated without scenecount was accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('scenecount', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        graph_updated::create(['objectid' => 1, 'context' => $fx->context, 'other' => ['mavrid' => (int)$fx->mavr->id]]);
    }
}
