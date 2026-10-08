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

namespace mod_vrroom\backup;

use backup;
use backup_controller;
use mod_vrroom\local\instance_manager;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');
require_once($CFG->dirroot . '/mod/vrroom/backup/moodle2/backup_vrroom_activity_task.class.php');
require_once($CFG->dirroot . '/mod/vrroom/backup/moodle2/restore_vrroom_activity_task.class.php');

/**
 * Backup and restore of VR room activities into a new course.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_vrroom_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_vrroom_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_vrroom_activity_task::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * A course with a question bank, a two-station gallery walk (one question) and a student
     * attempt with a visit, an answer and a finish.
     *
     * @return array [course, vrroom row, cm, question, student, attempt]
     */
    private function create_setup(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Museum walk',
            'stationtitles' => 'Vase|Portrait',
            'stationquestions' => 'Q1|',
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('vrroom', $module->cmid, 0, false, MUST_EXIST);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $vrroomgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $vrroomgen->record_event(
            $attempt,
            'answered',
            'question',
            null,
            '1',
            false,
            ['fraction' => 1.0, 'state' => 'gradedright', 'mark' => 1.0, 'maxmark' => 1.0]
        );

        return [$course, $vrroom, $cm, $question, $student, $attempt];
    }

    /**
     * Back up a course (optionally with user data) and restore it into a new course.
     *
     * @param \stdClass $course the course
     * @param \stdClass $cm the VR room course module (its userinfo setting is forced too)
     * @param bool $users include user data
     * @return int new course id
     */
    private function backup_and_restore(\stdClass $course, \stdClass $cm, bool $users): int {
        global $USER;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($users);
        // In import mode the activity's userinfo setting does not follow the root setting.
        $activitysetting = $bc->get_plan()->get_setting('vrroom_' . $cm->id . '_userinfo');
        $activitysetting->set_status(\backup_setting::NOT_LOCKED);
        $activitysetting->set_value($users);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored course', 'restored_' . $course->id, $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return (int)$newcourseid;
    }

    /**
     * The restored VR room activity of a course.
     *
     * @param int $courseid course id
     * @return array [vrroom row, cm_info]
     */
    private function get_restored(int $courseid): array {
        global $DB;
        $instances = get_fast_modinfo($courseid)->get_instances_of('vrroom');
        $this->assertCount(1, $instances);
        $cm = reset($instances);
        return [$DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST), $cm];
    }

    /**
     * With user data: settings, scenes, graphs, slots, remapped references, attempts, usages and events.
     */
    public function test_backup_restore_with_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $vrroom, $cm, $question, $student, $attempt] = $this->create_setup();

        $newcourseid = $this->backup_and_restore($course, $cm, true);
        [$new, $newcm] = $this->get_restored($newcourseid);
        $newcontext = \context_module::instance($newcm->id);

        // Activity settings and the graph are identical; ids are uuids, so nothing was remapped.
        $this->assertSame($vrroom->name, $new->name);
        $this->assertSame($vrroom->runtime, $new->runtime);
        $this->assertSame($vrroom->template, $new->template);
        $this->assertEquals($vrroom->graphversion, $new->graphversion);
        $this->assertSame($vrroom->graphjson, $new->graphjson);
        $this->assertEquals(
            $DB->get_records_menu('vrroom_graphs', ['vrroomid' => $vrroom->id], 'version', 'version, graphjson'),
            $DB->get_records_menu('vrroom_graphs', ['vrroomid' => $new->id], 'version', 'version, graphjson')
        );
        $this->assertEquals(
            $DB->get_records_menu('vrroom_scenes', ['vrroomid' => $vrroom->id], 'sortorder', 'idnumber, scenejson'),
            $DB->get_records_menu('vrroom_scenes', ['vrroomid' => $new->id], 'sortorder', 'idnumber, scenejson')
        );

        // The slot and its reference point at the new activity and a remapped bank entry.
        $slots = $DB->get_records('vrroom_slots', ['vrroomid' => $new->id]);
        $this->assertCount(1, $slots);
        $slot = reset($slots);
        $this->assertEquals(1, $slot->slot);
        $reference = $DB->get_record(
            'question_references',
            ['component' => 'mod_vrroom', 'questionarea' => 'slot', 'itemid' => $slot->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals($newcontext->id, $reference->usingcontextid);
        $this->assertNotEquals($question->questionbankentryid, $reference->questionbankentryid);
        $bankcontextid = $DB->get_field_sql(
            "SELECT qc.contextid
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qbe.id = :id",
            ['id' => $reference->questionbankentryid],
            MUST_EXIST
        );
        $bankcontext = \context::instance_by_id($bankcontextid);
        $this->assertSame((int)$newcourseid, (int)$bankcontext->get_course_context()->instanceid);

        // The template config names the remapped entry too.
        $config = json_decode($new->templateconfig);
        $this->assertEquals($reference->questionbankentryid, $config->stations[0]->questionbankentryid);

        // The attempt, its usage and its events came across.
        $attempts = $DB->get_records('vrroom_attempts', ['vrroomid' => $new->id]);
        $this->assertCount(1, $attempts);
        $newattempt = reset($attempts);
        $this->assertEquals($student->id, $newattempt->userid);
        $this->assertEquals(1, $newattempt->attempt);
        $this->assertSame($attempt->slotmap, $newattempt->slotmap);
        $this->assertEquals($attempt->graphversion, $newattempt->graphversion);
        $this->assertNotEquals($attempt->uniqueid, $newattempt->uniqueid);
        $usage = $DB->get_record('question_usages', ['id' => $newattempt->uniqueid], '*', MUST_EXIST);
        $this->assertSame('mod_vrroom', $usage->component);
        $this->assertEquals($newcontext->id, $usage->contextid);
        $this->assertEquals(1, $DB->count_records('question_attempts', ['questionusageid' => $usage->id]));

        $oldevents = $DB->get_records('vrroom_events', ['attemptid' => $attempt->id], 'id ASC');
        $newevents = $DB->get_records('vrroom_events', ['attemptid' => $newattempt->id], 'id ASC');
        $this->assertSame(array_column($oldevents, 'verb'), array_column($newevents, 'verb'));
        $this->assertSame(array_column($oldevents, 'objectid'), array_column($newevents, 'objectid'));
        $this->assertSame(array_column($oldevents, 'clientasserted'), array_column($newevents, 'clientasserted'));
        // Same site: every statement id collided with the original and was renewed.
        $this->assertEmpty(array_intersect(
            array_column($oldevents, 'statementid'),
            array_column($newevents, 'statementid')
        ));

        // A replay of the restored attempt gives the same score.
        $this->assertEqualsWithDelta((float)$attempt->score, (float)$newattempt->score, 1e-6);
        $restoredresult = \mod_vrroom\local\attempt\attempt_manager::evaluate($newattempt);
        $this->assertEqualsWithDelta((float)$attempt->score, $restoredresult->score, 1e-6);
    }

    /**
     * Without user data no attempts, events or usages are restored, but the activity is complete.
     */
    public function test_backup_restore_without_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $vrroom, $cm] = $this->create_setup();

        $newcourseid = $this->backup_and_restore($course, $cm, false);
        [$new, $newcm] = $this->get_restored($newcourseid);

        $this->assertSame($vrroom->graphjson, $new->graphjson);
        $this->assertEquals(1, $DB->count_records('vrroom_slots', ['vrroomid' => $new->id]));
        $this->assertEquals(0, $DB->count_records('vrroom_attempts', ['vrroomid' => $new->id]));
        $this->assertEquals(0, $DB->count_records(
            'question_usages',
            ['component' => 'mod_vrroom', 'contextid' => \context_module::instance($newcm->id)->id]
        ));
    }

    /**
     * The room editor mark (customised), the attempt deadline (timedue) and the room editor
     * files (area assets) come across unchanged.
     */
    public function test_backup_restore_customised_timedue_and_assets(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $vrroom, $cm, , , $attempt] = $this->create_setup();

        $customised = time() - 3600;
        $timedue = (int)$attempt->timestart + 900;
        $DB->set_field('vrroom', 'customised', $customised, ['id' => $vrroom->id]);
        $DB->set_field('vrroom_attempts', 'timedue', $timedue, ['id' => $attempt->id]);
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_vrroom',
            'filearea' => 'assets',
            'itemid' => 4,
            'filepath' => '/',
            'filename' => 'tape.mp3',
        ], 'not really audio');

        $newcourseid = $this->backup_and_restore($course, $cm, true);
        [$new, $newcm] = $this->get_restored($newcourseid);
        $newcontext = \context_module::instance($newcm->id);

        $this->assertEquals($customised, $new->customised);
        $newattempt = $DB->get_record('vrroom_attempts', ['vrroomid' => $new->id], '*', MUST_EXIST);
        $this->assertEquals($timedue, $newattempt->timedue);
        $this->assertEquals($attempt->timestart, $newattempt->timestart);

        $file = get_file_storage()->get_file($newcontext->id, 'mod_vrroom', 'assets', 4, '/', 'tape.mp3');
        $this->assertNotFalse($file);
        $this->assertSame('not really audio', $file->get_content());
    }

    /**
     * The live mode settings come across; the live room presences and messages of the original
     * activity are never backed up.
     */
    public function test_backup_restore_live_mode(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $vrroom, $cm, , $student, $attempt] = $this->create_setup();

        $DB->update_record('vrroom', (object)['id' => $vrroom->id, 'livemode' => 1, 'livemaxusers' => 4]);
        /** @var \vrroomruntime_builtin_generator $livegen */
        $livegen = $this->getDataGenerator()->get_plugin_generator('vrroomruntime_builtin');
        $peer = $livegen->create_peer(['vrroomid' => $vrroom->id, 'userid' => $student->id, 'attemptid' => $attempt->id]);
        $livegen->create_signal(['vrroomid' => $vrroom->id, 'frompeer' => $peer->peerid, 'topeer' => str_repeat('d', 32)]);

        $newcourseid = $this->backup_and_restore($course, $cm, true);
        [$new] = $this->get_restored($newcourseid);

        $this->assertEquals(1, $new->livemode);
        $this->assertEquals(4, $new->livemaxusers);
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $new->id]));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $new->id]));
        // The original room is untouched by the backup.
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $vrroom->id]));
    }

    /**
     * An escape chain's config names the remapped question bank entry after a restore into a new
     * course (the template's remap_question_entries()).
     */
    public function test_backup_restore_escape_config_is_remapped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Escape',
            'template' => 'escape',
            'puzzletitles' => 'Desk|Safe',
            'puzzlelocks' => 'key|question',
            'puzzlequestions' => '|Q1',
        ]);
        $cm = get_coursemodule_from_id('vrroom', $module->cmid, 0, false, MUST_EXIST);
        $config = json_decode($DB->get_field('vrroom', 'templateconfig', ['id' => $module->id]));
        $this->assertEquals($question->questionbankentryid, $config->puzzles[1]->questionbankentryid);

        $newcourseid = $this->backup_and_restore($course, $cm, false);
        [$new] = $this->get_restored($newcourseid);

        $slot = $DB->get_record('vrroom_slots', ['vrroomid' => $new->id], '*', MUST_EXIST);
        $reference = $DB->get_record(
            'question_references',
            ['component' => 'mod_vrroom', 'questionarea' => 'slot', 'itemid' => $slot->id],
            '*',
            MUST_EXIST
        );
        $this->assertNotEquals($question->questionbankentryid, $reference->questionbankentryid);

        $newconfig = json_decode($new->templateconfig);
        $this->assertSame('vrroomtemplate_escape/1', $newconfig->schema);
        $this->assertEquals($reference->questionbankentryid, $newconfig->puzzles[1]->questionbankentryid);
        $this->assertEquals(0, $newconfig->puzzles[0]->questionbankentryid);
    }

    /**
     * Content links in the intro are encoded on backup.
     */
    public function test_encode_content_links(): void {
        global $CFG;
        $this->resetAfterTest();
        $content = $CFG->wwwroot . '/mod/vrroom/view.php?id=12 and ' . $CFG->wwwroot . '/mod/vrroom/index.php?id=3';
        $encoded = \backup_vrroom_activity_task::encode_content_links($content);
        $this->assertSame('$@VRROOMVIEWBYID*12@$ and $@VRROOMINDEX*3@$', $encoded);
    }
}
