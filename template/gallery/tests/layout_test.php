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

namespace mavrtemplate_gallery;

use mavrtemplate_gallery\local\layout;
use mod_mavr\local\scene\presets;

/**
 * Tests for the Gallery walk station layout.
 *
 * @package    mavrtemplate_gallery
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(layout::class)]
final class layout_test extends \advanced_testcase {
    /**
     * Assert that an anchor faces the origin (its front vector points at the centre).
     *
     * @param array $anchor ['position' => [x, y, z], 'yaw' => deg]
     */
    private function assert_faces_centre(array $anchor): void {
        [$x, , $z] = $anchor['position'];
        $length = sqrt($x * $x + $z * $z);
        $this->assertGreaterThan(0, $length);
        $front = layout::front($anchor['yaw']);
        $dot = ($front[0] * -$x + $front[2] * -$z) / $length;
        $this->assertEqualsWithDelta(1.0, $dot, 1e-3, 'Anchor at ' . json_encode($anchor) . ' does not face the centre.');
    }

    /**
     * Gallery hall: the preset anchors come back in order, sliced to the station count.
     */
    public function test_anchors_gallery_hall(): void {
        $this->resetAfterTest();
        $preset = presets::get('gallery-hall');
        $this->assertNotNull($preset);

        $all = layout::anchors($preset, 10);
        $this->assertCount(10, $all);
        $this->assertSame([-4.5, 0.0, 9.0], $all[0]['position']);
        $this->assertSame(90.0, $all[0]['yaw']);
        $this->assertSame([4.5, 0.0, 9.0], $all[1]['position']);
        $this->assertSame(-90.0, $all[1]['yaw']);
        $this->assertSame([2.0, 0.0, -13.5], $all[9]['position']);
        $this->assertSame(0.0, $all[9]['yaw']);

        $three = layout::anchors($preset, 3);
        $this->assertSame(array_slice($all, 0, 3), $three);

        // Never more than the preset has.
        $this->assertCount(10, layout::anchors($preset, 11));
    }

    /**
     * Courtyard: eight anchors on a circle, every one facing the centre.
     */
    public function test_anchors_courtyard_face_centre(): void {
        $this->resetAfterTest();
        $preset = presets::get('courtyard');
        $this->assertNotNull($preset);

        $anchors = layout::anchors($preset, 8);
        $this->assertCount(8, $anchors);
        $this->assertSame([0.0, 0.0, -9.0], $anchors[0]['position']);
        $this->assertSame(0.0, $anchors[0]['yaw']);
        $this->assertSame(180.0, $anchors[4]['yaw']);
        foreach ($anchors as $anchor) {
            $this->assert_faces_centre($anchor);
        }
    }

    /**
     * Uploaded environment: stations on a circle (r = 6 up to six stations, else r = count).
     */
    public function test_anchors_upload_circle(): void {
        $anchors = layout::anchors(null, 4);
        $this->assertCount(4, $anchors);
        $this->assertSame([0.0, 0.0, -6.0], $anchors[0]['position']);
        $this->assertSame(0.0, $anchors[0]['yaw']);
        $this->assertSame([6.0, 0.0, 0.0], $anchors[1]['position']);
        $this->assertSame(-90.0, $anchors[1]['yaw']);
        $this->assertSame([0.0, 0.0, 6.0], $anchors[2]['position']);
        $this->assertSame(180.0, $anchors[2]['yaw']);
        $this->assertSame([-6.0, 0.0, 0.0], $anchors[3]['position']);
        $this->assertSame(90.0, $anchors[3]['yaw']);
        foreach ($anchors as $anchor) {
            $this->assert_faces_centre($anchor);
        }

        $ten = layout::anchors(null, 10);
        $this->assertCount(10, $ten);
        foreach ($ten as $anchor) {
            [$x, , $z] = $anchor['position'];
            $this->assertEqualsWithDelta(10.0, sqrt($x * $x + $z * $z), 1e-2);
            $this->assert_faces_centre($anchor);
        }

        $this->assertSame([], layout::anchors(null, 0));
    }

    /**
     * Circle radius and bounds.
     */
    public function test_circle_radius_and_bounds(): void {
        $this->assertSame(6.0, layout::circle_radius(1));
        $this->assertSame(6.0, layout::circle_radius(6));
        $this->assertSame(7.0, layout::circle_radius(7));
        $this->assertSame(12.0, layout::circle_radius(12));
        $this->assertSame(['min' => [-9.0, 0.0, -9.0], 'max' => [9.0, 0.0, 9.0]], layout::circle_bounds(4));
        $this->assertSame(['min' => [-15.0, 0.0, -15.0], 'max' => [15.0, 0.0, 15.0]], layout::circle_bounds(12));
    }

    /**
     * Front vectors for the cardinal yaws, without floating-point noise.
     */
    public function test_front(): void {
        $this->assertSame([0.0, 0.0, 1.0], layout::front(0));
        $this->assertSame([1.0, 0.0, 0.0], layout::front(90));
        $this->assertSame([-1.0, 0.0, 0.0], layout::front(-90));
        $this->assertSame([0.0, 0.0, -1.0], layout::front(180));
        $front = layout::front(45);
        $this->assertEqualsWithDelta(sqrt(0.5), $front[0], 1e-9);
        $this->assertEqualsWithDelta(sqrt(0.5), $front[2], 1e-9);
    }

    /**
     * Yaw normalisation to (−180, 180] and rounding without negative zero.
     */
    public function test_normalise_yaw_and_round3(): void {
        $this->assertSame(180.0, layout::normalise_yaw(-180));
        $this->assertSame(180.0, layout::normalise_yaw(540));
        $this->assertSame(-90.0, layout::normalise_yaw(270));
        $this->assertSame(0.0, layout::normalise_yaw(-360));
        $this->assertSame(45.0, layout::normalise_yaw(45));
        $this->assertSame('0.0', json_encode(layout::round3(-0.0001), JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame(6.364, layout::round3(6.36396));
    }
}
