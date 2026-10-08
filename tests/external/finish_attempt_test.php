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

namespace mod_vrroom\external;

use core_external\external_api;

/**
 * Tests of mod_vrroom_finish_attempt.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(finish_attempt::class)]
final class finish_attempt_test extends \advanced_testcase {
    /**
     * The owner finishes once; the grade reaches the gradebook; others and repeats are refused.
     *
     * @return void
     */
    public function test_finish(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');

        $this->setUser($student);
        $attemptid = (int)start_attempt::execute((int)$module->cmid)['attemptid'];

        $this->setUser($other);
        try {
            finish_attempt::execute($attemptid);
            $this->fail('Another student must not finish the attempt');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotyourattempt', $e->errorcode);
        }
        $this->assertSame('inprogress', $DB->get_field('vrroom_attempts', 'state', ['id' => $attemptid]));

        $this->setUser($student);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), finish_attempt::execute($attemptid));
        $this->assertSame($attemptid, $result['attemptid']);
        $this->assertSame('finished', $result['state']);
        $this->assertEqualsWithDelta(0.0, $result['score'], 1e-6);
        $this->assertSame(
            (new \moodle_url('/mod/vrroom/view.php', ['id' => $module->cmid]))->out(false),
            $result['redirecturl']
        );

        $grades = grade_get_grades($course->id, 'mod', 'vrroom', $module->id, (int)$student->id);
        $this->assertEqualsWithDelta(0.0, (float)$grades->items[0]->grades[$student->id]->grade, 1e-6);

        try {
            finish_attempt::execute($attemptid);
            $this->fail('A finished attempt cannot be finished again');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptclosed', $e->errorcode);
        }
    }
}
