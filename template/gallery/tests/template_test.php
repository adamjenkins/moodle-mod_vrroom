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

namespace vrroomtemplate_gallery;

use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\scene\environment_registry;
use mod_vrroom\local\scene\scene_validator;

/**
 * Tests for the Gallery walk template: config extraction, files, validation and compile.
 *
 * @package    vrroomtemplate_gallery
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template::class)]
final class template_test extends \advanced_testcase {
    /** @var string Fixed scene id used by the configs built here. */
    private const SCENEID = '5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11';

    protected function tearDown(): void {
        environment_registry::phpunit_set_packs(null);
        parent::tearDown();
    }

    /**
     * Install the fixture room pack (rooms testpack/a with 4 anchors and testpack/b).
     */
    private function use_test_pack(): void {
        global $CFG;
        environment_registry::phpunit_set_packs(['testpack' => $CFG->dirroot . '/mod/vrroom/tests/fixtures/envpack']);
    }

    /**
     * A module context to hold mod_vrroom file areas (compile only reads files from it).
     *
     * @return \context_module
     */
    private function make_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return \context_module::instance($page->cmid);
    }

    /**
     * Build a gallery config.
     *
     * @param array[] $stations per-station overrides
     * @param string $environment preset id or 'upload'
     * @param bool $ordered stations in order
     * @return \stdClass
     */
    private function make_config(array $stations, string $environment = 'gallery-hall', bool $ordered = false): \stdClass {
        $list = [];
        foreach (array_values($stations) as $i => $overrides) {
            $list[] = (object)array_merge([
                'id' => 'st' . ($i + 1),
                'title' => 'Station ' . ($i + 1),
                'text' => '<p>Text ' . ($i + 1) . '</p>',
                'textformat' => '1',
                'mediatype' => 'none',
                'fileitemid' => $i + 1,
                'questionbankentryid' => 0,
                'required' => true,
                'points' => 1,
            ], $overrides);
        }
        return (object)[
            'schema' => template::SCHEMA,
            'sceneid' => self::SCENEID,
            'environment' => $environment,
            'ordered' => $ordered,
            'stations' => $list,
        ];
    }

    /**
     * Store a file in a mod_vrroom file area.
     *
     * @param \context_module $context
     * @param string $area
     * @param int $itemid
     * @param string $filename
     * @param string $content
     */
    private function store_file(\context_module $context, string $area, int $itemid, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_vrroom',
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Create a draft area of the current user holding one file.
     *
     * @param string $filename
     * @param string $content
     * @return int draft item id
     */
    private function make_draft(string $filename, string $content): int {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftitemid;
    }

    /**
     * Content of the cube fixture.
     *
     * @return string
     */
    private function cube(): string {
        global $CFG;
        return file_get_contents($CFG->dirroot . '/mod/vrroom/tests/fixtures/cube.glb');
    }

    /**
     * Index a list of id-carrying arrays by id.
     *
     * @param array[] $items
     * @return array[]
     */
    private function by_id(array $items): array {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item['id']] = $item;
        }
        return $indexed;
    }

    /**
     * Assert the compiled scenes and graph pass the mod_vrroom validators.
     *
     * @param \mod_vrroom\local\template\compiled_activity $compiled
     */
    private function assert_valid(\mod_vrroom\local\template\compiled_activity $compiled): void {
        foreach ($compiled->scenes as $scene) {
            $this->assertSame([], scene_validator::validate($scene));
        }
        $this->assertSame([], graph_validator::validate($compiled->graph));
        // The documents survive a JSON round trip unchanged (they are stored as JSON).
        $this->assertSame($compiled->graph, json_decode(json_encode($compiled->graph, JSON_PRESERVE_ZERO_FRACTION), true));
    }

    /**
     * Name, completion rules and default grade.
     */
    public function test_basics(): void {
        $template = new template();
        $this->assertSame('gallery', $template->get_name());
        $this->assertSame(['completionobjectives'], $template->completion_rules());
        $this->assertSame(100, $template->default_grade());
    }

    /**
     * Unordered gallery: geometry from the anchors, visit and question objectives, slot numbering.
     */
    public function test_compile_unordered(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config([
            ['title' => 'The Vase', 'questionbankentryid' => 101, 'points' => 2],
            ['title' => 'Portrait', 'required' => false, 'points' => 1.5],
            ['title' => 'Coins', 'questionbankentryid' => 102, 'points' => 0],
        ]);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);

        $this->assertCount(1, $compiled->scenes);
        $scene = $compiled->scenes[0];
        $this->assertSame('vrroom-scene/1', $scene['schema']);
        $this->assertSame(self::SCENEID, $scene['id']);
        $this->assertSame('preset', $scene['environment']['type']);
        $this->assertSame('gallery-hall', $scene['environment']['preset']);
        $this->assertSame('hall', $scene['environment']['shape']['kind']);
        $this->assertSame([['id' => 'spawn-main', 'position' => [0.0, 0.0, 13.0], 'yaw' => 0.0]], $scene['spawn']);

        // Station 1 sits at anchor (-4.5, 0, 9), yaw 90 (front +X).
        $zones = $this->by_id($scene['zones']);
        $this->assertSame([-3.0, 1.5, 9.0], $zones['z-st1']['center']);
        $this->assertSame([3.0, 3.0, 3.0], $zones['z-st1']['size']);
        $this->assertSame('The Vase', $zones['z-st1']['name']);
        // Station 2 sits at anchor (4.5, 0, 9), yaw -90 (front -X).
        $this->assertSame([3.0, 1.5, 9.0], $zones['z-st2']['center']);

        $interactables = $this->by_id($scene['interactables']);
        $this->assertCount(3, $interactables);
        $this->assertSame('hotspot', $interactables['h-st1']['kind']);
        $this->assertSame([-3.9, 1.4, 9.0], $interactables['h-st1']['position']);
        $this->assertSame([0.0, 90.0, 0.0], $interactables['h-st1']['rotation']);
        $this->assertSame('z-st1', $interactables['h-st1']['zone']);
        $this->assertSame(1, $interactables['h-st1']['content']['question']);
        $this->assertNull($interactables['h-st2']['content']['question']);
        $this->assertSame(2, $interactables['h-st3']['content']['question']);
        $this->assertSame('<p>Text 1</p>', $interactables['h-st1']['content']['text']);
        $this->assertSame('1', $interactables['h-st1']['content']['textformat']);
        $this->assertSame([], $scene['props']);

        $graph = $compiled->graph;
        $this->assertSame('vrroom-graph/1', $graph['schema']);
        $objectives = $this->by_id($graph['objectives']);
        $this->assertSame(['v-st1', 'q-st1', 'v-st2', 'v-st3', 'q-st3'], array_keys($objectives));

        $this->assertSame('visit_zone', $objectives['v-st1']['type']);
        $this->assertSame('z-st1', $objectives['v-st1']['zone']);
        $this->assertSame(self::SCENEID, $objectives['v-st1']['scene']);
        $this->assertEquals(0, $objectives['v-st1']['points']);
        $this->assertTrue($objectives['v-st1']['required']);
        $this->assertArrayNotHasKey('unlock', $objectives['v-st1']);
        $this->assertSame(get_string('objectivevisit', 'vrroomtemplate_gallery', 'The Vase'), $objectives['v-st1']['title']);

        $this->assertSame('answer_question', $objectives['q-st1']['type']);
        $this->assertSame(1, $objectives['q-st1']['slot']);
        $this->assertEquals(2, $objectives['q-st1']['points']);
        $this->assertSame(['after' => ['v-st1']], $objectives['q-st1']['unlock']);

        // A station without a question gives its points to the visit.
        $this->assertEquals(1.5, $objectives['v-st2']['points']);
        $this->assertFalse($objectives['v-st2']['required']);
        $this->assertArrayNotHasKey('unlock', $objectives['v-st2']);

        $this->assertSame(2, $objectives['q-st3']['slot']);
        $this->assertArrayNotHasKey('s-order', $objectives);

        $this->assertSame([
            ['slot' => 1, 'questionbankentryid' => 101, 'maxmark' => 2.0, 'objectiveid' => 'q-st1'],
            ['slot' => 2, 'questionbankentryid' => 102, 'maxmark' => 1.0, 'objectiveid' => 'q-st3'],
        ], $compiled->slots);

        $rules = $this->by_id($graph['rules']);
        $this->assertSame(['r-open-st1', 'r-open-st3', 'r-finish'], array_keys($rules));
        $this->assertSame(['on' => 'objective_completed', 'objective' => 'v-st1'], $rules['r-open-st1']['on']);
        $this->assertSame([['do' => 'open_question', 'slot' => 1]], $rules['r-open-st1']['actions']);
        $this->assertSame([['do' => 'open_question', 'slot' => 2]], $rules['r-open-st3']['actions']);
        $this->assertSame(['on' => 'all_required_completed'], $rules['r-finish']['on']);
        $this->assertSame([['do' => 'finish']], $rules['r-finish']['actions']);
        $this->assertSame([], $graph['hidden']);
    }

    /**
     * Ordered gallery: each visit unlocks after the previous one, and a sequence covers the visits.
     */
    public function test_compile_ordered(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config([
            ['title' => 'A'],
            ['title' => 'B', 'questionbankentryid' => 55],
            ['title' => 'C'],
        ], 'courtyard', true);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);

        $scene = $compiled->scenes[0];
        $this->assertSame('courtyard', $scene['environment']['preset']);
        $zones = $this->by_id($scene['zones']);
        // Courtyard anchor 0 is (0, 0, -9) facing +Z: zone centre 1.5 m in front.
        $this->assertSame([0.0, 1.5, -7.5], $zones['z-st1']['center']);

        $objectives = $this->by_id($compiled->graph['objectives']);
        $this->assertSame(['v-st1', 'v-st2', 'q-st2', 'v-st3', 's-order'], array_keys($objectives));
        $this->assertArrayNotHasKey('unlock', $objectives['v-st1']);
        $this->assertSame(['after' => ['v-st1']], $objectives['v-st2']['unlock']);
        $this->assertSame(['after' => ['v-st2']], $objectives['v-st3']['unlock']);
        $this->assertSame(['after' => ['v-st2']], $objectives['q-st2']['unlock']);
        $this->assertSame('sequence', $objectives['s-order']['type']);
        $this->assertSame(['v-st1', 'v-st2', 'v-st3'], $objectives['s-order']['steps']);
        $this->assertFalse($objectives['s-order']['required']);
        $this->assertEquals(0, $objectives['s-order']['points']);
        $this->assertSame(get_string('objectiveordered', 'vrroomtemplate_gallery'), $objectives['s-order']['title']);

        $this->assertSame(
            [['slot' => 1, 'questionbankentryid' => 55, 'maxmark' => 1.0, 'objectiveid' => 'q-st2']],
            $compiled->slots
        );
    }

    /**
     * Model and image exhibits reference the stored files; a missing file drops the exhibit only.
     */
    public function test_compile_model_and_image(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_file($context, 'models', 1, 'cube.glb', $this->cube());
        $this->store_file($context, 'media', 2, 'portrait.png', 'not really a png');
        $config = $this->make_config([
            ['title' => 'Cube', 'mediatype' => 'model'],
            ['title' => 'Portrait', 'mediatype' => 'image'],
            ['title' => 'Lost model', 'mediatype' => 'model'],
        ]);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        $scene = $compiled->scenes[0];

        $props = $this->by_id($scene['props']);
        $this->assertSame(['p-st1'], array_keys($props));
        $this->assertSame([-4.5, 0.5, 9.0], $props['p-st1']['position']);
        $this->assertSame('box', $props['p-st1']['shape']);

        $interactables = $this->by_id($scene['interactables']);
        $this->assertSame(['m-st1', 'h-st1', 'i-st2', 'h-st2', 'h-st3'], array_keys($interactables));
        $model = $interactables['m-st1'];
        $this->assertSame('model', $model['kind']);
        $this->assertSame([-4.5, 1.0, 9.0], $model['position']);
        $this->assertSame([0.0, 90.0, 0.0], $model['rotation']);
        $this->assertSame('h-st1', $model['opens']);
        $this->assertSame(['area' => 'models', 'itemid' => 1, 'filepath' => '/', 'filename' => 'cube.glb'], $model['file']);

        $image = $interactables['i-st2'];
        $this->assertSame('image', $image['kind']);
        $this->assertSame([4.5, 1.6, 9.0], $image['position']);
        $this->assertEquals(1.6, $image['width']);
        $this->assertSame(['area' => 'media', 'itemid' => 2, 'filepath' => '/', 'filename' => 'portrait.png'], $image['file']);
        $this->assertSame('h-st2', $image['opens']);
    }

    /**
     * Uploaded environment: model environment, circle layout and bounds; refused without a file.
     */
    public function test_compile_upload_environment(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config([[], [], [], []], 'upload');

        try {
            (new template())->compile($config, $context);
            $this->fail('An uploaded environment without a file must not compile.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidscene', $e->errorcode);
        }

        $this->store_file($context, 'environment', 0, 'room.glb', $this->cube());
        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        $scene = $compiled->scenes[0];
        $this->assertSame('model', $scene['environment']['type']);
        $this->assertSame(
            ['area' => 'environment', 'itemid' => 0, 'filepath' => '/', 'filename' => 'room.glb'],
            $scene['environment']['file']
        );
        $this->assertSame(['min' => [-9.0, 0.0, -9.0], 'max' => [9.0, 0.0, 9.0]], $scene['environment']['bounds']);
        $this->assertSame([0.0, 0.0, 0.0], $scene['spawn'][0]['position']);

        // Station 1 at (0, 0, -6) facing +Z; station 2 at (6, 0, 0) facing -X.
        $zones = $this->by_id($scene['zones']);
        $this->assertSame([0.0, 1.5, -4.5], $zones['z-st1']['center']);
        $this->assertSame([4.5, 1.5, 0.0], $zones['z-st2']['center']);
    }

    /**
     * More stations than the environment has anchors is refused at compile time too.
     */
    public function test_compile_too_many_stations(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config(array_fill(0, 9, []), 'courtyard');
        $this->expectException(\moodle_exception::class);
        (new template())->compile($config, $context);
    }

    /**
     * A room from a room pack: its environment block, start point and anchors; no snapshot needed.
     */
    public function test_compile_pack_room(): void {
        $this->resetAfterTest();
        $this->use_test_pack();
        $context = $this->make_context();
        $config = $this->make_config([['title' => 'One'], ['title' => 'Two']], 'testpack/a');

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        $scene = $compiled->scenes[0];
        $def = environment_registry::get('testpack/a');
        $this->assertSame($def['environment'], $scene['environment']);
        $this->assertSame('pack', $scene['environment']['type']);
        $this->assertSame([['id' => 'spawn-main', 'position' => [0.0, 0.0, 8.0], 'yaw' => 0.0]], $scene['spawn']);
        // Station 1 sits at anchor (-8, 0, 4), yaw 90 (front +X): zone 1.5 m in front.
        $zones = $this->by_id($scene['zones']);
        $this->assertSame([-6.5, 1.5, 4.0], $zones['z-st1']['center']);
        $this->assertSame([6.5, 1.5, 4.0], $zones['z-st2']['center']);

        // Five stations do not fit the room's four anchors.
        $this->expectException(\moodle_exception::class);
        (new template())->compile($this->make_config(array_fill(0, 5, []), 'testpack/a'), $context);
    }

    /**
     * A small room (testpack/d: 'small', 'zonesize' 2, two anchors): 2 m zones 1 m in front, two
     * stations at most, and the same scenes from its snapshot without the pack.
     */
    public function test_compile_small_room(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_test_pack();
        $context = $this->make_context();
        $config = $this->make_config([['title' => 'One'], ['title' => 'Two']], 'testpack/d');
        $config->envsnapshot = environment_registry::snapshot(environment_registry::get('testpack/d'));
        $config = json_decode(json_encode($config));

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        // Station 1 sits at anchor (-1.1, 0, 0), yaw 0 (front +Z).
        $zones = $this->by_id($compiled->scenes[0]['zones']);
        $this->assertSame([-1.1, 1.5, 1.0], $zones['z-st1']['center']);
        $this->assertSame([2.0, 3.0, 2.0], $zones['z-st1']['size']);
        $this->assertSame([1.1, 1.5, 1.0], $zones['z-st2']['center']);
        $this->assertSame([2.0, 3.0, 2.0], $zones['z-st2']['size']);

        environment_registry::phpunit_set_packs([]);
        $missing = (new template())->compile($config, $context);
        $this->assertSame(json_encode($compiled->scenes), json_encode($missing->scenes));
        $this->use_test_pack();

        // Three stations do not fit its two places.
        $course = $this->getDataGenerator()->create_course();
        $template = new template();
        $errors = $template->validation(
            ['gallery_environment' => 'testpack/d', 'gallery_title' => ['One', 'Two', 'Three']],
            [],
            (int)$course->id
        );
        $this->assertSame(get_string('errortoomanystations', 'vrroomtemplate_gallery', 2), $errors['gallery_environment']);
        $this->expectException(\moodle_exception::class);
        $template->compile($this->make_config(array_fill(0, 3, []), 'testpack/d'), $context);
    }

    /**
     * Without the pack the activity compiles from the stored snapshot (the viewer shows a plain hall).
     */
    public function test_compile_from_snapshot_without_pack(): void {
        $this->resetAfterTest();
        $this->use_test_pack();
        $context = $this->make_context();
        $config = $this->make_config([['title' => 'One'], ['title' => 'Two']], 'testpack/a');
        $config->envsnapshot = environment_registry::snapshot(environment_registry::get('testpack/a'));
        $config = json_decode(json_encode($config));
        $installed = (new template())->compile($config, $context);

        environment_registry::phpunit_set_packs([]);
        $missing = (new template())->compile($config, $context);
        $this->assert_valid($missing);
        $this->assertSame(json_encode($installed->scenes), json_encode($missing->scenes));

        // Without a snapshot it cannot compile.
        unset($config->envsnapshot);
        $this->expectException(\moodle_exception::class);
        (new template())->compile($config, $context);
    }

    /**
     * extract_config() stores a snapshot for a pack room, none for a preset; validation limits the
     * stations to the room's anchors.
     */
    public function test_pack_room_config_and_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_test_pack();
        $template = new template();

        $config = $template->extract_config((object)['gallery_environment' => 'testpack/a', 'gallery_title' => ['One']]);
        $this->assertSame('testpack/a', $config->environment);
        $this->assertSame(environment_registry::snapshot(environment_registry::get('testpack/a')), $config->envsnapshot);
        $config = $template->extract_config((object)['gallery_environment' => 'courtyard', 'gallery_title' => ['One']]);
        $this->assertNull($config->envsnapshot);
        // An unknown key falls back to the default.
        $config = $template->extract_config((object)['gallery_environment' => 'testpack/zz', 'gallery_title' => ['One']]);
        $this->assertSame(template::DEFAULT_ENVIRONMENT, $config->environment);

        $course = $this->getDataGenerator()->create_course();
        $titles = array_map(fn ($i) => "Station {$i}", range(1, 5));
        $errors = $template->validation(['gallery_environment' => 'testpack/a', 'gallery_title' => $titles], [], (int)$course->id);
        $this->assertSame(get_string('errortoomanystations', 'vrroomtemplate_gallery', 4), $errors['gallery_environment']);
        $errors = $template->validation(['gallery_environment' => 'testpack/c', 'gallery_title' => ['One']], [], (int)$course->id);
        $this->assertSame(get_string('required'), $errors['gallery_environment']);
    }

    /**
     * The scene id and graph are stable across recompiles of the same config.
     */
    public function test_compile_is_deterministic(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config([['title' => 'A', 'questionbankentryid' => 7], ['title' => 'B']], 'gallery-hall', true);
        $first = (new template())->compile($config, $context);
        $second = (new template())->compile(json_decode(json_encode($config)), $context);
        $this->assertSame(json_encode($first->graph), json_encode($second->graph));
        $this->assertSame(json_encode($first->scenes), json_encode($second->scenes));
        $this->assertSame($first->slots, $second->slots);
    }

    /**
     * Form data to config: blank rows dropped, ids generated, values cleaned.
     */
    public function test_extract_config(): void {
        $this->resetAfterTest();
        $formdata = (object)[
            'gallery_environment' => 'courtyard',
            'gallery_ordered' => 1,
            'gallery_stationcount' => 4,
            'gallery_stationid' => ['', 'forged-id', '', ''],
            'gallery_title' => ['  First  ', 'Second', '', 'Fourth'],
            'gallery_text' => [
                ['text' => '<p>One</p>', 'format' => FORMAT_HTML],
                ['text' => 'Two', 'format' => FORMAT_PLAIN],
                ['text' => '', 'format' => FORMAT_HTML],
                ['text' => '<p>Four</p>', 'format' => FORMAT_HTML],
            ],
            'gallery_mediatype' => ['model', 'bogus', 'none', 'image'],
            'gallery_question' => [12, 0, 0, '13'],
            'gallery_required' => [1, 0, 1, 1],
            'gallery_points' => [2, -1, 1, '0.5'],
        ];

        $config = (new template())->extract_config($formdata);

        $this->assertSame(template::SCHEMA, $config->schema);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $config->sceneid);
        $this->assertSame('courtyard', $config->environment);
        $this->assertTrue($config->ordered);
        $this->assertCount(3, $config->stations);

        [$first, $second, $fourth] = $config->stations;
        $this->assertSame('First', $first->title);
        $this->assertSame('<p>One</p>', $first->text);
        $this->assertSame((string)FORMAT_HTML, $first->textformat);
        $this->assertSame('model', $first->mediatype);
        $this->assertSame(1, $first->fileitemid);
        $this->assertSame(12, $first->questionbankentryid);
        $this->assertTrue($first->required);
        $this->assertEquals(2, $first->points);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first->id);

        // A posted id the stored config does not know is replaced.
        $this->assertNotSame('forged-id', $second->id);
        $this->assertSame('none', $second->mediatype);
        $this->assertSame((string)FORMAT_PLAIN, $second->textformat);
        $this->assertFalse($second->required);
        $this->assertEquals(0, $second->points);
        $this->assertSame(2, $second->fileitemid);

        $this->assertSame('Fourth', $fourth->title);
        $this->assertSame(3, $fourth->fileitemid);
        $this->assertSame('image', $fourth->mediatype);
        $this->assertSame(13, $fourth->questionbankentryid);
        $this->assertEquals(0.5, $fourth->points);

        $ids = array_map(fn ($station) => $station->id, $config->stations);
        $this->assertCount(3, array_unique($ids));

        // An unknown environment falls back to the default.
        $formdata->gallery_environment = 'nowhere';
        $this->assertSame(template::DEFAULT_ENVIRONMENT, (new template())->extract_config($formdata)->environment);
    }

    /**
     * Editing keeps the stored scene id and the station ids the stored config knows.
     */
    public function test_extract_config_keeps_stored_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $stored = $this->make_config([['id' => 'keepme', 'title' => 'Old']]);
        $vrroomid = $DB->insert_record('vrroom', (object)[
            'course' => $course->id,
            'name' => 'Gallery',
            'runtime' => 'builtin',
            'template' => 'gallery',
            'templateconfig' => json_encode($stored),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $formdata = (object)[
            'instance' => $vrroomid,
            'gallery_environment' => 'gallery-hall',
            'gallery_ordered' => 0,
            'gallery_stationid' => ['keepme', 'keepme'],
            'gallery_title' => ['Renamed', 'Copy'],
            'gallery_text' => [['text' => '', 'format' => FORMAT_HTML], ['text' => '', 'format' => FORMAT_HTML]],
            'gallery_mediatype' => ['none', 'none'],
            'gallery_question' => [0, 0],
            'gallery_required' => [1, 1],
            'gallery_points' => [1, 1],
        ];
        $config = (new template())->extract_config($formdata);

        $this->assertSame(self::SCENEID, $config->sceneid);
        $this->assertSame('keepme', $config->stations[0]->id);
        $this->assertSame('Renamed', $config->stations[0]->title);
        // The same id posted twice is kept once only.
        $this->assertNotSame('keepme', $config->stations[1]->id);
    }

    /**
     * Draft areas are saved per station; files beyond the station count and of the other exhibit go.
     */
    public function test_save_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->make_context();
        $fs = get_file_storage();

        $formdata = (object)[
            'gallery_environment' => 'upload',
            'gallery_environmentfile' => $this->make_draft('room.glb', $this->cube()),
            'gallery_title' => ['Cube', '', 'Picture'],
            'gallery_mediatype' => ['model', 'none', 'image'],
            'gallery_model' => [$this->make_draft('cube.glb', $this->cube()), 0, 0],
            'gallery_image' => [0, 0, $this->make_draft('picture.png', 'png bytes')],
        ];
        $template = new template();
        $template->save_files($formdata, $context);

        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'environment', 0));
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'models', 1));
        // The blank row is skipped, so the picture belongs to station 2.
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 2));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 3));

        // Now one station, text only, bundled environment: everything else is removed.
        $template->save_files((object)[
            'gallery_environment' => 'gallery-hall',
            'gallery_title' => ['Cube'],
            'gallery_mediatype' => ['none'],
        ], $context);
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'environment'));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'models'));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'media'));
    }

    /**
     * Valid form data (with real draft files and a real question) passes validation.
     */
    public function test_validation_valid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbeid = $this->create_question_entry($course);

        $data = [
            'gallery_environment' => 'gallery-hall',
            'gallery_title' => ['Cube', 'Picture', ''],
            'gallery_text' => array_fill(0, 3, ['text' => '', 'format' => FORMAT_HTML]),
            'gallery_mediatype' => ['model', 'image', 'none'],
            'gallery_model' => [$this->make_draft('cube.glb', $this->cube()), 0, 0],
            'gallery_image' => [0, $this->make_draft('picture.png', 'png'), 0],
            'gallery_question' => [$qbeid, 0, 0],
            'gallery_required' => [1, 1, 1],
            'gallery_points' => [1, 0, 1],
        ];
        $this->assertSame([], (new template())->validation($data, [], (int)$course->id));
        $this->assertTrue($DB->record_exists('question_bank_entries', ['id' => $qbeid]));
    }

    /**
     * Each validation rule reports on the right element.
     */
    public function test_validation_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int)$course->id;
        $template = new template();
        $qbeid = $this->create_question_entry($course);
        $component = 'vrroomtemplate_gallery';

        // No stations at all.
        $errors = $template->validation(['gallery_environment' => 'gallery-hall', 'gallery_title' => ['', '']], [], $courseid);
        $this->assertSame(get_string('errornostations', $component), $errors['gallery_title[0]']);

        // Too many stations for the courtyard (8 anchors).
        $titles = array_map(fn ($i) => "Station {$i}", range(1, 9));
        $errors = $template->validation(['gallery_environment' => 'courtyard', 'gallery_title' => $titles], [], $courseid);
        $this->assertSame(get_string('errortoomanystations', $component, 8), $errors['gallery_environment']);

        // Upload without a file; more than 12 stations for an upload.
        $titles = array_map(fn ($i) => "Station {$i}", range(1, 13));
        $errors = $template->validation(['gallery_environment' => 'upload', 'gallery_environmentfile' => 0,
            'gallery_title' => $titles], [], $courseid);
        $this->assertSame(get_string('errorenvironmentfile', $component), $errors['gallery_environmentfile']);
        $this->assertSame(get_string('errortoomanystations', $component, 12), $errors['gallery_environment']);

        // Per-station errors.
        $data = [
            'gallery_environment' => 'gallery-hall',
            'gallery_title' => ['Model', 'Image', 'Points', 'Dup', 'Unknown', ''],
            'gallery_text' => array_fill(0, 6, ['text' => '', 'format' => FORMAT_HTML]),
            'gallery_mediatype' => ['model', 'image', 'none', 'none', 'none', 'none'],
            'gallery_model' => [0, 0, 0, 0, 0, 0],
            'gallery_image' => [0, 0, 0, 0, 0, 0],
            'gallery_question' => [$qbeid, 0, 0, $qbeid, 999999999, 0],
            'gallery_required' => [1, 1, 1, 1, 1, 1],
            'gallery_points' => [1, 1, -2, 1, 1, 1],
        ];
        $data['gallery_text'][5] = ['text' => '<p>Orphan text</p>', 'format' => FORMAT_HTML];
        $errors = $template->validation($data, [], $courseid);
        $this->assertSame(get_string('errormodelrequired', $component), $errors['gallery_model[0]']);
        $this->assertSame(get_string('errorimagerequired', $component), $errors['gallery_image[1]']);
        $this->assertSame(get_string('errorpoints', $component), $errors['gallery_points[2]']);
        $this->assertSame(get_string('errorquestionduplicate', 'mod_vrroom'), $errors['gallery_question[3]']);
        $this->assertSame(get_string('errorquestionnotfound', 'mod_vrroom'), $errors['gallery_question[4]']);
        $this->assertSame(get_string('errortitlerequired', $component), $errors['gallery_title[5]']);
        $this->assertArrayNotHasKey('gallery_question[0]', $errors);
        $this->assertArrayNotHasKey('gallery_environment', $errors);
    }

    /**
     * The activity form shows the gallery fields and fills them from the stored config.
     */
    public function test_form_for_existing_activity(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/vrroom/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php');
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase|Portrait',
            'stationrequired' => '1|0',
            'stationpoints' => '1|2',
        ]);
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        $html = $form->render();

        $this->assertStringContainsString('name="gallery_environment"', $html);
        $this->assertStringContainsString('name="gallery_title[0]"', $html);
        // Two stored stations still get the default three station groups.
        $this->assertStringContainsString('name="gallery_title[2]"', $html);
        $this->assertStringContainsString('value="Vase"', $html);
        $this->assertStringContainsString('value="Portrait"', $html);
        $this->assertStringContainsString('name="gallery_addstations"', $html);
        // The room preview stands under the selector, its image named after the room.
        $this->assertStringContainsString('data-region="vrroom-envpreview"', $html);
        $this->assertStringContainsString('alt="' . s(get_string('preset_galleryhall', 'mod_vrroom')) . '"', $html);
        $this->assertStringContainsString('environments/gallery-hall', $html);

        // Stored values beat the repeat_elements() defaults (required 1, points 1).
        $xpath = self::form_xpath($html);
        $required = $xpath->query('//input[@type="checkbox"][@name="gallery_required[1]"]')->item(0);
        $this->assertNotNull($required);
        $this->assertFalse($required->hasAttribute('checked'));
        $points = $xpath->query('//input[@name="gallery_points[1]"]')->item(0);
        $this->assertNotNull($points);
        $this->assertEquals(2.0, (float)$points->getAttribute('value'));
    }

    /**
     * Parse rendered form HTML for XPath queries.
     *
     * @param string $html
     * @return \DOMXPath
     */
    private static function form_xpath(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }

    /**
     * Points that cannot be stored (infinite, too large for the question slot, too small to keep)
     * are validation errors, never a failed save.
     */
    public function test_validation_points_range(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $component = 'vrroomtemplate_gallery';
        $max = format_float(template::MAX_POINTS, 0);

        $data = [
            'gallery_environment' => 'gallery-hall',
            'gallery_title' => ['Inf', 'Big', 'Tiny', 'Max', 'Small', 'Zero'],
            'gallery_text' => array_fill(0, 6, ['text' => '', 'format' => FORMAT_HTML]),
            'gallery_mediatype' => array_fill(0, 6, 'none'),
            'gallery_model' => array_fill(0, 6, 0),
            'gallery_image' => array_fill(0, 6, 0),
            'gallery_question' => array_fill(0, 6, 0),
            'gallery_required' => array_fill(0, 6, 1),
            'gallery_points' => ['1e309', 100000, 0.00000001, 99999, 0.0000001, 0],
        ];
        $errors = (new template())->validation($data, [], (int)$course->id);

        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['gallery_points[0]']);
        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['gallery_points[1]']);
        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['gallery_points[2]']);
        $this->assertArrayNotHasKey('gallery_points[3]', $errors);
        $this->assertArrayNotHasKey('gallery_points[4]', $errors);
        $this->assertArrayNotHasKey('gallery_points[5]', $errors);
    }

    /**
     * Without the form's validation (a generator or a web service), points that cannot be stored
     * are clamped, so the config always encodes.
     */
    public function test_extract_config_clamps_points(): void {
        $this->resetAfterTest();
        $formdata = (object)[
            'gallery_environment' => 'gallery-hall',
            'gallery_stationcount' => 2,
            'gallery_title' => ['One', 'Two'],
            'gallery_text' => array_fill(0, 2, ['text' => '', 'format' => FORMAT_HTML]),
            'gallery_mediatype' => ['none', 'none'],
            'gallery_question' => [0, 0],
            'gallery_required' => [1, 1],
            'gallery_points' => [INF, 1e12],
        ];

        $config = (new template())->extract_config($formdata);

        $this->assertSame(0.0, $config->stations[0]->points);
        $this->assertSame(template::MAX_POINTS, $config->stations[1]->points);
        $this->assertIsString(json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * Restore remapping replaces the stations' question bank entry ids and reports whether anything changed.
     */
    public function test_remap_question_entries(): void {
        $template = new template();
        $config = $this->make_config([
            ['questionbankentryid' => 101],
            ['questionbankentryid' => 0],
            ['questionbankentryid' => 102],
        ]);
        $map = fn (int $old): int => [101 => 501][$old] ?? $old;

        $this->assertTrue($template->remap_question_entries($config, $map));
        $this->assertSame(501, $config->stations[0]->questionbankentryid);
        $this->assertSame(0, $config->stations[1]->questionbankentryid);
        $this->assertSame(102, $config->stations[2]->questionbankentryid);

        // Nothing left to map: unchanged.
        $this->assertFalse($template->remap_question_entries($config, $map));
        $this->assertFalse($template->remap_question_entries((object)['schema' => template::SCHEMA], $map));

        // A question the restoring user may not use is dropped (the map returns 0).
        $this->assertTrue($template->remap_question_entries($config, fn (int $old): int => $old === 102 ? 0 : $old));
        $this->assertSame(0, $config->stations[2]->questionbankentryid);
        $this->assertSame(501, $config->stations[0]->questionbankentryid);
    }

    /**
     * The chooser help comes from the template's own string.
     */
    public function test_chooser_help(): void {
        $this->assertSame(get_string('chooserhelp', 'vrroomtemplate_gallery'), (new template())->get_chooser_help());
    }

    /**
     * Create a short-answer question in a question bank of the course.
     *
     * @param \stdClass $course
     * @return int question bank entry id
     */
    private function create_question_entry(\stdClass $course): int {
        global $DB;
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
            ['contextid' => \context_module::instance($qbank->cmid)->id]
        );
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        return (int)$DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
    }
}
