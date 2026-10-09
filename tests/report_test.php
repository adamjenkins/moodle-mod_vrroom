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

namespace mod_vrroom;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\template\compiled_activity;
use mod_vrroom\output\report_attempt;
use mod_vrroom\output\report_overview;

/**
 * Tests for the VR room report renderables and templates.
 *
 * @package    mod_vrroom
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
     * @return array [vrroom row, cm_info, context, student, attempt]
     */
    private function create_setup(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['lastname' => 'Pupil']);
        // Stored directly: the user generator would clean the tags out of the name, but a
        // name can still reach the table unclean (an import, an auth plugin, an old site).
        $DB->set_field('user', 'firstname', self::HOSTILE, ['id' => $student->id]);
        $student->firstname = self::HOSTILE;
        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase <script>alert(1)</script> & jug'],
                ['title' => 'Portrait'],
            ],
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $vrroomgen->record_event($attempt, 'interacted', 'zone', $scene['id'], $scene['zones'][0]['id']);

        return [$vrroom, $cm, $context, $student, $attempt];
    }

    /**
     * The overview lists the attempt and escapes the user's name exactly once.
     */
    public function test_overview_escapes_names(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$vrroom, $cm, $context, $student, $attempt] = $this->create_setup();

        $output = $PAGE->get_renderer('core');
        $data = (new report_overview($vrroom, $cm, $context))->export_for_template($output);
        $this->assertTrue($data['hasattempts']);
        $this->assertCount(1, $data['attempts']);
        $row = $data['attempts'][0];
        $this->assertSame((int)$attempt->id, $row['attemptid']);
        $this->assertSame(get_string('state_inprogress', 'mod_vrroom'), $row['state']);
        $this->assertTrue($row['candelete']);
        $this->assertStringContainsString('delete=' . $attempt->id, $row['deleteurl']);

        $html = $output->render_from_template('mod_vrroom/report_overview', $data);
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
        [$vrroom, $cm, $context] = $this->create_setup();
        $teacher = $this->getDataGenerator()->create_and_enrol($cm->get_course(), 'teacher');
        $this->setUser($teacher);

        $data = (new report_overview($vrroom, $cm, $context))->export_for_template($PAGE->get_renderer('core'));
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
        [$vrroom, $cm, $context, $student, $attempt] = $this->create_setup();

        $output = $PAGE->get_renderer('core');
        $data = (new report_attempt($vrroom, $cm, $context, $attempt))->export_for_template($output);

        $statuses = array_column($data['objectives'], 'status', 'id');
        $this->assertCount(2, $statuses);
        $this->assertContains('completed', $statuses);
        $this->assertContains('available', $statuses);

        $sources = array_column($data['statements'], 'clientasserted');
        $this->assertContains(true, $sources);
        $this->assertContains(false, $sources);
        $interacted = array_values(array_filter(
            $data['statements'],
            fn($s) => $s['verb'] === get_string('verb_interacted', 'mod_vrroom')
        ));
        $this->assertCount(1, $interacted);
        $this->assertTrue($interacted[0]['clientasserted']);
        $this->assertSame(get_string('reportclientasserted', 'mod_vrroom'), $interacted[0]['source']);
        $this->assertSame(get_string('objecttype_zone', 'mod_vrroom'), $interacted[0]['objecttype']);
        $this->assertStringNotContainsString('<script>', $interacted[0]['objectname']);
        $this->assertStringContainsString('Vase', $interacted[0]['objectname']);
        // The format_string() call made the name HTML-safe: the ampersand is escaped exactly once.
        $this->assertStringContainsString('&amp; jug', $interacted[0]['objectname']);
        $this->assertStringNotContainsString('&amp;amp;', $interacted[0]['objectname']);

        $html = $output->render_from_template('mod_vrroom/report_attempt', $data);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $html);
        $this->assertStringContainsString('data-region="vrroom-report-statements"', $html);
    }

    /**
     * An activity in a group mode with groups A and B, a student with an attempt in each, and a
     * non-editing teacher (no "access all groups") in group A.
     *
     * @param int $groupmode SEPARATEGROUPS, VISIBLEGROUPS or NOGROUPS
     * @return array [course, vrroom row, cm_info, context, teacher, group A, group B, attempt A, attempt B]
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

        $module = $generator->create_module('vrroom', ['course' => $course->id, 'groupmode' => $groupmode]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempta = $vrroomgen->create_attempt((int)$vrroom->id, (int)$studenta->id);
        $attemptb = $vrroomgen->create_attempt((int)$vrroom->id, (int)$studentb->id);

        return [$course, $vrroom, $cm, $context, $teacher, $groupa, $groupb, $attempta, $attemptb];
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
        [$course, $vrroom, $cm, $context, $teacher, $groupa, $groupb, $attempta, $attemptb] =
            $this->create_group_setup(SEPARATEGROUPS);
        $this->setUser($teacher);

        $this->assertSame(
            [(int)$attempta->id],
            $this->overview_attemptids(new report_overview($vrroom, $cm, $context, (int)$groupa->id))
        );
        $this->assertSame(
            [(int)$attemptb->id],
            $this->overview_attemptids(new report_overview($vrroom, $cm, $context, (int)$groupb->id))
        );
        $this->assertSame([], $this->overview_attemptids(new report_overview($vrroom, $cm, $context, 0)));

        // The detail page and the deletion both go through this check.
        report_overview::require_attempt_in_groups($course, $cm, $attempta);
        try {
            report_overview::require_attempt_in_groups($course, $cm, $attemptb);
            $this->fail('An attempt of another group must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattemptnotingroup', $e->errorcode);
            $this->assertSame(get_string('errorattemptnotingroup', 'mod_vrroom'), $e->getMessage());
        }
    }

    /**
     * Separate groups: someone with "access all groups" sees every attempt under "all groups" and
     * reaches every attempt.
     */
    public function test_separate_groups_with_access_all_groups(): void {
        $this->resetAfterTest();
        [$course, $vrroom, $cm, $context, , $groupa, , $attempta, $attemptb] =
            $this->create_group_setup(SEPARATEGROUPS);
        $this->setAdminUser();

        $expected = [(int)$attempta->id, (int)$attemptb->id];
        sort($expected);
        $this->assertSame($expected, $this->overview_attemptids(new report_overview($vrroom, $cm, $context, 0)));
        $this->assertSame(
            [(int)$attempta->id],
            $this->overview_attemptids(new report_overview($vrroom, $cm, $context, (int)$groupa->id))
        );
        report_overview::require_attempt_in_groups($course, $cm, $attemptb);
    }

    /**
     * Visible groups and no groups: the teacher sees and reaches every attempt.
     */
    public function test_visible_groups_and_no_groups_unchanged(): void {
        $this->resetAfterTest();
        foreach ([VISIBLEGROUPS, NOGROUPS] as $groupmode) {
            [$course, $vrroom, $cm, $context, $teacher, , , $attempta, $attemptb] =
                $this->create_group_setup($groupmode);
            $this->setUser($teacher);

            $expected = [(int)$attempta->id, (int)$attemptb->id];
            sort($expected);
            $this->assertSame(
                $expected,
                $this->overview_attemptids(new report_overview($vrroom, $cm, $context, 0)),
                'group mode ' . $groupmode
            );
            report_overview::require_attempt_in_groups($course, $cm, $attemptb);
        }
    }

    /**
     * An attempt in progress shows its running score but no grade until it is finished.
     */
    public function test_in_progress_attempt_has_no_grade(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$vrroom, $cm, $context, , $attempt] = $this->create_setup();
        $output = $PAGE->get_renderer('core');
        $attempt = attempt_manager::get_attempt((int)$attempt->id);
        $this->assertNotNull($attempt->score);

        $row = (new report_overview($vrroom, $cm, $context))->export_for_template($output)['attempts'][0];
        $this->assertSame('-', $row['grade']);
        $this->assertNotSame('-', $row['score']);
        $details = (new report_attempt($vrroom, $cm, $context, $attempt))->export_for_template($output);
        $this->assertSame('-', $details['grade']);

        $attempt = attempt_manager::finish($attempt);
        $row = (new report_overview($vrroom, $cm, $context))->export_for_template($output)['attempts'][0];
        $this->assertNotSame('-', $row['grade']);
        $details = (new report_attempt($vrroom, $cm, $context, $attempt))->export_for_template($output);
        $this->assertNotSame('-', $details['grade']);
    }

    /**
     * A hint request is shown with its objective's title and the hint's number, not its id.
     */
    public function test_hint_statement_is_named(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'template' => 'gallery',
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => 0, 'required' => 1, 'points' => 1,
                    'mediatype' => 'none'],
            ],
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_cmid($module->cmid, 'vrroom');
        $context = \context_module::instance($cm->id);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $graph = [
            'schema' => 'vrroom-graph/1',
            'hidden' => [],
            'objectives' => [
                ['id' => 'v-1', 'type' => 'visit_zone', 'title' => 'Find the <b>key</b>',
                    'zone' => (string)$scene['zones'][0]['id'], 'points' => 1, 'required' => true,
                    'hints' => [
                        ['id' => 'hn-abc-1', 'text' => 'Look left', 'penalty' => 0.5],
                        ['id' => 'hn-abc-2', 'text' => 'Look down', 'penalty' => 0.5],
                    ]],
            ],
            'rules' => [],
        ];
        instance_manager::apply_compiled($vrroom, $context, new compiled_activity([$scene], $graph, []));
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);

        $student = $generator->create_and_enrol($course, 'student');
        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id);
        $vrroomgen->record_event($attempt, 'requested', 'hint', null, 'hn-abc-2', false, ['penalty' => 0.5,
            'objective' => 'v-1']);

        $data = (new report_attempt($vrroom, $cm, $context, $attempt))->export_for_template($PAGE->get_renderer('core'));
        $hints = array_values(array_filter(
            $data['statements'],
            fn($s) => $s['objecttype'] === get_string('objecttype_hint', 'mod_vrroom')
        ));
        $this->assertCount(1, $hints);
        $expected = get_string('reporthintname', 'mod_vrroom', (object)[
            'objective' => format_string('Find the <b>key</b>', true, ['context' => $context]),
            'number' => 2,
        ]);
        $this->assertSame($expected, $hints[0]['objectname']);
        $this->assertStringNotContainsString('hn-abc', $hints[0]['objectname']);
    }

    /**
     * Scores and grades are formatted, and an attempt without score shows a dash.
     */
    public function test_format_score(): void {
        $this->assertSame('-', report_overview::format_score(null));
        $this->assertSame(format_float(75, 2) . ' %', report_overview::format_score(0.75));
    }
}
