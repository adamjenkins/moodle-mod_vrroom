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

namespace vrroomruntime_builtin;

use mod_vrroom\local\runtime\cleanup_scope;
use mod_vrroom\local\runtime\launch_descriptor;
use mod_vrroom\local\runtime\registry;

/**
 * Tests for the built-in viewer run mode.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(runtime::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\vrroomruntime_builtin\local\live\access::class)]
final class runtime_test extends \advanced_testcase {
    /**
     * The registry finds the built-in run mode under its short name.
     */
    public function test_registry_discovery(): void {
        $this->resetAfterTest();
        $runtime = registry::get('builtin');
        $this->assertInstanceOf(runtime::class, $runtime);
        $this->assertSame('builtin', $runtime->get_name());
        $this->assertSame(get_string('pluginname', 'vrroomruntime_builtin'), $runtime->get_display_name());
        $this->assertArrayHasKey('builtin', registry::get_choices());
    }

    /**
     * Readiness, capabilities, statement channel and client entry.
     */
    public function test_static_properties(): void {
        $this->resetAfterTest();
        $runtime = new runtime();

        $ready = $runtime->readiness();
        $this->assertTrue($ready->ready);

        // Live mode on (the default): small rooms with voice and avatars.
        $caps = $runtime->capabilities();
        $this->assertSame(6, $caps->maxusers);
        $this->assertTrue($caps->voice);
        $this->assertTrue($caps->avatars);
        $this->assertSame(['vr', 'ar', 'mr'], $caps->xrmodes);
        $this->assertSame(['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'], $caps->objectivetypes);
        $this->assertTrue($caps->multirooms);

        // Live mode off for the site: one user.
        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        $caps = $runtime->capabilities();
        $this->assertSame(1, $caps->maxusers);
        $this->assertFalse($caps->voice);
        $this->assertFalse($caps->avatars);
        $this->assertFalse($caps->multirooms);
        set_config('liveenabled', 1, 'vrroomruntime_builtin');

        $this->assertSame('browser', $runtime->statement_channel());
        $this->assertSame(['module' => 'vrroomruntime_builtin/launch', 'function' => 'init'], $runtime->client_entry());

        // Cleanup with nothing stored must not fail for any scope.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::COURSE_RESET, 2));
        $runtime->cleanup(new cleanup_scope(cleanup_scope::INSTANCE_DELETE, 2, 3));
        $runtime->cleanup(new cleanup_scope(cleanup_scope::USER_DELETE, 0, 0, 4));
    }

    /**
     * An attempt launch is inline and carries the launch config.
     */
    public function test_launch_inline_config(): void {
        $this->resetAfterTest();
        [$cm, $vrroom, $student] = $this->create_activity();
        $this->setUser($student);

        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $attempt = $generator->create_attempt((int)$vrroom->id, (int)$student->id);

        $runtime = registry::get('builtin');
        $descriptor = $runtime->launch($cm, $attempt, $student);

        $this->assertSame(launch_descriptor::TYPE_INLINE, $descriptor->type);
        $this->assertNull($descriptor->url);
        $config = $descriptor->config;
        foreach (
            ['schema', 'preview', 'cmid', 'contextid', 'courseid', 'userid', 'attemptid', 'attemptnumber',
                'activityiri', 'actor', 'extbase', 'verbs', 'maxbatch', 'startscene', 'scenes', 'graph', 'state',
                'events', 'questions', 'resume', 'finishurl', 'look'] as $key
        ) {
            $this->assertArrayHasKey($key, $config, "Launch config key {$key}");
        }
        $this->assertSame('vrroom-launch/1', $config['schema']);
        $this->assertFalse($config['preview']);
        $this->assertEquals($cm->id, $config['cmid']);
        $this->assertEquals($attempt->id, $config['attemptid']);
        $this->assertEquals($student->id, $config['userid']);
        $this->assertNotEmpty($config['scenes']);
        $this->assertSame('vrroom-graph/1', $config['graph']['schema']);
        $this->assertSame(['invertx' => false, 'inverty' => false, 'locky' => false], $config['look']);
        set_user_preference('mod_vrroom_inverty', 1, $student);
        set_user_preference('mod_vrroom_locky', 1, $student);
        $look = $runtime->launch($cm, $attempt, $student)->config['look'];
        $this->assertSame(['invertx' => false, 'inverty' => true, 'locky' => true], $look);

        // The live block (live mode is off in a new activity).
        $this->assertSame(['available', 'canjoin', 'reason', 'transport', 'transportmodule', 'cmid', 'attemptid',
            'maxpeers', 'groups', 'defaultgroupid', 'selfname', 'roomlist', 'roommode', 'maxrooms',
            'video'], array_keys($config['live']));
        $this->assertFalse($config['live']['available']);
        $this->assertFalse($config['live']['roomlist']);
        $this->assertSame((int)$attempt->id, $config['live']['attemptid']);

        // The descriptor serialises the way start_attempt sends it.
        $array = $descriptor->to_array();
        $this->assertSame('inline', $array['type']);
        $this->assertArrayHasKey('config', $array);
        $this->assertArrayHasKey('url', $array);
        $decoded = json_decode(json_encode($array), true);
        $this->assertSame($config['attemptid'], $decoded['config']['attemptid']);
    }

    /**
     * A preview launch has no attempt and no events.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        [$cm, , , $teacher] = $this->create_activity();
        $this->setUser($teacher);

        $descriptor = registry::get('builtin')->preview($cm, $teacher);
        $this->assertSame(launch_descriptor::TYPE_INLINE, $descriptor->type);
        $this->assertTrue($descriptor->config['preview']);
        $this->assertEquals(0, $descriptor->config['attemptid']);
        $this->assertSame([], $descriptor->config['events']);
        $this->assertArrayHasKey('live', $descriptor->config);
        $this->assertSame(0, $descriptor->config['live']['attemptid']);
    }

    /**
     * With live mode on, attempt and preview launches may join.
     */
    public function test_launch_live_block(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $vrroom, $student, $teacher] = $this->create_activity();
        $DB->update_record('vrroom', (object)['id' => $vrroom->id, 'livemode' => 2, 'livemaxusers' => 3, 'liverooms' => 4]);

        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $attempt = $generator->create_attempt((int)$vrroom->id, (int)$student->id);

        $this->setUser($student);
        $live = registry::get('builtin')->launch($cm, $attempt, $student)->config['live'];
        $this->assertTrue($live['available']);
        $this->assertTrue($live['canjoin']);
        $this->assertSame('mesh', $live['transport']);
        $this->assertSame((int)$cm->id, $live['cmid']);
        $this->assertSame(3, $live['maxpeers']);
        $this->assertSame([], $live['groups']);
        $this->assertSame(fullname($student), $live['selfname']);
        $this->assertTrue($live['roomlist']);
        $this->assertSame('dynamic', $live['roommode']);
        $this->assertSame(4, $live['maxrooms']);

        $this->setUser($teacher);
        $live = registry::get('builtin')->preview($cm, $teacher)->config['live'];
        $this->assertTrue($live['canjoin']);
        $this->assertSame(0, $live['attemptid']);
    }

    /**
     * cleanup() removes the live rows of exactly its scope.
     */
    public function test_cleanup_live_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $two = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $three = $this->getDataGenerator()->create_module('vrroom', ['course' => $othercourse->id]);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        /** @var \vrroomruntime_builtin_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('vrroomruntime_builtin');
        $peers = [];
        foreach ([$one, $two, $three] as $vrroom) {
            $mine = $generator->create_peer(['vrroomid' => $vrroom->id, 'userid' => $user->id]);
            $theirs = $generator->create_peer(['vrroomid' => $vrroom->id, 'userid' => $other->id]);
            $generator->create_signal(['vrroomid' => $vrroom->id, 'frompeer' => $mine->peerid, 'topeer' => $theirs->peerid]);
            $generator->create_signal(['vrroomid' => $vrroom->id, 'frompeer' => $theirs->peerid, 'topeer' => $mine->peerid]);
            $peers[$vrroom->id] = [$mine, $theirs];
        }
        $runtime = new runtime();
        $count = fn(int $vrroomid, string $table) =>
            $DB->count_records('vrroomruntime_builtin_' . $table, ['vrroomid' => $vrroomid]);

        // A user's data in one activity: their presence and every signal from or to it.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::USER_DELETE, 0, (int)$one->id, (int)$user->id));
        $this->assertSame(1, $count((int)$one->id, 'peers'));
        $this->assertSame(0, $count((int)$one->id, 'signals'));
        $this->assertSame(2, $count((int)$two->id, 'peers'));

        // A user's data everywhere.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::USER_DELETE, 0, 0, (int)$user->id));
        $this->assertSame(1, $count((int)$two->id, 'peers'));
        $this->assertSame(0, $count((int)$two->id, 'signals'));
        $this->assertSame(1, $count((int)$three->id, 'peers'));

        // One activity deleted.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::INSTANCE_DELETE, (int)$course->id, (int)$one->id));
        $this->assertSame(0, $count((int)$one->id, 'peers'));
        $this->assertSame(1, $count((int)$two->id, 'peers'));

        // A course reset without an activity: every activity of the course, not the other course.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::COURSE_RESET, (int)$course->id));
        $this->assertSame(0, $count((int)$two->id, 'peers'));
        $this->assertSame(1, $count((int)$three->id, 'peers'));

        // A course reset of one activity.
        $runtime->cleanup(new cleanup_scope(cleanup_scope::COURSE_RESET, (int)$othercourse->id, (int)$three->id));
        $this->assertSame(0, $count((int)$three->id, 'peers'));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals'));
    }

    /**
     * Create a course with a VR room activity, a student and a teacher.
     *
     * @return array [cm_info, vrroom record, student, teacher]
     */
    private function create_activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($vrroom->cmid);
        return [$cm, $vrroom, $student, $teacher];
    }
}
