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

/**
 * Tests for the lib.php callbacks of mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_get_coursemodule_info')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mavr_get_file_areas')]
final class lib_test extends \advanced_testcase {
    /**
     * Load lib.php.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/mavr/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * The supported features.
     */
    public function test_supports(): void {
        $this->assertTrue(mavr_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(mavr_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(mavr_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(mavr_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(mavr_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(mavr_supports(FEATURE_USES_QUESTIONS));
        $this->assertTrue(mavr_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertSame(MOD_PURPOSE_INTERACTIVECONTENT, mavr_supports(FEATURE_MOD_PURPOSE));
        $this->assertTrue(mavr_supports(FEATURE_GROUPS));
        $this->assertTrue(mavr_supports(FEATURE_GROUPINGS));
        $this->assertFalse(mavr_supports(FEATURE_GRADE_OUTCOMES));
        $this->assertNull(mavr_supports(FEATURE_RATE));
        $this->assertNull(mavr_supports('nosuchfeature'));
    }

    /**
     * Creating an activity stores the row, one scene, graph version 1 and a grade item.
     */
    public function test_add_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $mavr = $this->getDataGenerator()->create_module('mavr', [
            'course' => $course->id,
            'name' => 'Museum',
            'grade' => 50,
            'stationtitles' => 'Vase|Portrait',
        ]);

        $record = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertSame('builtin', $record->runtime);
        $this->assertSame('gallery', $record->template);
        $this->assertEquals(50, $record->grade);
        $this->assertEquals(1, $record->graphversion);
        $this->assertNotEmpty($record->templateconfig);
        $this->assertNotEmpty($record->graphjson);
        $this->assertEquals(1, $DB->count_records('mavr_scenes', ['mavrid' => $mavr->id]));
        $this->assertEquals(1, $DB->count_records('mavr_graphs', ['mavrid' => $mavr->id, 'version' => 1]));
        $this->assertSame(
            $record->graphjson,
            $DB->get_field('mavr_graphs', 'graphjson', ['mavrid' => $mavr->id, 'version' => 1])
        );

        $scenes = instance_manager::get_scenes($mavr->id);
        $this->assertCount(1, $scenes);
        $this->assertCount(2, $scenes[0]['zones']);

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'courseid' => $course->id]);
        $this->assertNotFalse($gradeitem);
        $this->assertEquals(50, $gradeitem->grademax);
        $this->assertEquals('Museum', $gradeitem->itemname);
    }

    /**
     * Updating with the stored config keeps the graph version; the name reaches the grade item.
     */
    public function test_update_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id, 'name' => 'Before']);
        $cm = get_coursemodule_from_instance('mavr', $mavr->id, $course->id, false, MUST_EXIST);

        $record = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $data = self::form_data_for_update($record, $cm);
        $data->name = 'After';
        $this->assertTrue(mavr_update_instance($data));

        $after = $DB->get_record('mavr', ['id' => $mavr->id], '*', MUST_EXIST);
        $this->assertSame('After', $after->name);
        $this->assertEquals(1, $after->graphversion);
        $this->assertSame('gallery', $after->template);

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'courseid' => $course->id]);
        $this->assertEquals('After', $gradeitem->itemname);
    }

    /**
     * Deleting removes every row of the activity, its question references and xAPI states.
     */
    public function test_delete_instance_leaves_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/xapi/tests/helper.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $mavr = $generator->create_module('mavr', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Old.</p>', 'questionbankentryid' => $question->questionbankentryid,
                    'required' => 1, 'points' => 2, 'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>New.</p>', 'questionbankentryid' => 0,
                    'required' => 1, 'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $context = \context_module::instance($mavr->cmid);
        $this->assertEquals(1, $DB->count_records('mavr_slots', ['mavrid' => $mavr->id]));
        $this->assertEquals(1, $DB->count_records(
            'question_references',
            ['component' => 'mod_mavr', 'usingcontextid' => $context->id]
        ));

        $student = $generator->create_and_enrol($course, 'student');
        $mavrgenerator = $generator->get_plugin_generator('mod_mavr');
        $attempt = $mavrgenerator->create_attempt($mavr->id, $student->id);
        $scene = instance_manager::get_scenes($mavr->id)[0];
        $mavrgenerator->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $this->assertGreaterThan(0, $DB->count_records('mavr_events', ['attemptid' => $attempt->id]));

        $this->setUser($student);
        \core_xapi\test_helper::create_state([
            'component' => 'mod_mavr',
            'activity' => \core_xapi\local\statement\item_activity::create_from_id((string)$context->id),
            'stateid' => 'mavr-resume',
            'data' => (object)['scene' => $scene['id'], 'position' => [0, 0, 0], 'yaw' => 0],
        ], true);
        $this->assertEquals(1, $DB->count_records('xapi_states', ['component' => 'mod_mavr']));
        $this->setAdminUser();

        $this->assertTrue(mavr_delete_instance($mavr->id));

        $this->assertFalse($DB->record_exists('mavr', ['id' => $mavr->id]));
        foreach (['mavr_scenes', 'mavr_graphs', 'mavr_slots', 'mavr_attempts'] as $table) {
            $this->assertEquals(0, $DB->count_records($table, ['mavrid' => $mavr->id]), $table);
        }
        $this->assertEquals(0, $DB->count_records('mavr_events', ['attemptid' => $attempt->id]));
        $this->assertEquals(0, $DB->count_records('question_references', ['component' => 'mod_mavr']));
        $this->assertEquals(0, $DB->count_records('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertEquals(0, $DB->count_records('xapi_states', ['component' => 'mod_mavr']));
        $this->assertFalse(\grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'mavr',
            'iteminstance' => $mavr->id, 'courseid' => $course->id]));

        $this->assertFalse(mavr_delete_instance($mavr->id));
    }

    /**
     * Course module info carries the custom completion rule only with automatic completion.
     */
    public function test_get_coursemodule_info(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $mavr = $this->getDataGenerator()->create_module('mavr', [
            'course' => $course->id,
            'name' => 'Room',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionobjectives' => 1,
        ]);
        $cmrecord = $DB->get_record('course_modules', ['id' => $mavr->cmid], '*', MUST_EXIST);
        $cmrecord->showdescription = 0;

        $info = mavr_get_coursemodule_info($cmrecord);
        $this->assertSame('Room', $info->name);
        $this->assertEquals(1, $info->customdata['customcompletionrules']['completionobjectives']);

        $cmrecord->completion = COMPLETION_TRACKING_MANUAL;
        $info = mavr_get_coursemodule_info($cmrecord);
        $this->assertArrayNotHasKey('customcompletionrules', (array)$info->customdata);

        $cm = get_fast_modinfo($course)->get_cm($mavr->cmid);
        $this->assertSame(
            [get_string('completiondetail:objectives', 'mavr')],
            mod_mavr_get_completion_active_rule_descriptions($cm)
        );
    }

    /**
     * The file areas offered to the file browser.
     */
    public function test_get_file_areas(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $mavr = $this->getDataGenerator()->create_module('mavr', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('mavr', $mavr->id);
        $areas = mavr_get_file_areas($course, $cm, \context_module::instance($cm->id));
        $this->assertSame(['models', 'media', 'environment', 'assets'], array_keys($areas));
    }

    /**
     * Form data for an update, rebuilt from the stored template config as the form would.
     *
     * @param \stdClass $record The mavr row.
     * @param \stdClass $cm The course module.
     * @return \stdClass
     */
    private static function form_data_for_update(\stdClass $record, \stdClass $cm): \stdClass {
        $context = \context_module::instance($cm->id);
        $defaults = (array)$record;
        $template = local\template\registry::get($record->template);
        $template->data_preprocessing($defaults, instance_manager::decode_config($record->templateconfig), $context);
        $data = (object)$defaults;
        $data->instance = $record->id;
        $data->coursemodule = $cm->id;
        $data->cmidnumber = '';
        return $data;
    }
}
