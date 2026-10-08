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

namespace mod_vrroom\local\template;

use mod_vrroom\local\instance_manager;

/**
 * Tests for choosing the template in the activity settings and changing it while the activity is unused.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_manager::class)]
final class template_choice_test extends \advanced_testcase {
    /**
     * Start every test with fresh template discovery and no request parameters.
     */
    protected function setUp(): void {
        parent::setUp();
        registry::reset_caches();
        unset($_GET['template'], $_POST['template']);
    }

    /**
     * Clean up request parameters set by a test.
     */
    protected function tearDown(): void {
        unset($_GET['template'], $_POST['template']);
        parent::tearDown();
    }

    /**
     * Render the form for a new activity in a new course.
     *
     * @return string form HTML
     */
    private function render_new_form(): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/vrroom/mod_form.php');
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php', ['add' => 'vrroom', 'course' => $course->id, 'section' => 0]);
        [, , $cw, $cm, $data] = prepare_new_moduleinfo_data($course, 'vrroom', 0);
        $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        return $form->render();
    }

    /**
     * Render the settings form of an existing activity.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $vrroom The activity record from the generator.
     * @return string form HTML
     */
    private function render_edit_form(\stdClass $course, \stdClass $vrroom): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/vrroom/mod_form.php');
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        return $form->render();
    }

    /**
     * A new activity offers the template select, defaulting to the gallery walk and its fields.
     */
    public function test_new_form_defaults_to_gallery(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_new_form();
        $this->assertMatchesRegularExpression('/<select[^>]*name="template"/', $html);
        $this->assertMatchesRegularExpression('/<option[^>]*value="gallery"[^>]*selected/', $html);
        $this->assertStringContainsString('name="vrroom_choosetemplate"', $html);
        $this->assertStringContainsString('name="gallery_title[0]"', $html);
        $this->assertStringNotContainsString('escape_title', $html);
        $this->assertStringContainsString(s(get_string('chooserhelp', 'vrroomtemplate_gallery')), $html);
    }

    /**
     * A chosen template (the reload after changing the select) shows that template's fields only.
     */
    public function test_new_form_with_chosen_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $_GET['template'] = 'escape';

        $html = $this->render_new_form();
        $this->assertMatchesRegularExpression('/<option[^>]*value="escape"[^>]*selected/', $html);
        $this->assertStringContainsString('name="escape_title[0]"', $html);
        $this->assertStringNotContainsString('gallery_title', $html);
    }

    /**
     * An unused activity can switch template: the form shows the new template's fields, and saving
     * stores the new template and rebuilds the room from it.
     */
    public function test_unused_activity_can_change_template(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'template' => 'gallery']);
        $row = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertNull(instance_manager::template_lock_reason($row));

        $_POST['template'] = 'escape';
        $html = $this->render_edit_form($course, $vrroom);
        $this->assertMatchesRegularExpression('/<select[^>]*name="template"/', $html);
        $this->assertStringContainsString('name="escape_title[0]"', $html);
        $this->assertStringNotContainsString('gallery_title', $html);
        unset($_POST['template']);

        $graphversion = (int)$row->graphversion;
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);
        $data = (object)[
            'instance' => $vrroom->id,
            'coursemodule' => $cm->id,
            'name' => 'Now an escape chain',
            'template' => 'escape',
            'grade' => 100,
            'escape_environment' => 'escape-room',
            'escape_story' => '',
            'escape_timelimit' => 0,
            'escape_hintpenalty' => 0,
            'escape_puzzlecount' => 2,
            'escape_puzzleid' => ['', ''],
            'escape_title' => ['Desk', 'Door'],
            'escape_clue' => [['text' => '<p>A</p>', 'format' => FORMAT_HTML], ['text' => '<p>B</p>', 'format' => FORMAT_HTML]],
            'escape_cluemedia' => ['none', 'none'],
            'escape_clueimage' => [0, 0],
            'escape_cluemodel' => [0, 0],
            'escape_locktype' => ['key', 'none'],
            'escape_keyname' => ['Key', ''],
            'escape_question' => [0, 0],
            'escape_hint1' => ['', ''],
            'escape_hint2' => ['', ''],
            'escape_points' => [1, 1],
        ];
        instance_manager::update_instance($data);

        $after = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('escape', $after->template);
        $this->assertGreaterThan($graphversion, (int)$after->graphversion);
        $this->assertStringContainsString('Desk', (string)$after->templateconfig);
    }

    /**
     * Once a student has an attempt the template is locked, in the form and on the server.
     */
    public function test_template_locked_by_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'template' => 'gallery']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $generator->create_attempt((int)$vrroom->id, (int)$student->id);
        $row = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('attempts', instance_manager::template_lock_reason($row));

        $_POST['template'] = 'escape';
        $html = $this->render_edit_form($course, $vrroom);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="template"/', $html);
        $this->assertStringContainsString(get_string('templatelockedattempts', 'vrroom'), $html);
        $this->assertStringContainsString('gallery_title', $html);
        unset($_POST['template']);

        // A tampered submission does not switch the template either.
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);
        instance_manager::update_instance((object)(['instance' => $vrroom->id, 'coursemodule' => $cm->id,
            'name' => 'Still a gallery', 'template' => 'escape', 'grade' => 100]
            + (array)$this->gallery_fields($row)));
        $this->assertSame('gallery', $DB->get_field('vrroom', 'template', ['id' => $vrroom->id]));
    }

    /**
     * A room edited in the room editor locks the template with its own reason.
     */
    public function test_template_locked_by_room_editor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $vrroom = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'template' => 'gallery']);
        $DB->set_field('vrroom', 'customised', time(), ['id' => $vrroom->id]);
        $row = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        $this->assertSame('edited', instance_manager::template_lock_reason($row));

        $html = $this->render_edit_form($course, $vrroom);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="template"/', $html);
        $this->assertStringContainsString(get_string('templatelockededited', 'vrroom'), $html);
    }

    /**
     * The template's description comes from its chooserhelp string, or '' without one.
     */
    public function test_template_description_fallback(): void {
        foreach (registry::get_all() as $name => $template) {
            $expected = get_string_manager()->string_exists('chooserhelp', 'vrroomtemplate_' . $name)
                ? get_string('chooserhelp', 'vrroomtemplate_' . $name) : '';
            $this->assertSame($expected, $template->get_chooser_help());
        }
    }

    /**
     * The stored gallery settings as form fields, so an update keeps the room as it is.
     *
     * @param \stdClass $row The vrroom row.
     * @return \stdClass
     */
    private function gallery_fields(\stdClass $row): \stdClass {
        $fields = new \stdClass();
        $config = instance_manager::decode_config($row->templateconfig);
        $cm = get_coursemodule_from_instance('vrroom', $row->id, 0, false, MUST_EXIST);
        $defaults = [];
        (new \vrroomtemplate_gallery\template())->data_preprocessing($defaults, $config, \context_module::instance($cm->id));
        foreach ($defaults as $key => $value) {
            if (str_starts_with((string)$key, 'gallery_')) {
                $fields->$key = $value;
            }
        }
        return $fields;
    }
}
