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

namespace mod_mavr;

use mod_mavr\local\instance_manager;
use mod_mavr\output\report_attempt;
use mod_mavr\output\report_overview;

/**
 * Tests for the MAVR report renderables and templates.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(report_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(report_attempt::class)]
final class report_test extends \advanced_testcase {

    /** @var string A hostile name part. */
    private const HOSTILE = '<b>Evil</b> & Co';

    /**
     * An activity whose first station has a hostile title, and a student with a hostile name who
     * visited it.
     *
     * @return array [mavr row, cm_info, context, student, attempt]
     */
    private function create_setup(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => self::HOSTILE, 'lastname' => 'Pupil']);
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase <script>alert(1)</script> & jug'],
                ['title' => 'Portrait'],
            ],
        ]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'mavr');
        $context = \context_module::instance($cm->id);

        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');
        $attempt = $mavrgen->create_attempt((int)$mavr->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $mavrgen->record_event($attempt, 'interacted', 'zone', $scene['id'], $scene['zones'][0]['id']);

        return [$mavr, $cm, $context, $student, $attempt];
    }

    /**
     * The overview lists the attempt and escapes the user's name exactly once.
     */
    public function test_overview_escapes_names(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mavr, $cm, $context, $student, $attempt] = $this->create_setup();

        $output = $PAGE->get_renderer('core');
        $data = (new report_overview($mavr, $cm, $context))->export_for_template($output);
        $this->assertTrue($data['hasattempts']);
        $this->assertCount(1, $data['attempts']);
        $row = $data['attempts'][0];
        $this->assertSame((int)$attempt->id, $row['attemptid']);
        $this->assertSame(get_string('state_inprogress', 'mod_mavr'), $row['state']);
        $this->assertTrue($row['candelete']);
        $this->assertStringContainsString('delete=' . $attempt->id, $row['deleteurl']);

        $html = $output->render_from_template('mod_mavr/report_overview', $data);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * Without the delete capability no delete links are offered.
     */
    public function test_overview_hides_delete_without_capability(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$mavr, $cm, $context] = $this->create_setup();
        $teacher = $this->getDataGenerator()->create_and_enrol($cm->get_course(), 'teacher');
        $this->setUser($teacher);

        $data = (new report_overview($mavr, $cm, $context))->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['attempts'][0]['candelete']);
        $this->assertSame('', $data['attempts'][0]['deleteurl']);
    }

    /**
     * The drill-down shows objective states, labels client-asserted statements and makes names safe.
     */
    public function test_attempt_details(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mavr, $cm, $context, $student, $attempt] = $this->create_setup();

        $output = $PAGE->get_renderer('core');
        $data = (new report_attempt($mavr, $cm, $context, $attempt))->export_for_template($output);

        $statuses = array_column($data['objectives'], 'status', 'id');
        $this->assertCount(2, $statuses);
        $this->assertContains('completed', $statuses);
        $this->assertContains('available', $statuses);

        $sources = array_column($data['statements'], 'clientasserted');
        $this->assertContains(true, $sources);
        $this->assertContains(false, $sources);
        $interacted = array_values(array_filter($data['statements'],
            fn($s) => $s['verb'] === get_string('verb_interacted', 'mod_mavr')));
        $this->assertCount(1, $interacted);
        $this->assertTrue($interacted[0]['clientasserted']);
        $this->assertSame(get_string('reportclientasserted', 'mod_mavr'), $interacted[0]['source']);
        $this->assertSame(get_string('objecttype_zone', 'mod_mavr'), $interacted[0]['objecttype']);
        $this->assertStringNotContainsString('<script>', $interacted[0]['objectname']);
        $this->assertStringContainsString('Vase', $interacted[0]['objectname']);
        // format_string() made the name HTML-safe: the ampersand is escaped exactly once.
        $this->assertStringContainsString('&amp; jug', $interacted[0]['objectname']);
        $this->assertStringNotContainsString('&amp;amp;', $interacted[0]['objectname']);

        $html = $output->render_from_template('mod_mavr/report_attempt', $data);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $html);
        $this->assertStringContainsString('data-region="mavr-report-statements"', $html);
    }

    /**
     * An activity in a group mode with groups A and B, a student with an attempt in each, and a
     * non-editing teacher (no "access all groups") in group A.
     *
     * @param int $groupmode SEPARATEGROUPS, VISIBLEGROUPS or NOGROUPS
     * @return array [course, mavr row, cm_info, context, teacher, group A, group B, attempt A, attempt B]
     */
    private function create_group_setup(int $groupmode): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $studenta = $generator->create_and_enrol($course, 'student');
        $studentb = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);

        $module = $generator->create_module('mavr', ['course' => $course->id, 'groupmode' => $groupmode]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, 'mavr');
        $context = \context_module::instance($cm->id);

        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');
        $attempta = $mavrgen->create_attempt((int)$mavr->id, (int)$studenta->id);
        $attemptb = $mavrgen->create_attempt((int)$mavr->id, (int)$studentb->id);

        return [$course, $mavr, $cm, $context, $teacher, $groupa, $groupb, $attempta, $attemptb];
    }

    /**
     * The attempt ids of an overview.
     *
     * @param report_overview $overview the overview
     * @return int[]
     */
    private function overview_attemptids(report_overview $overview): array {
        global $PAGE;
        $data = $overview->export_for_template($PAGE->get_renderer('core'));
        $ids = array_column($data['attempts'], 'attemptid');
        sort($ids);
        $this->assertSame(!empty($ids), $data['hasattempts']);
        return $ids;
    }

    /**
     * Separate groups: a teacher without "access all groups" sees only the attempts of their own
     * groups and cannot reach an attempt of another group; "all groups" shows them nothing.
     */
    public function test_separate_groups_limit_the_report(): void {
        $this->resetAfterTest();
        [$course, $mavr, $cm, $context, $teacher, $groupa, $groupb, $attempta, $attemptb] =
            $this->create_group_setup(SEPARATEGROUPS);
        $this->setUser($teacher);

        $this->assertSame([(int)$attempta->id],
            $this->overview_attemptids(new report_overview($mavr, $cm, $context, (int)$groupa->id)));
        $this->assertSame([(int)$attemptb->id],
            $this->overview_attemptids(new report_overview($mavr, $cm, $context, (int)$groupb->id)));
        $this->assertSame([], $this->overview_attemptids(new report_overview($mavr, $cm, $context, 0)));

        // The detail page and the deletion both go through this check.
        report_overview::require_attempt_in_groups($course, $cm, $attempta);
        try {
            report_overview::require_attempt_in_groups($course, $cm, $attemptb);
            $this->fail('An attempt of another group must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptnotingroup', $e->errorcode);
            $this->assertSame(get_string('errorattemptnotingroup', 'mod_mavr'), $e->getMessage());
        }
    }

    /**
     * Separate groups: someone with "access all groups" sees every attempt under "all groups" and
     * reaches every attempt.
     */
    public function test_separate_groups_with_access_all_groups(): void {
        $this->resetAfterTest();
        [$course, $mavr, $cm, $context, , $groupa, , $attempta, $attemptb] =
            $this->create_group_setup(SEPARATEGROUPS);
        $this->setAdminUser();

        $expected = [(int)$attempta->id, (int)$attemptb->id];
        sort($expected);
        $this->assertSame($expected, $this->overview_attemptids(new report_overview($mavr, $cm, $context, 0)));
        $this->assertSame([(int)$attempta->id],
            $this->overview_attemptids(new report_overview($mavr, $cm, $context, (int)$groupa->id)));
        report_overview::require_attempt_in_groups($course, $cm, $attemptb);
    }

    /**
     * Visible groups and no groups: the teacher sees and reaches every attempt.
     */
    public function test_visible_groups_and_no_groups_unchanged(): void {
        $this->resetAfterTest();
        foreach ([VISIBLEGROUPS, NOGROUPS] as $groupmode) {
            [$course, $mavr, $cm, $context, $teacher, , , $attempta, $attemptb] =
                $this->create_group_setup($groupmode);
            $this->setUser($teacher);

            $expected = [(int)$attempta->id, (int)$attemptb->id];
            sort($expected);
            $this->assertSame($expected, $this->overview_attemptids(new report_overview($mavr, $cm, $context, 0)),
                'group mode ' . $groupmode);
            report_overview::require_attempt_in_groups($course, $cm, $attemptb);
        }
    }

    /**
     * Scores and grades are formatted, and an attempt without score shows a dash.
     */
    public function test_format_score(): void {
        $this->assertSame('-', report_overview::format_score(null));
        $this->assertSame(format_float(75, 2) . ' %', report_overview::format_score(0.75));
    }
}
