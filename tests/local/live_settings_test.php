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

use vrroomruntime_builtin\local\live\access;

/**
 * Tests for the live mode settings (mode, number of rooms) and the state the upgrade leaves.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(live_settings::class)]
final class live_settings_test extends \advanced_testcase {
    /**
     * clean_mode() keeps 0, 1 and 2, turns anything else into off, and 2 into 1 without multirooms.
     */
    public function test_clean_mode(): void {
        $this->assertSame(live_settings::MODE_OFF, live_settings::clean_mode(0, true));
        $this->assertSame(live_settings::MODE_FIXED, live_settings::clean_mode('1', true));
        $this->assertSame(live_settings::MODE_DYNAMIC, live_settings::clean_mode(2, true));
        $this->assertSame(live_settings::MODE_FIXED, live_settings::clean_mode(2, false));
        $this->assertSame(live_settings::MODE_FIXED, live_settings::clean_mode(1, false));
        $this->assertSame(live_settings::MODE_OFF, live_settings::clean_mode(7, true));
        $this->assertSame(live_settings::MODE_OFF, live_settings::clean_mode(-1, true));
        $this->assertSame(live_settings::MODE_OFF, live_settings::clean_mode('abc', true));
        $this->assertSame(live_settings::MODE_OFF, live_settings::clean_mode(null, false));
    }

    /**
     * clean_rooms() clamps to 0..20, and keeps "no limit" (0) only when students open rooms.
     */
    public function test_clean_rooms(): void {
        $this->assertSame(3, live_settings::clean_rooms(3, live_settings::MODE_FIXED));
        $this->assertSame(20, live_settings::clean_rooms(25, live_settings::MODE_FIXED));
        $this->assertSame(1, live_settings::clean_rooms(0, live_settings::MODE_FIXED));
        $this->assertSame(1, live_settings::clean_rooms(-4, live_settings::MODE_FIXED));
        $this->assertSame(1, live_settings::clean_rooms(0, live_settings::MODE_OFF));
        $this->assertSame(0, live_settings::clean_rooms(0, live_settings::MODE_DYNAMIC));
        $this->assertSame(0, live_settings::clean_rooms(-1, live_settings::MODE_DYNAMIC));
        $this->assertSame(20, live_settings::clean_rooms(500, live_settings::MODE_DYNAMIC));
        $this->assertSame(5, live_settings::clean_rooms('5', live_settings::MODE_DYNAMIC));
    }

    /**
     * runtime_has_multirooms(): the built-in viewer runs several rooms while live mode is on for
     * the site; a run mode that is not installed runs none.
     */
    public function test_runtime_has_multirooms(): void {
        $this->resetAfterTest();
        set_config('liveenabled', 1, 'vrroomruntime_builtin');
        $this->assertTrue(live_settings::runtime_has_multirooms('builtin'));
        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        $this->assertFalse(live_settings::runtime_has_multirooms('builtin'));
        $this->assertFalse(live_settings::runtime_has_multirooms('notinstalled'));
    }

    /**
     * The upgrade adds vrroom.liverooms with default 1, so an activity that had live mode on
     * before rooms existed (livemode 1, no liverooms given) is one fixed room, as it was.
     */
    public function test_existing_live_activity_is_one_fixed_room(): void {
        global $DB;
        $this->resetAfterTest();

        $columns = $DB->get_columns('vrroom');
        $this->assertArrayHasKey('liverooms', $columns);
        $this->assertEquals(1, $columns['liverooms']->default_value);
        $this->assertTrue($columns['liverooms']->not_null);

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        // As the upgrade leaves an existing row: the column filled by its default, livemode 1.
        $DB->update_record('vrroom', (object)[
            'id' => $module->id,
            'livemode' => 1,
            'liverooms' => $columns['liverooms']->default_value,
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);

        $this->assertEquals(1, $vrroom->liverooms);
        $this->assertSame(1, access::max_rooms($vrroom));
        $this->assertSame('fixed', access::room_mode($vrroom));
    }
}
