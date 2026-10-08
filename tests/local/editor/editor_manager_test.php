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

namespace mod_vrroom\local\editor;

use mod_vrroom\local\instance_manager;
use mod_vrroom\local\questions\slots;

/**
 * Tests for the room editor's document: load, revision, validation and save.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(editor_manager::class)]
final class editor_manager_test extends \advanced_testcase {
    /**
     * A course with a two-station gallery, as the admin.
     *
     * @return \stdClass course, cm (cm_info), context, vrroomid
     */
    private function setup_activity(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', ['course' => $course->id, 'stationtitles' => 'Vase|Portrait']);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        return (object)[
            'course' => $course,
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'vrroomid' => (int)$module->id,
        ];
    }

    /**
     * The stored vrroom row.
     *
     * @param \stdClass $fx fixture
     * @return \stdClass
     */
    private function vrroom(\stdClass $fx): \stdClass {
        global $DB;
        return $DB->get_record('vrroom', ['id' => $fx->vrroomid], '*', MUST_EXIST);
    }

    /**
     * The editor document as the editor receives it, decoded to arrays.
     *
     * @param \stdClass $fx fixture
     * @return array
     */
    private function load_document(\stdClass $fx): array {
        $loaded = editor_manager::load($fx->cm, $fx->context, $this->vrroom($fx));
        return json_decode($loaded['documentjson'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Save a document as the editor would send it.
     *
     * @param \stdClass $fx fixture
     * @param array $document document
     * @return array save() result
     */
    private function save_document(\stdClass $fx, array $document): array {
        $payload = [
            'schema' => $document['schema'],
            'revision' => $document['revision'],
            'scenes' => $document['scenes'],
            'graph' => $document['graph'],
            'bindings' => $document['bindings'],
        ];
        return editor_manager::save($fx->cm, $fx->context, $this->vrroom($fx), json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Assert that saving a document fails with an error code.
     *
     * @param \stdClass $fx fixture
     * @param array $document document
     * @param string $errorcode expected mod_vrroom error code
     * @param string|null $contains text the message must contain
     */
    private function assert_save_fails(\stdClass $fx, array $document, string $errorcode, ?string $contains = null): void {
        try {
            $this->save_document($fx, $document);
            $this->fail("Saving did not fail with {$errorcode}.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $e->getMessage());
            if ($contains !== null) {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    /**
     * Store a file in an area of the activity.
     *
     * @param \context $context context
     * @param string $area file area
     * @param int $itemid item id
     * @param string $filename file name
     * @param string $component component
     * @return \stored_file
     */
    private static function store_file(
        \context $context,
        string $area,
        int $itemid,
        string $filename,
        string $component = 'mod_vrroom'
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => $component,
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'content of ' . $filename);
    }

    /**
     * A second room with one start point.
     *
     * @param array $first the first room (its environment is reused)
     * @param string $id room id
     * @param string $spawnid start point id
     * @return array
     */
    private static function second_room(array $first, string $id, string $spawnid): array {
        return [
            'schema' => 'vrroom-scene/1',
            'id' => $id,
            'name' => 'Cellar',
            'environment' => $first['environment'],
            'spawn' => [['id' => $spawnid, 'position' => [0, 0, 0], 'yaw' => 0]],
            'zones' => [],
            'props' => [],
            'interactables' => [],
        ];
    }

    /**
     * load() returns the five documents with the stored rooms, tasks and limits.
     */
    public function test_load_shape_and_revision(): void {
        $fx = $this->setup_activity();
        $vrroom = $this->vrroom($fx);
        $loaded = editor_manager::load($fx->cm, $fx->context, $vrroom);
        $this->assertSame(['documentjson', 'assetsjson', 'presetsjson', 'questionsjson', 'infojson'], array_keys($loaded));

        $document = json_decode($loaded['documentjson'], true);
        $this->assertSame(editor_manager::DOC_SCHEMA, $document['schema']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $document['revision']);
        $this->assertSame(editor_manager::revision($vrroom, $fx->context), $document['revision']);
        $this->assertSame('gallery', $document['template']);
        $this->assertSame(0, $document['customised']);
        $this->assertFalse($document['hasattempts']);
        $this->assertCount(1, $document['scenes']);
        $this->assertSame(instance_manager::get_scenes($fx->vrroomid), $document['scenes']);
        $this->assertSame(instance_manager::get_graph($fx->vrroomid), $document['graph']);
        $this->assertSame([], $document['bindings']);

        $this->assertSame([], json_decode($loaded['assetsjson'], true));
        $presets = json_decode($loaded['presetsjson'], true);
        $this->assertArrayHasKey('gallery-hall', $presets);
        $this->assertSame(get_string('preset_galleryhall', 'mod_vrroom'), $presets['gallery-hall']['name']);
        $this->assertIsArray(json_decode($loaded['questionsjson'], true));

        $info = json_decode($loaded['infojson'], true);
        $this->assertSame((int)$fx->cm->id, $info['cmid']);
        $this->assertSame((int)$fx->course->id, $info['courseid']);
        $this->assertSame((int)$fx->context->id, $info['contextid']);
        $this->assertSame(editor_manager::MAX_DOCUMENT_BYTES, $info['maxdocumentbytes']);
        $this->assertSame(20, $info['maxscenes']);
        $this->assertSame(20 * 1048576, $info['maxbytes']);
    }

    /**
     * Saving the unchanged document changes nothing but marks the room customised.
     */
    public function test_save_unchanged_round_trip(): void {
        global $DB;
        $fx = $this->setup_activity();
        $before = $this->vrroom($fx);
        $document = $this->load_document($fx);

        $sink = $this->redirectEvents();
        $result = $this->save_document($fx, $document);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($result['scenesupdated']);
        $this->assertFalse($result['graphupdated']);
        $this->assertSame((int)$before->graphversion, $result['graphversion']);
        $this->assertGreaterThan(0, $result['customised']);
        $this->assertSame(editor_manager::revision($this->vrroom($fx), $fx->context), $result['revision']);
        $this->assertSame($result['customised'], (int)$DB->get_field('vrroom', 'customised', ['id' => $fx->vrroomid]));
        $this->assertSame(1, $DB->count_records('vrroom_graphs', ['vrroomid' => $fx->vrroomid]));
        foreach ($events as $event) {
            $this->assertNotInstanceOf(\mod_vrroom\event\scene_updated::class, $event);
            $this->assertNotInstanceOf(\mod_vrroom\event\graph_updated::class, $event);
        }
    }

    /**
     * A moved zone is stored and fires scene_updated only; the customised time is kept on later saves.
     */
    public function test_save_moved_zone(): void {
        global $DB;
        $fx = $this->setup_activity();
        $document = $this->load_document($fx);
        $zoneid = $document['scenes'][0]['zones'][0]['id'];
        $document['scenes'][0]['zones'][0]['center'] = [2.5, 1.5, 9.0];

        $sink = $this->redirectEvents();
        $result = $this->save_document($fx, $document);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['scenesupdated']);
        $this->assertFalse($result['graphupdated']);
        $scenes = instance_manager::get_scenes($fx->vrroomid);
        $this->assertSame($zoneid, $scenes[0]['zones'][0]['id']);
        $this->assertEquals([2.5, 1.5, 9.0], $scenes[0]['zones'][0]['center']);

        $sceneevents = array_values(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\scene_updated));
        $graphevents = array_values(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\graph_updated));
        $this->assertCount(1, $sceneevents);
        $this->assertCount(0, $graphevents);
        $this->assertSame($fx->vrroomid, (int)$sceneevents[0]->objectid);
        $this->assertSame(1, $sceneevents[0]->other['scenecount']);

        // The first save's time stays.
        $DB->set_field('vrroom', 'customised', 12345, ['id' => $fx->vrroomid]);
        $document = $this->load_document($fx);
        $document['scenes'][0]['zones'][0]['center'] = [3.0, 1.5, 9.0];
        $result = $this->save_document($fx, $document);
        $this->assertSame(12345, $result['customised']);
        $this->assertEquals(12345, $DB->get_field('vrroom', 'customised', ['id' => $fx->vrroomid]));
    }

    /**
     * A changed task makes a new graph version and fires graph_updated with that version's row.
     */
    public function test_graph_change_makes_new_version(): void {
        global $DB;
        $fx = $this->setup_activity();
        $document = $this->load_document($fx);
        $document['graph']['objectives'][0]['title'] = 'Find the vase';

        $sink = $this->redirectEvents();
        $result = $this->save_document($fx, $document);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['graphupdated']);
        $this->assertSame(2, $result['graphversion']);
        $this->assertSame('Find the vase', instance_manager::get_graph($fx->vrroomid)['objectives'][0]['title']);
        $graphevents = array_values(array_filter($events, fn($e) => $e instanceof \mod_vrroom\event\graph_updated));
        $this->assertCount(1, $graphevents);
        $graphid = (int)$DB->get_field('vrroom_graphs', 'id', ['vrroomid' => $fx->vrroomid, 'version' => 2]);
        $this->assertSame($graphid, (int)$graphevents[0]->objectid);
        $this->assertSame(2, $graphevents[0]->other['version']);
        $this->assertSame($fx->vrroomid, $graphevents[0]->other['vrroomid']);

        // The same graph again, with whole numbers as a browser sends them: no new version.
        $document = $this->load_document($fx);
        foreach ($document['graph']['objectives'] as $i => $objective) {
            $document['graph']['objectives'][$i]['points'] = (int)$objective['points'];
        }
        $result = $this->save_document($fx, $document);
        $this->assertFalse($result['graphupdated']);
        $this->assertSame(2, $result['graphversion']);
    }

    /**
     * A save over someone else's save is refused.
     */
    public function test_conflict(): void {
        $fx = $this->setup_activity();
        $document = $this->load_document($fx);
        $stale = $document;
        $document['scenes'][0]['zones'][0]['center'] = [1.0, 1.5, 9.0];
        $this->save_document($fx, $document);

        $stale['scenes'][0]['zones'][0]['center'] = [-1.0, 1.5, 9.0];
        $this->assert_save_fails($fx, $stale, 'erroreditorconflict');
        $this->assertEquals([1.0, 1.5, 9.0], instance_manager::get_scenes($fx->vrroomid)[0]['zones'][0]['center']);
    }

    /**
     * Oversized documents, broken JSON and wrong schemas are refused.
     */
    public function test_too_large_and_bad_json(): void {
        $fx = $this->setup_activity();
        $vrroom = $this->vrroom($fx);

        try {
            editor_manager::save($fx->cm, $fx->context, $vrroom, str_repeat(' ', editor_manager::MAX_DOCUMENT_BYTES + 1));
            $this->fail('An oversized document was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroreditortoolarge', $e->errorcode);
        }
        foreach (['not json', '[]', '{"schema": "vrroom-editdoc/2"}', '"text"'] as $json) {
            try {
                editor_manager::save($fx->cm, $fx->context, $vrroom, $json);
                $this->fail("The document {$json} was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame('erroreditorinvalid', $e->errorcode);
            }
        }
    }

    /**
     * Invalid rooms, rooms that clash and portals to nowhere are refused with the validator's message.
     */
    public function test_invalid_rooms(): void {
        $fx = $this->setup_activity();
        $document = $this->load_document($fx);
        $first = $document['scenes'][0];

        $broken = $document;
        $broken['scenes'][0]['interactables'][0]['kind'] = 'teleporter';
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'kind');

        $broken = $document;
        $broken['scenes'] = [];
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'between 1 and 20 rooms');

        $broken = $document;
        $second = self::second_room($first, '1f5e7c2a-9b3d-4e8f-a1c6-5d2e0b7f3a99', 's-cellar');
        $second['zones'][] = $first['zones'][0];
        $broken['scenes'][] = $second;
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'is used in more than one room');

        $broken = $document;
        $broken['scenes'][0]['interactables'][] = ['id' => 'pt-cellar', 'kind' => 'portal', 'name' => 'Door',
            'position' => [0, 0, 0], 'target' => ['scene' => '1f5e7c2a-9b3d-4e8f-a1c6-5d2e0b7f3a99', 'spawn' => 's-cellar']];
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'does not name a start point');

        // With the room it leads to, the portal is fine.
        $broken['scenes'][] = self::second_room($first, '1f5e7c2a-9b3d-4e8f-a1c6-5d2e0b7f3a99', 's-cellar');
        $result = $this->save_document($fx, $broken);
        $this->assertTrue($result['scenesupdated']);
        $this->assertCount(2, instance_manager::get_scenes($fx->vrroomid));

        $document = $this->load_document($fx);
        $document['scenes'][0]['interactables'][0]['content']['textformat'] = '9';
        $this->assert_save_fails($fx, $document, 'erroreditorinvalid', 'textformat');
    }

    /**
     * File references must name files of this activity; unused editor uploads are deleted on save.
     */
    public function test_file_references_and_pruning(): void {
        $fx = $this->setup_activity();
        $document = $this->load_document($fx);
        $model = ['id' => 'm-skull', 'kind' => 'model', 'name' => 'Skull', 'position' => [1, 0, 1],
            'file' => ['area' => 'assets', 'itemid' => 1, 'filepath' => '/', 'filename' => 'skull.glb']];

        // Missing file.
        $broken = $document;
        $broken['scenes'][0]['interactables'][] = $model;
        $this->assert_save_fails($fx, $broken, 'erroreditorasset', 'skull.glb');

        // A file of another activity does not count.
        $course2 = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_module('vrroom', ['course' => $course2->id]);
        self::store_file(\context_module::instance($other->cmid), 'assets', 1, 'skull.glb');
        $this->assert_save_fails($fx, $broken, 'erroreditorasset');

        // A runtime URL is stripped, and with the file in place the save goes through.
        self::store_file($fx->context, 'assets', 1, 'skull.glb');
        self::store_file($fx->context, 'assets', 2, 'unused.png');
        $this->assertSame(3, editor_manager::next_asset_itemid($fx->context));
        $broken['scenes'][0]['interactables'][count($broken['scenes'][0]['interactables']) - 1]['file']['url'] =
            'https://example.com/evil.glb';
        $this->save_document($fx, $broken);

        $stored = instance_manager::get_scenes($fx->vrroomid)[0]['interactables'];
        $skull = array_values(array_filter($stored, fn($i) => $i['id'] === 'm-skull'))[0];
        $this->assertArrayNotHasKey('url', $skull['file']);

        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($fx->context->id, 'mod_vrroom', 'assets', 1, '/', 'skull.glb'));
        $this->assertFalse($fs->file_exists($fx->context->id, 'mod_vrroom', 'assets', 2, '/', 'unused.png'));
        $this->assertSame(2, editor_manager::next_asset_itemid($fx->context));

        $assets = editor_manager::list_assets($fx->context);
        $this->assertCount(1, $assets);
        $this->assertSame('model', $assets[0]['kind']);
        $this->assertSame('skull.glb', $assets[0]['filename']);
        $this->assertStringContainsString('/mod_vrroom/assets/1/skull.glb', $assets[0]['url']);
    }

    /**
     * Bindings are checked against the question bank, may not repeat a question, and take their
     * objective from the graph.
     */
    public function test_bindings(): void {
        global $DB;
        $fx = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $qbank = $generator->create_module('qbank', ['course' => $fx->course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $q1 = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        $q2 = $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $document = $this->load_document($fx);
        $document['graph']['objectives'][] = ['id' => 'q-safe', 'type' => 'answer_question', 'title' => 'Safe',
            'slot' => 1, 'points' => 2, 'required' => false];
        $document['graph']['objectives'][] = ['id' => 'q-box', 'type' => 'answer_question', 'title' => 'Box',
            'slot' => 2, 'points' => 1, 'required' => false];

        // No binding for the slots.
        $this->assert_save_fails($fx, $document, 'erroreditorinvalid', 'question slot 1 has no question');

        // An entry that does not exist.
        $broken = $document;
        $broken['bindings'] = [
            ['slot' => 1, 'questionbankentryid' => 999999, 'maxmark' => 2],
            ['slot' => 2, 'questionbankentryid' => (int)$q2->questionbankentryid, 'maxmark' => 1],
        ];
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', get_string('errorquestionnotfound', 'mod_vrroom'));

        // The same question twice.
        $broken['bindings'][0]['questionbankentryid'] = (int)$q2->questionbankentryid;
        $this->assert_save_fails($fx, $broken, 'errorquestionduplicate');

        // Bad shapes.
        $broken['bindings'][0] = ['slot' => 1, 'questionbankentryid' => (int)$q1->questionbankentryid, 'maxmark' => 0];
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'maxmark');
        $broken['bindings'][0] = ['slot' => '1', 'questionbankentryid' => (int)$q1->questionbankentryid, 'maxmark' => 2];
        $this->assert_save_fails($fx, $broken, 'erroreditorinvalid', 'slot');

        // A good set: the client's objective id is ignored.
        $document['bindings'] = [
            ['slot' => 1, 'questionbankentryid' => (int)$q1->questionbankentryid, 'maxmark' => 2, 'objectiveid' => 'wrong'],
            ['slot' => 2, 'questionbankentryid' => (int)$q2->questionbankentryid, 'maxmark' => 1],
        ];
        $result = $this->save_document($fx, $document);
        $this->assertTrue($result['graphupdated']);
        $slots = slots::get_slots($fx->vrroomid, $fx->context);
        $this->assertSame('q-safe', $slots[1]->objectiveid);
        $this->assertSame((int)$q1->questionbankentryid, $slots[1]->questionbankentryid);
        $this->assertEquals(2.0, $slots[1]->maxmark);
        $this->assertSame('q-box', $slots[2]->objectiveid);
        $this->assertSame(2, $DB->count_records('question_references', ['usingcontextid' => $fx->context->id,
            'component' => 'mod_vrroom', 'questionarea' => 'slot']));

        // The loaded bindings show what is stored.
        $loaded = $this->load_document($fx);
        $this->assertEquals([
            ['slot' => 1, 'questionbankentryid' => (int)$q1->questionbankentryid, 'maxmark' => 2.0],
            ['slot' => 2, 'questionbankentryid' => (int)$q2->questionbankentryid, 'maxmark' => 1.0],
        ], $loaded['bindings']);
    }

    /**
     * The cross-check names everything the tasks refer to that the rooms do not hold.
     */
    public function test_cross_check(): void {
        $scene = [
            'id' => 'room-1',
            'spawn' => [['id' => 's-1']],
            'zones' => [['id' => 'z-1']],
            'props' => [['id' => 'p-1']],
            'interactables' => [
                ['id' => 'h-1', 'kind' => 'hotspot', 'content' => ['question' => 3]],
                ['id' => 'a-1', 'kind' => 'media'],
                ['id' => 'g-1', 'kind' => 'grabbable'],
            ],
        ];
        $graph = [
            'schema' => 'vrroom-graph/1',
            'hidden' => ['p-1', 'nothing'],
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'zone' => 'z-9'],
                ['id' => 'v-2', 'type' => 'visit_zone', 'zone' => 'z-1', 'scene' => 'room-2'],
                ['id' => 'x-1', 'type' => 'interact', 'target' => 'g-1'],
                ['id' => 'x-2', 'type' => 'interact', 'target' => 'g-9'],
                ['id' => 'c-1', 'type' => 'collect', 'targets' => ['g-1', 'k-9']],
                ['id' => 'q-1', 'type' => 'answer_question', 'slot' => 1],
            ],
            'rules' => [
                ['id' => 'r-1', 'on' => ['on' => 'start'], 'actions' => [
                    ['do' => 'show', 'target' => 'g-1'],
                    ['do' => 'hide', 'target' => 'gone'],
                    ['do' => 'play_media', 'target' => 'g-1'],
                    ['do' => 'teleport', 'target' => 's-9'],
                ]],
                ['id' => 'r-2', 'on' => ['on' => 'event', 'verb' => 'entered', 'type' => 'zone', 'id' => 'z-8'],
                    'actions' => [['do' => 'finish']]],
                ['id' => 'r-3', 'on' => ['on' => 'event', 'type' => 'scene', 'id' => 'room-7'],
                    'actions' => [['do' => 'finish']]],
            ],
        ];
        $errors = editor_manager::cross_check([$scene], $graph, [['slot' => 2]]);
        $this->assertSame([
            'objectives[0].zone "z-9" is not a zone of any room',
            'objectives[1].zone "z-1" is not a zone of room "room-2"',
            'objectives[3].target "g-9" is not an object in any room',
            'objectives[4].targets[1] "k-9" is not an object in any room',
            'hidden[1] "nothing" is not an object or block in any room',
            'rules[0].actions[1].target "gone" is not an object or block in any room',
            'rules[0].actions[2].target "g-1" is not an audio or video object',
            'rules[0].actions[3].target "s-9" is not a start point of any room',
            'rules[1].on.id "z-8" is not a zone of any room',
            'rules[2].on.id "room-7" is not a room of this activity',
            'question slot 1 has no question',
            'question slot 2 is bound but no objective answers it',
            'scenes[0].interactables[0].content.question names slot 3, which no objective answers',
        ], $errors);

        $graph = ['schema' => 'vrroom-graph/1', 'hidden' => ['g-1'], 'objectives' => [
            ['id' => 'v-1', 'type' => 'visit_zone', 'zone' => 'z-1', 'scene' => 'room-1'],
            ['id' => 'q-3', 'type' => 'answer_question', 'slot' => 3],
        ], 'rules' => [['id' => 'r-1', 'on' => ['on' => 'start'], 'actions' => [['do' => 'play_media', 'target' => 'a-1']]]]];
        $this->assertSame([], editor_manager::cross_check([$scene], $graph, [['slot' => 3]]));
    }

    /**
     * Asset kinds come from the file extension.
     */
    public function test_asset_kind(): void {
        $this->assertSame('model', editor_manager::asset_kind('Skull.GLB'));
        $this->assertSame('image', editor_manager::asset_kind('a.webp'));
        $this->assertSame('audio', editor_manager::asset_kind('tape.m4a'));
        $this->assertSame('video', editor_manager::asset_kind('film.webm'));
        $this->assertSame('other', editor_manager::asset_kind('notes.txt'));
    }
}
