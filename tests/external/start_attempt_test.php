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

namespace mod_mavr\external;

use core_external\external_api;
use mod_mavr\local\token;

/**
 * Tests of mod_mavr_start_attempt and mod_mavr_get_preview.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(start_attempt::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_preview::class)]
final class start_attempt_test extends \advanced_testcase {
    /**
     * Course with an activity, a student and an editing teacher.
     *
     * @param array $record extra module fields
     * @return \stdClass course, module, student, teacher
     */
    private function setup_activity(array $record = []): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', $record + ['course' => $course->id]);
        return (object) [
            'course' => $course,
            'module' => $module,
            'student' => $generator->create_and_enrol($course, 'student'),
            'teacher' => $generator->create_and_enrol($course, 'editingteacher'),
        ];
    }

    /**
     * A student starts, gets an inline launch with the §4.5 config, and resumes on the next call.
     *
     * @return void
     */
    public function test_start_and_resume(): void {
        global $CFG;
        $fx = $this->setup_activity();
        $this->setUser($fx->student);

        $result = external_api::clean_returnvalue(
            start_attempt::execute_returns(),
            start_attempt::execute((int)$fx->module->cmid)
        );
        $this->assertFalse($result['resumed']);
        $this->assertSame(1, $result['attempt']);

        $descriptor = json_decode($result['launchjson'], true);
        $this->assertSame('inline', $descriptor['type']);
        $config = $descriptor['config'];
        $this->assertSame('mavr-launch/1', $config['schema']);
        $this->assertFalse($config['preview']);
        $this->assertSame((int)$fx->module->cmid, $config['cmid']);
        $this->assertSame($result['attemptid'], $config['attemptid']);
        $this->assertSame((int)$fx->student->id, $config['userid']);
        $this->assertSame($CFG->wwwroot, $config['actor']['account']['homePage']);
        $this->assertSame((string)$fx->student->id, $config['actor']['account']['name']);
        $this->assertSame($CFG->wwwroot . '/mod/mavr/xapi/' . $fx->module->cmid, $config['activityiri']);
        $this->assertSame('mavr-resume', $config['resume']['stateid']);
        $this->assertNotEmpty($config['scenes']);
        $this->assertSame($config['scenes'][0]['id'], $config['startscene']);
        $this->assertSame('mavr-graph/1', $config['graph']['schema']);
        $this->assertSame($result['attemptid'], $config['state']['attemptid']);
        foreach ($config['scenes'][0]['interactables'] as $item) {
            if ($item['kind'] === 'hotspot') {
                $this->assertArrayHasKey('html', $item['content']);
                $this->assertArrayNotHasKey('text', $item['content']);
            }
        }

        $claims = token::verify($result['token']);
        $this->assertSame((int)$fx->student->id, $claims['userid']);
        $this->assertSame((int)$fx->module->cmid, $claims['cmid']);
        $this->assertSame($result['attemptid'], $claims['attemptid']);

        $again = external_api::clean_returnvalue(
            start_attempt::execute_returns(),
            start_attempt::execute((int)$fx->module->cmid)
        );
        $this->assertTrue($again['resumed']);
        $this->assertSame($result['attemptid'], $again['attemptid']);
    }

    /**
     * Only users with mod/mavr:attempt can start; users outside the course cannot reach it.
     *
     * @return void
     */
    public function test_capability_and_enrolment(): void {
        $fx = $this->setup_activity();

        $this->setUser($fx->teacher);
        try {
            start_attempt::execute((int)$fx->module->cmid);
            $this->fail('A teacher has no attempt capability');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        start_attempt::execute((int)$fx->module->cmid);
    }

    /**
     * The attempt limit is reported as errornomoreattempts.
     *
     * @return void
     */
    public function test_no_more_attempts(): void {
        $fx = $this->setup_activity(['maxattempts' => 1]);
        $this->setUser($fx->student);
        $result = start_attempt::execute((int)$fx->module->cmid);
        finish_attempt::execute($result['attemptid']);
        try {
            start_attempt::execute((int)$fx->module->cmid);
            $this->fail('No second attempt');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornomoreattempts', $e->errorcode);
        }
    }

    /**
     * A teacher previews without an attempt; a student cannot preview.
     *
     * @return void
     */
    public function test_preview(): void {
        global $DB;
        $fx = $this->setup_activity();

        $this->setUser($fx->teacher);
        $result = external_api::clean_returnvalue(
            get_preview::execute_returns(),
            get_preview::execute((int)$fx->module->cmid)
        );
        $config = json_decode($result['launchjson'], true)['config'];
        $this->assertTrue($config['preview']);
        $this->assertSame(0, $config['attemptid']);
        $this->assertSame([], $config['events']);
        $this->assertSame([], $config['hints']);
        $this->assertSame(0, $config['state']['timedue']);
        foreach ($config['questions'] as $question) {
            $this->assertFalse($question['available']);
        }
        $this->assertSame(0, $DB->count_records('mavr_attempts'));

        $this->setUser($fx->student);
        $this->expectException(\required_capability_exception::class);
        get_preview::execute((int)$fx->module->cmid);
    }
}
