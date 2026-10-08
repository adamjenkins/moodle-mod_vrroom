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

namespace mod_mavr\form;

/**
 * Tests of the room editor's upload form, submitted the way core_form/modalform submits it.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(asset_upload::class)]
final class asset_upload_test extends \advanced_testcase {

    /**
     * A course with a gallery, an editing teacher and a student.
     *
     * @return \stdClass
     */
    private function setup_activity(): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', ['course' => $course->id]);
        return (object)[
            'module' => $module,
            'context' => \context_module::instance($module->cmid),
            'teacher' => $generator->create_and_enrol($course, 'editingteacher'),
            'student' => $generator->create_and_enrol($course, 'student'),
        ];
    }

    /**
     * Put a file in a new draft area of the current user.
     *
     * @param string $filename file name
     * @return int draft item id
     */
    private static function draft_with(string $filename): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'content of ' . $filename);
        return $draftid;
    }

    /**
     * Submit the form through the dynamic form web service.
     *
     * @param int $cmid course module id
     * @param string $kind kind of file
     * @param int $draftid draft item id
     * @return array web service result
     */
    private static function submit(int $cmid, string $kind, int $draftid): array {
        // Sesskey confirmation reads the real $_POST, as the AJAX request carries it.
        $_POST['sesskey'] = sesskey();
        $formdata = http_build_query([
            'cmid' => $cmid,
            'kind' => $kind,
            'assetfile' => $draftid,
            'sesskey' => sesskey(),
            '_qf__mod_mavr_form_asset_upload' => 1,
        ], '', '&');
        return \core_form\external\dynamic_form::execute(asset_upload::class, $formdata);
    }

    /**
     * Each kind of file is stored under a new item id of the assets area.
     */
    public function test_upload_each_kind(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        $files = ['model' => 'skull.glb', 'image' => 'map.png', 'audio' => 'tape.mp3', 'video' => 'film.mp4'];
        $itemid = 0;
        foreach ($files as $kind => $filename) {
            $result = self::submit((int)$fx->module->cmid, $kind, self::draft_with($filename));
            $this->assertTrue($result['submitted'], $kind);
            $asset = json_decode($result['data'], true);
            $this->assertSame('assets', $asset['area']);
            $this->assertSame($kind, $asset['kind']);
            $this->assertSame($filename, $asset['filename']);
            $this->assertSame('/', $asset['filepath']);
            $this->assertGreaterThan($itemid, $asset['itemid']);
            $itemid = $asset['itemid'];
            $this->assertStringContainsString("/mod_mavr/assets/{$itemid}/{$filename}", $asset['url']);
            $this->assertTrue(get_file_storage()->file_exists($fx->context->id, 'mod_mavr', 'assets', $itemid, '/',
                $filename));
        }
        $this->assertSame(4, $itemid);
    }

    /**
     * A file whose extension does not fit the kind is refused and not kept.
     */
    public function test_wrong_extension(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        try {
            self::submit((int)$fx->module->cmid, 'model', self::draft_with('notes.txt'));
            $this->fail('A text file was stored as a model.');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroreditorinvalid', $e->errorcode);
        }
        $this->assertSame([], get_file_storage()->get_area_files($fx->context->id, 'mod_mavr', 'assets', false, 'id', false));
    }

    /**
     * A student cannot upload.
     */
    public function test_capability(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->student);
        $this->expectException(\required_capability_exception::class);
        self::submit((int)$fx->module->cmid, 'image', self::draft_with('map.png'));
    }

    /**
     * A course module of another type is refused.
     */
    public function test_other_module(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $fx->module->course]);
        $this->expectException(\moodle_exception::class);
        self::submit((int)$page->cmid, 'image', self::draft_with('map.png'));
    }
}
