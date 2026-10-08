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

namespace vrroomruntime_builtin\task;

use vrroomruntime_builtin\local\live\room_manager;

/**
 * Tests of the live room purge task.
 *
 * @package    vrroomruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(purge_live::class)]
final class purge_live_test extends \advanced_testcase {
    /**
     * Stale presences with their signals and expired signals go; fresh rows stay.
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $two = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[] = $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        /** @var \vrroomruntime_builtin_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('vrroomruntime_builtin');

        $stale = $generator->create_peer(['vrroomid' => $one->id, 'userid' => $users[0]->id,
            'timeseen' => $now - room_manager::STALE_AFTER - 5]);
        $fresh = $generator->create_peer(['vrroomid' => $one->id, 'userid' => $users[1]->id, 'timeseen' => $now]);
        $otherfresh = $generator->create_peer(['vrroomid' => $two->id, 'userid' => $users[2]->id, 'timeseen' => $now]);
        $otherfresh2 = $generator->create_peer(['vrroomid' => $two->id, 'userid' => $users[3]->id, 'timeseen' => $now]);

        // From and to the stale peer: removed with it.
        $generator->create_signal(['vrroomid' => $one->id, 'frompeer' => $stale->peerid, 'topeer' => $fresh->peerid,
            'timecreated' => $now]);
        $generator->create_signal(['vrroomid' => $one->id, 'frompeer' => $fresh->peerid, 'topeer' => $stale->peerid,
            'timecreated' => $now]);
        // Expired between fresh peers: removed.
        $generator->create_signal(['vrroomid' => $two->id, 'frompeer' => $otherfresh->peerid, 'topeer' => $otherfresh2->peerid,
            'timecreated' => $now - room_manager::SIGNAL_TTL - 5]);
        // Recent between fresh peers: kept.
        $kept = $generator->create_signal(['vrroomid' => $two->id, 'frompeer' => $otherfresh2->peerid,
            'topeer' => $otherfresh->peerid, 'timecreated' => $now]);

        $this->expectOutputRegex('/Removed 1 stale live room presences and 3 connection messages/');
        (new purge_live())->execute();

        $this->assertFalse($DB->record_exists('vrroomruntime_builtin_peers', ['id' => $stale->id]));
        $this->assertEqualsCanonicalizing(
            [$fresh->peerid, $otherfresh->peerid, $otherfresh2->peerid],
            $DB->get_fieldset_select('vrroomruntime_builtin_peers', 'peerid', '1 = 1')
        );
        $signalids = $DB->get_fieldset_select('vrroomruntime_builtin_signals', 'id', '1 = 1');
        $this->assertSame([(int)$kept->id], array_map('intval', $signalids));
        $this->assertNotEmpty((new purge_live())->get_name());
    }
}
