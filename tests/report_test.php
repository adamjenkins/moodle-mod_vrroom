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
     * Scores and grades are formatted, and an attempt without score shows a dash.
     */
    public function test_format_score(): void {
        $this->assertSame('-', report_overview::format_score(null));
        $this->assertSame(format_float(75, 2) . ' %', report_overview::format_score(0.75));
    }
}
