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

namespace vrroomtemplate_tour;

use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\panorama;
use mod_vrroom\local\scene\scene_validator;

/**
 * Tests for the Virtual tour template: config extraction, files, validation and compile.
 *
 * @package    vrroomtemplate_tour
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template::class)]
final class template_test extends \advanced_testcase {
    /** @var string Fixed id of the first photo. */
    private const PHOTO1 = '5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11';

    /** @var string Fixed id of the second photo. */
    private const PHOTO2 = '7a0f3e2b-1c4d-4e5f-8a9b-0c1d2e3f4a5b';

    /**
     * A module context to hold mod_vrroom file areas (compile only reads files from it).
     *
     * @return \context_module
     */
    private function make_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return \context_module::instance($page->cmid);
    }

    /**
     * A JPEG made with GD (no binary fixtures).
     *
     * @param int $width
     * @param int $height
     * @return string JPEG bytes
     */
    private function make_photo(int $width = 2048, int $height = 1024): string {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, (int)($height / 2), imagecolorallocate($image, 120, 170, 230));
        imagefilledrectangle($image, 0, (int)($height / 2), $width - 1, $height - 1, imagecolorallocate($image, 90, 140, 60));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * Store a file in a mod_vrroom file area.
     *
     * @param \context_module $context
     * @param string $area
     * @param int $itemid
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function store_file(
        \context_module $context,
        string $area,
        int $itemid,
        string $filename,
        string $content
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_vrroom',
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Create a draft area of the current user holding one file.
     *
     * @param string $filename
     * @param string $content
     * @return int draft item id
     */
    private function make_draft(string $filename, string $content): int {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftitemid;
    }

    /**
     * Build a tour config with two photos.
     *
     * @param array[] $stops per-stop overrides
     * @param bool $ordered stops in order
     * @return \stdClass
     */
    private function make_config(array $stops, bool $ordered = false): \stdClass {
        $list = [];
        foreach (array_values($stops) as $i => $overrides) {
            $list[] = (object)array_merge([
                'id' => 'st' . ($i + 1),
                'photo' => self::PHOTO1,
                'title' => 'Stop ' . ($i + 1),
                'text' => '<p>Text ' . ($i + 1) . '</p>',
                'textformat' => '1',
                'yaw' => 30.0 * $i,
                'pitch' => 5.0,
                'mediatype' => 'none',
                'fileitemid' => 1000 + $i,
                'required' => true,
                'points' => 2,
            ], $overrides);
        }
        return (object)[
            'schema' => template::SCHEMA,
            'ordered' => $ordered,
            'photos' => [
                (object)['id' => self::PHOTO1, 'title' => 'Lobby', 'fileitemid' => 1, 'northoffset' => 0.0],
                (object)['id' => self::PHOTO2, 'title' => 'Lab', 'fileitemid' => 2, 'northoffset' => 45.0],
            ],
            'stops' => $list,
        ];
    }

    /**
     * Store the two photos of make_config().
     *
     * @param \context_module $context
     */
    private function store_photos(\context_module $context): void {
        $this->store_file($context, 'environment', 1, 'lobby.jpg', $this->make_photo());
        $this->store_file($context, 'environment', 2, 'lab.jpg', $this->make_photo());
    }

    /**
     * Index a list of id-carrying arrays by id.
     *
     * @param array[] $items
     * @return array[]
     */
    private function by_id(array $items): array {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item['id']] = $item;
        }
        return $indexed;
    }

    /**
     * Assert the compiled scenes and graph pass the mod_vrroom validators.
     *
     * @param \mod_vrroom\local\template\compiled_activity $compiled
     */
    private function assert_valid(\mod_vrroom\local\template\compiled_activity $compiled): void {
        foreach ($compiled->scenes as $scene) {
            $this->assertSame([], scene_validator::validate($scene));
        }
        $this->assertSame([], scene_validator::validate_set($compiled->scenes));
        $this->assertSame([], graph_validator::validate($compiled->graph));
        $this->assertSame($compiled->graph, json_decode(json_encode($compiled->graph, JSON_PRESERVE_ZERO_FRACTION), true));
    }

    /**
     * Name, completion rules, default grade and chooser help.
     */
    public function test_basics(): void {
        $template = new template();
        $this->assertSame('tour', $template->get_name());
        $this->assertSame('vrroomtemplate_tour', $template->get_component());
        $this->assertSame(get_string('pluginname', 'vrroomtemplate_tour'), $template->get_display_name());
        $this->assertSame('Virtual tour', $template->get_display_name());
        $this->assertSame(['completionobjectives'], $template->completion_rules());
        $this->assertSame(100, $template->default_grade());
        $this->assertSame(get_string('chooserhelp', 'vrroomtemplate_tour'), $template->get_chooser_help());
    }

    /**
     * Unordered: one panorama scene per photo, arrows between them, a hotspot and objective per stop.
     */
    public function test_compile_unordered(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_photos($context);
        $this->store_file($context, 'media', 1001, 'clip.mp3', 'audio bytes');
        $config = $this->make_config([
            [],
            ['photo' => self::PHOTO2, 'mediatype' => 'audio', 'required' => false],
            ['photo' => 'unknown-photo'],
        ]);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        $this->assertSame([], $compiled->slots);
        $this->assertCount(2, $compiled->scenes);
        [$lobby, $lab] = $compiled->scenes;

        $this->assertSame(self::PHOTO1, $lobby['id']);
        $this->assertSame('Lobby', $lobby['name']);
        $this->assertSame('panorama', $lobby['environment']['type']);
        $this->assertSame(
            ['area' => 'environment', 'itemid' => 1, 'filepath' => '/', 'filename' => 'lobby.jpg'],
            $lobby['environment']['file']
        );
        $this->assertEquals(0.0, $lobby['environment']['northoffset']);
        $this->assertEquals(45.0, $lab['environment']['northoffset']);
        $this->assertSame([['id' => 'sp-' . self::PHOTO1, 'position' => [0.0, 0.0, 0.0], 'yaw' => 0.0]], $lobby['spawn']);
        $this->assertSame([], $lobby['zones']);

        // The first photo has only a "next" arrow, the last only a "back" arrow.
        $lobbyitems = $this->by_id($lobby['interactables']);
        $labitems = $this->by_id($lab['interactables']);
        $this->assertArrayHasKey('pn-' . self::PHOTO1, $lobbyitems);
        $this->assertArrayNotHasKey('pp-' . self::PHOTO1, $lobbyitems);
        $this->assertArrayHasKey('pp-' . self::PHOTO2, $labitems);
        $this->assertArrayNotHasKey('pn-' . self::PHOTO2, $labitems);
        $next = $lobbyitems['pn-' . self::PHOTO1];
        $this->assertSame('portal', $next['kind']);
        $this->assertSame('Lab', $next['name']);
        $this->assertSame(['scene' => self::PHOTO2, 'spawn' => 'sp-' . self::PHOTO2], $next['target']);
        $this->assertEquals(['yaw' => 0.0, 'pitch' => -25.0, 'distance' => 4.0], $next['placement']);
        $back = $labitems['pp-' . self::PHOTO2];
        $this->assertSame(['scene' => self::PHOTO1, 'spawn' => 'sp-' . self::PHOTO1], $back['target']);
        $this->assertEquals(180.0, $back['placement']['yaw']);

        // Stops: a stop on an unknown photo lands on the first one.
        $this->assertArrayHasKey('h-st1', $lobbyitems);
        $this->assertArrayHasKey('h-st3', $lobbyitems);
        $this->assertArrayHasKey('h-st2', $labitems);
        $hotspot = $lobbyitems['h-st1'];
        $this->assertSame('hotspot', $hotspot['kind']);
        $this->assertSame('Stop 1', $hotspot['content']['title']);
        $this->assertSame('<p>Text 1</p>', $hotspot['content']['text']);
        $this->assertSame([], $hotspot['content']['media']);
        $this->assertSame(
            [['area' => 'media', 'itemid' => 1001, 'filepath' => '/', 'filename' => 'clip.mp3']],
            $labitems['h-st2']['content']['media']
        );

        $objectives = $this->by_id($compiled->graph['objectives']);
        $this->assertCount(3, $objectives);
        $this->assertSame('interact', $objectives['v-st1']['type']);
        $this->assertSame('h-st1', $objectives['v-st1']['target']);
        $this->assertSame(2, $objectives['v-st1']['points']);
        $this->assertTrue($objectives['v-st1']['required']);
        $this->assertFalse($objectives['v-st2']['required']);
        $this->assertArrayNotHasKey('unlock', $objectives['v-st2']);
        $this->assertArrayNotHasKey('s-order', $objectives);
        $this->assertSame(get_string('objectivevisit', 'vrroomtemplate_tour', 'Stop 1'), $objectives['v-st1']['title']);

        $rules = $this->by_id($compiled->graph['rules']);
        $this->assertSame(['on' => 'all_required_completed'], $rules['r-finish']['on']);
        $this->assertSame([['do' => 'finish']], $rules['r-finish']['actions']);
    }

    /**
     * Ordered: each stop unlocks after the previous one, plus a non-required sequence.
     */
    public function test_compile_ordered(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_photos($context);
        $config = $this->make_config([[], ['photo' => self::PHOTO2], []], true);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        $objectives = $this->by_id($compiled->graph['objectives']);
        $this->assertArrayNotHasKey('unlock', $objectives['v-st1']);
        $this->assertSame(['after' => ['v-st1']], $objectives['v-st2']['unlock']);
        $this->assertSame(['after' => ['v-st2']], $objectives['v-st3']['unlock']);
        $this->assertSame('sequence', $objectives['s-order']['type']);
        $this->assertSame(['v-st1', 'v-st2', 'v-st3'], $objectives['s-order']['steps']);
        $this->assertFalse($objectives['s-order']['required']);
    }

    /**
     * Every marker writes both its placement and the position panorama::placement_position() derives.
     */
    public function test_compile_placement(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_photos($context);
        $config = $this->make_config([['yaw' => 90.0, 'pitch' => 20.0], ['yaw' => -135.5, 'pitch' => -30.0]]);

        $compiled = (new template())->compile($config, $context);
        $this->assert_valid($compiled);
        foreach ($compiled->scenes as $scene) {
            foreach ($scene['interactables'] as $item) {
                $placement = $item['placement'];
                $this->assertEquals(
                    panorama::placement_position(
                        (float)$placement['yaw'],
                        (float)$placement['pitch'],
                        (float)$placement['distance']
                    ),
                    $item['position'],
                    $item['id']
                );
                $this->assertEquals([0.0, (float)$placement['yaw'], 0.0], $item['rotation'], $item['id']);
            }
        }
        $items = $this->by_id($compiled->scenes[0]['interactables']);
        $this->assertEquals(['yaw' => 90.0, 'pitch' => 20.0, 'distance' => 4.0], $items['h-st1']['placement']);
        $this->assertEquals(['yaw' => -135.5, 'pitch' => -30.0, 'distance' => 4.0], $items['h-st2']['placement']);
    }

    /**
     * Compile is deterministic, and a missing photo file is reported.
     */
    public function test_compile_deterministic_and_missing_photo(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_photos($context);
        $config = $this->make_config([[], ['photo' => self::PHOTO2]], true);
        $first = (new template())->compile($config, $context);
        $second = (new template())->compile(json_decode(json_encode($config)), $context);
        $this->assertSame(json_encode($first->scenes), json_encode($second->scenes));
        $this->assertSame(json_encode($first->graph), json_encode($second->graph));

        get_file_storage()->delete_area_files($context->id, 'mod_vrroom', 'environment', 2);
        $this->expectException(\moodle_exception::class);
        (new template())->compile($config, $context);
    }

    /**
     * A tour with no stops is a valid walk-through with no objectives and no finish rule.
     */
    public function test_compile_without_stops(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $this->store_photos($context);
        $compiled = (new template())->compile($this->make_config([]), $context);
        $this->assert_valid($compiled);
        $this->assertSame([], $compiled->graph['objectives']);
        $this->assertSame([], $compiled->graph['rules']);
    }

    /**
     * Form data without file fields (generator, web services) still makes a config.
     */
    public function test_extract_config_without_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $formdata = (object)[
            'tour_ordered' => 1,
            'tour_photoid' => ['', 'forged-id', ''],
            'tour_phototitle' => ['  Lobby  ', '', 'Lab'],
            'tour_northoffset' => [12.34, 0, 400],
            'tour_stopid' => ['', ''],
            'tour_stopphoto' => [2, 1],
            'tour_stoptitle' => ['Desk', 'Window'],
            'tour_stoptext' => [
                ['text' => '<p>A desk</p>', 'format' => FORMAT_HTML],
                ['text' => 'Glass', 'format' => FORMAT_PLAIN],
            ],
            'tour_yaw' => [45, -200],
            'tour_pitch' => [10, 90],
            'tour_mediatype' => ['video', 'bogus'],
            'tour_required' => [1, 0],
            'tour_points' => [3, -1],
        ];
        $config = (new template())->extract_config($formdata);

        $this->assertSame(template::SCHEMA, $config->schema);
        $this->assertTrue($config->ordered);
        $this->assertCount(2, $config->photos);
        [$lobby, $lab] = $config->photos;
        $this->assertSame('Lobby', $lobby->title);
        $this->assertSame(1, $lobby->fileitemid);
        $this->assertEquals(12.3, $lobby->northoffset);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $lobby->id);
        $this->assertSame('Lab', $lab->title);
        $this->assertSame(2, $lab->fileitemid);
        $this->assertEquals(180.0, $lab->northoffset);

        [$desk, $window] = $config->stops;
        // Row 2 is the second photo kept; row 1 (blank) is not a photo, so the stop falls back to the first.
        $this->assertSame($lab->id, $desk->photo);
        $this->assertSame($lobby->id, $window->photo);
        $this->assertSame(1000, $desk->fileitemid);
        $this->assertSame(1001, $window->fileitemid);
        $this->assertSame('video', $desk->mediatype);
        $this->assertSame('none', $window->mediatype);
        $this->assertEquals(45.0, $desk->yaw);
        $this->assertEquals(-180.0, $window->yaw);
        $this->assertEquals(85.0, $window->pitch);
        $this->assertSame(3, $desk->points);
        $this->assertSame(0, $window->points);
        $this->assertTrue($desk->required);
        $this->assertFalse($window->required);
        $this->assertSame((string)FORMAT_PLAIN, $window->textformat);
    }

    /**
     * Editing keeps the stored photo (scene) and stop ids, so the rooms survive an edit.
     */
    public function test_extract_config_keeps_stored_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $stored = $this->make_config([['id' => 'keepme']]);
        $vrroomid = $DB->insert_record('vrroom', (object)[
            'course' => $course->id,
            'name' => 'Tour',
            'runtime' => 'builtin',
            'template' => 'tour',
            'templateconfig' => json_encode($stored),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $formdata = (object)[
            'instance' => $vrroomid,
            'tour_photoid' => [self::PHOTO1, self::PHOTO2, self::PHOTO1],
            'tour_phototitle' => ['Lobby renamed', 'Lab', 'Copy'],
            'tour_northoffset' => [0, 0, 0],
            'tour_stopid' => ['keepme', 'keepme'],
            'tour_stopphoto' => [1, 0],
            'tour_stoptitle' => ['Renamed', 'Copy'],
            'tour_mediatype' => ['none', 'none'],
        ];
        $config = (new template())->extract_config($formdata);

        $this->assertSame(self::PHOTO1, $config->photos[0]->id);
        $this->assertSame(self::PHOTO2, $config->photos[1]->id);
        // The same id posted twice is kept once only.
        $this->assertNotSame(self::PHOTO1, $config->photos[2]->id);
        $this->assertSame('keepme', $config->stops[0]->id);
        $this->assertSame(self::PHOTO2, $config->stops[0]->photo);
        $this->assertNotSame('keepme', $config->stops[1]->id);

        // A config for another activity does not lend its ids.
        $formdata->instance = 0;
        $fresh = (new template())->extract_config($formdata);
        $this->assertNotSame(self::PHOTO1, $fresh->photos[0]->id);
        $this->assertNotSame('keepme', $fresh->stops[0]->id);
    }

    /**
     * Photos are saved with their copies, media per stop, and anything else is cleaned up.
     */
    public function test_save_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('panodesktopwidth', 1536, 'mod_vrroom');
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $context = $this->make_context();
        $fs = get_file_storage();
        // Leftovers from another template.
        $this->store_file($context, 'environment', 0, 'room.glb', 'glb');
        $this->store_file($context, 'models', 1, 'cube.glb', 'glb');
        $this->store_file($context, 'media', 1, 'old.png', 'png');

        $formdata = (object)[
            'tour_phototitle' => ['Lobby', '', 'Lab'],
            'tour_photofile' => [
                $this->make_draft('lobby.jpg', $this->make_photo()),
                0,
                $this->make_draft('lab.jpg', $this->make_photo()),
            ],
            'tour_stoptitle' => ['Desk', '', 'Window'],
            'tour_stopphoto' => [0, 0, 2],
            'tour_mediatype' => ['audio', 'none', 'image'],
            'tour_media' => [$this->make_draft('clip.mp3', 'mp3'), 0, $this->make_draft('view.png', 'png')],
        ];
        $template = new template();
        $template->save_files($formdata, $context);

        // The blank photo row is skipped, so the lab is photo 2.
        $lobbyfiles = $fs->get_area_files($context->id, 'mod_vrroom', 'environment', 1, 'filepath, filename', false);
        $this->assertCount(3, $lobbyfiles);
        $lobby = null;
        foreach ($lobbyfiles as $file) {
            if ($file->get_filepath() === '/') {
                $lobby = $file;
            }
        }
        $this->assertNotNull($lobby);
        $this->assertSame('lobby.jpg', $lobby->get_filename());
        $variants = panorama::find_variants($lobby);
        $this->assertNotNull($variants['desktop']);
        $this->assertNotNull($variants['mobile']);
        $this->assertSame('/variants/', $variants['mobile']->get_filepath());
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'environment', 2));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'environment', 0));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'models'));

        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 1000));
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 1001));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'media', 1));

        // The compiled scene references the original, never a copy.
        $config = $template->extract_config($formdata);
        $compiled = $template->compile($config, $context);
        $this->assertSame('/', $compiled->scenes[0]['environment']['file']['filepath']);
        $this->assertSame('lobby.jpg', $compiled->scenes[0]['environment']['file']['filename']);

        // Now one photo and one text-only stop: everything else goes.
        $template->save_files((object)[
            'tour_phototitle' => ['Lobby'],
            'tour_stoptitle' => ['Desk'],
            'tour_mediatype' => ['none'],
        ], $context);
        $this->assertFalse($fs->is_area_empty($context->id, 'mod_vrroom', 'environment', 1));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'environment', 2));
        $this->assertTrue($fs->is_area_empty($context->id, 'mod_vrroom', 'media'));
    }

    /**
     * The form's draft area holds the photo only; saving it back unchanged keeps the copies.
     */
    public function test_draft_round_trip_keeps_variants(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('panodesktopwidth', 1536, 'mod_vrroom');
        set_config('panomobilewidth', 1024, 'mod_vrroom');
        $context = $this->make_context();
        $fs = get_file_storage();
        $template = new template();

        $template->save_files((object)[
            'tour_phototitle' => ['Lobby'],
            'tour_photofile' => [$this->make_draft('lobby.jpg', $this->make_photo())],
        ], $context);
        $config = $template->extract_config((object)['tour_phototitle' => ['Lobby']]);
        $before = [];
        foreach ($fs->get_area_files($context->id, 'mod_vrroom', 'environment', 1, 'filepath, filename', false) as $file) {
            $before[$file->get_filepath() . $file->get_filename()] = $file->get_id();
        }
        $this->assertCount(3, $before);

        $defaults = [];
        $template->data_preprocessing($defaults, $config, $context);
        $draftitemid = (int)$defaults['tour_photofile'][0];
        $usercontext = \context_user::instance(get_admin()->id);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filepath, filename', false);
        $this->assertCount(1, $draftfiles);
        $this->assertSame('lobby.jpg', reset($draftfiles)->get_filename());

        $template->save_files((object)['tour_phototitle' => ['Lobby'], 'tour_photofile' => [$draftitemid]], $context);
        $after = [];
        foreach ($fs->get_area_files($context->id, 'mod_vrroom', 'environment', 1, 'filepath, filename', false) as $file) {
            $after[$file->get_filepath() . $file->get_filename()] = $file->get_id();
        }
        $this->assertSame($before, $after);
    }

    /**
     * Valid form data (real draft photos) passes validation.
     */
    public function test_validation_valid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $data = [
            'tour_phototitle' => ['Lobby', ''],
            'tour_photofile' => [$this->make_draft('lobby.jpg', $this->make_photo()), 0],
            'tour_northoffset' => [-90, 0],
            'tour_stoptitle' => ['Desk', ''],
            'tour_stoptext' => array_fill(0, 2, ['text' => '', 'format' => FORMAT_HTML]),
            'tour_stopphoto' => [0, 0],
            'tour_yaw' => [180, 0],
            'tour_pitch' => [-85, 0],
            'tour_mediatype' => ['audio', 'none'],
            'tour_media' => [$this->make_draft('clip.mp3', 'mp3'), 0],
            'tour_required' => [1, 1],
            'tour_points' => [5, 1],
        ];
        $this->assertSame([], (new template())->validation($data, [], (int)$course->id));
    }

    /**
     * Each validation rule reports on the right element.
     */
    public function test_validation_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $courseid = (int)$this->getDataGenerator()->create_course()->id;
        $template = new template();
        $component = 'vrroomtemplate_tour';

        // No photos at all.
        $errors = $template->validation(['tour_phototitle' => ['', ''], 'tour_stoptitle' => ['']], [], $courseid);
        $this->assertSame(get_string('errornophotos', $component), $errors['tour_phototitle[0]']);

        $data = [
            'tour_phototitle' => ['Lobby', 'No file', '', 'Square', ''],
            'tour_photofile' => [
                $this->make_draft('lobby.jpg', $this->make_photo()),
                0,
                $this->make_draft('untitled.jpg', $this->make_photo()),
                $this->make_draft('square.jpg', $this->make_photo(1024, 1024)),
                0,
            ],
            'tour_northoffset' => [200, 0, 0, 0, 0],
            'tour_stoptitle' => ['Blank photo', 'Angles', 'No media', 'Wrong media', 'Points', ''],
            'tour_stoptext' => array_fill(0, 6, ['text' => '', 'format' => FORMAT_HTML]),
            'tour_stopphoto' => [4, 0, 0, 0, 0, 0],
            'tour_yaw' => [0, 181, 0, 0, 0, 0],
            'tour_pitch' => [0, -86, 0, 0, 0, 0],
            'tour_mediatype' => ['none', 'none', 'video', 'audio', 'none', 'none'],
            'tour_media' => [0, 0, 0, $this->make_draft('movie.mp4', 'mp4'), 0, 0],
            'tour_required' => array_fill(0, 6, 1),
            'tour_points' => [1, 1, 1, 1, -3, 1],
        ];
        $data['tour_stoptext'][5] = ['text' => '<p>Orphan text</p>', 'format' => FORMAT_HTML];
        $errors = $template->validation($data, [], $courseid);

        $this->assertSame(get_string('errornorthoffset', $component), $errors['tour_northoffset[0]']);
        $this->assertArrayNotHasKey('tour_photofile[0]', $errors);
        $this->assertSame(get_string('errorphotofile', $component), $errors['tour_photofile[1]']);
        $this->assertSame(get_string('errorphototitle', $component), $errors['tour_phototitle[2]']);
        $limits = (object)panorama::limits();
        $limits->maxsize = display_size((int)$limits->maxbytes);
        $this->assertSame(get_string('panoramabadratio', 'mod_vrroom', $limits), $errors['tour_photofile[3]']);
        $this->assertSame(get_string('errorstopphoto', $component), $errors['tour_stopphoto[0]']);
        $this->assertSame(get_string('erroryaw', $component), $errors['tour_yaw[1]']);
        $this->assertSame(get_string('errorpitch', $component), $errors['tour_pitch[1]']);
        $this->assertSame(get_string('errormediarequired', $component), $errors['tour_media[2]']);
        $this->assertSame(get_string('errormediatype', $component, 'mp3, ogg, oga, m4a'), $errors['tour_media[3]']);
        $this->assertSame(get_string('errorpoints', $component, template::MAX_POINTS), $errors['tour_points[4]']);
        $this->assertSame(get_string('errorstoptitle', $component), $errors['tour_stoptitle[5]']);
    }

    /**
     * The activity form for a new tour shows the tour fields.
     */
    public function test_form_fields(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/vrroom/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        \mod_vrroom\local\template\registry::reset_caches();
        $_GET['template'] = 'tour';

        try {
            $course = $this->getDataGenerator()->create_course();
            $PAGE->set_course($course);
            $PAGE->set_url('/course/modedit.php', ['add' => 'vrroom', 'course' => $course->id, 'section' => 0]);
            [, , $cw, $cm, $data] = prepare_new_moduleinfo_data($course, 'vrroom', 0);
            $form = new \mod_vrroom_mod_form($data, $cw->section, $cm, $course);
            $form->set_data($data);
            $html = $form->render();
        } finally {
            unset($_GET['template']);
        }

        $this->assertStringContainsString('name="tour_ordered"', $html);
        $this->assertStringContainsString('name="tour_phototitle[0]"', $html);
        $this->assertStringContainsString('name="tour_phototitle[1]"', $html);
        $this->assertStringContainsString('name="tour_photofile[0]"', $html);
        $this->assertStringContainsString('name="tour_stoptitle[2]"', $html);
        $this->assertStringContainsString('name="tour_stopphoto[0]"', $html);
        $this->assertStringContainsString('id_tour_pick_0', $html);
        $this->assertStringContainsString('name="tour_addphotos"', $html);
        $this->assertStringContainsString('name="tour_addstops"', $html);
    }
}
