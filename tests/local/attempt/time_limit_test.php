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


namespace mod_mavr\local\attempt;

use core_external\external_api;
use mod_mavr\external\finish_attempt;
use mod_mavr\external\get_attempt_state;
use mod_mavr\external\get_question;
use mod_mavr\local\instance_manager;
use mod_mavr\task\finish_overdue_attempts;

/**
 * Tests of the attempt time limit: deadline at start, refusal after it, and the overdue task.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(finish_overdue_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_mavr\external\helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(finish_attempt::class)]
final class time_limit_test extends \advanced_testcase {

    /** @var int The time limit of the escape fixture, in seconds. */
    private const LIMIT = 900;

    /**
     * A course with an escape chain (time limit LIMIT, a question lock) and two students.
     *
     * @param int $timelimit time limit in seconds (0 = none)
     * @return \stdClass course, mavr, cm, context, student, other
     */
    private function setup_activity(int $timelimit = self::LIMIT): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'template' => 'escape',
            'puzzletitles' => 'Desk|Safe',
            'puzzlelocks' => 'none|question',
            'puzzlequestions' => '|Q1',
            'timelimit' => $timelimit,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        return (object) [
            'course' => $course,
            'mavr' => $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST),
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'student' => $generator->create_and_enrol($course, 'student'),
            'other' => $generator->create_and_enrol($course, 'student'),
        ];
    }

    /**
     * Move an attempt's deadline into the past, beyond the grace period.
     *
     * @param \stdClass $attempt attempt row (updated in place)
     * @return int the new deadline
     */
    private function make_overdue(\stdClass $attempt): int {
        global $DB;
        $deadline = time() - attempt_manager::DEADLINE_GRACE - 30;
        $DB->set_field('mavr_attempts', 'timedue', $deadline, ['id' => $attempt->id]);
        $attempt->timedue = $deadline;
        return $deadline;
    }

    /**
     * The completed-activity statement of an attempt, decoded.
     *
     * @param int $attemptid
     * @return \stdClass|null
     */
    private function completed_result(int $attemptid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('mavr_events', ['attemptid' => $attemptid, 'verb' => 'completed',
            'objecttype' => 'activity'], 'id ASC');
        return $rows ? json_decode(reset($rows)->resultjson) : null;
    }

    /**
     * A new attempt's deadline is its start plus the graph's time limit, and the state shows it.
     *
     * @return void
     */
    public function test_timedue_from_graph(): void {
        $fx = $this->setup_activity();
        $this->assertSame(self::LIMIT, instance_manager::get_graph((int)$fx->mavr->id)['timelimit']);

        $now = time();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id, $now);
        $this->assertEquals($now + self::LIMIT, $attempt->timedue);
        $this->assertEquals($now + self::LIMIT, attempt_manager::get_attempt((int)$attempt->id)->timedue);

        $state = attempt_manager::get_state(attempt_manager::get_attempt((int)$attempt->id));
        $this->assertSame($now + self::LIMIT, $state['timedue']);
        $this->assertGreaterThanOrEqual($now, $state['servertime']);
        foreach ($state['objectives'] as $objective) {
            $this->assertSame(0, $objective['hintsused']);
        }
        $this->assertFalse(attempt_manager::is_overdue($attempt, $now + self::LIMIT + attempt_manager::DEADLINE_GRACE));
        $this->assertTrue(attempt_manager::is_overdue($attempt, $now + self::LIMIT + attempt_manager::DEADLINE_GRACE + 1));
    }

    /**
     * Without a time limit the deadline is 0 and an attempt is never overdue.
     *
     * @return void
     */
    public function test_no_limit_gives_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);

        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, (int)$student->id);
        $this->assertEquals(0, $attempt->timedue);
        $this->assertFalse(attempt_manager::is_overdue($attempt, time() + 10 * YEARSECS));
        $this->assertFalse(attempt_manager::close_if_overdue($attempt, time() + 10 * YEARSECS));
        $this->assertSame(0, attempt_manager::get_state($attempt)['timedue']);
    }

    /**
     * After the deadline every attempt service fails with errorattempttimeup, and the attempt is
     * finished as of its deadline with a "timeup" completed statement.
     *
     * @return void
     */
    public function test_services_throw_timeup(): void {
        global $DB;
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $deadline = $this->make_overdue($attempt);
        $this->setUser($fx->student);

        try {
            get_question::execute((int)$attempt->id, 1);
            $this->fail('A question must not be served after the deadline');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattempttimeup', $e->errorcode);
        }

        $row = attempt_manager::get_attempt((int)$attempt->id);
        $this->assertSame(attempt_manager::STATE_FINISHED, $row->state);
        $this->assertEquals($deadline, $row->timefinish);
        $this->assertTrue($this->completed_result((int)$attempt->id)->timeup);
        $this->assertEquals(1, $DB->count_records('mavr_events', ['attemptid' => $attempt->id, 'verb' => 'completed',
            'objecttype' => 'activity']));

        // Once finished, it is an ordinary closed attempt.
        try {
            attempt_manager::require_own_open_attempt((int)$attempt->id, (int)$fx->student->id);
            $this->fail('A finished attempt is not open');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptclosed', $e->errorcode);
        }
    }

    /**
     * Another user's overdue attempt is not closed by them: ownership is checked first.
     *
     * @return void
     */
    public function test_other_user_cannot_close(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $this->make_overdue($attempt);

        try {
            attempt_manager::require_own_open_attempt((int)$attempt->id, (int)$fx->other->id);
            $this->fail('Another user must not reach the attempt');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotyourattempt', $e->errorcode);
        }
        $this->assertSame(attempt_manager::STATE_INPROGRESS, attempt_manager::get_attempt((int)$attempt->id)->state);
    }

    /**
     * get_attempt_state finishes an overdue attempt and reports it finished, with its deadline.
     *
     * @return void
     */
    public function test_get_attempt_state_reports_finished(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $deadline = $this->make_overdue($attempt);
        $this->setUser($fx->student);

        $state = external_api::clean_returnvalue(get_attempt_state::execute_returns(),
            get_attempt_state::execute((int)$attempt->id));
        $this->assertSame(attempt_manager::STATE_FINISHED, $state['state']);
        $this->assertSame($deadline, $state['timedue']);
        $this->assertArrayHasKey('servertime', $state);
        $this->assertArrayHasKey('hintsused', $state['objectives'][0]);
    }

    /**
     * finish_attempt on an overdue attempt succeeds and returns the finished attempt.
     *
     * @return void
     */
    public function test_finish_attempt_on_overdue_succeeds(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $deadline = $this->make_overdue($attempt);
        $this->setUser($fx->student);

        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(),
            finish_attempt::execute((int)$attempt->id));
        $this->assertSame((int)$attempt->id, $result['attemptid']);
        $this->assertSame(attempt_manager::STATE_FINISHED, $result['state']);
        $this->assertEquals($deadline, attempt_manager::get_attempt((int)$attempt->id)->timefinish);
        $this->assertTrue($this->completed_result((int)$attempt->id)->timeup);

        // A second call is refused as for any finished attempt.
        try {
            finish_attempt::execute((int)$attempt->id);
            $this->fail('A finished attempt cannot be finished again');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptclosed', $e->errorcode);
        }
    }

    /**
     * finish_attempt within the time is an ordinary finish, without "timeup".
     *
     * @return void
     */
    public function test_finish_attempt_in_time_has_no_timeup(): void {
        $fx = $this->setup_activity();
        [$attempt] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $this->setUser($fx->student);

        finish_attempt::execute((int)$attempt->id);
        $result = $this->completed_result((int)$attempt->id);
        $this->assertNotNull($result);
        $this->assertFalse(property_exists($result, 'timeup'));
    }

    /**
     * start_or_resume finishes the overdue attempt and starts a new one with a fresh deadline.
     *
     * @return void
     */
    public function test_start_or_resume_replaces_overdue_attempt(): void {
        $fx = $this->setup_activity();
        [$first] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $this->make_overdue($first);

        $now = time();
        [$second, $resumed] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id, $now);
        $this->assertFalse($resumed);
        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(2, $second->attempt);
        $this->assertEquals($now + self::LIMIT, $second->timedue);
        $this->assertSame(attempt_manager::STATE_FINISHED, attempt_manager::get_attempt((int)$first->id)->state);

        // An attempt still within its time is resumed.
        [$again, $resumed] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $this->assertTrue($resumed);
        $this->assertEquals($second->id, $again->id);
    }

    /**
     * The overdue attempt is finished even when no attempt is left: the limit applies after the close.
     *
     * @return void
     */
    public function test_start_or_resume_respects_max_attempts(): void {
        global $DB;
        $fx = $this->setup_activity();
        $DB->set_field('mavr', 'maxattempts', 1, ['id' => $fx->mavr->id]);
        $fx->mavr->maxattempts = 1;
        [$first] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $this->make_overdue($first);

        try {
            attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
            $this->fail('No second attempt is allowed');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornomoreattempts', $e->errorcode);
        }
        $this->assertSame(attempt_manager::STATE_FINISHED, attempt_manager::get_attempt((int)$first->id)->state);
    }

    /**
     * The scheduled task finishes overdue attempts only: not those within their time, not those
     * without a limit, and not finished ones.
     *
     * @return void
     */
    public function test_task_finishes_overdue_attempts_only(): void {
        global $DB;
        $fx = $this->setup_activity();
        $third = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');
        $fourth = $this->getDataGenerator()->create_and_enrol($fx->course, 'student');

        [$overdue] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->student->id);
        $deadline = $this->make_overdue($overdue);
        [$intime] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fx->other->id);
        [$nolimit] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$third->id);
        $DB->set_field('mavr_attempts', 'timedue', 0, ['id' => $nolimit->id]);
        [$grace] = attempt_manager::start_or_resume($fx->cm, $fx->mavr, (int)$fourth->id);
        $DB->set_field('mavr_attempts', 'timedue', time() - 1, ['id' => $grace->id]);

        $task = new finish_overdue_attempts();
        $this->assertSame(get_string('taskfinishoverdue', 'mod_mavr'), $task->get_name());
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('finished 1 overdue', $output);

        $row = attempt_manager::get_attempt((int)$overdue->id);
        $this->assertSame(attempt_manager::STATE_FINISHED, $row->state);
        $this->assertEquals($deadline, $row->timefinish);
        $this->assertTrue($this->completed_result((int)$overdue->id)->timeup);
        foreach ([$intime, $nolimit, $grace] as $attempt) {
            $this->assertSame(attempt_manager::STATE_INPROGRESS, attempt_manager::get_attempt((int)$attempt->id)->state);
        }

        // A second run has nothing to do.
        ob_start();
        $task->execute();
        $this->assertSame('', ob_get_clean());
        $this->assertEquals(1, $DB->count_records('mavr_events', ['attemptid' => $overdue->id, 'verb' => 'completed',
            'objecttype' => 'activity']));
    }

    /**
     * The task is registered to run every minute.
     *
     * @return void
     */
    public function test_task_is_scheduled_every_minute(): void {
        $this->resetAfterTest();
        $task = \core\task\manager::get_scheduled_task(finish_overdue_attempts::class);
        $this->assertNotNull($task);
        $this->assertSame('*', $task->get_minute());
        $this->assertSame('*', $task->get_hour());
    }
}
