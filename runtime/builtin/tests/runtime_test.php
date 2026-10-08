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

namespace mavrruntime_builtin;

use mod_mavr\local\runtime\cleanup_scope;
use mod_mavr\local\runtime\launch_descriptor;
use mod_mavr\local\runtime\registry;

/**
 * Tests for the built-in viewer run mode.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(runtime::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mavrruntime_builtin\local\live\access::class)]
final class runtime_test extends \advanced_testcase {
    /**
     * The registry finds the built-in run mode under its short name.
     */
    public function test_registry_discovery(): void {
        $this->resetAfterTest();
        $runtime = registry::get('builtin');
        $this->assertInstanceOf(runtime::class, $runtime);
        $this->assertSame('builtin', $runtime->get_name());
        $this->assertSame(get_string('pluginname', 'mavrruntime_builtin'), $runtime->get_display_name());
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

        // Live mode off for the site: one user.
        set_config('liveenabled', 0, 'mavrruntime_builtin');
        $caps = $runtime->capabilities();
        $this->assertSame(1, $caps->maxusers);
        $this->assertFalse($caps->voice);
        $this->assertFalse($caps->avatars);
        set_config('liveenabled', 1, 'mavrruntime_builtin');

        $this->assertSame('browser', $runtime->statement_channel());
        $this->assertSame(['module' => 'mavrruntime_builtin/launch', 'function' => 'init'], $runtime->client_entry());

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
        [$cm, $mavr, $student] = $this->create_activity();
        $this->setUser($student);

        /** @var \mod_mavr_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_mavr');
        $attempt = $generator->create_attempt((int)$mavr->id, (int)$student->id);

        $runtime = registry::get('builtin');
        $descriptor = $runtime->launch($cm, $attempt, $student);

        $this->assertSame(launch_descriptor::TYPE_INLINE, $descriptor->type);
        $this->assertNull($descriptor->url);
        $config = $descriptor->config;
        foreach (['schema', 'preview', 'cmid', 'contextid', 'courseid', 'userid', 'attemptid', 'attemptnumber',
                'activityiri', 'actor', 'extbase', 'verbs', 'maxbatch', 'startscene', 'scenes', 'graph', 'state',
                'events', 'questions', 'resume', 'finishurl'] as $key) {
            $this->assertArrayHasKey($key, $config, "Launch config key {$key}");
        }
        $this->assertSame('mavr-launch/1', $config['schema']);
        $this->assertFalse($config['preview']);
        $this->assertEquals($cm->id, $config['cmid']);
        $this->assertEquals($attempt->id, $config['attemptid']);
        $this->assertEquals($student->id, $config['userid']);
        $this->assertNotEmpty($config['scenes']);
        $this->assertSame('mavr-graph/1', $config['graph']['schema']);

        // The live block (live mode is off in a new activity).
        $this->assertSame(['available', 'canjoin', 'reason', 'transport', 'transportmodule', 'cmid', 'attemptid',
            'maxpeers', 'groups', 'defaultgroupid', 'selfname'], array_keys($config['live']));
        $this->assertFalse($config['live']['available']);
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
        [$cm, $mavr, $student, $teacher] = $this->create_activity();
        $DB->update_record('mavr', (object)['id' => $mavr->id, 'livemode' => 1, 'livemaxusers' => 3]);

        /** @var \mod_mavr_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_mavr');
        $attempt = $generator->create_attempt((int)$mavr->id, (int)$student->id);

        $this->setUser($student);
        $live = registry::get('builtin')->launch($cm, $attempt, $student)->config['live'];
        $this->assertTrue($live['available']);
        $this->assertTrue($live['canjoin']);
        $this->assertSame('mesh', $live['transport']);
        $this->assertSame((int)$cm->id, $live['cmid']);
        $this->assertSame(3, $live['maxpeers']);
        $this->assertSame([], $live['groups']);
        $this->assertSame(fullname($student), $live['selfname']);

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
        $one = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $two = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $three = $this->getDataGenerator()->create_module('mavr', ['course' => $othercourse->id]);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        /** @var \mavrruntime_builtin_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mavrruntime_builtin');
        $peers = [];
        foreach ([$one, $two, $three] as $mavr) {
            $mine = $generator->create_peer(['mavrid' => $mavr->id, 'userid' => $user->id]);
            $theirs = $generator->create_peer(['mavrid' => $mavr->id, 'userid' => $other->id]);
            $generator->create_signal(['mavrid' => $mavr->id, 'frompeer' => $mine->peerid, 'topeer' => $theirs->peerid]);
            $generator->create_signal(['mavrid' => $mavr->id, 'frompeer' => $theirs->peerid, 'topeer' => $mine->peerid]);
            $peers[$mavr->id] = [$mine, $theirs];
        }
        $runtime = new runtime();
        $count = fn(int $mavrid, string $table) => $DB->count_records('mavrruntime_builtin_' . $table, ['mavrid' => $mavrid]);

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
        $this->assertSame(0, $DB->count_records('mavrruntime_builtin_signals'));
    }

    /**
     * Create a course with a MAVR activity, a student and a teacher.
     *
     * @return array [cm_info, mavr record, student, teacher]
     */
    private function create_activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($mavr->cmid);
        return [$cm, $mavr, $student, $teacher];
    }
}
