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

namespace vrroomtemplate_escape;

use vrroomtemplate_escape\local\layout;
use mod_vrroom\local\scene\presets;

/**
 * Tests for the Escape chain layout: anchors, front vectors and the exit anchor.
 *
 * @package    vrroomtemplate_escape
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(layout::class)]
final class layout_test extends \advanced_testcase {
    /**
     * Anchors come from the preset in order, rounded, at most $count of them.
     */
    public function test_anchors(): void {
        $preset = presets::get('escape-room');
        $this->assertSame([], layout::anchors($preset, 0));

        $anchors = layout::anchors($preset, 3);
        $this->assertSame([
            ['position' => [-4.0, 0.0, 3.0], 'yaw' => 90.0],
            ['position' => [4.0, 0.0, 3.0], 'yaw' => -90.0],
            ['position' => [-4.0, 0.0, -0.5], 'yaw' => 90.0],
        ], $anchors);

        // Asking for more than the preset has returns what there is.
        $this->assertCount(8, layout::anchors($preset, 20));
    }

    /**
     * Puzzle zone edge: the room's zone size clamped to 1.5-3 m, and never more than 2.4 m.
     */
    public function test_zone_size(): void {
        $this->resetAfterTest();
        $this->assertSame(2.4, layout::zone_size(presets::get('escape-room')));
        $this->assertSame(2.4, layout::zone_size(['zonesize' => 3.0]));
        $this->assertSame(2.0, layout::zone_size(['zonesize' => 2.0]));
        $this->assertSame(1.5, layout::zone_size(['zonesize' => 1.0]));
        $this->assertSame(2.4, layout::zone_size([]));
    }

    /**
     * Front vectors are (sin yaw, 0, cos yaw) without floating-point noise.
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
     * The escape room's exit anchor is used as it is, with a door.
     */
    public function test_exit_anchor_from_preset(): void {
        $this->assertSame(
            ['position' => [0.0, 0.0, -5.6], 'yaw' => 0.0, 'door' => true],
            layout::exit_anchor(presets::get('escape-room'))
        );
    }

    /**
     * Without an exit anchor, the exit is 2 m ahead of the spawn point, facing it, without a door.
     */
    public function test_exit_anchor_fallback(): void {
        // Gallery hall: spawn (0, 0, 13) looking along -Z.
        $this->assertSame(
            ['position' => [0.0, 0.0, 11.0], 'yaw' => 0.0, 'door' => false],
            layout::exit_anchor(presets::get('gallery-hall'))
        );
        // Courtyard: spawn at the origin looking along -Z.
        $this->assertSame(
            ['position' => [0.0, 0.0, -2.0], 'yaw' => 0.0, 'door' => false],
            layout::exit_anchor(presets::get('courtyard'))
        );
        // A spawn turned by 90 degrees looks along -X, so the exit is 2 m towards -X, facing +X.
        $exit = layout::exit_anchor(['spawn' => ['position' => [1, 0, 1], 'yaw' => 90]]);
        $this->assertSame(['position' => [-1.0, 0.0, 1.0], 'yaw' => 90.0, 'door' => false], $exit);
        $this->assertSame([1.0, 0.0, 0.0], layout::front($exit['yaw']));
    }

    /**
     * Offsets move along the front vector and up.
     */
    public function test_offset_and_rounding(): void {
        $this->assertSame([-3.55, 0.83, 3.0], layout::offset([-4.0, 0.0, 3.0], [1.0, 0.0, 0.0], 0.45, 0.83));
        $this->assertSame([0.0, 1.3, -5.3], layout::offset([0.0, 0.0, -5.6], [0.0, 0.0, 1.0], 0.3, 1.3));
        $this->assertSame(0.0, layout::round3(-0.0001));
        $this->assertSame(180.0, layout::normalise_yaw(-180));
        $this->assertSame(-90.0, layout::normalise_yaw(270));
        $this->assertSame(['position' => [0.0, 0.0, 4.5], 'yaw' => 0.0], layout::spawn(presets::get('escape-room')));
    }
}
