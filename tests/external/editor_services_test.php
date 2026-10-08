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

namespace mod_mavr\external;

use core_external\external_api;

/**
 * Tests of mod_mavr_editor_load and mod_mavr_editor_save.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(editor_load::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(editor_save::class)]
final class editor_services_test extends \advanced_testcase {
    /**
     * A course with a gallery, a student, a non-editing teacher, an editing teacher and an outsider.
     *
     * @return \stdClass
     */
    private function setup_activity(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', ['course' => $course->id, 'stationtitles' => 'Vase|Portrait']);
        return (object)[
            'course' => $course,
            'module' => $module,
            'student' => $generator->create_and_enrol($course, 'student'),
            'teacher' => $generator->create_and_enrol($course, 'teacher'),
            'editor' => $generator->create_and_enrol($course, 'editingteacher'),
            'outsider' => $generator->create_user(),
        ];
    }

    /**
     * The document to send back, built from a load result.
     *
     * @param array $loaded editor_load result
     * @return string
     */
    private static function payload(array $loaded): string {
        $document = json_decode($loaded['documentjson'], true);
        return json_encode([
            'schema' => $document['schema'],
            'revision' => $document['revision'],
            'scenes' => $document['scenes'],
            'graph' => $document['graph'],
            'bindings' => $document['bindings'],
        ]);
    }

    /**
     * Load as the editing teacher.
     *
     * @param \stdClass $fx fixture
     * @return array cleaned result
     */
    private function load_as_editor(\stdClass $fx): array {
        $this->setUser($fx->editor);
        return external_api::clean_returnvalue(editor_load::execute_returns(), editor_load::execute((int)$fx->module->cmid));
    }

    /**
     * An editing teacher loads and saves; the responses have the declared shapes.
     */
    public function test_editing_teacher(): void {
        $fx = $this->setup_activity();
        $loaded = $this->load_as_editor($fx);
        foreach (['documentjson', 'assetsjson', 'presetsjson', 'questionsjson', 'infojson'] as $key) {
            $this->assertIsArray(json_decode($loaded[$key], true), $key);
        }
        $this->assertSame('mavr-editdoc/1', json_decode($loaded['documentjson'], true)['schema']);

        $result = external_api::clean_returnvalue(
            editor_save::execute_returns(),
            editor_save::execute((int)$fx->module->cmid, self::payload($loaded))
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $result['revision']);
        $this->assertSame(1, $result['graphversion']);
        $this->assertFalse($result['scenesupdated']);
        $this->assertFalse($result['graphupdated']);
        $this->assertGreaterThan(0, $result['customised']);
    }

    /**
     * Students, non-editing teachers and users outside the course are refused, for both services.
     */
    public function test_refused_users(): void {
        $fx = $this->setup_activity();
        $payload = self::payload($this->load_as_editor($fx));

        foreach (['student', 'teacher', 'outsider'] as $who) {
            $this->setUser($fx->$who);
            try {
                editor_load::execute((int)$fx->module->cmid);
                $this->fail("{$who} could load the editor.");
            } catch (\required_capability_exception | \require_login_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            try {
                editor_save::execute((int)$fx->module->cmid, $payload);
                $this->fail("{$who} could save the editor.");
            } catch (\required_capability_exception | \require_login_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * A course module that does not exist, or is not a MAVR activity, is refused.
     */
    public function test_unknown_cmid(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->editor);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $fx->course->id]);
        foreach ([(int)$page->cmid, 999999] as $cmid) {
            try {
                editor_load::execute($cmid);
                $this->fail("Course module {$cmid} was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertInstanceOf(\moodle_exception::class, $e);
            }
        }
    }

    /**
     * A save with a broken document stores nothing.
     */
    public function test_invalid_document(): void {
        global $DB;
        $fx = $this->setup_activity();
        $loaded = $this->load_as_editor($fx);
        $before = $DB->get_records('mavr_scenes', ['mavrid' => $fx->module->id]);
        $document = json_decode(self::payload($loaded), true);
        $document['scenes'][0]['spawn'] = [];

        try {
            editor_save::execute((int)$fx->module->cmid, json_encode($document));
            $this->fail('A room without a start point was saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroreditorinvalid', $e->errorcode);
        }
        $this->assertEquals($before, $DB->get_records('mavr_scenes', ['mavrid' => $fx->module->id]));
        $this->assertEquals(0, $DB->get_field('mavr', 'customised', ['id' => $fx->module->id]));
    }
}
