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

use mod_vrroom\local\questions\mode;
use mod_vrroom\local\template\registry as template_registry;

/**
 * Tests for saving the activity settings: core's add and update flow, failed saves, the question
 * mode lock and a Save pressed after changing the template select without the reload.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(instance_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_vrroom_mod_form::class)]
final class settings_save_test extends \advanced_testcase {
    /** @var string[] Request parameters the form reads, cleared around every test. */
    private const PARAMS = ['template', 'vrroom_formtemplate', 'vrroom_choosetemplate'];

    /**
     * Start every test with fresh template discovery and no request parameters.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/vrroom/mod_form.php');
        template_registry::reset_caches();
        $this->clear_params();
    }

    /**
     * Clean up request parameters set by a test.
     */
    protected function tearDown(): void {
        $this->clear_params();
        parent::tearDown();
    }

    /**
     * Remove the form's request parameters.
     */
    private function clear_params(): void {
        foreach (self::PARAMS as $name) {
            unset($_GET[$name], $_POST[$name]);
        }
    }

    /**
     * The settings form of an existing activity, with its stored values.
     *
     * @param \stdClass $course The course.
     * @param int $vrroomid The vrroom id.
     * @return \mod_vrroom_mod_form
     */
    private function edit_form(\stdClass $course, int $vrroomid): \mod_vrroom_mod_form {
        global $PAGE;
        $cm = get_coursemodule_from_instance('vrroom', $vrroomid, 0, false, MUST_EXIST);
        // Each form is a new page request: a page whose theme is set up cannot change course.
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        return $form;
    }

    /**
     * Form data for update_instance(), rebuilt from the stored template config as the form would.
     *
     * @param int $vrroomid The vrroom id.
     * @return \stdClass
     */
    private static function update_data(int $vrroomid): \stdClass {
        global $DB;
        $record = $DB->get_record('vrroom', ['id' => $vrroomid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('vrroom', $record->id, $record->course, false, MUST_EXIST);
        $defaults = (array)$record;
        $template = template_registry::get($record->template);
        $template->data_preprocessing(
            $defaults,
            instance_manager::decode_config($record->templateconfig),
            \context_module::instance($cm->id)
        );
        $data = (object)$defaults;
        $data->instance = $record->id;
        $data->coursemodule = $cm->id;
        $data->cmidnumber = '';
        return $data;
    }

    /**
     * A Blank-template activity is added and updated through core's own flow (add_moduleinfo(),
     * update_moduleinfo()), whose edit_module_post_actions() calls question_get_default_category()
     * because the activity uses questions; mod_vrroom's lib.php loads the question library for it.
     *
     * One PHPUnit process cannot unload the question library, so this cannot prove that nothing
     * else loaded it first; it proves the whole save flow and the course's module info afterwards.
     */
    public function test_blank_add_and_update_through_core(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/mod/vrroom/lib.php');
        $this->assertTrue(function_exists('question_get_default_category'));
        $this->assertContains(realpath($CFG->libdir . '/questionlib.php'), array_map('realpath', get_included_files()));

        $course = $this->getDataGenerator()->create_course();
        // The generator saves through add_moduleinfo().
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'template' => 'blank',
            'name' => 'Empty room',
        ]);
        $cmid = (int)$vrroom->cmid;
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));
        $this->assertNotFalse(\context_module::instance($cmid, IGNORE_MISSING));
        // Drop this request's copy, so the module info comes from the course cache, as on the next page.
        get_fast_modinfo($course->id, 0, true);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertSame('Empty room', $modinfo->get_cm($cmid)->name);

        // A settings save of the same activity through update_moduleinfo().
        $cm = get_coursemodule_from_id('vrroom', $cmid, 0, false, MUST_EXIST);
        [$cm, , , $data] = get_moduleinfo_data($cm, $course);
        $data->name = 'Empty room, renamed';
        $data->blank_environment = 'gallery-hall';
        update_moduleinfo($cm, $data, $course);

        $this->assertSame('Empty room, renamed', $DB->get_field('vrroom', 'name', ['id' => $vrroom->id]));
        // Drop this request's copy, so the module info comes from the course cache, as on the next page.
        get_fast_modinfo($course->id, 0, true);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertSame('Empty room, renamed', $modinfo->get_cm($cmid)->name);
        $this->assertSame([$cmid], array_values(array_map(fn($cm) => (int)$cm->id, $modinfo->get_instances_of('vrroom'))));
    }

    /**
     * A save that fails inside update_instance() rolls everything back and leaves the course's
     * module info consistent with the database.
     */
    public function test_failed_update_leaves_course_consistent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Gallery',
            'stationtitles' => 'Vase|Portrait',
        ]);

        // Far more stations than the room has places: the compile fails after the row was updated.
        $data = self::update_data((int)$vrroom->id);
        $data->name = 'Never saved';
        $data->gallery_title = array_map(fn($i) => "Station {$i}", range(1, 60));
        try {
            instance_manager::update_instance($data);
            $this->fail('The update should have failed.');
        } catch (\moodle_exception $e) {
            $this->assertFalse($DB->is_transaction_started());
        }

        $this->assertSame('Gallery', $DB->get_field('vrroom', 'name', ['id' => $vrroom->id]));
        // Drop this request's copy, so the module info comes from the course cache, as on the next page.
        get_fast_modinfo($course->id, 0, true);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertSame('Gallery', $modinfo->get_cm((int)$vrroom->cmid)->name);
    }

    /**
     * Without attempts the question mode and the retry penalty can be changed.
     */
    public function test_question_mode_changes_without_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id]);
        $this->assertFalse(instance_manager::has_attempts((int)$vrroom->id));

        $data = self::update_data((int)$vrroom->id);
        $data->questionmode = mode::RETRY;
        $data->retrypenalty = '0.5';
        instance_manager::update_instance($data);

        $row = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame(mode::RETRY, $row->questionmode);
        $this->assertEqualsWithDelta(0.5, (float)$row->retrypenalty, 0.0000001);

        $html = $this->edit_form($course, (int)$vrroom->id)->render();
        $this->assertMatchesRegularExpression('/<select[^>]*name="questionmode"/', $html);
        $this->assertStringNotContainsString(get_string('questionmodelockedattempts', 'vrroom'), $html);
    }

    /**
     * Once a student has an attempt, the form shows the question mode frozen and the server keeps
     * the stored mode and penalty, whatever is posted.
     */
    public function test_question_mode_locked_by_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', [
            'course' => $course->id,
            'questionmode' => mode::RETRY,
            'retrypenalty' => 0.5,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $generator->create_attempt((int)$vrroom->id, (int)$student->id);
        $this->assertTrue(instance_manager::has_attempts((int)$vrroom->id));

        $html = $this->edit_form($course, (int)$vrroom->id)->render();
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="questionmode"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="retrypenalty"/', $html);
        $this->assertStringContainsString(get_string('questionmodelockedattempts', 'vrroom'), $html);
        $this->assertStringContainsString(get_string('questionmoderetry', 'vrroom'), $html);

        // A tampered submission changes the other settings only.
        $data = self::update_data((int)$vrroom->id);
        $data->name = 'Renamed';
        $data->questionmode = mode::ONCE;
        $data->retrypenalty = '0.1';
        instance_manager::update_instance($data);

        $row = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('Renamed', $row->name);
        $this->assertSame(mode::RETRY, $row->questionmode);
        $this->assertEqualsWithDelta(0.5, (float)$row->retrypenalty, 0.0000001);
    }

    /**
     * A Save pressed after changing the template select without the reload button keeps the fields
     * the page showed (and what was typed in them) and asks for the reload instead of saving; the
     * reload button itself shows the new template's fields.
     */
    public function test_save_after_template_select_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'template' => 'gallery']);

        // The reload button: the new template's fields.
        $_POST['template'] = 'escape';
        $_POST['vrroom_formtemplate'] = 'gallery';
        $_POST['vrroom_choosetemplate'] = get_string('choosetemplatebutton', 'vrroom');
        $html = $this->edit_form($course, (int)$vrroom->id)->render();
        $this->assertStringContainsString('name="escape_title[0]"', $html);
        $this->assertStringNotContainsString('gallery_title', $html);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="vrroom_formtemplate")(?=[^>]*value="escape")/', $html);

        // Save instead: the gallery's fields stay, and validation asks for the reload.
        unset($_POST['vrroom_choosetemplate']);
        $form = $this->edit_form($course, (int)$vrroom->id);
        $html = $form->render();
        $this->assertStringContainsString('name="gallery_title[0]"', $html);
        $this->assertStringNotContainsString('escape_title', $html);
        // The fields core's own form posts with every save.
        $submitted = ['modulename' => 'vrroom', 'availabilityconditionsjson' => '']
            + (array)self::update_data((int)$vrroom->id);
        $errors = $form->validation(['template' => 'escape'] + $submitted, []);
        $this->assertSame(
            get_string('errortemplatenotshown', 'vrroom', get_string('choosetemplatebutton', 'vrroom')),
            $errors['template']
        );

        // Choosing the shown template again saves as usual.
        $_POST['template'] = 'gallery';
        $form = $this->edit_form($course, (int)$vrroom->id);
        $errors = $form->validation(['template' => 'gallery'] + $submitted, []);
        $this->assertArrayNotHasKey('template', $errors);
    }
}
