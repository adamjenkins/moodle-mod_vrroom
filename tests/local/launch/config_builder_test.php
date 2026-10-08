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

namespace mod_vrroom\local\launch;

use mod_vrroom\local\scene\environment_registry;

/**
 * Tests for the launch config's handling of rooms from room packs.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(config_builder::class)]
final class config_builder_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        environment_registry::phpunit_set_packs(['testpack' => $CFG->dirroot . '/mod/vrroom/tests/fixtures/envpack']);
    }

    protected function tearDown(): void {
        environment_registry::phpunit_set_packs(null);
        parent::tearDown();
    }

    /**
     * A gallery in fixture room a, and its teacher-preview launch config.
     *
     * @return array [launch config, \stdClass vrroom row, \cm_info, \context_module]
     */
    private function preview_config(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'stationtitles' => 'Vase|Portrait',
            'gallery_environment' => 'testpack/a',
        ]);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        return [config_builder::build($cm, $context, $vrroom, null, $USER), $vrroom, $cm, $context];
    }

    /**
     * An installed pack room gets its envfile.php URL, its name and its credit.
     */
    public function test_installed_room(): void {
        [$config] = $this->preview_config();
        $environment = $config['scenes'][0]['environment'];
        $def = environment_registry::get('testpack/a');
        $this->assertSame('pack', $environment['type']);
        $this->assertSame('testpack', $environment['pack']);
        $this->assertSame('a', $environment['room']);
        $this->assertStringContainsString('/mod/vrroom/envfile.php/testpack/', $environment['url']);
        $this->assertStringEndsWith('/a/room.glb', $environment['url']);
        $this->assertSame($def['url'], $environment['url']);
        $this->assertSame('a', $environment['roomname']);
        $this->assertSame($def['credit'], $environment['credit']);
        $this->assertArrayNotHasKey('missing', $environment);
        $this->assertSame($def['bounds'], $environment['bounds']);
    }

    /**
     * A pack room this site lacks is marked missing and gets no URL (the viewer shows a plain hall).
     */
    public function test_missing_room(): void {
        global $USER;
        [, $vrroom, $cm, $context] = $this->preview_config();
        environment_registry::phpunit_set_packs([]);
        $config = config_builder::build($cm, $context, $vrroom, null, $USER);
        $environment = $config['scenes'][0]['environment'];
        $this->assertSame('pack', $environment['type']);
        $this->assertTrue($environment['missing']);
        $this->assertArrayNotHasKey('url', $environment);
        $this->assertArrayNotHasKey('credit', $environment);
        $this->assertArrayHasKey('bounds', $environment);
    }
}
