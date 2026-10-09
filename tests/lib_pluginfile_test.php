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

namespace mod_vrroom;

use mod_vrroom\local\file_serving;

/**
 * Tests for vrroom_pluginfile(): the file area allowlist and the forced-download rule.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_pluginfile')]
#[\PHPUnit\Framework\Attributes\CoversClass(file_serving::class)]
final class lib_pluginfile_test extends \advanced_testcase {
    /**
     * Load lib.php.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/vrroom/lib.php');
    }

    /**
     * Only the activity's own file areas are served.
     */
    public function test_is_served_filearea(): void {
        foreach (['models', 'media', 'environment', 'assets'] as $filearea) {
            $this->assertTrue(file_serving::is_served_filearea($filearea), $filearea);
        }
        foreach (['intro', 'content', 'backup', 'MODELS', '', 'assets/'] as $filearea) {
            $this->assertFalse(file_serving::is_served_filearea($filearea), $filearea);
        }
    }

    /**
     * Raster images open inline; active content and everything else is sent as a download.
     */
    public function test_force_download(): void {
        foreach (['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as $mimetype) {
            $this->assertFalse(file_serving::force_download($mimetype, false), $mimetype);
            $this->assertTrue(file_serving::force_download($mimetype, true), $mimetype . ' with forcedownload');
        }
        $forced = ['image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/javascript',
            'model/gltf-binary', 'application/octet-stream', 'audio/mpeg', 'video/mp4', 'IMAGE/PNG', ''];
        foreach ($forced as $mimetype) {
            $this->assertTrue(file_serving::force_download($mimetype, false), $mimetype);
        }
    }

    /**
     * A file area outside the allowlist is refused before any file is looked up.
     */
    public function test_pluginfile_refuses_unknown_filearea(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);

        // A file stored in an area the callback does not serve, under the activity's own context.
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_vrroom',
            'filearea' => 'secret',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'page.html',
        ], '<script>alert(1)</script>');

        $this->assertFalse(vrroom_pluginfile($course, $cm, $context, 'secret', [0, 'page.html'], false));
        $this->assertFalse(vrroom_pluginfile($course, $cm, $context, 'intro', [0, 'page.html'], false));
    }

    /**
     * A context that is not the activity's module context is refused.
     */
    public function test_pluginfile_refuses_non_module_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');

        $this->assertFalse(vrroom_pluginfile($course, $cm, \context_course::instance($course->id), 'models', [0, 'a.glb'], false));
    }

    /**
     * A served area with no such file returns false rather than sending anything.
     */
    public function test_pluginfile_missing_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);

        $this->assertFalse(vrroom_pluginfile($course, $cm, $context, 'models', [0, 'nothing.glb'], false));
        $this->assertFalse(vrroom_pluginfile($course, $cm, $context, 'models', [0], false));
    }
}
