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

namespace vrroomtemplate_escape;

use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\scene\environment_registry;
use mod_vrroom\local\scene\scene_validator;
use mod_vrroom\local\template\compiled_activity;

/**
 * Tests for the Escape chain template: compile, config extraction, files, validation and remapping.
 *
 * @package    vrroomtemplate_escape
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template::class)]
final class template_test extends \advanced_testcase {
    /** @var string Fixed scene id used by the configs built here. */
    private const SCENEID = '7a2e4c1d-3b5f-4e6a-8c9d-0f1e2d3c4b5a';

    protected function tearDown(): void {
        environment_registry::phpunit_set_packs(null);
        parent::tearDown();
    }

    /**
     * Install the fixture room pack (testpack/a: 4 anchors and an exit; testpack/b: start yaw 90, no exit).
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
     * Build an escape chain config.
     *
     * @param array[] $puzzles per-puzzle overrides
     * @param array $overrides top-level overrides
     * @return \stdClass
     */
    private function make_config(array $puzzles, array $overrides = []): \stdClass {
        $list = [];
        foreach (array_values($puzzles) as $i => $puzzle) {
            $list[] = (object)array_merge([
                'id' => 'pz' . ($i + 1),
                'title' => 'Puzzle ' . ($i + 1),
                'clue' => '<p>Clue ' . ($i + 1) . '</p>',
                'clueformat' => '1',
                'cluemedia' => 'none',
                'fileitemid' => $i + 1,
                'locktype' => 'none',
                'keyname' => 'Key',
                'questionbankentryid' => 0,
                'hints' => [],
                'points' => 1,
            ], $puzzle);
        }
        return (object)array_merge([
            'schema' => template::SCHEMA,
            'sceneid' => self::SCENEID,
            'environment' => 'escape-room',
            'story' => '',
            'timelimit' => 0,
            'hintpenalty' => 0,
            'puzzles' => $list,
        ], $overrides);
    }

    /**
     * The realistic three-puzzle chain used by several tests: key, code lock, nothing.
     *
     * @return \stdClass
     */
    private function chain_config(): \stdClass {
        return $this->make_config([
            ['id' => 'desk', 'title' => 'Desk', 'locktype' => 'key', 'keyname' => 'Brass key',
                'hints' => ['Look on the desk.'], 'points' => 2],
            ['id' => 'safe', 'title' => 'Safe', 'locktype' => 'question', 'questionbankentryid' => 77,
                'hints' => ['Count the books.', 'It is a year.'], 'points' => 3],
            ['id' => 'hall', 'title' => 'Hall', 'locktype' => 'none', 'hints' => ['Go to the back.'], 'points' => 1],
        ], ['story' => 'You are locked in the lab.', 'timelimit' => 900, 'hintpenalty' => 0.5]);
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
     * Assert the compiled scenes and graph pass the mod_vrroom validators and survive JSON.
     *
     * @param compiled_activity $compiled
     */
    private function assert_valid(compiled_activity $compiled): void {
        foreach ($compiled->scenes as $scene) {
            $this->assertSame([], scene_validator::validate($scene));
        }
        $this->assertSame([], scene_validator::validate_set($compiled->scenes));
        $this->assertSame([], graph_validator::validate($compiled->graph));
        $this->assertSame($compiled->graph, json_decode(json_encode($compiled->graph, JSON_PRESERVE_ZERO_FRACTION), true));
        $this->assertSame($compiled->scenes, json_decode(json_encode($compiled->scenes, JSON_PRESERVE_ZERO_FRACTION), true));
    }

    /**
     * Name, completion rules, default grade and chooser help.
     */
    public function test_basics(): void {
        $template = new template();
        $this->assertSame('escape', $template->get_name());
        $this->assertSame('vrroomtemplate_escape', $template->get_component());
        $this->assertSame(['completionobjectives'], $template->completion_rules());
        $this->assertSame(100, $template->default_grade());
        $this->assertSame(get_string('chooserhelp', 'vrroomtemplate_escape'), $template->get_chooser_help());
    }

    /**
     * The realistic chain: room, desks, clues, key, code lock, exit door and story.
     */
    public function test_compile_scene(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $compiled = (new template())->compile($this->chain_config(), $context);
        $this->assert_valid($compiled);

        $this->assertCount(1, $compiled->scenes);
        $scene = $compiled->scenes[0];
        $this->assertSame(self::SCENEID, $scene['id']);
        $this->assertSame(get_string('scenename', 'vrroomtemplate_escape'), $scene['name']);
        $this->assertSame('preset', $scene['environment']['type']);
        $this->assertSame('escape-room', $scene['environment']['preset']);
        $this->assertSame('#101215', $scene['environment']['sky']);
        $this->assertSame([['id' => 's-start', 'position' => [0.0, 0.0, 4.5], 'yaw' => 0.0]], $scene['spawn']);

        // Puzzle 1 sits at anchor (-4, 0, 3), yaw 90 (front +X).
        $zones = $this->by_id($scene['zones']);
        $this->assertSame(['z-desk', 'z-safe', 'z-hall'], array_keys($zones));
        $this->assertSame([-2.8, 1.5, 3.0], $zones['z-desk']['center']);
        $this->assertSame([2.4, 3.0, 2.4], $zones['z-desk']['size']);
        $this->assertSame('Desk', $zones['z-desk']['name']);
        // Puzzle 2 at (4, 0, 3), yaw -90; puzzle 3 at (-4, 0, -0.5), yaw 90.
        $this->assertSame([2.8, 1.5, 3.0], $zones['z-safe']['center']);
        $this->assertSame([-2.8, 1.5, -0.5], $zones['z-hall']['center']);

        $props = $this->by_id($scene['props']);
        $this->assertSame(['p-desk', 'p-safe', 'p-hall', 'p-exit'], array_keys($props));
        $this->assertSame([-3.7, 0.4, 3.0], $props['p-desk']['position']);
        $this->assertSame([1.2, 0.8, 0.6], $props['p-desk']['size']);
        $this->assertSame([0.0, 90.0, 0.0], $props['p-desk']['rotation']);
        $this->assertSame('#6b4f3a', $props['p-desk']['color']);
        $this->assertSame([0.0, 1.1, -5.75], $props['p-exit']['position']);
        $this->assertSame([1.2, 2.2, 0.1], $props['p-exit']['size']);
        $this->assertSame('#6b2f2f', $props['p-exit']['color']);

        $items = $this->by_id($scene['interactables']);
        $this->assertSame(['h-desk', 'k-desk', 'h-safe', 'h-hall', 'd-exit', 't-story'], array_keys($items));

        $this->assertSame('hotspot', $items['h-desk']['kind']);
        $this->assertSame([-3.1, 1.3, 3.0], $items['h-desk']['position']);
        $this->assertSame('z-desk', $items['h-desk']['zone']);
        $this->assertSame(['title' => 'Desk', 'text' => '<p>Clue 1</p>', 'textformat' => '1', 'media' => [],
            'question' => null], $items['h-desk']['content']);
        $this->assertSame(1, $items['h-safe']['content']['question']);
        $this->assertSame([3.1, 1.3, 3.0], $items['h-safe']['position']);
        $this->assertNull($items['h-hall']['content']['question']);

        $key = $items['k-desk'];
        $this->assertSame('grabbable', $key['kind']);
        $this->assertSame('Brass key', $key['name']);
        $this->assertSame('box', $key['shape']);
        $this->assertSame([0.24, 0.05, 0.08], $key['size']);
        $this->assertSame('#d4af37', $key['color']);
        $this->assertSame([-3.55, 0.83, 3.0], $key['position']);
        $this->assertSame([0.0, 90.0, 0.0], $key['rotation']);
        $this->assertSame('z-desk', $key['zone']);

        $door = $items['d-exit'];
        $this->assertSame('hotspot', $door['kind']);
        $this->assertSame(get_string('exitdoor', 'vrroomtemplate_escape'), $door['name']);
        $this->assertSame([0.0, 1.3, -5.3], $door['position']);
        $this->assertArrayNotHasKey('zone', $door);
        $this->assertSame('<p>' . s(get_string('exittext', 'vrroomtemplate_escape')) . '</p>', $door['content']['text']);
        $this->assertSame('1', $door['content']['textformat']);

        $story = $items['t-story'];
        $this->assertSame('text', $story['kind']);
        $this->assertSame('You are locked in the lab.', $story['text']);
        $this->assertSame([0.0, 2.2, 2.6], $story['position']);
        $this->assertEquals(2.4, $story['width']);
        $this->assertSame(get_string('storyname', 'vrroomtemplate_escape'), $story['name']);
    }

    /**
     * The realistic chain's graph: objectives, unlock chain, hints, hidden items, rules, slots.
     */
    public function test_compile_graph(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $compiled = (new template())->compile($this->chain_config(), $context);
        $graph = $compiled->graph;

        $this->assertSame('vrroom-graph/1', $graph['schema']);
        $this->assertSame(900, $graph['timelimit']);
        $this->assertSame(['k-desk', 'd-exit'], $graph['hidden']);

        $objectives = $this->by_id($graph['objectives']);
        $this->assertSame(['v-desk', 'x-desk', 'v-safe', 'x-safe', 'v-hall', 'o-exit'], array_keys($objectives));

        // Key puzzle: the visit is worth nothing, taking the key carries the points and the hints.
        $this->assertSame('visit_zone', $objectives['v-desk']['type']);
        $this->assertSame('z-desk', $objectives['v-desk']['zone']);
        $this->assertSame(self::SCENEID, $objectives['v-desk']['scene']);
        $this->assertEquals(0, $objectives['v-desk']['points']);
        $this->assertTrue($objectives['v-desk']['required']);
        $this->assertArrayNotHasKey('unlock', $objectives['v-desk']);
        $this->assertArrayNotHasKey('hints', $objectives['v-desk']);
        $this->assertSame(get_string('objectivefind', 'vrroomtemplate_escape', 'Desk'), $objectives['v-desk']['title']);

        $this->assertSame('interact', $objectives['x-desk']['type']);
        $this->assertSame('k-desk', $objectives['x-desk']['target']);
        $this->assertEquals(2, $objectives['x-desk']['points']);
        $this->assertSame(['after' => ['v-desk']], $objectives['x-desk']['unlock']);
        $this->assertSame(get_string('objectivekey', 'vrroomtemplate_escape', 'Brass key'), $objectives['x-desk']['title']);
        $this->assertSame(
            [['id' => 'hn-desk-1', 'text' => 'Look on the desk.', 'penalty' => 0.5]],
            $objectives['x-desk']['hints']
        );

        // Code lock: opens after the previous puzzle is solved, retried until right.
        $this->assertSame(['after' => ['x-desk']], $objectives['v-safe']['unlock']);
        $this->assertSame('answer_question', $objectives['x-safe']['type']);
        $this->assertSame(1, $objectives['x-safe']['slot']);
        $this->assertSame(1, $objectives['x-safe']['pass_fraction']);
        $this->assertEquals(3, $objectives['x-safe']['points']);
        $this->assertSame(['after' => ['v-safe']], $objectives['x-safe']['unlock']);
        $this->assertSame(get_string('objectivecode', 'vrroomtemplate_escape', 'Safe'), $objectives['x-safe']['title']);
        $this->assertSame(['hn-safe-1', 'hn-safe-2'], array_column($objectives['x-safe']['hints'], 'id'));

        // No lock: the visit is the solve and carries the points and hints.
        $this->assertSame(['after' => ['x-safe']], $objectives['v-hall']['unlock']);
        $this->assertEquals(1, $objectives['v-hall']['points']);
        $this->assertSame(
            [['id' => 'hn-hall-1', 'text' => 'Go to the back.', 'penalty' => 0.5]],
            $objectives['v-hall']['hints']
        );

        $this->assertSame('interact', $objectives['o-exit']['type']);
        $this->assertSame('d-exit', $objectives['o-exit']['target']);
        $this->assertEquals(0, $objectives['o-exit']['points']);
        $this->assertTrue($objectives['o-exit']['required']);
        $this->assertSame(['after' => ['x-desk', 'x-safe', 'v-hall']], $objectives['o-exit']['unlock']);
        $this->assertSame(get_string('objectiveexit', 'vrroomtemplate_escape'), $objectives['o-exit']['title']);

        $rules = $this->by_id($graph['rules']);
        $this->assertSame(['r-show-desk', 'r-take-desk', 'r-open-safe', 'r-exit', 'r-finish'], array_keys($rules));
        $this->assertSame(['on' => 'objective_completed', 'objective' => 'v-desk'], $rules['r-show-desk']['on']);
        $this->assertSame([['do' => 'show', 'target' => 'k-desk']], $rules['r-show-desk']['actions']);
        $this->assertSame(['on' => 'objective_completed', 'objective' => 'x-desk'], $rules['r-take-desk']['on']);
        $this->assertSame([['do' => 'hide', 'target' => 'k-desk']], $rules['r-take-desk']['actions']);
        $this->assertSame(['on' => 'objective_completed', 'objective' => 'v-safe'], $rules['r-open-safe']['on']);
        $this->assertSame([['do' => 'open_question', 'slot' => 1]], $rules['r-open-safe']['actions']);
        $this->assertSame(['on' => 'objective_unlocked', 'objective' => 'o-exit'], $rules['r-exit']['on']);
        $this->assertSame([['do' => 'show', 'target' => 'd-exit']], $rules['r-exit']['actions']);
        $this->assertSame(['on' => 'objective_completed', 'objective' => 'o-exit'], $rules['r-finish']['on']);
        $this->assertSame([['do' => 'finish']], $rules['r-finish']['actions']);

        $this->assertSame([
            ['slot' => 1, 'questionbankentryid' => 77, 'maxmark' => 3.0, 'objectiveid' => 'x-safe'],
        ], $compiled->slots);
    }

    /**
     * Without hints, a time limit, a story or a penalty, those keys stay out; slots number in order.
     */
    public function test_compile_minimal_and_slots(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->make_config([
            ['locktype' => 'question', 'questionbankentryid' => 11, 'points' => 0],
            ['locktype' => 'key', 'keyname' => ''],
            ['locktype' => 'question', 'questionbankentryid' => 12],
            // A question lock without a question cannot be solved: it compiles as no lock.
            ['locktype' => 'question', 'questionbankentryid' => 0],
        ]);
        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);

        $graph = $compiled->graph;
        $this->assertSame(0, $graph['timelimit']);
        foreach ($graph['objectives'] as $objective) {
            $this->assertArrayNotHasKey('hints', $objective, $objective['id']);
        }
        $this->assertSame([
            ['slot' => 1, 'questionbankentryid' => 11, 'maxmark' => 1.0, 'objectiveid' => 'x-pz1'],
            ['slot' => 2, 'questionbankentryid' => 12, 'maxmark' => 1.0, 'objectiveid' => 'x-pz3'],
        ], $compiled->slots);

        $objectives = $this->by_id($graph['objectives']);
        $this->assertSame(
            ['v-pz1', 'x-pz1', 'v-pz2', 'x-pz2', 'v-pz3', 'x-pz3', 'v-pz4', 'o-exit'],
            array_keys($objectives)
        );
        $this->assertSame(['after' => ['x-pz3']], $objectives['v-pz4']['unlock']);
        $this->assertEquals(1, $objectives['v-pz4']['points']);
        $this->assertSame(['after' => ['x-pz1', 'x-pz2', 'x-pz3', 'v-pz4']], $objectives['o-exit']['unlock']);
        // An empty key name falls back to the default name.
        $this->assertSame(get_string(
            'objectivekey',
            'vrroomtemplate_escape',
            get_string('keyname_default', 'vrroomtemplate_escape')
        ), $objectives['x-pz2']['title']);

        $items = $this->by_id($compiled->scenes[0]['interactables']);
        $this->assertArrayNotHasKey('t-story', $items);
        $this->assertNull($items['h-pz4']['content']['question']);
        $this->assertSame(2, $items['h-pz3']['content']['question']);
    }

    /**
     * Clue exhibits reference the stored files; a missing file drops the exhibit only.
     */
    public function test_compile_clue_exhibits(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_file($context, 'media', 1, 'note.png', 'not really a png');
        $this->store_file($context, 'models', 2, 'cube.glb', $this->cube());
        $config = $this->make_config([
            ['cluemedia' => 'image'],
            ['cluemedia' => 'model'],
            ['cluemedia' => 'model'],
        ]);
        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);

        $items = $this->by_id($compiled->scenes[0]['interactables']);
        $this->assertSame(['i-pz1', 'h-pz1', 'm-pz2', 'h-pz2', 'h-pz3', 'd-exit'], array_keys($items));

        $image = $items['i-pz1'];
        $this->assertSame('image', $image['kind']);
        $this->assertSame([-4.35, 1.7, 3.0], $image['position']);
        $this->assertEquals(1.0, $image['width']);
        $this->assertSame('h-pz1', $image['opens']);
        $this->assertSame('z-pz1', $image['zone']);
        $this->assertSame(['area' => 'media', 'itemid' => 1, 'filepath' => '/', 'filename' => 'note.png'], $image['file']);

        // Puzzle 2 at (4, 0, 3), yaw -90: the model sits on the desk.
        $model = $items['m-pz2'];
        $this->assertSame('model', $model['kind']);
        $this->assertSame([3.7, 0.8, 3.0], $model['position']);
        $this->assertEquals(0.5, $model['fit']);
        $this->assertSame('h-pz2', $model['opens']);
        $this->assertSame(['area' => 'models', 'itemid' => 2, 'filepath' => '/', 'filename' => 'cube.glb'], $model['file']);
    }

    /**
     * A room from a room pack: environment block, start, anchors; the story panel follows the start yaw.
     */
    public function test_compile_pack_room(): void {
        $this->resetAfterTest();
        $this->use_test_pack();
        $context = $this->make_context();

        $compiled = (new template())->compile($this->make_config([[], []], [
            'environment' => 'testpack/b',
            'story' => 'Find the way out.',
        ]), $context);
        $this->assert_valid($compiled);
        $scene = $compiled->scenes[0];
        $this->assertSame(environment_registry::get('testpack/b')['environment'], $scene['environment']);
        $this->assertSame([['id' => template::SPAWN_ID, 'position' => [0.0, 0.0, 0.0], 'yaw' => 90.0]], $scene['spawn']);
        $items = $this->by_id($scene['interactables']);
        // Start (0, 0, 0) at yaw 90 looks along -X: the story stands 1.9 m ahead, facing back.
        $this->assertSame([-1.9, 2.2, 0.0], $items['t-story']['position']);
        $this->assertSame([0.0, 90.0, 0.0], $items['t-story']['rotation']);

        // Room a has an exit anchor (0, 0, -9.5): the door goes there.
        $compiled = (new template())->compile($this->make_config([[]], ['environment' => 'testpack/a']), $context);
        $this->assert_valid($compiled);
        $props = $this->by_id($compiled->scenes[0]['props']);
        $this->assertArrayHasKey('p-exit', $props);

        // Five puzzles do not fit four anchors.
        $this->expectException(\moodle_exception::class);
        (new template())->compile($this->make_config(array_fill(0, 5, []), ['environment' => 'testpack/a']), $context);
    }

    /**
     * Without the pack the activity compiles from its stored snapshot, identically.
     */
    public function test_compile_from_snapshot_without_pack(): void {
        $this->resetAfterTest();
        $this->use_test_pack();
        $context = $this->make_context();
        $config = $this->make_config([[], []], ['environment' => 'testpack/a', 'story' => 'Hello.']);
        $config->envsnapshot = environment_registry::snapshot(environment_registry::get('testpack/a'));
        $config = json_decode(json_encode($config));
        $installed = (new template())->compile($config, $context);

        environment_registry::phpunit_set_packs([]);
        $missing = (new template())->compile($config, $context);
        $this->assert_valid($missing);
        $this->assertSame(json_encode($installed->scenes), json_encode($missing->scenes));

        // The extract_config() method keeps the stored missing room only through the stored config's snapshot.
        $config = (new template())->extract_config((object)['escape_environment' => 'testpack/a', 'escape_title' => ['One']]);
        $this->assertSame(template::DEFAULT_ENVIRONMENT, $config->environment);
        $this->assertNull($config->envsnapshot);
    }

    /**
     * A small room (testpack/d: 'small', 'zonesize' 2, two anchors, no exit): 2 m puzzle zones 1 m in
     * front, two puzzles at most.
     */
    public function test_compile_small_room(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_test_pack();
        $context = $this->make_context();
        $compiled = (new template())->compile($this->make_config([[], []], ['environment' => 'testpack/d']), $context);
        $this->assert_valid($compiled);
        $zones = $this->by_id($compiled->scenes[0]['zones']);
        // Puzzle 1 sits at anchor (-1.1, 0, 0), yaw 0 (front +Z).
        $this->assertSame([-1.1, 1.5, 1.0], $zones['z-pz1']['center']);
        $this->assertSame([2.0, 3.0, 2.0], $zones['z-pz1']['size']);
        $this->assertSame([1.1, 1.5, 1.0], $zones['z-pz2']['center']);

        $course = $this->getDataGenerator()->create_course();
        $errors = (new template())->validation(
            ['escape_environment' => 'testpack/d', 'escape_title' => ['One', 'Two', 'Three']],
            [],
            (int)$course->id
        );
        $this->assertSame(get_string('errortoomanypuzzles', 'vrroomtemplate_escape', 2), $errors['escape_environment']);
        $this->expectException(\moodle_exception::class);
        (new template())->compile($this->make_config(array_fill(0, 3, []), ['environment' => 'testpack/d']), $context);
    }

    /**
     * Validation limits the puzzles to the pack room's anchors.
     */
    public function test_pack_room_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_test_pack();
        $course = $this->getDataGenerator()->create_course();
        $titles = array_map(fn ($i) => "Puzzle {$i}", range(1, 5));
        $template = new template();
        $errors = $template->validation(['escape_environment' => 'testpack/a', 'escape_title' => $titles], [], (int)$course->id);
        $this->assertSame(get_string('errortoomanypuzzles', 'vrroomtemplate_escape', 4), $errors['escape_environment']);
        $errors = $template->validation(['escape_environment' => 'testpack/zz', 'escape_title' => ['One']], [], (int)$course->id);
        $this->assertSame(get_string('required'), $errors['escape_environment']);
    }

    /**
     * A room without an exit anchor gets the exit in front of the spawn point and no door prop.
     */
    public function test_compile_exit_fallback(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $compiled = (new template())->compile($this->make_config([[]], ['environment' => 'gallery-hall']), $context);
        $this->assert_valid($compiled);

        $scene = $compiled->scenes[0];
        $this->assertSame('gallery-hall', $scene['environment']['preset']);
        $this->assertArrayNotHasKey('p-exit', $this->by_id($scene['props']));
        $items = $this->by_id($scene['interactables']);
        // Spawn (0, 0, 13) looking along -Z: exit at (0, 0, 11) facing the spawn point.
        $this->assertSame([0.0, 1.3, 11.3], $items['d-exit']['position']);
        $this->assertSame([0.0, 0.0, 0.0], $items['d-exit']['rotation']);
    }

    /**
     * More puzzles than the room has places is refused at compile time.
     */
    public function test_compile_too_many_puzzles(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->expectException(\moodle_exception::class);
        (new template())->compile($this->make_config(array_fill(0, 9, [])), $context);
    }

    /**
     * The same config compiles to the same documents (ids come from the stored uuids).
     */
    public function test_compile_is_deterministic(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = $this->chain_config();
        $first = (new template())->compile($config, $context);
        $second = (new template())->compile(json_decode(json_encode($config)), $context);
        $this->assertSame(json_encode($first->scenes), json_encode($second->scenes));
        $this->assertSame(json_encode($first->graph), json_encode($second->graph));
        $this->assertSame($first->slots, $second->slots);
    }

    /**
     * Form data to config: blank rows dropped, ids generated, values cleaned.
     */
    public function test_extract_config(): void {
        $this->resetAfterTest();
        $formdata = (object)[
            'escape_environment' => 'escape-room',
            'escape_story' => '  Locked in.  ',
            'escape_timelimit' => 1200,
            'escape_hintpenalty' => '0.25',
            'escape_puzzlecount' => 4,
            'escape_puzzleid' => ['', 'forged-id', '', ''],
            'escape_title' => ['  Desk  ', 'Safe', '', 'Hall'],
            'escape_clue' => [
                ['text' => '<p>One</p>', 'format' => FORMAT_HTML],
                ['text' => 'Two', 'format' => FORMAT_PLAIN],
                ['text' => '', 'format' => FORMAT_HTML],
                ['text' => '<p>Four</p>', 'format' => FORMAT_HTML],
            ],
            'escape_cluemedia' => ['image', 'bogus', 'none', 'model'],
            'escape_locktype' => ['key', 'question', 'key', 'bogus'],
            'escape_keyname' => ['Brass key', 'Ignored', '', ''],
            'escape_question' => [5, '13', 0, 9],
            'escape_hint1' => ['  First  ', '', '', ''],
            'escape_hint2' => ['Second', 'Only', '', ''],
            'escape_points' => [2, -1, 1, '0.5'],
        ];

        $config = (new template())->extract_config($formdata);

        $this->assertSame(template::SCHEMA, $config->schema);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $config->sceneid);
        $this->assertSame('escape-room', $config->environment);
        $this->assertSame('Locked in.', $config->story);
        $this->assertSame(1200, $config->timelimit);
        $this->assertEquals(0.25, $config->hintpenalty);
        $this->assertCount(3, $config->puzzles);

        [$desk, $safe, $hall] = $config->puzzles;
        $this->assertSame('Desk', $desk->title);
        $this->assertSame('<p>One</p>', $desk->clue);
        $this->assertSame((string)FORMAT_HTML, $desk->clueformat);
        $this->assertSame('image', $desk->cluemedia);
        $this->assertSame(1, $desk->fileitemid);
        $this->assertSame('key', $desk->locktype);
        $this->assertSame('Brass key', $desk->keyname);
        // The question of a non-question lock is not kept.
        $this->assertSame(0, $desk->questionbankentryid);
        $this->assertSame(['First', 'Second'], $desk->hints);
        $this->assertEquals(2, $desk->points);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $desk->id);

        // A posted id the stored config does not know is replaced.
        $this->assertNotSame('forged-id', $safe->id);
        $this->assertSame('none', $safe->cluemedia);
        $this->assertSame((string)FORMAT_PLAIN, $safe->clueformat);
        $this->assertSame('question', $safe->locktype);
        $this->assertSame(13, $safe->questionbankentryid);
        $this->assertSame(['Only'], $safe->hints);
        $this->assertEquals(0, $safe->points);
        $this->assertSame(2, $safe->fileitemid);

        $this->assertSame('Hall', $hall->title);
        $this->assertSame(3, $hall->fileitemid);
        $this->assertSame('model', $hall->cluemedia);
        $this->assertSame('none', $hall->locktype);
        $this->assertSame(get_string('keyname_default', 'vrroomtemplate_escape'), $hall->keyname);
        $this->assertSame([], $hall->hints);
        $this->assertEquals(0.5, $hall->points);

        // Unknown room and out-of-range time limit fall back.
        $formdata->escape_environment = 'nowhere';
        $formdata->escape_timelimit = 999999999;
        $config = (new template())->extract_config($formdata);
        $this->assertSame(template::DEFAULT_ENVIRONMENT, $config->environment);
        $this->assertSame(template::MAX_TIMELIMIT, $config->timelimit);
    }

    /**
     * Editing keeps the stored scene id and the puzzle ids the stored config knows.
     */
    public function test_extract_config_keeps_stored_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $stored = $this->make_config([['id' => 'keepme', 'title' => 'Old']]);
        $vrroomid = $DB->insert_record('vrroom', (object)[
            'course' => $course->id,
            'name' => 'Escape',
            'runtime' => 'builtin',
            'template' => 'escape',
            'templateconfig' => json_encode($stored),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $formdata = (object)[
            'instance' => $vrroomid,
            'escape_environment' => 'escape-room',
            'escape_puzzleid' => ['keepme', 'keepme'],
            'escape_title' => ['Renamed', 'Copy'],
            'escape_locktype' => ['none', 'none'],
        ];
        $config = (new template())->extract_config($formdata);

        $this->assertSame(self::SCENEID, $config->sceneid);
        $this->assertSame('keepme', $config->puzzles[0]->id);
        $this->assertSame('Renamed', $config->puzzles[0]->title);
        // The same id posted twice is kept once only.
        $this->assertNotSame('keepme', $config->puzzles[1]->id);
    }

    /**
     * Draft areas are saved per puzzle; files beyond the puzzle count and of the other exhibit go.
     */
    public function test_save_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->make_context();
        $fs = get_file_storage();

        $template = new template();
        $template->save_files((object)[
            'escape_title' => ['Note', '', 'Cube'],
            'escape_cluemedia' => ['image', 'none', 'model'],
            'escape_clueimage' => [$this->make_draft('note.png', 'png bytes'), 0, 0],
            'escape_cluemodel' => [0, 0, $this->make_draft('cube.glb', $this->cube())],
        ], $context);

        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 1));
        // The blank row is skipped, so the cube belongs to puzzle 2.
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'models', 2));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'models', 3));

        // Now one puzzle, text only: everything else is removed.
        $template->save_files((object)[
            'escape_title' => ['Note'],
            'escape_cluemedia' => ['none'],
        ], $context);
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'media'));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'models'));
    }

    /**
     * Valid form data (with real draft files and a real question) passes validation.
     */
    public function test_validation_valid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbeid = $this->create_question_entry($course);

        $data = [
            'escape_environment' => 'escape-room',
            'escape_timelimit' => 900,
            'escape_hintpenalty' => 0.5,
            'escape_title' => ['Desk', 'Safe', 'Picture', ''],
            'escape_clue' => array_fill(0, 4, ['text' => '', 'format' => FORMAT_HTML]),
            'escape_cluemedia' => ['none', 'model', 'image', 'none'],
            'escape_clueimage' => [0, 0, $this->make_draft('picture.png', 'png'), 0],
            'escape_cluemodel' => [0, $this->make_draft('cube.glb', $this->cube()), 0, 0],
            'escape_locktype' => ['key', 'question', 'none', 'key'],
            'escape_keyname' => ['Key', '', '', 'Key'],
            'escape_question' => [0, $qbeid, 0, 0],
            'escape_hint1' => ['', '', '', ''],
            'escape_hint2' => ['', '', '', ''],
            'escape_points' => [1, 0, 1, 1],
        ];
        $this->assertSame([], (new template())->validation($data, [], (int)$course->id));
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
        $component = 'vrroomtemplate_escape';

        // Unknown room.
        $errors = $template->validation(['escape_environment' => 'moon-base', 'escape_title' => ['A']], [], $courseid);
        $this->assertSame(get_string('required'), $errors['escape_environment']);

        // No puzzles at all.
        $errors = $template->validation(['escape_environment' => 'escape-room', 'escape_title' => ['', '']], [], $courseid);
        $this->assertSame(get_string('errornopuzzles', $component), $errors['escape_title[0]']);

        // Too many puzzles for the escape room (8 places); bad time limit and hint penalty.
        $titles = array_map(fn ($i) => "Puzzle {$i}", range(1, 9));
        $errors = $template->validation(['escape_environment' => 'escape-room', 'escape_title' => $titles,
            'escape_timelimit' => 604801, 'escape_hintpenalty' => -1], [], $courseid);
        $this->assertSame(get_string('errortoomanypuzzles', $component, 8), $errors['escape_environment']);
        $this->assertSame(get_string('errortimelimit', $component), $errors['escape_timelimit']);
        $this->assertSame(get_string('errorhintpenalty', $component), $errors['escape_hintpenalty']);

        // Per-puzzle errors.
        $data = [
            'escape_environment' => 'escape-room',
            'escape_title' => ['Image', 'Model', 'Points', 'No question', 'Dup', 'Unknown', '', 'First'],
            'escape_clue' => array_fill(0, 8, ['text' => '', 'format' => FORMAT_HTML]),
            'escape_cluemedia' => ['image', 'model', 'none', 'none', 'none', 'none', 'none', 'none'],
            'escape_clueimage' => array_fill(0, 8, 0),
            'escape_cluemodel' => array_fill(0, 8, 0),
            'escape_locktype' => ['none', 'none', 'none', 'question', 'question', 'question', 'none', 'question'],
            'escape_question' => [0, 0, 0, 0, $qbeid, 999999999, 0, $qbeid],
            'escape_hint1' => array_fill(0, 8, ''),
            'escape_hint2' => array_fill(0, 8, ''),
            'escape_points' => [1, 1, -2, 1, 1, 1, 1, 1],
        ];
        $data['escape_hint1'][6] = 'An orphan hint';
        $errors = $template->validation($data, [], $courseid);
        $this->assertSame(get_string('errorimagerequired', $component), $errors['escape_clueimage[0]']);
        $this->assertSame(get_string('errormodelrequired', $component), $errors['escape_cluemodel[1]']);
        $this->assertSame(get_string('errorpoints', $component), $errors['escape_points[2]']);
        $this->assertSame(get_string('errorquestionrequired', $component), $errors['escape_question[3]']);
        $this->assertSame(get_string('errorquestionnotfound', 'mod_vrroom'), $errors['escape_question[5]']);
        $this->assertSame(get_string('errortitlerequired', $component), $errors['escape_title[6]']);
        // The first use of a question is fine; the second is the duplicate.
        $this->assertArrayNotHasKey('escape_question[4]', $errors);
        $this->assertSame(get_string('errorquestionduplicate', 'mod_vrroom'), $errors['escape_question[7]']);
        $this->assertArrayNotHasKey('escape_environment', $errors);
    }

    /**
     * Numbers that cannot be stored (infinite, too large, too small to keep, an overflowing time
     * limit) and over-long hints are validation errors, never a failed save.
     */
    public function test_validation_number_ranges_and_hint_length(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int)$course->id;
        $template = new template();
        $component = 'vrroomtemplate_escape';
        $max = format_float(template::MAX_POINTS, 0);

        $data = [
            'escape_environment' => 'escape-room',
            'escape_title' => ['Inf', 'Big', 'Tiny', 'Max', 'Small', 'Zero', 'Hints'],
            'escape_clue' => array_fill(0, 7, ['text' => '', 'format' => FORMAT_HTML]),
            'escape_cluemedia' => array_fill(0, 7, 'none'),
            'escape_clueimage' => array_fill(0, 7, 0),
            'escape_cluemodel' => array_fill(0, 7, 0),
            'escape_locktype' => array_fill(0, 7, 'none'),
            'escape_question' => array_fill(0, 7, 0),
            'escape_hint1' => array_fill(0, 7, ''),
            'escape_hint2' => array_fill(0, 7, ''),
            'escape_points' => ['1e309', 100000, 0.00000001, 99999, 0.0000001, 0, 1],
            'escape_timelimit' => 1e30 * WEEKSECS,
            'escape_hintpenalty' => INF,
        ];
        $data['escape_hint1'][6] = str_repeat('a', template::MAX_HINT_LENGTH);
        $data['escape_hint2'][6] = str_repeat('b', template::MAX_HINT_LENGTH + 1);
        $errors = $template->validation($data, [], $courseid);

        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['escape_points[0]']);
        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['escape_points[1]']);
        $this->assertSame(get_string('errorpointsrange', $component, $max), $errors['escape_points[2]']);
        $this->assertArrayNotHasKey('escape_points[3]', $errors);
        $this->assertArrayNotHasKey('escape_points[4]', $errors);
        $this->assertArrayNotHasKey('escape_points[5]', $errors);
        $this->assertSame(get_string('errortimelimit', $component), $errors['escape_timelimit']);
        $this->assertSame(get_string('errorhintpenaltyrange', $component, $max), $errors['escape_hintpenalty']);
        $this->assertArrayNotHasKey('escape_hint1[6]', $errors);
        $this->assertSame(get_string('maximumchars', '', template::MAX_HINT_LENGTH), $errors['escape_hint2[6]']);

        // A huge penalty is out of range too; the largest time limit is fine.
        $data['escape_hintpenalty'] = 100000;
        $data['escape_timelimit'] = template::MAX_TIMELIMIT;
        $errors = $template->validation($data, [], $courseid);
        $this->assertSame(get_string('errorhintpenaltyrange', $component, $max), $errors['escape_hintpenalty']);
        $this->assertArrayNotHasKey('escape_timelimit', $errors);

        // Core's duration element has already turned "1e30 weeks" into 0 seconds: the typed number
        // is what shows the mistake.
        $data['escape_timelimit'] = 0;
        $_POST['escape_timelimit'] = ['number' => '1e30', 'timeunit' => (string)WEEKSECS, 'enabled' => '1'];
        try {
            $errors = $template->validation($data, [], $courseid);
        } finally {
            unset($_POST['escape_timelimit']);
        }
        $this->assertSame(get_string('errortimelimit', $component), $errors['escape_timelimit']);
        $_POST['escape_timelimit'] = ['number' => '7', 'timeunit' => (string)DAYSECS, 'enabled' => '1'];
        try {
            $data['escape_timelimit'] = 7 * DAYSECS;
            $errors = $template->validation($data, [], $courseid);
        } finally {
            unset($_POST['escape_timelimit']);
        }
        $this->assertArrayNotHasKey('escape_timelimit', $errors);
    }

    /**
     * Without the form's validation (a generator or a web service), numbers that cannot be stored
     * are clamped, so the config always encodes and an overflowing time limit is not "no limit".
     */
    public function test_extract_config_clamps_numbers(): void {
        $this->resetAfterTest();
        $formdata = (object)[
            'escape_environment' => 'escape-room',
            'escape_timelimit' => 1e30 * WEEKSECS,
            'escape_hintpenalty' => INF,
            'escape_puzzlecount' => 2,
            'escape_title' => ['One', 'Two'],
            'escape_clue' => array_fill(0, 2, ['text' => '', 'format' => FORMAT_HTML]),
            'escape_cluemedia' => ['none', 'none'],
            'escape_locktype' => ['none', 'none'],
            'escape_points' => [NAN, 1e12],
        ];

        $config = (new template())->extract_config($formdata);

        $this->assertSame(template::MAX_TIMELIMIT, $config->timelimit);
        // Not a number that can be stored at all: no penalty, as for any other bad value.
        $this->assertSame(0.0, $config->hintpenalty);
        $this->assertSame(0.0, $config->puzzles[0]->points);
        $this->assertSame(template::MAX_POINTS, $config->puzzles[1]->points);
        $this->assertIsString(json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * Restore remapping replaces the puzzles' question bank entry ids.
     */
    public function test_remap_question_entries(): void {
        $template = new template();
        $config = $this->chain_config();
        $map = fn (int $old): int => [77 => 177][$old] ?? $old;

        $this->assertTrue($template->remap_question_entries($config, $map));
        $this->assertSame(177, $config->puzzles[1]->questionbankentryid);
        $this->assertSame(0, $config->puzzles[0]->questionbankentryid);
        $this->assertFalse($template->remap_question_entries($config, $map));
        $this->assertFalse($template->remap_question_entries((object)['schema' => template::SCHEMA], $map));
    }

    /**
     * The form of an existing escape chain shows its fields, filled from the stored config.
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
            'template' => 'escape',
            'puzzletitles' => 'Desk|Safe',
            'puzzlelocks' => 'key|none',
        ]);
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        $html = $form->render();

        $this->assertStringContainsString('name="escape_environment"', $html);
        $this->assertStringContainsString('name="escape_title[0]"', $html);
        $this->assertStringContainsString('name="escape_title[2]"', $html);
        $this->assertStringContainsString('value="Desk"', $html);
        $this->assertStringContainsString('value="Safe"', $html);
        $this->assertStringContainsString('name="escape_addpuzzles"', $html);
        $this->assertStringNotContainsString('gallery_', $html);
        // Unused activity: the template can still be changed, and escape is the one selected.
        $this->assertMatchesRegularExpression('/<option[^>]*value="escape"[^>]*selected/', $html);

        // The stored lock types beat the repeat_elements() default (key): Safe keeps "no lock".
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $selected = $xpath->query('//select[@name="escape_locktype[1]"]/option[@selected]')->item(0);
        $this->assertNotNull($selected);
        $this->assertSame(template::LOCK_NONE, $selected->getAttribute('value'));
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
