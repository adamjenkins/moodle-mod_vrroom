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

namespace mod_mavr\local\scene;

/**
 * Tests for the bundled environment presets.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(presets::class)]
final class presets_test extends \advanced_testcase {
    /**
     * The file loads and has every preset with its anchor count.
     */
    public function test_load(): void {
        $all = presets::get_all();
        $this->assertSame(['gallery-hall', 'courtyard', 'escape-room'], array_keys($all));
        $this->assertCount(10, presets::get('gallery-hall')['anchors']);
        $this->assertCount(8, presets::get('courtyard')['anchors']);
        $this->assertCount(8, presets::get('escape-room')['anchors']);
        $this->assertNull(presets::get('moon-base'));

        foreach ($all as $id => $preset) {
            foreach (['string', 'shape', 'sky', 'lights', 'bounds', 'spawn', 'anchors'] as $key) {
                $this->assertArrayHasKey($key, $preset, "{$id}.{$key}");
            }
            $this->assertContains($preset['shape']['kind'], scene_validator::SHAPE_KINDS);
            foreach ($preset['anchors'] as $anchor) {
                // Every anchor is on the floor and inside the walkable bounds.
                $this->assertEquals(0, $anchor['position'][1]);
                $this->assertGreaterThanOrEqual($preset['bounds']['min'][0], $anchor['position'][0]);
                $this->assertLessThanOrEqual($preset['bounds']['max'][0], $anchor['position'][0]);
                $this->assertGreaterThanOrEqual($preset['bounds']['min'][2], $anchor['position'][2]);
                $this->assertLessThanOrEqual($preset['bounds']['max'][2], $anchor['position'][2]);
            }
        }
    }

    /**
     * Choices use the lang strings.
     */
    public function test_choices(): void {
        $this->assertSame([
            'gallery-hall' => get_string('preset_galleryhall', 'mod_mavr'),
            'courtyard' => get_string('preset_courtyard', 'mod_mavr'),
            'escape-room' => get_string('preset_escaperoom', 'mod_mavr'),
        ], presets::get_choices());
    }

    /**
     * Each courtyard anchor faces the centre: its front (sin yaw, 0, cos yaw) points at the origin.
     */
    public function test_courtyard_anchors_face_centre(): void {
        foreach (presets::get('courtyard')['anchors'] as $i => $anchor) {
            $yaw = deg2rad($anchor['yaw']);
            $front = [sin($yaw), cos($yaw)];
            $x = $anchor['position'][0];
            $z = $anchor['position'][2];
            $length = sqrt($x * $x + $z * $z);
            $tocentre = [-$x / $length, -$z / $length];
            $dot = $front[0] * $tocentre[0] + $front[1] * $tocentre[1];
            $this->assertEqualsWithDelta(1.0, $dot, 1e-3, "anchor {$i}");
            $this->assertEqualsWithDelta(9.0, $length, 1e-3, "anchor {$i} radius");
            $this->assertGreaterThan(-180, $anchor['yaw']);
            $this->assertLessThanOrEqual(180, $anchor['yaw']);
        }
    }

    /**
     * Each gallery-hall anchor faces into the hall (away from the nearest wall).
     */
    public function test_gallery_hall_anchors_face_inwards(): void {
        foreach (presets::get('gallery-hall')['anchors'] as $i => $anchor) {
            $yaw = deg2rad($anchor['yaw']);
            $frontx = sin($yaw);
            $frontz = cos($yaw);
            $x = $anchor['position'][0];
            if (abs($x) > 4) {
                // Side wall anchors face across the hall.
                $this->assertEqualsWithDelta(-$x / abs($x), $frontx, 1e-9, "anchor {$i}");
            } else {
                // End wall anchors face back down the hall (+Z).
                $this->assertEqualsWithDelta(1.0, $frontz, 1e-9, "anchor {$i}");
            }
        }
    }

    /**
     * Each escape-room anchor faces into the room, and only the escape room has an exit inside its bounds.
     */
    public function test_escape_room_anchors_and_exit(): void {
        $preset = presets::get('escape-room');
        foreach ($preset['anchors'] as $i => $anchor) {
            $yaw = deg2rad($anchor['yaw']);
            $frontx = sin($yaw);
            $frontz = cos($yaw);
            $x = $anchor['position'][0];
            if (abs($x) > 3) {
                // Side wall anchors face across the room.
                $this->assertEqualsWithDelta(-$x / abs($x), $frontx, 1e-9, "anchor {$i}");
            } else {
                // Back wall anchors face the start (+Z).
                $this->assertEqualsWithDelta(1.0, $frontz, 1e-9, "anchor {$i}");
            }
        }

        $exit = $preset['exit'];
        $this->assertEquals([0, 0, -5.6], $exit['position']);
        $this->assertEquals(0, $exit['yaw']);
        $this->assertGreaterThanOrEqual($preset['bounds']['min'][2], $exit['position'][2]);
        $this->assertLessThanOrEqual($preset['bounds']['max'][2], $exit['position'][2]);
        $this->assertArrayNotHasKey('exit', presets::get('gallery-hall'));
        $this->assertArrayNotHasKey('exit', presets::get('courtyard'));
    }
}
