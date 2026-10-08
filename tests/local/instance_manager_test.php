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

namespace mod_mavr\local;

use mod_mavr\local\template\compiled_activity;
use mod_mavr\local\template\registry as template_registry;

/**
 * Tests for the instance manager.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(instance_manager::class)]
final class instance_manager_test extends \advanced_testcase {
    /**
     * Create a course and a two-station gallery activity.
     *
     * @return array [course, mavr row, cm]
     */
    private function create_activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('mavr', [
            'course' => $course->id,
            'stationtitles' => 'Vase|Portrait',
        ]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('mavr', $mavr->id, $course->id, false, MUST_EXIST);
        return [$course, $mavr, $cm];
    }

    /**
     * Form data for an update, rebuilt from the stored template config as the form would.
     *
     * @param \stdClass $record The mavr row.
     * @param \stdClass $cm The course module.
     * @return \stdClass
     */
    private static function form_data_for_update(\stdClass $record, \stdClass $cm): \stdClass {
        $context = \context_module::instance($cm->id);
        $defaults = (array)$record;
        $template = template_registry::get($record->template);
        $template->data_preprocessing($defaults, instance_manager::decode_config($record->templateconfig), $context);
        $data = (object)$defaults;
        $data->instance = $record->id;
        $data->coursemodule = $cm->id;
        $data->cmidnumber = '';
        return $data;
    }

    /**
     * A minimal valid scene.
     *
     * @param string $id Scene id.
     * @return array
     */
    private static function scene(string $id): array {
        return [
            'schema' => 'mavr-scene/1',
            'id' => $id,
            'name' => 'Scene ' . $id,
            'environment' => [
                'type' => 'preset',
                'preset' => 'gallery-hall',
                'shape' => ['kind' => 'hall', 'width' => 12, 'length' => 30, 'height' => 5,
                    'wallcolor' => '#e8e4dc', 'floorcolor' => '#8a7f72', 'ceiling' => true],
                'sky' => '#20242a',
                'lights' => [['type' => 'ambient', 'intensity' => 0.7]],
                'bounds' => ['min' => [-5.6, 0, -14.6], 'max' => [5.6, 0, 14.6]],
            ],
            'spawn' => [['id' => 'spawn-main', 'position' => [0, 0, 13], 'yaw' => 0]],
            'zones' => [['id' => 'z-1', 'name' => 'One', 'center' => [-3, 1.5, 9], 'size' => [3, 3, 3]]],
            'props' => [],
            'interactables' => [],
        ];
    }

    /**
     * A minimal valid graph.
     *
     * @param string $title Title of its one objective.
     * @return array
     */
    private static function graph(string $title): array {
        return [
            'schema' => 'mavr-graph/1',
            'hidden' => [],
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => $title, 'zone' => 'z-1', 'points' => 1, 'required' => true],
            ],
            'rules' => [],
        ];
    }

    /**
     * The graph version moves only when the graph changes.
     */
    public function test_graph_version_increments_only_on_change(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $mavr, $cm] = $this->create_activity();
        $this->assertEquals(1, $mavr->graphversion);

        // Same config: no new version.
        $data = self::form_data_for_update($mavr, $cm);
        instance_manager::update_instance($data);
        $mavr = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertEquals(1, $mavr->graphversion);
        $this->assertEquals(1, $DB->count_records('mavr_graphs', ['mavrid' => $mavr->id]));

        // A renamed station changes the objective titles: a new version, the old one kept.
        $oldgraph = instance_manager::get_graph($mavr->id, 1);
        $data = self::form_data_for_update($mavr, $cm);
        $data->gallery_title[0] = 'Amphora';
        instance_manager::update_instance($data);
        $mavr = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertEquals(2, $mavr->graphversion);
        $this->assertEquals(2, $DB->count_records('mavr_graphs', ['mavrid' => $mavr->id]));
        $this->assertSame($oldgraph, instance_manager::get_graph($mavr->id, 1));
        $this->assertNotSame($oldgraph, instance_manager::get_graph($mavr->id));
        $this->assertSame(instance_manager::get_graph($mavr->id, 2), instance_manager::get_graph($mavr->id));
        $this->assertSame(json_decode($mavr->graphjson, true), instance_manager::get_graph($mavr->id));

        // Scene id is stable across recompiles.
        $this->assertEquals(1, $DB->count_records('mavr_scenes', ['mavrid' => $mavr->id]));
    }

    /**
     * apply_compiled() upserts scenes by idnumber and deletes vanished ones.
     */
    public function test_apply_compiled_scenes_upsert_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $mavr, $cm] = $this->create_activity();
        $context = \context_module::instance($cm->id);

        $first = self::scene('scene-a');
        $second = self::scene('scene-b');
        $compiled = new compiled_activity([$first, $second], self::graph('A'), []);
        $version = instance_manager::apply_compiled($mavr, $context, $compiled);
        $this->assertEquals(2, $version);
        $rows = $DB->get_records('mavr_scenes', ['mavrid' => $mavr->id], 'sortorder ASC');
        $this->assertSame(['scene-a', 'scene-b'], array_values(array_map(fn($r) => $r->idnumber, $rows)));
        $idb = $DB->get_field('mavr_scenes', 'id', ['mavrid' => $mavr->id, 'idnumber' => 'scene-b']);

        // Same graph again, scene-a gone and scene-b renamed: same version, row of scene-b kept.
        $second['name'] = 'Renamed';
        $version = instance_manager::apply_compiled($mavr, $context, new compiled_activity([$second], self::graph('A'), []));
        $this->assertEquals(2, $version);
        $rows = $DB->get_records('mavr_scenes', ['mavrid' => $mavr->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals($idb, $row->id);
        $this->assertSame('Renamed', $row->name);
        $this->assertEquals(0, $row->sortorder);
        $this->assertSame([$second], instance_manager::get_scenes($mavr->id));

        // A different graph: next version.
        $version = instance_manager::apply_compiled($mavr, $context, new compiled_activity([$second], self::graph('B'), []));
        $this->assertEquals(3, $version);
        $this->assertEquals(3, $DB->get_field('mavr', 'graphversion', ['id' => $mavr->id]));
    }

    /**
     * Changed slot bindings alone also make a new graph version.
     */
    public function test_slot_binding_change_increments_version(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        [$course, $mavr, $cm] = $this->create_activity();
        $context = \context_module::instance($cm->id);

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $graph = self::graph('A');
        $scene = self::scene('scene-a');
        $v1 = instance_manager::apply_compiled($mavr, $context, new compiled_activity([$scene], $graph, []));
        $binding = ['slot' => 1, 'questionbankentryid' => (int)$question->questionbankentryid,
            'maxmark' => 2.0, 'objectiveid' => 'q-1'];
        $v2 = instance_manager::apply_compiled($mavr, $context, new compiled_activity([$scene], $graph, [$binding]));
        $this->assertSame($v1 + 1, $v2);
        $v3 = instance_manager::apply_compiled($mavr, $context, new compiled_activity([$scene], $graph, [$binding]));
        $this->assertSame($v2, $v3);
    }

    /**
     * Invalid compiled output is refused with the first error.
     */
    public function test_invalid_compile_throws(): void {
        $method = new \ReflectionMethod(instance_manager::class, 'validate_compiled');

        $badscene = self::scene('scene-a');
        $badscene['schema'] = 'mavr-scene/0';
        try {
            $method->invoke(null, new compiled_activity([$badscene], self::graph('A'), []));
            $this->fail('An invalid scene was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidscene', $e->errorcode);
        }

        $badgraph = self::graph('A');
        $badgraph['objectives'][0]['type'] = 'teleport_everyone';
        try {
            $method->invoke(null, new compiled_activity([self::scene('scene-a')], $badgraph, []));
            $this->fail('An invalid graph was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidgraph', $e->errorcode);
        }

        try {
            $method->invoke(null, new compiled_activity([], self::graph('A'), []));
            $this->fail('No scene was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidscene', $e->errorcode);
        }

        $binding = ['slot' => 1, 'questionbankentryid' => 1, 'maxmark' => 1.0, 'objectiveid' => 'q-1'];
        try {
            $method->invoke(null, new compiled_activity([self::scene('scene-a')], self::graph('A'), [$binding, $binding]));
            $this->fail('Duplicate slots were accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidgraph', $e->errorcode);
        }

        // And a valid one passes.
        $method->invoke(null, new compiled_activity([self::scene('scene-a')], self::graph('A'), []));
    }

    /**
     * An unknown run mode is refused when creating.
     */
    public function test_add_instance_unknown_runtime(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorunknownruntime', 'mod_mavr', 'nosuchmode'));
        $this->getDataGenerator()->create_module('mavr', ['course' => $course->id, 'runtime' => 'nosuchmode']);
    }

    /**
     * get_graph() of a missing version throws.
     */
    public function test_get_graph_missing_version(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $mavr] = $this->create_activity();
        $this->expectException(\moodle_exception::class);
        instance_manager::get_graph($mavr->id, 99);
    }

    /**
     * decode_config() returns objects, and null for nothing stored.
     */
    public function test_decode_config(): void {
        $this->assertNull(instance_manager::decode_config(null));
        $this->assertNull(instance_manager::decode_config(''));
        $config = instance_manager::decode_config('{"stations":[{"id":"a"}]}');
        $this->assertInstanceOf(\stdClass::class, $config);
        $this->assertSame('a', $config->stations[0]->id);
    }
}
