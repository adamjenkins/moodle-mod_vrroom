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

namespace mod_vrroom\local\grading;

/**
 * Tests of the grader.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grader::class)]
final class grader_test extends \advanced_testcase {
    /** @var int Next fake question usage id (vrroom_attempts.uniqueid is unique). */
    private int $nextusage = 900000;

    /**
     * Create a course, an activity and a student.
     *
     * @param array $record extra module fields
     * @return array [course, vrroom row, student]
     */
    private function setup_activity(array $record = []): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('vrroom', $record + ['course' => $course->id]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $vrroom, $student];
    }

    /**
     * Insert an attempt row directly.
     *
     * @param \stdClass $vrroom
     * @param int $userid
     * @param int $number attempt number
     * @param float|null $score
     * @param string $state
     * @return void
     */
    private function add_attempt(\stdClass $vrroom, int $userid, int $number, ?float $score, string $state = 'finished'): void {
        global $DB;
        $DB->insert_record('vrroom_attempts', (object) [
            'vrroomid' => $vrroom->id,
            'userid' => $userid,
            'attempt' => $number,
            'state' => $state,
            'uniqueid' => $this->nextusage++,
            'slotmap' => '{}',
            'graphversion' => (int)$vrroom->graphversion,
            'score' => $score,
            'requireddone' => 0,
            'timestart' => time(),
            'timefinish' => $state === 'finished' ? time() : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Gradebook grade of a user.
     *
     * @param \stdClass $course
     * @param \stdClass $vrroom
     * @param int $userid
     * @return float|null
     */
    private function gradebook(\stdClass $course, \stdClass $vrroom, int $userid): ?float {
        $grades = grade_get_grades($course->id, 'mod', 'vrroom', $vrroom->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade;
        return $grade === null ? null : (float)$grade;
    }

    /**
     * Grading methods over finished attempts; unfinished attempts never count.
     *
     * @return void
     */
    public function test_grading_methods(): void {
        global $DB;
        [, $vrroom, $student] = $this->setup_activity();
        $userid = (int)$student->id;
        $this->add_attempt($vrroom, $userid, 1, 0.5);
        $this->add_attempt($vrroom, $userid, 2, 0.9);
        $this->add_attempt($vrroom, $userid, 3, 0.4);
        $this->add_attempt($vrroom, $userid, 4, 1.0, 'inprogress');

        $vrroom->grademethod = grader::GRADE_HIGHEST;
        $this->assertEqualsWithDelta(0.9, grader::calculate_user_score($vrroom, $userid), 1e-6);
        $vrroom->grademethod = grader::GRADE_AVERAGE;
        $this->assertEqualsWithDelta(0.6, grader::calculate_user_score($vrroom, $userid), 1e-6);
        $vrroom->grademethod = grader::GRADE_LAST;
        $this->assertEqualsWithDelta(0.4, grader::calculate_user_score($vrroom, $userid), 1e-6);

        $other = $this->getDataGenerator()->create_user();
        $this->assertNull(grader::calculate_user_score($vrroom, (int)$other->id));

        $vrroom->grade = 80;
        $grades = grader::get_user_grades($vrroom, $userid);
        $this->assertSame([$userid], array_keys($grades));
        $this->assertEqualsWithDelta(32.0, $grades[$userid]->rawgrade, 1e-6);
        $this->assertCount(3, $DB->get_records('vrroom_attempts', ['vrroomid' => $vrroom->id, 'state' => 'finished']));
    }

    /**
     * update_grades writes the gradebook, and nullifnone clears a user without grades.
     *
     * @return void
     */
    public function test_update_grades_and_nullifnone(): void {
        global $DB;
        [$course, $vrroom, $student] = $this->setup_activity();
        $userid = (int)$student->id;
        $this->add_attempt($vrroom, $userid, 1, 0.75);
        grader::update_grades($vrroom, $userid);
        $this->assertEqualsWithDelta(75.0, $this->gradebook($course, $vrroom, $userid), 1e-6);

        $DB->delete_records('vrroom_attempts', ['vrroomid' => $vrroom->id]);
        grader::update_grades($vrroom, $userid, false);
        $this->assertEqualsWithDelta(75.0, $this->gradebook($course, $vrroom, $userid), 1e-6);
        grader::update_grades($vrroom, $userid, true);
        $this->assertNull($this->gradebook($course, $vrroom, $userid));
    }

    /**
     * All users are graded at once.
     *
     * @return void
     */
    public function test_update_grades_for_all_users(): void {
        [$course, $vrroom, $student] = $this->setup_activity(['grademethod' => grader::GRADE_AVERAGE]);
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->add_attempt($vrroom, (int)$student->id, 1, 0.2);
        $this->add_attempt($vrroom, (int)$student->id, 2, 0.4);
        $this->add_attempt($vrroom, (int)$other->id, 1, 1.0);
        grader::update_grades($vrroom);
        $this->assertEqualsWithDelta(30.0, $this->gradebook($course, $vrroom, (int)$student->id), 1e-6);
        $this->assertEqualsWithDelta(100.0, $this->gradebook($course, $vrroom, (int)$other->id), 1e-6);
    }

    /**
     * Grade item type and maximum follow the activity; a zero grade means no grade.
     *
     * @return void
     */
    public function test_grade_item_update_and_delete(): void {
        [$course, $vrroom] = $this->setup_activity(['grade' => 40]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'itemnumber' => 0]);
        $this->assertEquals(GRADE_TYPE_VALUE, $item->gradetype);
        $this->assertEqualsWithDelta(40.0, (float)$item->grademax, 1e-6);

        $vrroom->grade = 0;
        $this->assertSame(GRADE_UPDATE_OK, grader::grade_item_update($vrroom));
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'itemnumber' => 0]);
        $this->assertEquals(GRADE_TYPE_NONE, $item->gradetype);

        $this->assertSame(GRADE_UPDATE_OK, grader::grade_item_delete($vrroom));
        $this->assertFalse(\grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'itemnumber' => 0]));
    }

    /**
     * Options and formatting.
     *
     * @return void
     */
    public function test_options_and_format(): void {
        [, $vrroom] = $this->setup_activity();
        $this->assertSame(
            [grader::GRADE_HIGHEST, grader::GRADE_AVERAGE, grader::GRADE_LAST],
            array_keys(grader::grade_options())
        );
        $this->assertSame('75.00', grader::format_grade($vrroom, 0.75));
        $this->assertSame('-', grader::format_grade($vrroom, null));
    }
}
