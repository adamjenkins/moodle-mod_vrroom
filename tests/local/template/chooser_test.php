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

namespace mod_mavr\local\template;

use core_course\local\entity\content_item;
use core_course\local\entity\string_title;

/**
 * Tests for the activity chooser items and the template selection in the activity form.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(chooser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
final class chooser_test extends \advanced_testcase {
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
     * A default item shaped like the one core builds for a course.
     *
     * @param int $courseid
     * @return content_item
     */
    private function default_item(int $courseid): content_item {
        return new content_item(
            id: 42,
            name: 'mavr',
            title: new string_title('MAVR'),
            link: new \moodle_url('/course/mod.php', ['id' => $courseid, 'add' => 'mavr']),
            icon: '<img class="icon" alt="">',
            help: 'Default help',
            archetype: MOD_ARCHETYPE_OTHER,
            componentname: 'mod_mavr',
            purpose: MOD_PURPOSE_INTERACTIVECONTENT,
            branded: false,
            gradable: true,
            otherpurpose: null,
            summary: 'Default summary',
        );
    }

    /**
     * One item per template, in name order, ids 1..n, titled and linked to the form with the template.
     */
    public function test_content_items(): void {
        $templates = registry::get_all();
        $this->assertArrayHasKey('blank', $templates);
        $this->assertArrayHasKey('escape', $templates);
        $this->assertArrayHasKey('gallery', $templates);

        $items = chooser::content_items($this->default_item(7));
        $this->assertCount(count($templates), $items);

        $position = 0;
        foreach ($templates as $name => $template) {
            $item = $items[$position];
            $this->assertSame($position + 1, $item->get_id());
            $this->assertSame('mavr_' . $name, $item->get_name());
            $this->assertSame(get_string('choosertitle', 'mod_mavr', $template->get_display_name()),
                $item->get_title()->get_value());
            $this->assertSame(get_string('chooserhelp', 'mavrtemplate_' . $name), $item->get_help());

            $link = $item->get_link();
            $this->assertStringEndsWith('/course/modedit.php', $link->out_omit_querystring());
            $this->assertSame($name, $link->param('template'));
            $this->assertSame('mavr', $link->param('add'));
            $this->assertSame('7', (string)$link->param('course'));

            // Everything else is the default item's.
            $this->assertSame('<img class="icon" alt="">', $item->get_icon());
            $this->assertSame(MOD_ARCHETYPE_OTHER, $item->get_archetype());
            $this->assertSame('mod_mavr', $item->get_component_name());
            $this->assertSame(MOD_PURPOSE_INTERACTIVECONTENT, $item->get_purpose());
            $this->assertTrue($item->is_gradable());
            $this->assertSame('Default summary', $item->get_summary());
            $position++;
        }
        $this->assertSame(['mavr_blank', 'mavr_escape', 'mavr_gallery'],
            array_slice(array_map(fn (content_item $item) => $item->get_name(), $items), 0, 3));
    }

    /**
     * Without a course scope the default link is kept and gains the template parameter.
     */
    public function test_content_items_without_course(): void {
        $default = new content_item(1, 'mavr', new string_title('MAVR'), new \moodle_url(''), '', 'Help',
            MOD_ARCHETYPE_OTHER, 'mod_mavr', MOD_PURPOSE_INTERACTIVECONTENT);
        foreach (chooser::content_items($default) as $item) {
            $this->assertNotNull($item->get_link()->param('template'));
            $this->assertNull($item->get_link()->param('add'));
        }
    }

    /**
     * The lib.php callbacks hand back the chooser's items.
     */
    public function test_callbacks(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/mavr/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $default = $this->default_item((int)$course->id);

        $expected = array_map(fn (content_item $item) => $item->get_name(), chooser::content_items($default));
        $courseitems = mod_mavr_get_course_content_items($default, get_admin(), $course);
        $this->assertSame($expected, array_map(fn (content_item $item) => $item->get_name(), $courseitems));
        $allitems = mod_mavr_get_all_content_items($default);
        $this->assertSame($expected, array_map(fn (content_item $item) => $item->get_name(), $allitems));
    }

    /**
     * Templates without a chooserhelp string fall back to '' in the base class.
     */
    public function test_chooser_help_fallback(): void {
        foreach (registry::get_all() as $name => $template) {
            $expected = get_string_manager()->string_exists('chooserhelp', 'mavrtemplate_' . $name)
                ? get_string('chooserhelp', 'mavrtemplate_' . $name) : '';
            $this->assertSame($expected, $template->get_chooser_help());
        }
    }

    /**
     * Render the form for a new activity in a course.
     *
     * @return string form HTML
     */
    private function render_new_form(): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/mavr/mod_form.php');
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        [, , $cw, $cm, $data] = prepare_new_moduleinfo_data($course, 'mavr', 0);
        $form = new \mod_mavr_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        return $form->render();
    }

    /**
     * A new activity with the template parameter shows that template's fields only.
     */
    public function test_form_with_template_parameter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $_GET['template'] = 'escape';

        $html = $this->render_new_form();
        $this->assertMatchesRegularExpression('/<input[^>]*name="template"[^>]*value="escape"/', $html);
        $this->assertStringContainsString('name="escape_title[0]"', $html);
        $this->assertStringNotContainsString('gallery_title', $html);
        $this->assertStringNotContainsString('name="mavr_choosetemplate"', $html);
        $this->assertStringContainsString(get_string('templatechosen', 'mavr'), $html);
    }

    /**
     * A new activity without (or with an unknown) template parameter asks for a template first.
     */
    public function test_form_without_template_parameter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $_GET['template'] = 'nosuchtemplate';

        $html = $this->render_new_form();
        $this->assertMatchesRegularExpression('/<select[^>]*name="template"/', $html);
        $this->assertStringContainsString('name="mavr_choosetemplate"', $html);
        $this->assertStringContainsString(get_string('choosetemplatebutton', 'mavr'), $html);
        $this->assertStringNotContainsString('escape_title', $html);
        $this->assertStringNotContainsString('gallery_title', $html);
    }
}
