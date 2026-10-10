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

namespace mod_vrroom\form;

use mod_vrroom\local\panorama;
use mod_vrroom\local\panorama_test_image;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/vrroom/tests/fixtures/panorama_test_image.php');

/**
 * Tests of the room editor's upload form, submitted the way core_form/modalform submits it.
 *
 * @package    mod_vrroom
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
        $module = $generator->create_module('vrroom', ['course' => $course->id]);
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
     * @param string|null $content file content; a .glb gets the real test cube by default
     * @return int draft item id
     */
    private static function draft_with(string $filename, ?string $content = null): int {
        global $CFG, $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content ?? (str_ends_with($filename, '.glb')
            ? file_get_contents($CFG->dirroot . '/mod/vrroom/tests/fixtures/cube.glb')
            : 'content of ' . $filename));
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
            '_qf__mod_vrroom_form_asset_upload' => 1,
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
            $this->assertStringContainsString("/mod_vrroom/assets/{$itemid}/{$filename}", $asset['url']);
            $this->assertTrue(get_file_storage()->file_exists(
                $fx->context->id,
                'mod_vrroom',
                'assets',
                $itemid,
                '/',
                $filename
            ));
        }
        $this->assertSame(4, $itemid);
    }

    /**
     * A file whose extension does not fit the kind is refused and not kept.
     */
    public function test_wrong_extension(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        // The file manager's accepted types refuse it at validation; the extension check in
        // process_dynamic_submission() is the second line and throws if validation is bypassed.
        try {
            $result = self::submit((int)$fx->module->cmid, 'model', self::draft_with('notes.txt'));
            $this->assertFalse($result['submitted'], 'A text file was stored as a model.');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroreditorinvalid', $e->errorcode);
        }
        $this->assertSame([], get_file_storage()->get_area_files($fx->context->id, 'mod_vrroom', 'assets', false, 'id', false));
    }

    /**
     * A text file renamed to .glb is refused and not kept.
     */
    public function test_fake_glb(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        $result = self::submit((int)$fx->module->cmid, 'model', self::draft_with('fake.glb', 'not a model'));
        $this->assertFalse($result['submitted']);
        $this->assertStringContainsString(
            get_string('erroreditorbadglb', 'mod_vrroom', 'fake.glb'),
            html_entity_decode($result['html'], ENT_QUOTES)
        );
        $this->assertSame([], get_file_storage()->get_area_files($fx->context->id, 'mod_vrroom', 'assets', false, 'id', false));
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

    /**
     * A 360° photo of the wrong shape is refused in the form, and the draft file is removed.
     */
    public function test_panorama_bad_ratio(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        $draftid = self::draft_with('square.jpg', panorama_test_image::make(1024, 1024));
        $result = self::submit((int)$fx->module->cmid, 'panorama', $draftid);
        $this->assertFalse($result['submitted']);
        $this->assertStringContainsString(
            get_string('panoramabadratio', 'mod_vrroom'),
            html_entity_decode($result['html'], ENT_QUOTES)
        );
        $this->assertSame([], get_file_storage()->get_area_files($fx->context->id, 'mod_vrroom', 'assets', false, 'id', false));
        $usercontext = \context_user::instance($fx->teacher->id);
        $this->assertSame([], get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false));
    }

    /**
     * A good 360° photo is stored as kind panorama, with its smaller copies beside it.
     */
    public function test_panorama_accepted_with_variants(): void {
        $fx = $this->setup_activity();
        set_config('panodesktopwidth', 1536, 'mod_vrroom');
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $this->setUser($fx->teacher);
        $draft = self::draft_with('yard.jpg', panorama_test_image::make(2048, 1024));
        $result = self::submit((int)$fx->module->cmid, 'panorama', $draft);
        $this->assertTrue($result['submitted']);
        $asset = json_decode($result['data'], true);
        $this->assertSame('panorama', $asset['kind']);
        $this->assertTrue($asset['panorama']);
        $this->assertSame('assets', $asset['area']);
        $this->assertSame('yard.jpg', $asset['filename']);

        $fs = get_file_storage();
        $file = $fs->get_file($fx->context->id, 'mod_vrroom', 'assets', $asset['itemid'], '/', 'yard.jpg');
        $variants = panorama::find_variants($file);
        $this->assertSame(1536, $variants['desktop']->get_imageinfo()['width']);
        $this->assertSame(1024, $variants['mobile']->get_imageinfo()['width']);

        // The editor's list shows the photo once, as a 360° image, and not its copies.
        $assets = \mod_vrroom\local\editor\editor_manager::list_assets($fx->context);
        $this->assertSame(['yard.jpg'], array_column($assets, 'filename'));
        $this->assertTrue($assets[0]['panorama']);
    }

    /**
     * A 360° photo must be a jpg, png or webp file; a gif is refused.
     */
    public function test_panorama_wrong_extension(): void {
        $fx = $this->setup_activity();
        $this->setUser($fx->teacher);
        try {
            $result = self::submit((int)$fx->module->cmid, 'panorama', self::draft_with('anim.gif'));
            $this->assertFalse($result['submitted']);
        } catch (\moodle_exception $e) {
            $this->assertSame('erroreditorinvalid', $e->errorcode);
        }
        $this->assertSame([], get_file_storage()->get_area_files($fx->context->id, 'mod_vrroom', 'assets', false, 'id', false));
    }
}
