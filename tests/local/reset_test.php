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

namespace mod_mavr\local;

use mod_mavr\local\attempt\attempt_manager;

/**
 * Tests for the MAVR course reset.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(reset::class)]
final class reset_test extends \advanced_testcase {

    /**
     * A course with one gallery walk and a student who finished an attempt with a grade.
     *
     * @return array [course, mavr row, student, attempt, context]
     */
    private function create_graded_attempt(): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $module = $generator->create_module('mavr', ['course' => $course->id, 'stationtitles' => 'Vase']);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance($module->cmid);

        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');
        $attempt = $mavrgen->create_attempt((int)$mavr->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $mavrgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $attempt = attempt_manager::finish($attempt);

        $DB->insert_record('xapi_states', (object)[
            'component' => 'mod_mavr', 'userid' => $student->id, 'itemid' => $context->id,
            'stateid' => 'mavr-resume', 'statedata' => '{}', 'registration' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        return [$course, $mavr, $student, $attempt, $context];
    }

    /**
     * The user's grade in the gradebook, or null.
     *
     * @param \stdClass $mavr mavr row
     * @param int $userid user id
     * @return float|null
     */
    private function gradebook_grade(\stdClass $mavr, int $userid): ?float {
        $grades = grade_get_grades($mavr->course, 'mod', 'mavr', $mavr->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }

    /**
     * The reset form element and its default.
     */
    public function test_course_form_defaults(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/mavr/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame(['reset_mavr_attempts' => 1], reset::course_form_defaults($course));
        $this->assertSame(['reset_mavr_attempts' => 1], mavr_reset_course_form_defaults($course));
    }

    /**
     * A course reset with the MAVR option deletes attempts, events, usages, states and grades.
     */
    public function test_reset_course_userdata_deletes_attempts_and_grades(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $mavr, $student, $attempt, $context] = $this->create_graded_attempt();

        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($mavr, (int)$student->id), 1e-5);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));

        $status = reset_course_userdata((object)['id' => $course->id, 'reset_mavr_attempts' => 1]);

        $this->assertEquals(0, $DB->count_records('mavr_attempts', ['mavrid' => $mavr->id]));
        $this->assertEquals(0, $DB->count_records('mavr_events', ['attemptid' => $attempt->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertEquals(0, $DB->count_records('xapi_states', ['component' => 'mod_mavr', 'itemid' => $context->id]));
        $this->assertNull($this->gradebook_grade($mavr, (int)$student->id));

        $items = array_filter($status, fn($row) => $row['component'] === get_string('modulenameplural', 'mod_mavr'));
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame(get_string('attemptsdeleted', 'mod_mavr'), $item['item']);
        $this->assertFalse($item['error']);

        // The activity itself is untouched and can be attempted again from number 1.
        $this->assertTrue($DB->record_exists('mavr', ['id' => $mavr->id]));
        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $this->getDataGenerator()->get_plugin_generator('mod_mavr');
        $this->assertEquals(1, $mavrgen->create_attempt((int)$mavr->id, (int)$student->id)->attempt);
    }

    /**
     * Without the MAVR option nothing is deleted.
     */
    public function test_reset_without_option_keeps_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $mavr] = $this->create_graded_attempt();

        $status = reset::reset_userdata((object)['courseid' => $course->id, 'reset_mavr_attempts' => 0]);

        $this->assertSame([], $status);
        $this->assertEquals(1, $DB->count_records('mavr_attempts', ['mavrid' => $mavr->id]));
    }

    /**
     * Resetting the gradebook clears the grades but keeps the attempts.
     */
    public function test_reset_gradebook(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $mavr, $student] = $this->create_graded_attempt();

        reset::reset_gradebook((int)$course->id);

        $this->assertNull($this->gradebook_grade($mavr, (int)$student->id));
        $this->assertEquals(1, $DB->count_records('mavr_attempts', ['mavrid' => $mavr->id]));
    }
}
