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

namespace vrroomruntime_builtin\local\live;

use mod_vrroom\local\attempt\attempt_manager;

/**
 * Tests for who may join which live room.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Availability: site setting, activity setting, run mode.
     */
    public function test_is_available(): void {
        $this->resetAfterTest();
        [, , $vrroom] = $this->create_activity();
        $this->assertTrue(access::is_available($vrroom));

        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        $this->assertFalse(access::is_available($vrroom));
        set_config('liveenabled', 1, 'vrroomruntime_builtin');

        $off = clone $vrroom;
        $off->livemode = 0;
        $this->assertFalse(access::is_available($off));

        $other = clone $vrroom;
        $other->runtime = 'companion';
        $this->assertFalse(access::is_available($other));
    }

    /**
     * The room size is clamped to 2..MAX_PEERS.
     */
    public function test_max_peers(): void {
        $this->assertSame(2, access::max_peers((object)['livemaxusers' => 0]));
        $this->assertSame(2, access::max_peers((object)['livemaxusers' => 2]));
        $this->assertSame(4, access::max_peers((object)['livemaxusers' => 4]));
        $this->assertSame(room_manager::MAX_PEERS, access::max_peers((object)['livemaxusers' => 50]));
    }

    /**
     * An own attempt in progress lets a learner join.
     */
    public function test_resolve_kind_attempt(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);

        $kind = access::resolve_kind($cm, $context, $vrroom, (int)$attempt->id, (int)$student->id);
        $this->assertSame(['kind' => 'attempt', 'attemptid' => (int)$attempt->id], $kind);
    }

    /**
     * Another user's attempt is refused.
     */
    public function test_resolve_kind_other_users_attempt(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$other->id);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornotyourattempt', 'mod_vrroom'));
        access::resolve_kind($cm, $context, $vrroom, (int)$attempt->id, (int)$student->id);
    }

    /**
     * An attempt of another activity is refused.
     */
    public function test_resolve_kind_attempt_of_other_activity(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        [, , $vrroom2] = $this->create_activity($course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom2->id, (int)$student->id);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivenotallowed', 'vrroomruntime_builtin'));
        access::resolve_kind($cm, $context, $vrroom, (int)$attempt->id, (int)$student->id);
    }

    /**
     * A finished attempt is refused.
     */
    public function test_resolve_kind_finished_attempt(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        attempt_manager::finish($attempt);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorattemptclosed', 'mod_vrroom'));
        access::resolve_kind($cm, $context, $vrroom, (int)$attempt->id, (int)$student->id);
    }

    /**
     * A preview needs mod/vrroom:manage.
     */
    public function test_resolve_kind_preview(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertSame(
            ['kind' => 'preview', 'attemptid' => 0],
            access::resolve_kind($cm, $context, $vrroom, 0, (int)$teacher->id)
        );

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivenotallowed', 'vrroomruntime_builtin'));
        access::resolve_kind($cm, $context, $vrroom, 0, (int)$student->id);
    }

    /**
     * Without group mode there is one room (group 0) and a requested group is ignored.
     */
    public function test_groups_no_group_mode(): void {
        $this->resetAfterTest();
        [$cm, $context, , $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $this->assertSame([], access::allowed_groups($cm, $context, (int)$student->id));
        $this->assertSame(0, access::resolve_group($cm, $context, (int)$student->id, 0));
        $this->assertSame(0, access::resolve_group($cm, $context, (int)$student->id, (int)$group->id));
    }

    /**
     * Separate and visible groups: members join only their own groups, accessallgroups any.
     */
    public function test_groups_membership(): void {
        $this->resetAfterTest();
        foreach ([SEPARATEGROUPS, VISIBLEGROUPS] as $groupmode) {
            [$cm, $context, , $course] = $this->create_activity(null, $groupmode);
            $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
            $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group B']);
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
            $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $student->id]);
            $this->setUser($student);

            $this->assertSame([(int)$groupa->id => 'Group A'], access::allowed_groups($cm, $context, (int)$student->id));
            $this->assertSame((int)$groupa->id, access::resolve_group($cm, $context, (int)$student->id, (int)$groupa->id));
            $this->assertSame((int)$groupa->id, access::resolve_group($cm, $context, (int)$student->id, 0));
            try {
                access::resolve_group($cm, $context, (int)$student->id, (int)$groupb->id);
                $this->fail('A learner joined the room of a group they are not in (group mode ' . $groupmode . ').');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorlivenoroom', $e->errorcode);
            }

            $this->setUser($teacher);
            $this->assertSame(
                [(int)$groupa->id => 'Group A', (int)$groupb->id => 'Group B'],
                access::allowed_groups($cm, $context, (int)$teacher->id)
            );
            $this->assertSame((int)$groupb->id, access::resolve_group($cm, $context, (int)$teacher->id, (int)$groupb->id));
        }
    }

    /**
     * A learner in no group has no room.
     */
    public function test_groups_no_group(): void {
        $this->resetAfterTest();
        [$cm, $context, , $course] = $this->create_activity(null, SEPARATEGROUPS);
        $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlivenoroom', 'vrroomruntime_builtin'));
        access::resolve_group($cm, $context, (int)$student->id, 0);
    }

    /**
     * A presence stops being allowed when the attempt finishes, the group changes or live mode goes.
     */
    public function test_still_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, SEPARATEGROUPS);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $peer = (object)['vrroomid' => (int)$vrroom->id, 'groupid' => (int)$group->id, 'userid' => (int)$student->id,
            'kind' => 'attempt', 'attemptid' => (int)$attempt->id];

        $this->assertTrue(access::still_allowed($peer, $cm, $context, $vrroom));

        // Group 0 in a grouped activity is never a room.
        $this->assertFalse(access::still_allowed((object)(['groupid' => 0] + (array)$peer), $cm, $context, $vrroom));

        // Live mode turned off.
        $off = clone $vrroom;
        $off->livemode = 0;
        $this->assertFalse(access::still_allowed($peer, $cm, $context, $off));

        // Removed from the group.
        groups_remove_member($group->id, $student->id);
        $this->assertFalse(access::still_allowed($peer, $cm, $context, $vrroom));
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $this->assertTrue(access::still_allowed($peer, $cm, $context, $vrroom));

        // Attempt finished.
        attempt_manager::finish($attempt);
        $this->assertFalse(access::still_allowed($peer, $cm, $context, $vrroom));

        // A preview presence of a user without manage.
        $preview = (object)(['kind' => 'preview', 'attemptid' => 0] + (array)$peer);
        $this->assertFalse(access::still_allowed($preview, $cm, $context, $vrroom));
        // Checking never writes a presence.
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers'));
    }

    /**
     * The launch block when live mode is not available.
     */
    public function test_launch_block_unavailable(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $vrroom->livemode = 0;

        $block = access::launch_block($cm, $context, $vrroom, $attempt, $student);
        $this->assertSame(['available', 'canjoin', 'reason', 'transport', 'transportmodule', 'cmid', 'attemptid',
            'maxpeers', 'groups', 'defaultgroupid', 'selfname'], array_keys($block));
        $this->assertFalse($block['available']);
        $this->assertFalse($block['canjoin']);
        $this->assertSame('', $block['reason']);
        $this->assertSame(0, $block['maxpeers']);
        $this->assertSame([], $block['groups']);
        $this->assertSame(0, $block['defaultgroupid']);
        $this->assertSame('', $block['selfname']);
        $this->assertNull($block['transportmodule']);
    }

    /**
     * The launch block of an attempt, of a learner without a group and of a preview.
     */
    public function test_launch_block_shapes(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, SEPARATEGROUPS, 4);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group <b>A</b> &amp; co']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Sam', 'lastname' => 'Student']);
        $loner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->setUser($student);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $block = access::launch_block($cm, $context, $vrroom, $attempt, $student);
        $this->assertTrue($block['available']);
        $this->assertTrue($block['canjoin']);
        $this->assertSame('', $block['reason']);
        $this->assertSame('mesh', $block['transport']);
        $this->assertSame((int)$cm->id, $block['cmid']);
        $this->assertSame((int)$attempt->id, $block['attemptid']);
        $this->assertSame(4, $block['maxpeers']);
        $this->assertSame([['id' => (int)$group->id, 'name' => 'Group A & co']], $block['groups']);
        $this->assertSame((int)$group->id, $block['defaultgroupid']);
        $this->assertSame('Sam Student', $block['selfname']);

        $this->setUser($loner);
        $attempt2 = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$loner->id);
        $block = access::launch_block($cm, $context, $vrroom, $attempt2, $loner);
        $this->assertTrue($block['available']);
        $this->assertFalse($block['canjoin']);
        $this->assertSame('nogroup', $block['reason']);
        $this->assertSame([], $block['groups']);

        $this->setUser($teacher);
        $block = access::launch_block($cm, $context, $vrroom, null, $teacher);
        $this->assertTrue($block['canjoin']);
        $this->assertSame(0, $block['attemptid']);
        $this->assertCount(1, $block['groups']);
    }

    /**
     * Room names: the group's, or the activity's.
     */
    public function test_room_name(): void {
        $this->resetAfterTest();
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, SEPARATEGROUPS);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Blue team']);
        $this->assertSame('Blue team', access::room_name($cm, $context, $vrroom, (int)$group->id));
        $this->assertSame(format_string($vrroom->name), access::room_name($cm, $context, $vrroom, 0));
    }

    /**
     * The mod_vrroom generator.
     *
     * @return \mod_vrroom_generator
     */
    private function vrroom_generator(): \mod_vrroom_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
    }

    /**
     * Create an activity with live mode on.
     *
     * @param \stdClass|null $course Course, or null for a new one.
     * @param int $groupmode Group mode of the activity.
     * @param int $maxusers Room size.
     * @return array [cm_info, context_module, vrroom row, course]
     */
    private function create_activity(?\stdClass $course = null, int $groupmode = NOGROUPS, int $maxusers = 6): array {
        global $DB;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $DB->update_record('vrroom', (object)['id' => $record->id, 'livemode' => 1, 'livemaxusers' => $maxusers]);
        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $record->cmid]);
        rebuild_course_cache((int)$course->id, true);
        $cm = get_fast_modinfo($course->id)->get_cm($record->cmid);
        $vrroom = $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST);
        return [$cm, \context_module::instance($cm->id), $vrroom, $course];
    }
}
