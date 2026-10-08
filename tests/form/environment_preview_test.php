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

namespace mod_vrroom\form;

use mod_vrroom\local\scene\environment_registry;

/**
 * Tests of the room preview under the environment selectors of the activity settings.
 *
 * Uses the fixture pack tests/fixtures/envpack (room d's credit holds HTML special characters).
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(environment_preview::class)]
final class environment_preview_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        environment_registry::phpunit_set_packs(['testpack' => $CFG->dirroot . '/mod/vrroom/tests/fixtures/envpack']);
    }

    protected function tearDown(): void {
        environment_registry::phpunit_set_packs(null);
        parent::tearDown();
    }

    /**
     * The gallery's choices: the registry's, a stored room this site lacks, and the upload choice.
     *
     * @return array
     */
    private static function gallery_choices(): array {
        $choices = environment_registry::get_choices('rooms/gone');
        $choices['Upload'] = ['upload' => 'Upload my own model'];
        return $choices;
    }

    /**
     * The extras the gallery passes for its upload choice.
     *
     * @return array
     */
    private static function gallery_extra(): array {
        return [
            'upload' => [
                'name' => get_string('environmentupload', 'vrroomtemplate_gallery'),
                'placeholder' => get_string('envpreviewmodel', 'mod_vrroom'),
                'places' => 12,
            ],
        ];
    }

    /**
     * build_map(): presets with their pix picture, pack rooms with envfile.php and a credit, a missing
     * room and the upload choice with placeholders.
     */
    public function test_build_map(): void {
        $this->resetAfterTest();
        $map = environment_preview::build_map(
            self::gallery_choices(),
            ['previewplaces', 'vrroomtemplate_gallery'],
            self::gallery_extra()
        );
        $this->assertSame(
            ['gallery-hall', 'courtyard', 'escape-room', 'testpack/a', 'testpack/b', 'testpack/d', 'rooms/gone', 'upload'],
            array_keys($map)
        );

        $hall = $map['gallery-hall'];
        $this->assertSame(get_string('preset_galleryhall', 'mod_vrroom'), $hall['name']);
        $this->assertStringContainsString('environments/gallery-hall', $hall['url']);
        $this->assertSame('', $hall['credit']);
        $this->assertSame(get_string('previewplaces', 'vrroomtemplate_gallery', 10), $hall['places']);
        $this->assertSame('Places for 10 stations', $hall['places']);
        $this->assertSame('', $hall['placeholder']);

        $a = $map['testpack/a'];
        $this->assertStringContainsString('/mod/vrroom/envfile.php', $a['url']);
        $this->assertStringEndsWith('/testpack/1/a/preview.jpg', $a['url']);
        $credit = environment_registry::get('testpack/a')['credit'];
        $this->assertSame(get_string('envpreviewcredit', 'mod_vrroom', $credit), $a['credit']);
        $this->assertSame('Places for 4 stations', $a['places']);

        // The small room has two places.
        $this->assertSame('Places for 2 stations', $map['testpack/d']['places']);

        $gone = $map['rooms/gone'];
        $this->assertNull($gone['url']);
        $this->assertSame(get_string('envmissingoption', 'mod_vrroom', 'rooms/gone'), $gone['name']);
        $this->assertSame(get_string('envpreviewmissing', 'mod_vrroom'), $gone['placeholder']);
        $this->assertSame('', $gone['places']);

        $upload = $map['upload'];
        $this->assertNull($upload['url']);
        $this->assertSame(get_string('envpreviewmodel', 'mod_vrroom'), $upload['placeholder']);
        $this->assertSame('Places for 12 stations', $upload['places']);

        // Without a places string (the empty room) there is no places line.
        $map = environment_preview::build_map(environment_registry::get_choices());
        $this->assertSame('', $map['gallery-hall']['places']);
    }

    /**
     * add(): a static element under the selector holding the figure, its data map, and the AMD call.
     */
    public function test_add(): void {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();
        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_context(\context_system::instance());

        $mform = new \MoodleQuickForm('testform', 'post', '');
        $choices = self::gallery_choices();
        $mform->addElement('selectgroups', 'gallery_environment', 'Environment', $choices);
        environment_preview::add(
            $mform,
            'gallery_environment',
            $choices,
            'gallery-hall',
            ['previewplaces', 'vrroomtemplate_gallery'],
            self::gallery_extra()
        );
        $this->assertTrue($mform->elementExists('gallery_environment_preview'));
        $html = $mform->getElement('gallery_environment_preview')->toHtml();

        $name = get_string('preset_galleryhall', 'mod_vrroom');
        $this->assertStringContainsString('alt="' . s($name) . '"', $html);
        $this->assertStringContainsString('environments/gallery-hall', $html);

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $figure = (new \DOMXPath($dom))->query('//figure[@data-region="vrroom-envpreview"]')->item(0);
        $this->assertNotNull($figure);
        $expected = environment_preview::build_map(
            $choices,
            ['previewplaces', 'vrroomtemplate_gallery'],
            self::gallery_extra()
        );
        $this->assertSame($expected, json_decode($figure->getAttribute('data-previews'), true));
        $this->assertSame(get_string('envpreviewnone', 'mod_vrroom'), $figure->getAttribute('data-none'));

        // The AMD module is started with the select's id and the figure's id.
        $this->assertStringContainsString('mod_vrroom/environment_preview', $PAGE->requires->get_end_code());
    }

    /**
     * A credit with HTML special characters arrives escaped, in the caption and in the data map.
     */
    public function test_add_escapes_credit(): void {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();
        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_context(\context_system::instance());

        $mform = new \MoodleQuickForm('testform', 'post', '');
        $choices = environment_registry::get_choices();
        $mform->addElement('selectgroups', 'escape_environment', 'Environment', $choices);
        environment_preview::add($mform, 'escape_environment', $choices, 'testpack/d', ['previewplaces', 'vrroomtemplate_escape']);
        $html = $mform->getElement('escape_environment_preview')->toHtml();

        $credit = environment_registry::get('testpack/d')['credit'];
        $this->assertStringContainsString('<b>', $credit);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('Places for 2 puzzles', $html);
        // An unknown initial key shows the first choice.
        $mform = new \MoodleQuickForm('testform2', 'post', '');
        environment_preview::add($mform, 'blank_environment', $choices, 'moon-base');
        $html = $mform->getElement('blank_environment_preview')->toHtml();
        $this->assertStringContainsString('alt="' . s(get_string('preset_galleryhall', 'mod_vrroom')) . '"', $html);
    }
}
