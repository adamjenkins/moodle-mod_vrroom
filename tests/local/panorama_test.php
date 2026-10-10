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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/vrroom/tests/fixtures/panorama_test_image.php');

/**
 * Tests for 360° photos: limits, checks, the smaller copies and placement maths.
 *
 * Test photos are drawn with GD inside the tests (no binary fixtures).
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panorama::class)]
final class panorama_test extends \advanced_testcase {
    /**
     * Store a file in the system context.
     *
     * @param string $filename
     * @param string $content
     * @param int $itemid
     * @param string $filepath
     * @return \stored_file
     */
    private static function store(string $filename, string $content, int $itemid = 1, string $filepath = '/'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_vrroom',
            'filearea' => 'environment',
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }

    /**
     * The settings getters: defaults, site values, and defaults again for nonsense.
     */
    public function test_limits(): void {
        $this->resetAfterTest();
        $this->assertSame(
            ['maxwidth' => 16384, 'desktopwidth' => 8192, 'mobilewidth' => 4096, 'maxbytes' => 50 * 1048576],
            panorama::limits()
        );
        set_config('panomaxwidth', 8000, 'mod_vrroom');
        set_config('panodesktopwidth', 4000, 'mod_vrroom');
        set_config('panomobilewidth', 2000, 'mod_vrroom');
        set_config('panomaxsize', 7, 'mod_vrroom');
        $this->assertSame(8000, panorama::max_width());
        $this->assertSame(4000, panorama::desktop_width());
        $this->assertSame(2000, panorama::mobile_width());
        $this->assertSame(7 * 1048576, panorama::max_bytes());
        set_config('panomaxwidth', -1, 'mod_vrroom');
        set_config('panomaxsize', 'lots', 'mod_vrroom');
        $this->assertSame(16384, panorama::max_width());
        $this->assertSame(50 * 1048576, panorama::max_bytes());
    }

    /**
     * check(): type, width from 1024 to the maximum, file size; any shape is accepted.
     */
    public function test_check(): void {
        $this->resetAfterTest();
        $this->assertNull(panorama::check(self::store('ok.jpg', panorama_test_image::make(2048, 1024))));
        $this->assertNull(panorama::check(self::store('ok.png', panorama_test_image::make(2048, 1040, 'png'))));
        $this->assertNull(panorama::check(self::store('wide.jpg', panorama_test_image::make(2048, 1000))));
        $this->assertNull(panorama::check(self::store('square.jpg', panorama_test_image::make(1024, 1024))));
        $this->assertNull(panorama::check(self::store('band.jpg', panorama_test_image::make(4096, 700))));
        $this->assertSame('panoramatoosmall', panorama::check(self::store('small.jpg', panorama_test_image::make(1000, 500))));
        $this->assertSame('panoramabadtype', panorama::check(self::store('notes.txt', 'Just text')));
        $this->assertSame('panoramabadtype', panorama::check(self::store('fake.jpg', 'not really a photo')));
        $this->assertSame('panoramabadtype', panorama::check(self::store('anim.gif', self::gif())));

        set_config('panomaxwidth', 1500, 'mod_vrroom');
        $this->assertSame('panoramatoolarge', panorama::check(self::store('big.jpg', panorama_test_image::make(2048, 1024))));

        $this->assertSame('panoramatoobig', panorama::check_dimensions(1024, 512, 1 + 1500 * 1048576));
        $this->assertNull(panorama::check_dimensions(1024, 512, 1048576));

        // Every result is a string of this plugin, and the message adds the site's limits.
        foreach (['panoramabadtype', 'panoramatoolarge', 'panoramatoosmall', 'panoramatoobig'] as $key) {
            $this->assertTrue(get_string_manager()->string_exists($key, 'mod_vrroom'), $key);
        }
        $this->assertStringContainsString('1500', panorama::message('panoramatoolarge'));
    }

    /**
     * has_ratio(): images at least 1.5 times as wide as high are offered as 360° photos.
     */
    public function test_has_ratio(): void {
        $this->resetAfterTest();
        $this->assertTrue(panorama::has_ratio(self::store('sphere.jpg', panorama_test_image::make(2048, 1024))));
        $this->assertTrue(panorama::has_ratio(self::store('band.jpg', panorama_test_image::make(4096, 700))));
        $this->assertTrue(panorama::has_ratio(self::store('near.png', panorama_test_image::make(1500, 1000, 'png'))));
        $this->assertFalse(panorama::has_ratio(self::store('photo.jpg', panorama_test_image::make(1600, 1200))));
        $this->assertFalse(panorama::has_ratio(self::store('fake.jpg', 'not a photo')));
        $this->assertFalse(panorama::has_ratio(self::store('notes.txt', 'Just text')));
    }

    /**
     * make_variants(): copies only narrower than the original, replaced on a second run, found and deleted.
     */
    public function test_make_find_delete_variants(): void {
        $this->resetAfterTest();
        set_config('panodesktopwidth', 1536, 'mod_vrroom');
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $photo = self::store('yard.png', panorama_test_image::make(2048, 1024, 'png'));

        $this->assertSame(['desktop' => null, 'mobile' => null], panorama::find_variants($photo));
        $this->assertSame(['desktop', 'mobile'], panorama::make_variants($photo));
        $variants = panorama::find_variants($photo);
        $this->assertSame('/variants/', $variants['desktop']->get_filepath());
        $this->assertSame('desktop.jpg', $variants['desktop']->get_filename());
        $this->assertSame('mobile.jpg', $variants['mobile']->get_filename());
        $this->assertSame('image/jpeg', $variants['desktop']->get_mimetype());
        $this->assertSame(['width' => 1536, 'height' => 768], array_intersect_key(
            $variants['desktop']->get_imageinfo(),
            ['width' => 0, 'height' => 0]
        ));
        $this->assertSame(1024, $variants['mobile']->get_imageinfo()['width']);
        $this->assertTrue(panorama::has_variants($photo));
        $this->assertTrue(panorama::is_variant($variants['mobile']));
        $this->assertFalse(panorama::is_variant($photo));
        // A copy has no copies of its own.
        $this->assertSame(['desktop' => null, 'mobile' => null], panorama::find_variants($variants['mobile']));

        // A second run replaces the copies: only the mobile one, now that the desktop width is larger.
        set_config('panodesktopwidth', 4096, 'mod_vrroom');
        $this->assertSame(['mobile'], panorama::make_variants($photo));
        $variants = panorama::find_variants($photo);
        $this->assertNull($variants['desktop']);
        $this->assertNotNull($variants['mobile']);

        panorama::delete_variants($photo);
        $this->assertFalse(panorama::has_variants($photo));
        $this->assertTrue(get_file_storage()->file_exists(
            $photo->get_contextid(),
            'mod_vrroom',
            'environment',
            1,
            '/',
            'yard.png'
        ));
        $left = get_file_storage()->get_area_files($photo->get_contextid(), 'mod_vrroom', 'environment', 1, 'id', true);
        $this->assertCount(2, $left, 'Only the photo and its folder record are left.');

        // A photo no wider than the copies gets none; neither does a file that is not a photo.
        $this->assertSame([], panorama::make_variants(self::store('small.jpg', panorama_test_image::make(1024, 512), 2)));
        $this->assertSame([], panorama::make_variants(self::store('fake.jpg', 'not a photo', 3)));

        // Photos in a sub folder keep their copies beside them.
        $nested = self::store('deep.jpg', panorama_test_image::make(2048, 1024), 4, '/3/');
        $this->assertSame(['mobile'], panorama::make_variants($nested));
        $this->assertSame('/3/variants/', panorama::find_variants($nested)['mobile']->get_filepath());
    }

    /**
     * A WebP photo gets WebP copies (when this PHP's GD writes WebP).
     */
    public function test_webp_variants(): void {
        if (!function_exists('imagewebp') || !(imagetypes() & IMG_WEBP)) {
            $this->markTestSkipped('GD without WebP support.');
        }
        $this->resetAfterTest();
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $photo = self::store('yard.webp', panorama_test_image::make(2048, 1024, 'webp'));
        $this->assertNull(panorama::check($photo));
        $this->assertSame(['mobile'], panorama::make_variants($photo));
        $this->assertSame('mobile.webp', panorama::find_variants($photo)['mobile']->get_filename());
    }

    /**
     * Placement maths: yaw 0 ahead (-z), +90 to the left (-x), pitch up, from eye height.
     */
    public function test_placement_position(): void {
        $cases = [
            [[0, 0, 4], [0.0, 1.6, -4.0]],
            [[90, 0, 2], [-2.0, 1.6, 0.0]],
            [[-90, 0, 2], [2.0, 1.6, 0.0]],
            [[180, 0, 1], [0.0, 1.6, 1.0]],
            [[0, 30, 4], [0.0, 3.6, -4 * cos(deg2rad(30))]],
            [[-90, 30, 4], [4 * cos(deg2rad(30)), 3.6, 0.0]],
        ];
        foreach ($cases as [$in, $expected]) {
            $position = panorama::placement_position(...$in);
            $this->assertCount(3, $position);
            foreach ($expected as $axis => $value) {
                $this->assertEqualsWithDelta($value, $position[$axis], 1e-9, json_encode($in));
            }
        }
        // The default distance is 4 m.
        $this->assertEqualsWithDelta(-4.0, panorama::placement_position(0, 0)[2], 1e-9);
    }

    /**
     * A small GIF (not an accepted 360° photo type).
     *
     * @return string
     */
    private static function gif(): string {
        $image = imagecreatetruecolor(2048, 1024);
        ob_start();
        imagegif($image);
        $data = ob_get_clean();
        imagedestroy($image);
        return $data;
    }
}
