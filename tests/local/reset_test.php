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

use mod_vrroom\local\attempt\attempt_manager;

/**
 * Tests for the VR room course reset.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(reset::class)]
final class reset_test extends \advanced_testcase {
    /**
     * A course with one gallery walk and a student who finished an attempt with a grade.
     *
     * @return array [course, vrroom row, student, attempt, context]
     */
    private function create_graded_attempt(): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $module = $generator->create_module('vrroom', ['course' => $course->id, 'stationtitles' => 'Vase']);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance($module->cmid);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $vrroomgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $attempt = attempt_manager::finish($attempt);

        $DB->insert_record('xapi_states', (object)[
            'component' => 'mod_vrroom', 'userid' => $student->id, 'itemid' => $context->id,
            'stateid' => 'vrroom-resume', 'statedata' => '{}', 'registration' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        return [$course, $vrroom, $student, $attempt, $context];
    }

    /**
     * The user's grade in the gradebook, or null.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int $userid user id
     * @return float|null
     */
    private function gradebook_grade(\stdClass $vrroom, int $userid): ?float {
        $grades = grade_get_grades($vrroom->course, 'mod', 'vrroom', $vrroom->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }

    /**
     * The reset form element and its default.
     */
    public function test_course_form_defaults(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/vrroom/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame(['reset_vrroom_attempts' => 1], reset::course_form_defaults($course));
        $this->assertSame(['reset_vrroom_attempts' => 1], vrroom_reset_course_form_defaults($course));
    }

    /**
     * A course reset with the VR room option deletes attempts, events, usages, states and grades.
     */
    public function test_reset_course_userdata_deletes_attempts_and_grades(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $vrroom, $student, $attempt, $context] = $this->create_graded_attempt();

        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($vrroom, (int)$student->id), 1e-5);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));

        $status = reset_course_userdata((object)['id' => $course->id, 'reset_vrroom_attempts' => 1]);

        $this->assertEquals(0, $DB->count_records('vrroom_attempts', ['vrroomid' => $vrroom->id]));
        $this->assertEquals(0, $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertEquals(0, $DB->count_records('xapi_states', ['component' => 'mod_vrroom', 'itemid' => $context->id]));
        $this->assertNull($this->gradebook_grade($vrroom, (int)$student->id));

        $items = array_filter($status, fn($row) => $row['component'] === get_string('modulenameplural', 'mod_vrroom'));
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame(get_string('attemptsdeleted', 'mod_vrroom'), $item['item']);
        $this->assertFalse($item['error']);

        // The activity itself is untouched and can be attempted again from number 1.
        $this->assertTrue($DB->record_exists('vrroom', ['id' => $vrroom->id]));
        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $this->assertEquals(1, $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id)->attempt);
    }

    /**
     * A course reset removes the live room presences and messages of the course's activities
     * (through the built-in run mode's cleanup()); other courses keep theirs.
     */
    public function test_reset_removes_live_rows_of_the_course_only(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $vrroom, $student] = $this->create_graded_attempt();
        $generator = $this->getDataGenerator();
        $othercourse = $generator->create_course();
        $otherstudent = $generator->create_and_enrol($othercourse, 'student');
        $othervrroom = $generator->create_module('vrroom', ['course' => $othercourse->id, 'livemode' => 1]);

        /** @var \vrroomruntime_builtin_generator $livegen */
        $livegen = $generator->get_plugin_generator('vrroomruntime_builtin');
        $peer = $livegen->create_peer(['vrroomid' => $vrroom->id, 'userid' => $student->id]);
        $livegen->create_signal(['vrroomid' => $vrroom->id, 'frompeer' => $peer->peerid, 'topeer' => str_repeat('e', 32)]);
        $otherpeer = $livegen->create_peer(['vrroomid' => $othervrroom->id, 'userid' => $otherstudent->id]);
        $livegen->create_signal(['vrroomid' => $othervrroom->id, 'frompeer' => $otherpeer->peerid,
            'topeer' => str_repeat('f', 32)]);

        reset_course_userdata((object)['id' => $course->id, 'reset_vrroom_attempts' => 1]);

        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $vrroom->id]));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $vrroom->id]));
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $othervrroom->id]));
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $othervrroom->id]));
    }

    /**
     * Without the VR room option nothing is deleted.
     */
    public function test_reset_without_option_keeps_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $vrroom] = $this->create_graded_attempt();

        $status = reset::reset_userdata((object)['courseid' => $course->id, 'reset_vrroom_attempts' => 0]);

        $this->assertSame([], $status);
        $this->assertEquals(1, $DB->count_records('vrroom_attempts', ['vrroomid' => $vrroom->id]));
    }

    /**
     * Resetting the gradebook clears the grades but keeps the attempts.
     */
    public function test_reset_gradebook(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $vrroom, $student] = $this->create_graded_attempt();

        reset::reset_gradebook((int)$course->id);

        $this->assertNull($this->gradebook_grade($vrroom, (int)$student->id));
        $this->assertEquals(1, $DB->count_records('vrroom_attempts', ['vrroomid' => $vrroom->id]));
    }
}
