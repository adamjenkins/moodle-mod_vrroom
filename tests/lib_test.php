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

use mod_vrroom\local\instance_manager;

/**
 * Tests for the lib.php callbacks of mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_get_coursemodule_info')]
#[\PHPUnit\Framework\Attributes\CoversFunction('vrroom_get_file_areas')]
final class lib_test extends \advanced_testcase {
    /**
     * Load lib.php.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/vrroom/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * The supported features.
     */
    public function test_supports(): void {
        $this->assertTrue(vrroom_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(vrroom_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(vrroom_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(vrroom_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(vrroom_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(vrroom_supports(FEATURE_USES_QUESTIONS));
        $this->assertTrue(vrroom_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertSame(MOD_PURPOSE_INTERACTIVECONTENT, vrroom_supports(FEATURE_MOD_PURPOSE));
        $this->assertTrue(vrroom_supports(FEATURE_GROUPS));
        $this->assertTrue(vrroom_supports(FEATURE_GROUPINGS));
        $this->assertFalse(vrroom_supports(FEATURE_GRADE_OUTCOMES));
        $this->assertNull(vrroom_supports(FEATURE_RATE));
        $this->assertNull(vrroom_supports('nosuchfeature'));
    }

    /**
     * Creating an activity stores the row, one scene, graph version 1 and a grade item.
     */
    public function test_add_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Museum',
            'grade' => 50,
            'stationtitles' => 'Vase|Portrait',
        ]);

        $record = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('builtin', $record->runtime);
        $this->assertSame('gallery', $record->template);
        $this->assertEquals(50, $record->grade);
        $this->assertEquals(1, $record->graphversion);
        $this->assertNotEmpty($record->templateconfig);
        $this->assertNotEmpty($record->graphjson);
        $this->assertEquals(1, $DB->count_records('vrroom_scenes', ['vrroomid' => $vrroom->id]));
        $this->assertEquals(1, $DB->count_records('vrroom_graphs', ['vrroomid' => $vrroom->id, 'version' => 1]));
        $this->assertSame(
            $record->graphjson,
            $DB->get_field('vrroom_graphs', 'graphjson', ['vrroomid' => $vrroom->id, 'version' => 1])
        );

        $scenes = instance_manager::get_scenes($vrroom->id);
        $this->assertCount(1, $scenes);
        $this->assertCount(2, $scenes[0]['zones']);

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'courseid' => $course->id]);
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
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'name' => 'Before']);
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $course->id, false, MUST_EXIST);

        $record = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $data = self::form_data_for_update($record, $cm);
        $data->name = 'After';
        $this->assertTrue(vrroom_update_instance($data));

        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('After', $after->name);
        $this->assertEquals(1, $after->graphversion);
        $this->assertSame('gallery', $after->template);

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'courseid' => $course->id]);
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

        $vrroom = $generator->create_module('vrroom', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Old.</p>', 'questionbankentryid' => $question->questionbankentryid,
                    'required' => 1, 'points' => 2, 'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>New.</p>', 'questionbankentryid' => 0,
                    'required' => 1, 'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $context = \context_module::instance($vrroom->cmid);
        $this->assertEquals(1, $DB->count_records('vrroom_slots', ['vrroomid' => $vrroom->id]));
        $this->assertEquals(1, $DB->count_records(
            'question_references',
            ['component' => 'mod_vrroom', 'usingcontextid' => $context->id]
        ));

        $student = $generator->create_and_enrol($course, 'student');
        $vrroomgenerator = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgenerator->create_attempt($vrroom->id, $student->id);
        $scene = instance_manager::get_scenes($vrroom->id)[0];
        $vrroomgenerator->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $this->assertGreaterThan(0, $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]));

        $this->setUser($student);
        \core_xapi\test_helper::create_state([
            'component' => 'mod_vrroom',
            'activity' => \core_xapi\local\statement\item_activity::create_from_id((string)$context->id),
            'stateid' => \mod_vrroom\local\launch\config_builder::resume_stateid((int)$attempt->id),
            'data' => (object)['scene' => $scene['id'], 'position' => [0, 0, 0], 'yaw' => 0],
        ], true);
        $this->assertEquals(1, $DB->count_records('xapi_states', ['component' => 'mod_vrroom']));
        $this->setAdminUser();

        $this->assertTrue(vrroom_delete_instance($vrroom->id));

        $this->assertFalse($DB->record_exists('vrroom', ['id' => $vrroom->id]));
        foreach (['vrroom_scenes', 'vrroom_graphs', 'vrroom_slots', 'vrroom_attempts'] as $table) {
            $this->assertEquals(0, $DB->count_records($table, ['vrroomid' => $vrroom->id]), $table);
        }
        $this->assertEquals(0, $DB->count_records('vrroom_events', ['attemptid' => $attempt->id]));
        $this->assertEquals(0, $DB->count_records('question_references', ['component' => 'mod_vrroom']));
        $this->assertEquals(0, $DB->count_records('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertEquals(0, $DB->count_records('xapi_states', ['component' => 'mod_vrroom']));
        $this->assertFalse(\grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id, 'courseid' => $course->id]));

        $this->assertFalse(vrroom_delete_instance($vrroom->id));
    }

    /**
     * Course module info carries the custom completion rule only with automatic completion.
     */
    public function test_get_coursemodule_info(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Room',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionobjectives' => 1,
        ]);
        $cmrecord = $DB->get_record('course_modules', ['id' => $vrroom->cmid], '*', MUST_EXIST);
        $cmrecord->showdescription = 0;

        $info = vrroom_get_coursemodule_info($cmrecord);
        $this->assertSame('Room', $info->name);
        $this->assertEquals(1, $info->customdata['customcompletionrules']['completionobjectives']);

        $cmrecord->completion = COMPLETION_TRACKING_MANUAL;
        $info = vrroom_get_coursemodule_info($cmrecord);
        $this->assertArrayNotHasKey('customcompletionrules', (array)$info->customdata);

        $cm = get_fast_modinfo($course)->get_cm($vrroom->cmid);
        $this->assertSame(
            [get_string('completiondetail:objectives', 'vrroom')],
            mod_vrroom_get_completion_active_rule_descriptions($cm)
        );
    }

    /**
     * The file areas offered to the file browser.
     */
    public function test_get_file_areas(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id);
        $areas = vrroom_get_file_areas($course, $cm, \context_module::instance($cm->id));
        $this->assertSame(['models', 'media', 'environment', 'assets'], array_keys($areas));
    }

    /**
     * Form data for an update, rebuilt from the stored template config as the form would.
     *
     * @param \stdClass $record The vrroom row.
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
