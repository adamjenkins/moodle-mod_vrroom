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

        $caps = $runtime->capabilities();
        $this->assertSame(1, $caps->maxusers);
        $this->assertFalse($caps->voice);
        $this->assertFalse($caps->avatars);
        $this->assertSame([], $caps->xrmodes);
        $this->assertSame(['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'], $caps->objectivetypes);

        $this->assertSame('browser', $runtime->statement_channel());
        $this->assertSame(['module' => 'mavrruntime_builtin/launch', 'function' => 'init'], $runtime->client_entry());

        // Cleanup has nothing to do and must not fail for any scope.
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
