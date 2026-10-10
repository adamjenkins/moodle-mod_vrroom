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

use mod_vrroom\local\editor\editor_manager;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\panorama;
use mod_vrroom\local\panorama_test_image;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/vrroom/tests/fixtures/panorama_test_image.php');

/**
 * Tests for the scene formatter: VR room's launch config, photo rooms, media kinds and the
 * standalone config other plugins embed.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scene_formatter::class)]
final class scene_formatter_test extends \advanced_testcase {
    /**
     * A course with a two-station gallery, as the admin.
     *
     * @return \stdClass cm (cm_info), context, vrroom row
     */
    private function setup_activity(): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'stationtitles' => 'Vase|Portrait']);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        return (object)[
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'vrroom' => $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST),
        ];
    }

    /**
     * Store a file of the activity.
     *
     * @param \context $context
     * @param string $area
     * @param int $itemid
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private static function store(\context $context, string $area, int $itemid, string $filename, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_vrroom',
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A photo room with a hotspot holding an image, a sound and a film.
     *
     * @return array
     */
    private static function photo_scene(): array {
        $ref = fn(string $area, int $itemid, string $name): array =>
            ['area' => $area, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $name];
        return [
            'schema' => 'vrroom-scene/1',
            'id' => 'photo-1',
            'name' => 'Yard <b>photo</b>',
            'environment' => ['type' => 'panorama', 'file' => $ref('environment', 1, 'yard.jpg'), 'northoffset' => 10],
            'spawn' => [['id' => 'sp-1', 'position' => [0, 0, 0], 'yaw' => 0]],
            'interactables' => [[
                'id' => 'h-1',
                'kind' => 'hotspot',
                'name' => 'Well',
                'placement' => ['yaw' => 20, 'pitch' => 0],
                'position' => panorama::placement_position(20, 0),
                'content' => [
                    'title' => 'Well',
                    'text' => '<p>Deep</p>',
                    'textformat' => '1',
                    'media' => [
                        $ref('media', 1001, 'bucket.png'),
                        $ref('assets', 2, 'splash.mp3'),
                        $ref('assets', 3, 'drop.mp4'),
                        $ref('other', 4, 'nowhere.webm'),
                    ],
                ],
            ]],
        ];
    }

    /**
     * VR room's own launch config is what the formatter makes of the stored rooms (no behaviour change).
     */
    public function test_config_builder_uses_formatter(): void {
        global $USER;
        $fx = $this->setup_activity();
        $config = config_builder::build($fx->cm, $fx->context, $fx->vrroom, null, $USER);
        $expected = [];
        foreach (instance_manager::get_scenes((int)$fx->vrroom->id) as $scene) {
            $expected[] = scene_formatter::prepare_scene($scene, $fx->context);
        }
        $this->assertSame($expected, $config['scenes']);
        $this->assertSame(scene_formatter::look($USER), $config['look']);

        // A gallery: formatted hotspot HTML, plain names, no photo-room keys.
        $scene = $config['scenes'][0];
        $this->assertArrayNotHasKey('variants', $scene['environment']);
        $hotspots = array_values(array_filter($scene['interactables'], fn($i) => $i['kind'] === 'hotspot'));
        $this->assertNotEmpty($hotspots);
        $this->assertArrayHasKey('html', $hotspots[0]['content']);
        $this->assertArrayNotHasKey('text', $hotspots[0]['content']);
    }

    /**
     * A photo room gets its photo URL and the URLs of the smaller copies; hotspot media get their kind.
     */
    public function test_photo_room_variants_and_media_kinds(): void {
        $fx = $this->setup_activity();
        set_config('panodesktopwidth', 1536, 'mod_vrroom');
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $photo = self::store($fx->context, 'environment', 1, 'yard.jpg', panorama_test_image::make(2048, 1024));
        $this->assertSame(['desktop', 'mobile'], panorama::make_variants($photo));
        self::store($fx->context, 'media', 1001, 'bucket.png', panorama_test_image::make(64, 64, 'png'));
        self::store($fx->context, 'assets', 2, 'splash.mp3', 'ID3 not really');
        self::store($fx->context, 'assets', 3, 'drop.mp4', 'not really');

        $scene = scene_formatter::prepare_scene(self::photo_scene(), $fx->context);
        $this->assertSame('Yard photo', $scene['name']);
        $environment = $scene['environment'];
        $this->assertStringContainsString("/{$fx->context->id}/mod_vrroom/environment/1/yard.jpg", $environment['file']['url']);
        $variants = (array)$environment['variants'];
        $this->assertSame(['desktop', 'mobile'], array_keys($variants));
        $this->assertStringContainsString('/mod_vrroom/environment/1/variants/desktop.jpg', $variants['desktop']);
        $this->assertStringContainsString('/mod_vrroom/environment/1/variants/mobile.jpg', $variants['mobile']);

        $media = $scene['interactables'][0]['content']['media'];
        $this->assertSame(['image', 'audio', 'video', 'video'], array_column($media, 'kind'));
        $this->assertStringContainsString('/mod_vrroom/media/1001/bucket.png', $media[0]['url']);
        $this->assertStringContainsString('/mod_vrroom/assets/3/drop.mp4', $media[2]['url']);
        // An area VR room does not serve gets no URL (its kind comes from the file name).
        $this->assertArrayNotHasKey('url', $media[3]);

        // The editor's save removes every runtime key again.
        $stripped = editor_manager::strip_runtime_keys($scene);
        $this->assertArrayNotHasKey('variants', $stripped['environment']);
        $this->assertArrayNotHasKey('url', $stripped['environment']['file']);
        foreach ($stripped['interactables'][0]['content']['media'] as $entry) {
            $this->assertArrayNotHasKey('kind', $entry);
            $this->assertArrayNotHasKey('url', $entry);
        }
        $this->assertArrayNotHasKey('html', $stripped['interactables'][0]['content']);

        // Without copies there is an empty variants object (the viewer then uses the photo itself).
        panorama::delete_variants($photo);
        $scene = scene_formatter::prepare_scene(self::photo_scene(), $fx->context);
        $this->assertSame([], (array)$scene['environment']['variants']);
    }

    /**
     * Another plugin's callback decides the URLs; copies of its files are found beside them.
     */
    public function test_custom_fileurl(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $context = \context_system::instance();
        $fs = get_file_storage();
        $photo = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'assignsubmission_vrtour',
            'filearea' => 'tour',
            'itemid' => 7,
            'filepath' => '/1/',
            'filename' => 'yard.jpg',
        ], panorama_test_image::make(2048, 1024));
        panorama::make_variants($photo);

        $calls = [];
        $fileurl = function (array $ref) use ($photo, &$calls): ?array {
            $calls[] = $ref['filename'];
            if ($ref['filename'] !== 'yard.jpg') {
                return null;
            }
            return ['url' => 'https://example.org/tour/yard.jpg', 'file' => $photo];
        };
        $scene = self::photo_scene();
        $scene['environment']['file'] = ['area' => 'assets', 'itemid' => 1, 'filepath' => '/', 'filename' => 'yard.jpg'];
        $scene = scene_formatter::prepare_scene($scene, $context, $fileurl);

        $this->assertSame('https://example.org/tour/yard.jpg', $scene['environment']['file']['url']);
        $variants = (array)$scene['environment']['variants'];
        $this->assertSame(['mobile'], array_keys($variants));
        $this->assertStringContainsString('/assignsubmission_vrtour/tour/7/1/variants/mobile.jpg', $variants['mobile']);
        $this->assertContains('bucket.png', $calls);
        foreach ($scene['interactables'][0]['content']['media'] as $entry) {
            $this->assertArrayNotHasKey('url', $entry);
        }
        $media = $scene['interactables'][0]['content']['media'];
        $this->assertSame(['image', 'audio', 'video', 'video'], array_column($media, 'kind'));
    }

    /**
     * The standalone config: a preview with no tasks, no questions, no resume and no live room.
     */
    public function test_standalone_config(): void {
        global $USER;
        $fx = $this->setup_activity();
        $config = scene_formatter::standalone_config(
            [self::photo_scene()],
            $fx->context,
            $USER,
            null,
            ['uid' => 42, 'finishurl' => 'https://example.org/done']
        );
        $this->assertSame(config_builder::SCHEMA, $config['schema']);
        $this->assertTrue($config['preview']);
        $this->assertTrue($config['standalone']);
        $this->assertSame(42, $config['cmid']);
        $this->assertSame((int)$fx->context->id, $config['contextid']);
        $this->assertSame((int)$fx->cm->course, $config['courseid']);
        $this->assertSame((int)$USER->id, $config['userid']);
        $this->assertSame('photo-1', $config['startscene']);
        $this->assertCount(1, $config['scenes']);
        $this->assertSame('Yard photo', $config['scenes'][0]['name']);
        $this->assertSame(['schema' => 'vrroom-graph/1', 'hidden' => [], 'objectives' => [], 'rules' => []], $config['graph']);
        $this->assertEquals((object)[], $config['hints']);
        $this->assertSame('preview', $config['state']['state']);
        $this->assertSame(0, $config['state']['attemptid']);
        $this->assertSame([], $config['events']);
        $this->assertSame([], $config['questions']);
        $this->assertNull($config['resume']);
        $this->assertSame('https://example.org/done', $config['finishurl']);
        $this->assertSame(['mode' => 'once', 'retrypenalty' => 0], $config['questionmode']);
        $this->assertSame(scene_formatter::look($USER), $config['look']);
        $this->assertSame(['available' => false], $config['live']);

        // Defaults: no uid, no finish URL.
        $config = scene_formatter::standalone_config([], $fx->context, $USER, null);
        $this->assertSame(0, $config['cmid']);
        $this->assertSame('', $config['finishurl']);
        $this->assertSame('', $config['startscene']);
    }
}
