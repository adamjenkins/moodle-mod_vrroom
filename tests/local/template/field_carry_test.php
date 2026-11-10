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

/**
 * Tests of keeping a template's fields across template switches in the activity form.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(field_carry::class)]
final class field_carry_test extends \advanced_testcase {
    /** @var array|null $_POST before the test. */
    private ?array $savedpost = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->savedpost = $_POST;
    }

    #[\Override]
    protected function tearDown(): void {
        $_POST = $this->savedpost;
        parent::tearDown();
    }

    /**
     * Only the fields named after the template are its fields.
     *
     * @return void
     */
    public function test_fields_of(): void {
        $post = ['name' => 'Room', 'gallery_title' => ['Vase'], 'gallery_stationcount' => 1, 'escape_story' => 'x',
            'galleryx' => 1];
        $this->assertSame(['gallery_title' => ['Vase'], 'gallery_stationcount' => 1], field_carry::fields_of($post, 'gallery'));
        $this->assertSame(['escape_story' => 'x'], field_carry::fields_of($post, 'escape'));
        $this->assertSame([], field_carry::fields_of($post, 'tour'));
    }

    /**
     * Gallery, then Escape, then Gallery: the gallery's station title comes back; what was typed in
     * Escape is kept in turn.
     *
     * @return void
     */
    public function test_carry_round_trip(): void {
        // Gallery page, the template select changed to Escape.
        $post = ['name' => 'Room', 'template' => 'escape', 'vrroom_formtemplate' => 'gallery',
            'gallery_title' => ['Vase'], 'gallery_stationcount' => 1];
        [$post1, $kept] = field_carry::carry($post, [], 'gallery', 'escape');
        $this->assertSame($post, $post1);
        $this->assertSame(['gallery' => ['gallery_title' => ['Vase'], 'gallery_stationcount' => 1]], $kept);

        // Escape page, a story typed, the select changed back to Gallery.
        $post = ['name' => 'Room', 'template' => 'gallery', 'vrroom_formtemplate' => 'escape', 'escape_story' => 'Locked in'];
        [$post2, $kept] = field_carry::carry($post, $kept, 'escape', 'gallery');
        $this->assertSame(['Vase'], $post2['gallery_title']);
        $this->assertSame(1, $post2['gallery_stationcount']);
        $this->assertSame('Room', $post2['name']);
        $this->assertSame(['escape_story' => 'Locked in'], $kept['escape']);
    }

    /**
     * Values just submitted for the template shown are never replaced by kept ones.
     *
     * @return void
     */
    public function test_carry_never_overwrites_posted_fields(): void {
        $kept = ['gallery' => ['gallery_title' => ['Old']]];
        $post = ['vrroom_formtemplate' => 'gallery', 'gallery_title' => ['New']];
        [$result] = field_carry::carry($post, $kept, 'gallery', 'gallery');
        $this->assertSame(['New'], $result['gallery_title']);
    }

    /**
     * apply() works on $_POST and the session: a switch reload keeps and brings back fields, a
     * fresh form forgets them.
     *
     * @return void
     */
    public function test_apply(): void {
        global $SESSION;
        $this->resetAfterTest();
        $_POST = ['vrroom_choosetemplate' => '1', 'vrroom_formtemplate' => 'gallery', 'gallery_title' => ['Vase']];
        field_carry::apply('2:section0', 'escape', true, 'gallery');
        $this->assertSame(['gallery_title' => ['Vase']], $SESSION->{field_carry::SESSION_KEY}['2:section0']['gallery']);

        $_POST = ['vrroom_choosetemplate' => '1', 'vrroom_formtemplate' => 'escape'];
        field_carry::apply('2:section0', 'gallery', true, 'escape');
        $this->assertSame(['Vase'], $_POST['gallery_title']);

        // Another form of the same user is not affected.
        $_POST = ['vrroom_choosetemplate' => '1', 'vrroom_formtemplate' => 'escape'];
        field_carry::apply('3:section0', 'gallery', true, 'escape');
        $this->assertArrayNotHasKey('gallery_title', $_POST);

        // A save (not the switch button) changes nothing.
        $_POST = ['vrroom_formtemplate' => 'gallery'];
        field_carry::apply('2:section0', 'gallery', false, 'gallery');
        $this->assertSame(['vrroom_formtemplate' => 'gallery'], $_POST);

        // The form shown afresh forgets what was kept.
        $_POST = [];
        field_carry::apply('2:section0', 'gallery', false, '');
        $this->assertArrayNotHasKey('2:section0', $SESSION->{field_carry::SESSION_KEY});
    }
}
