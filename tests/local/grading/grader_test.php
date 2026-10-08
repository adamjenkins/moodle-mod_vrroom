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

namespace mod_mavr\local\grading;

/**
 * Tests of the grader.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grader::class)]
final class grader_test extends \advanced_testcase {

    /** @var int Next fake question usage id (mavr_attempts.uniqueid is unique). */
    private int $nextusage = 900000;

    /**
     * Create a course, an activity and a student.
     *
     * @param array $record extra module fields
     * @return array [course, mavr row, student]
     */
    private function setup_activity(array $record = []): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('mavr', $record + ['course' => $course->id]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $mavr, $student];
    }

    /**
     * Insert an attempt row directly.
     *
     * @param \stdClass $mavr
     * @param int $userid
     * @param int $number attempt number
     * @param float|null $score
     * @param string $state
     * @return void
     */
    private function add_attempt(\stdClass $mavr, int $userid, int $number, ?float $score, string $state = 'finished'): void {
        global $DB;
        $DB->insert_record('mavr_attempts', (object) [
            'mavrid' => $mavr->id,
            'userid' => $userid,
            'attempt' => $number,
            'state' => $state,
            'uniqueid' => $this->nextusage++,
            'slotmap' => '{}',
            'graphversion' => (int)$mavr->graphversion,
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
     * @param \stdClass $mavr
     * @param int $userid
     * @return float|null
     */
    private function gradebook(\stdClass $course, \stdClass $mavr, int $userid): ?float {
        $grades = grade_get_grades($course->id, 'mod', 'mavr', $mavr->id, $userid);
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
        [, $mavr, $student] = $this->setup_activity();
        $userid = (int)$student->id;
        $this->add_attempt($mavr, $userid, 1, 0.5);
        $this->add_attempt($mavr, $userid, 2, 0.9);
        $this->add_attempt($mavr, $userid, 3, 0.4);
        $this->add_attempt($mavr, $userid, 4, 1.0, 'inprogress');

        $mavr->grademethod = grader::GRADE_HIGHEST;
        $this->assertEqualsWithDelta(0.9, grader::calculate_user_score($mavr, $userid), 1e-6);
        $mavr->grademethod = grader::GRADE_AVERAGE;
        $this->assertEqualsWithDelta(0.6, grader::calculate_user_score($mavr, $userid), 1e-6);
        $mavr->grademethod = grader::GRADE_LAST;
        $this->assertEqualsWithDelta(0.4, grader::calculate_user_score($mavr, $userid), 1e-6);

        $other = $this->getDataGenerator()->create_user();
        $this->assertNull(grader::calculate_user_score($mavr, (int)$other->id));

        $mavr->grade = 80;
        $grades = grader::get_user_grades($mavr, $userid);
        $this->assertSame([$userid], array_keys($grades));
        $this->assertEqualsWithDelta(32.0, $grades[$userid]->rawgrade, 1e-6);
        $this->assertCount(3, $DB->get_records('mavr_attempts', ['mavrid' => $mavr->id, 'state' => 'finished']));
    }

    /**
     * update_grades writes the gradebook, and nullifnone clears a user without grades.
     *
     * @return void
     */
    public function test_update_grades_and_nullifnone(): void {
        global $DB;
        [$course, $mavr, $student] = $this->setup_activity();
        $userid = (int)$student->id;
        $this->add_attempt($mavr, $userid, 1, 0.75);
        grader::update_grades($mavr, $userid);
        $this->assertEqualsWithDelta(75.0, $this->gradebook($course, $mavr, $userid), 1e-6);

        $DB->delete_records('mavr_attempts', ['mavrid' => $mavr->id]);
        grader::update_grades($mavr, $userid, false);
        $this->assertEqualsWithDelta(75.0, $this->gradebook($course, $mavr, $userid), 1e-6);
        grader::update_grades($mavr, $userid, true);
        $this->assertNull($this->gradebook($course, $mavr, $userid));
    }

    /**
     * All users are graded at once.
     *
     * @return void
     */
    public function test_update_grades_for_all_users(): void {
        [$course, $mavr, $student] = $this->setup_activity(['grademethod' => grader::GRADE_AVERAGE]);
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->add_attempt($mavr, (int)$student->id, 1, 0.2);
        $this->add_attempt($mavr, (int)$student->id, 2, 0.4);
        $this->add_attempt($mavr, (int)$other->id, 1, 1.0);
        grader::update_grades($mavr);
        $this->assertEqualsWithDelta(30.0, $this->gradebook($course, $mavr, (int)$student->id), 1e-6);
        $this->assertEqualsWithDelta(100.0, $this->gradebook($course, $mavr, (int)$other->id), 1e-6);
    }

    /**
     * Grade item type and maximum follow the activity; a zero grade means no grade.
     *
     * @return void
     */
    public function test_grade_item_update_and_delete(): void {
        [$course, $mavr] = $this->setup_activity(['grade' => 40]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'itemnumber' => 0]);
        $this->assertEquals(GRADE_TYPE_VALUE, $item->gradetype);
        $this->assertEqualsWithDelta(40.0, (float)$item->grademax, 1e-6);

        $mavr->grade = 0;
        $this->assertSame(GRADE_UPDATE_OK, grader::grade_item_update($mavr));
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'itemnumber' => 0]);
        $this->assertEquals(GRADE_TYPE_NONE, $item->gradetype);

        $this->assertSame(GRADE_UPDATE_OK, grader::grade_item_delete($mavr));
        $this->assertFalse(\grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'itemnumber' => 0]));
    }

    /**
     * Options and formatting.
     *
     * @return void
     */
    public function test_options_and_format(): void {
        [, $mavr] = $this->setup_activity();
        $this->assertSame([grader::GRADE_HIGHEST, grader::GRADE_AVERAGE, grader::GRADE_LAST],
            array_keys(grader::grade_options()));
        $this->assertSame('75.00', grader::format_grade($mavr, 0.75));
        $this->assertSame('-', grader::format_grade($mavr, null));
    }
}
