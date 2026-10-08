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

namespace mod_vrroom\local;

use mod_vrroom\local\template\compiled_activity;
use mod_vrroom\local\template\registry as template_registry;

/**
 * Tests for the instance manager.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(instance_manager::class)]
final class instance_manager_test extends \advanced_testcase {
    /**
     * Create a course and a two-station gallery activity.
     *
     * @return array [course, vrroom row, cm]
     */
    private function create_activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase|Portrait',
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $course->id, false, MUST_EXIST);
        return [$course, $vrroom, $cm];
    }

    /**
     * Form data for an update, rebuilt from the stored template config as the form would.
     *
     * @param \stdClass $record The vrroom row.
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
            'schema' => 'vrroom-scene/1',
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
            'schema' => 'vrroom-graph/1',
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
        [, $vrroom, $cm] = $this->create_activity();
        $this->assertEquals(1, $vrroom->graphversion);

        // Same config: no new version.
        $data = self::form_data_for_update($vrroom, $cm);
        instance_manager::update_instance($data);
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(1, $vrroom->graphversion);
        $this->assertEquals(1, $DB->count_records('vrroom_graphs', ['vrroomid' => $vrroom->id]));

        // A renamed station changes the objective titles: a new version, the old one kept.
        $oldgraph = instance_manager::get_graph($vrroom->id, 1);
        $data = self::form_data_for_update($vrroom, $cm);
        $data->gallery_title[0] = 'Amphora';
        instance_manager::update_instance($data);
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(2, $vrroom->graphversion);
        $this->assertEquals(2, $DB->count_records('vrroom_graphs', ['vrroomid' => $vrroom->id]));
        $this->assertSame($oldgraph, instance_manager::get_graph($vrroom->id, 1));
        $this->assertNotSame($oldgraph, instance_manager::get_graph($vrroom->id));
        $this->assertSame(instance_manager::get_graph($vrroom->id, 2), instance_manager::get_graph($vrroom->id));
        $this->assertSame(json_decode($vrroom->graphjson, true), instance_manager::get_graph($vrroom->id));

        // Scene id is stable across recompiles.
        $this->assertEquals(1, $DB->count_records('vrroom_scenes', ['vrroomid' => $vrroom->id]));
    }

    /**
     * While the room editor owns the room, the settings form keeps the room and the tasks; a rebuild
     * recompiles from the template and clears the customised flag.
     */
    public function test_customised_room_kept_until_rebuild(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $vrroom, $cm] = $this->create_activity();
        $context = \context_module::instance($cm->id);
        $this->assertEquals(0, $vrroom->customised);
        $templatesceneid = instance_manager::get_scenes($vrroom->id)[0]['id'];

        // The room editor replaces the room and the tasks.
        instance_manager::apply_compiled($vrroom, $context, new compiled_activity([self::scene('scene-a')], self::graph('A'), []));
        $DB->set_field('vrroom', 'customised', 100, ['id' => $vrroom->id]);
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $configbefore = $vrroom->templateconfig;
        $versionbefore = (int)$vrroom->graphversion;

        // A settings form save changes the activity's own settings only.
        $data = self::form_data_for_update($vrroom, $cm);
        $data->name = 'Renamed walk';
        $data->maxattempts = 3;
        $data->gallery_title[0] = 'Amphora';
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('Renamed walk', $after->name);
        $this->assertEquals(3, $after->maxattempts);
        $this->assertEquals(100, $after->customised);
        $this->assertSame($configbefore, $after->templateconfig);
        $this->assertEquals($versionbefore, $after->graphversion);
        $this->assertSame(['scene-a'], array_column(instance_manager::get_scenes($vrroom->id), 'id'));
        $this->assertSame('A', instance_manager::get_graph($vrroom->id)['objectives'][0]['title']);

        // The "Rebuild from the template" action recompiles and clears the flag.
        $data = self::form_data_for_update($after, $cm);
        $data->gallery_title[0] = 'Amphora';
        $data->vrroom_rebuild = 1;
        instance_manager::update_instance($data);
        $rebuilt = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(0, $rebuilt->customised);
        $this->assertSame([$templatesceneid], array_column(instance_manager::get_scenes($vrroom->id), 'id'));
        $this->assertGreaterThan($versionbefore, (int)$rebuilt->graphversion);
        $this->assertStringContainsString('Amphora', json_encode(instance_manager::get_graph($vrroom->id)));
    }

    /**
     * apply_compiled() upserts scenes by idnumber and deletes vanished ones.
     */
    public function test_apply_compiled_scenes_upsert_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $vrroom, $cm] = $this->create_activity();
        $context = \context_module::instance($cm->id);

        $first = self::scene('scene-a');
        $second = self::scene('scene-b');
        $compiled = new compiled_activity([$first, $second], self::graph('A'), []);
        $version = instance_manager::apply_compiled($vrroom, $context, $compiled);
        $this->assertEquals(2, $version);
        $rows = $DB->get_records('vrroom_scenes', ['vrroomid' => $vrroom->id], 'sortorder ASC');
        $this->assertSame(['scene-a', 'scene-b'], array_values(array_map(fn($r) => $r->idnumber, $rows)));
        $idb = $DB->get_field('vrroom_scenes', 'id', ['vrroomid' => $vrroom->id, 'idnumber' => 'scene-b']);

        // Same graph again, scene-a gone and scene-b renamed: same version, row of scene-b kept.
        $second['name'] = 'Renamed';
        $version = instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$second], self::graph('A'), []));
        $this->assertEquals(2, $version);
        $rows = $DB->get_records('vrroom_scenes', ['vrroomid' => $vrroom->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals($idb, $row->id);
        $this->assertSame('Renamed', $row->name);
        $this->assertEquals(0, $row->sortorder);
        $this->assertSame([$second], instance_manager::get_scenes($vrroom->id));

        // A different graph: next version.
        $version = instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$second], self::graph('B'), []));
        $this->assertEquals(3, $version);
        $this->assertEquals(3, $DB->get_field('vrroom', 'graphversion', ['id' => $vrroom->id]));
    }

    /**
     * Changed slot bindings alone also make a new graph version.
     */
    public function test_slot_binding_change_increments_version(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        [$course, $vrroom, $cm] = $this->create_activity();
        $context = \context_module::instance($cm->id);

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $graph = self::graph('A');
        $scene = self::scene('scene-a');
        $v1 = instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$scene], $graph, []));
        $binding = ['slot' => 1, 'questionbankentryid' => (int)$question->questionbankentryid,
            'maxmark' => 2.0, 'objectiveid' => 'q-1'];
        $v2 = instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$scene], $graph, [$binding]));
        $this->assertSame($v1 + 1, $v2);
        $v3 = instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$scene], $graph, [$binding]));
        $this->assertSame($v2, $v3);
    }

    /**
     * Invalid compiled output is refused with the first error.
     */
    public function test_invalid_compile_throws(): void {
        $method = new \ReflectionMethod(instance_manager::class, 'validate_compiled');

        $badscene = self::scene('scene-a');
        $badscene['schema'] = 'vrroom-scene/0';
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
        $this->expectExceptionMessage(get_string('errorunknownruntime', 'mod_vrroom', 'nosuchmode'));
        $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'runtime' => 'nosuchmode']);
    }

    /**
     * get_graph() of a missing version throws.
     */
    public function test_get_graph_missing_version(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $vrroom] = $this->create_activity();
        $this->expectException(\moodle_exception::class);
        instance_manager::get_graph($vrroom->id, 99);
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

    /**
     * Live mode settings are saved on add, and the room size is clamped to 2..50.
     */
    public function test_live_settings_saved_on_add(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase',
            'livemode' => 1,
            'livemaxusers' => 4,
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $this->assertEquals(1, $vrroom->livemode);
        $this->assertEquals(4, $vrroom->livemaxusers);

        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase',
            'livemode' => 1,
            'livemaxusers' => 1,
        ]);
        $this->assertEquals(2, $DB->get_field('vrroom', 'livemaxusers', ['id' => $module->id]));

        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase',
            'livemode' => 1,
            'livemaxusers' => 500,
        ]);
        $this->assertEquals(50, $DB->get_field('vrroom', 'livemaxusers', ['id' => $module->id]));
    }

    /**
     * Live mode settings are saved on update, also for a room changed in the room editor; an update
     * without the live fields keeps the stored values.
     */
    public function test_live_settings_saved_on_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $vrroom, $cm] = $this->create_activity();

        // A template room.
        $data = self::form_data_for_update($vrroom, $cm);
        $data->livemode = 1;
        $data->livemaxusers = 3;
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(1, $after->livemode);
        $this->assertEquals(3, $after->livemaxusers);

        // Without the fields (no run mode can run a live room): the stored values stay.
        $data = self::form_data_for_update($after, $cm);
        unset($data->livemode, $data->livemaxusers);
        $data->name = 'Renamed';
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('Renamed', $after->name);
        $this->assertEquals(1, $after->livemode);
        $this->assertEquals(3, $after->livemaxusers);

        // A customised room saves the live fields too.
        $DB->set_field('vrroom', 'customised', 100, ['id' => $vrroom->id]);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $data = self::form_data_for_update($after, $cm);
        $data->livemode = 0;
        $data->livemaxusers = 99;
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(100, $after->customised);
        $this->assertEquals(0, $after->livemode);
        $this->assertEquals(50, $after->livemaxusers);
    }

    /**
     * The number of rooms is saved on add and cleaned: at most 20, "no limit" (0) only when
     * students open rooms, at least 1 with fixed rooms; an unknown live mode is stored as off.
     */
    public function test_live_rooms_saved_on_add(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $cases = [
            // Each case: livemode given, liverooms given, livemode stored, liverooms stored.
            [live_settings::MODE_FIXED, 3, live_settings::MODE_FIXED, 3],
            [live_settings::MODE_FIXED, 25, live_settings::MODE_FIXED, 20],
            [live_settings::MODE_FIXED, 0, live_settings::MODE_FIXED, 1],
            [live_settings::MODE_DYNAMIC, 0, live_settings::MODE_DYNAMIC, 0],
            [live_settings::MODE_DYNAMIC, -1, live_settings::MODE_DYNAMIC, 0],
            [live_settings::MODE_DYNAMIC, 5, live_settings::MODE_DYNAMIC, 5],
            [7, 3, live_settings::MODE_OFF, 3],
        ];
        foreach ($cases as [$mode, $rooms, $expectedmode, $expectedrooms]) {
            $module = $this->getDataGenerator()->create_module('vrroom', [
                'course' => $course->id,
                'stationtitles' => 'Vase',
                'livemode' => $mode,
                'liverooms' => $rooms,
            ]);
            $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
            $this->assertEquals($expectedmode, $vrroom->livemode, "livemode {$mode}, liverooms {$rooms}");
            $this->assertEquals($expectedrooms, $vrroom->liverooms, "livemode {$mode}, liverooms {$rooms}");
        }
    }

    /**
     * A run mode that runs one live room only never gets "students open rooms": it is stored as
     * "On, fixed rooms" (the companion service relies on this), on add and on update.
     */
    public function test_live_dynamic_stored_as_fixed_without_multirooms(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // With live mode off for the site, the built-in viewer has no live rooms, so no multirooms.
        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        $this->assertFalse(live_settings::runtime_has_multirooms('builtin'));
        $this->assertFalse(live_settings::runtime_has_multirooms('notinstalled'));
        $course = $this->getDataGenerator()->create_course();

        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase',
            'livemode' => live_settings::MODE_DYNAMIC,
            'liverooms' => 0,
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $this->assertEquals(live_settings::MODE_FIXED, $vrroom->livemode);
        $this->assertEquals(1, $vrroom->liverooms);

        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $course->id, false, MUST_EXIST);
        $data = self::form_data_for_update($vrroom, $cm);
        $data->livemode = live_settings::MODE_DYNAMIC;
        $data->liverooms = 4;
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(live_settings::MODE_FIXED, $after->livemode);
        $this->assertEquals(4, $after->liverooms);

        // A stored mode 2 met by an update without the live fields is turned into 1 as well.
        $DB->set_field('vrroom', 'livemode', live_settings::MODE_DYNAMIC, ['id' => $vrroom->id]);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $data = self::form_data_for_update($after, $cm);
        unset($data->livemode, $data->liverooms, $data->livemaxusers);
        instance_manager::update_instance($data);
        $this->assertEquals(live_settings::MODE_FIXED, $DB->get_field('vrroom', 'livemode', ['id' => $vrroom->id]));

        // With live mode on for the site again, the built-in viewer runs several rooms.
        set_config('liveenabled', 1, 'vrroomruntime_builtin');
        $this->assertTrue(live_settings::runtime_has_multirooms('builtin'));
    }

    /**
     * The number of rooms is saved on update, also for a room changed in the room editor (the
     * keeproom path lists its columns); an update without the live fields keeps it.
     */
    public function test_live_rooms_saved_on_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $vrroom, $cm] = $this->create_activity();

        $data = self::form_data_for_update($vrroom, $cm);
        $data->livemode = live_settings::MODE_DYNAMIC;
        $data->liverooms = 0;
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(live_settings::MODE_DYNAMIC, $after->livemode);
        $this->assertEquals(0, $after->liverooms);

        // Without the fields the stored values stay.
        $data = self::form_data_for_update($after, $cm);
        unset($data->livemode, $data->liverooms, $data->livemaxusers);
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(live_settings::MODE_DYNAMIC, $after->livemode);
        $this->assertEquals(0, $after->liverooms);

        // A customised room: fixed mode cannot keep "no limit", and the number is written.
        $DB->set_field('vrroom', 'customised', 100, ['id' => $vrroom->id]);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $data = self::form_data_for_update($after, $cm);
        $data->livemode = live_settings::MODE_FIXED;
        $data->liverooms = 0;
        instance_manager::update_instance($data);
        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertEquals(100, $after->customised);
        $this->assertEquals(live_settings::MODE_FIXED, $after->livemode);
        $this->assertEquals(1, $after->liverooms);

        $data = self::form_data_for_update($after, $cm);
        $data->liverooms = 7;
        instance_manager::update_instance($data);
        $this->assertEquals(7, $DB->get_field('vrroom', 'liverooms', ['id' => $vrroom->id]));
    }
}
