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

namespace mod_vrroom\local\scene;

/**
 * Tests for the vrroom-scene/1 validator.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scene_validator::class)]
final class scene_validator_test extends \advanced_testcase {
    /**
     * The example scene of the build contract (§4.1), with zone z-2 added because the example's
     * image refers to it.
     *
     * @return array
     */
    public static function valid_scene(): array {
        return json_decode(<<<'JSON'
{
  "schema": "vrroom-scene/1",
  "id": "5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11",
  "name": "Gallery",
  "environment": {
    "type": "preset",
    "preset": "gallery-hall",
    "shape": {"kind": "hall", "width": 12, "length": 30, "height": 5,
              "wallcolor": "#e8e4dc", "floorcolor": "#8a7f72", "ceiling": true},
    "sky": "#20242a",
    "lights": [{"type": "ambient", "intensity": 0.7},
               {"type": "directional", "intensity": 0.9, "position": [4, 10, 6]}],
    "bounds": {"min": [-5.6, 0, -14.6], "max": [5.6, 0, 14.6]}
  },
  "spawn": [{"id": "spawn-main", "position": [0, 0, 13], "yaw": 0}],
  "zones": [
    {"id": "z-1", "name": "The Vase", "center": [-3, 1.5, 9], "size": [3, 3, 3]},
    {"id": "z-2", "name": "Portrait", "center": [3, 1.5, 9], "size": [3, 3, 3]}
  ],
  "props": [
    {"id": "p-1", "shape": "box", "position": [-4.5, 0.5, 9], "size": [0.9, 1.0, 0.9], "color": "#d9d4cc"}
  ],
  "interactables": [
    {"id": "h-1", "kind": "hotspot", "name": "The Vase", "zone": "z-1",
     "position": [-3.9, 1.4, 9], "rotation": [0, 90, 0],
     "content": {"title": "The Vase", "text": "<p>Made in 1720.</p>", "textformat": "1",
                 "media": [], "question": 1}},
    {"id": "m-1", "kind": "model", "name": "The Vase", "zone": "z-1",
     "position": [-4.5, 1.0, 9], "rotation": [0, 90, 0], "scale": [1, 1, 1], "fit": 1.0,
     "file": {"area": "models", "itemid": 1, "filepath": "/", "filename": "vase.glb"},
     "opens": "h-1"},
    {"id": "i-2", "kind": "image", "name": "Portrait", "zone": "z-2",
     "position": [4.5, 1.6, 9], "rotation": [0, -90, 0], "width": 1.6,
     "file": {"area": "media", "itemid": 2, "filepath": "/", "filename": "portrait.jpg"},
     "opens": "h-1"},
    {"id": "t-3", "kind": "text", "name": "Notice", "position": [0, 2.2, -13.5],
     "rotation": [0, 0, 0], "text": "Welcome", "width": 2.0}
  ]
}
JSON
, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The contract's example is valid; so is a model environment.
     */
    public function test_valid(): void {
        $this->assertSame([], scene_validator::validate(self::valid_scene()));

        $scene = self::valid_scene();
        $scene['environment'] = [
            'type' => 'model',
            'file' => ['area' => 'environment', 'itemid' => 0, 'filepath' => '/', 'filename' => 'room.glb'],
            'sky' => '#9cc9ef',
            'lights' => [['type' => 'hemisphere', 'intensity' => 0.8]],
            'bounds' => ['min' => [-9, 0, -9], 'max' => [9, 0, 9]],
        ];
        $this->assertSame([], scene_validator::validate($scene));
    }

    /**
     * One broken scene per rule.
     *
     * @return array name => [mutator]
     */
    public static function invalid_provider(): array {
        return [
            'wrong schema' => [fn(array &$s) => $s['schema'] = 'vrroom-scene/2'],
            'missing id' => [function (array &$s) {
                unset($s['id']);
            }],
            'missing name' => [fn(array &$s) => $s['name'] = ''],
            'unknown environment type' => [fn(array &$s) => $s['environment']['type'] = 'skybox'],
            'unknown preset' => [fn(array &$s) => $s['environment']['preset'] = 'moon-base'],
            'unknown shape kind' => [fn(array &$s) => $s['environment']['shape']['kind'] = 'dome'],
            'model environment without file' => [function (array &$s) {
                $s['environment'] = ['type' => 'model'];
            }],
            'no spawn' => [fn(array &$s) => $s['spawn'] = []],
            'duplicate id across lists' => [fn(array &$s) => $s['props'][0]['id'] = 'z-1'],
            'bad id characters' => [fn(array &$s) => $s['zones'][0]['id'] = 'z 1'],
            'vector of two' => [fn(array &$s) => $s['spawn'][0]['position'] = [0, 0]],
            'vector with a string' => [fn(array &$s) => $s['zones'][0]['center'] = [0, '1', 0]],
            'vector with infinity' => [fn(array &$s) => $s['props'][0]['position'] = [0, INF, 0]],
            'zone size zero' => [fn(array &$s) => $s['zones'][0]['size'] = [3, 0, 3]],
            'prop shape' => [fn(array &$s) => $s['props'][0]['shape'] = 'pyramid'],
            'unknown kind' => [fn(array &$s) => $s['interactables'][0]['kind'] = 'teleporter'],
            'portal without target' => [fn(array &$s) => $s['interactables'][3]['kind'] = 'portal'],
            'portal target without spawn' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'pt-1', 'kind' => 'portal', 'position' => [0, 0, 0],
                    'target' => ['scene' => 'room-2']];
            }],
            'portal target scene id too long' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'pt-1', 'kind' => 'portal', 'position' => [0, 0, 0],
                    'target' => ['scene' => str_repeat('a', 37), 'spawn' => 's-1']];
            }],
            'portal height zero' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'pt-1', 'kind' => 'portal', 'position' => [0, 0, 0], 'height' => 0,
                    'target' => ['scene' => 'room-2', 'spawn' => 's-1']];
            }],
            'grabbable without shape or file' => [fn(array &$s) => $s['interactables'][3]['kind'] = 'grabbable'],
            'grabbable size zero' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'g-1', 'kind' => 'grabbable', 'position' => [0, 0, 0], 'shape' => 'box',
                    'size' => [0.2, 0, 0.2]];
            }],
            'grabbable colour' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'g-1', 'kind' => 'grabbable', 'position' => [0, 0, 0], 'shape' => 'box',
                    'color' => 'gold'];
            }],
            'grabbable file in media area' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'g-1', 'kind' => 'grabbable', 'position' => [0, 0, 0],
                    'file' => ['area' => 'media', 'itemid' => 1, 'filepath' => '/', 'filename' => 'a.glb']];
            }],
            'media without type' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'a-1', 'kind' => 'media', 'position' => [0, 0, 0],
                    'file' => ['area' => 'assets', 'itemid' => 2, 'filepath' => '/', 'filename' => 'tape.mp3']];
            }],
            'media without file' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'a-1', 'kind' => 'media', 'media' => 'audio', 'position' => [0, 0, 0]];
            }],
            'media loop not boolean' => [function (array &$s) {
                $s['interactables'][3] = ['id' => 'a-1', 'kind' => 'media', 'media' => 'audio', 'position' => [0, 0, 0],
                    'file' => ['area' => 'assets', 'itemid' => 2, 'filepath' => '/', 'filename' => 'tape.mp3'], 'loop' => 1];
            }],
            'scene id too long' => [fn(array &$s) => $s['id'] = str_repeat('a', 37)],
            'xr not an object' => [fn(array &$s) => $s['xr'] = [1, 2]],
            'xr arscale too big' => [fn(array &$s) => $s['xr'] = ['arscale' => 2]],
            'xr arscale too small' => [fn(array &$s) => $s['xr'] = ['arscale' => 0.001]],
            'spawn name not a string' => [fn(array &$s) => $s['spawn'][0]['name'] = 5],
            'environment file in models area' => [function (array &$s) {
                $s['environment'] = ['type' => 'model',
                    'file' => ['area' => 'models', 'itemid' => 0, 'filepath' => '/', 'filename' => 'a.glb']];
            }],
            'zone reference' => [fn(array &$s) => $s['interactables'][0]['zone'] = 'z-9'],
            'opens reference' => [fn(array &$s) => $s['interactables'][1]['opens'] = 'h-9'],
            'model without file' => [function (array &$s) {
                unset($s['interactables'][1]['file']);
            }],
            'model file in media area' => [fn(array &$s) => $s['interactables'][1]['file']['area'] = 'media'],
            'image file in models area' => [fn(array &$s) => $s['interactables'][2]['file']['area'] = 'models'],
            'question zero' => [fn(array &$s) => $s['interactables'][0]['content']['question'] = 0],
            'question string' => [fn(array &$s) => $s['interactables'][0]['content']['question'] = '1'],
            'textformat not a string' => [fn(array &$s) => $s['interactables'][0]['content']['textformat'] = 1],
            'file path traversal' => [fn(array &$s) => $s['interactables'][1]['file']['filepath'] = '/../'],
            'too many zones' => [function (array &$s) {
                for ($i = 0; $i < scene_validator::MAX_ZONES; $i++) {
                    $s['zones'][] = ['id' => 'extra-' . $i, 'center' => [0, 0, 0], 'size' => [1, 1, 1]];
                }
            }],
            'too many interactables' => [function (array &$s) {
                for ($i = 0; $i < scene_validator::MAX_INTERACTABLES; $i++) {
                    $s['interactables'][] = ['id' => 'x-' . $i, 'kind' => 'text', 'position' => [0, 0, 0], 'text' => 'x'];
                }
            }],
        ];
    }

    /**
     * Each rule rejects its broken scene.
     *
     * @param callable $mutate Breaks the valid scene.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(callable $mutate): void {
        $scene = self::valid_scene();
        $mutate($scene);
        $this->assertNotEmpty(scene_validator::validate($scene));
    }

    /**
     * An unknown kind is reported as reserved or unknown.
     */
    public function test_unknown_kind_message(): void {
        $scene = self::valid_scene();
        $scene['interactables'][3]['kind'] = 'teleporter';
        $errors = scene_validator::validate($scene);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('kind reserved', $errors[0]);
    }

    /**
     * The Phase 2 kinds, the sphere prop, the assets area and the xr block are valid.
     */
    public function test_new_kinds_valid(): void {
        $scene = self::valid_scene();
        $scene['xr'] = ['arscale' => null];
        $scene['spawn'][0]['name'] = 'Start';
        $scene['props'][] = ['id' => 'p-ball', 'shape' => 'sphere', 'position' => [2, 0.3, 0], 'size' => [0.6, 0.6, 0.6],
            'color' => '#cc3333'];
        $assets = fn(int $itemid, string $name) => ['area' => 'assets', 'itemid' => $itemid, 'filepath' => '/',
            'filename' => $name];
        $scene['interactables'][] = ['id' => 'pt-cellar', 'kind' => 'portal', 'name' => 'Cellar door',
            'position' => [4.5, 0, -2], 'rotation' => [0, -90, 0], 'width' => 1.2, 'height' => 2.2,
            'target' => ['scene' => '1f5e7c2a-9b3d-4e8f-a1c6-5d2e0b7f3a99', 'spawn' => 's-cellar']];
        $scene['interactables'][] = ['id' => 'g-key', 'kind' => 'grabbable', 'name' => 'Brass key', 'zone' => 'z-1',
            'position' => [-2.6, 0.83, 3], 'shape' => 'box', 'size' => [0.24, 0.05, 0.08], 'color' => '#d4af37'];
        $scene['interactables'][] = ['id' => 'g-skull', 'kind' => 'grabbable', 'name' => 'Skull', 'position' => [1, 0.9, -1],
            'file' => $assets(4, 'skull.glb'), 'fit' => 0.3];
        $scene['interactables'][] = ['id' => 'a-tape', 'kind' => 'media', 'name' => 'Tape', 'media' => 'audio',
            'position' => [0, 1.2, -5.5], 'file' => $assets(2, 'tape.mp3'), 'loop' => false, 'autoplay' => false];
        $scene['interactables'][] = ['id' => 'a-film', 'kind' => 'media', 'name' => 'Film', 'media' => 'video',
            'position' => [0, 1.6, -5.7], 'width' => 1.6, 'file' => $assets(3, 'film.mp4')];
        $scene['interactables'][1]['file'] = $assets(5, 'vase.glb');
        $scene['interactables'][2]['file'] = $assets(6, 'portrait.jpg');
        $scene['interactables'][0]['content']['media'] = [$assets(7, 'detail.png')];
        $this->assertSame([], scene_validator::validate($scene));

        $scene['environment'] = ['type' => 'model', 'file' => $assets(8, 'room.glb')];
        $this->assertSame([], scene_validator::validate($scene));

        // Scene ids up to 36 characters.
        $scene['id'] = str_repeat('a', 36);
        $this->assertSame([], scene_validator::validate($scene));
    }

    /**
     * A room set: valid rooms, ids unique across rooms, portals lead to start points of the set.
     */
    public function test_validate_set(): void {
        $first = self::valid_scene();
        $second = [
            'schema' => 'vrroom-scene/1',
            'id' => '1f5e7c2a-9b3d-4e8f-a1c6-5d2e0b7f3a99',
            'name' => 'Cellar',
            'environment' => $first['environment'],
            'spawn' => [['id' => 's-cellar', 'position' => [0, 0, 0], 'yaw' => 0]],
            'zones' => [],
            'props' => [],
            'interactables' => [],
        ];
        $first['interactables'][] = ['id' => 'pt-cellar', 'kind' => 'portal', 'position' => [4.5, 0, -2],
            'target' => ['scene' => $second['id'], 'spawn' => 's-cellar']];
        $this->assertSame([], scene_validator::validate_set([$first, $second]));

        $this->assertSame(['an activity needs between 1 and 20 rooms'], scene_validator::validate_set([]));
        $this->assertSame(
            ['an activity needs between 1 and 20 rooms'],
            scene_validator::validate_set(array_fill(0, scene_validator::MAX_SCENES + 1, $second))
        );

        // The portal's room is missing.
        $errors = scene_validator::validate_set([$first]);
        $this->assertSame(
            ['scenes[0].interactables[4].target does not name a start point of a room of this activity'],
            $errors
        );

        // A room's own errors carry its index.
        $broken = $second;
        $broken['spawn'] = [];
        $errors = scene_validator::validate_set([$first, $broken]);
        $this->assertContains('scenes[1]: spawn must list at least one spawn point', $errors);

        // Duplicate room ids and ids used in two rooms.
        $copy = $second;
        $copy['spawn'][0]['id'] = 's-other';
        $errors = scene_validator::validate_set([$first, $second, $copy]);
        $this->assertContains('room id "' . $second['id'] . '" is used twice', $errors);

        $clash = $second;
        $clash['zones'] = [['id' => 'z-1', 'center' => [0, 1.5, 0], 'size' => [1, 1, 1]]];
        $errors = scene_validator::validate_set([$first, $clash]);
        $this->assertSame(['id "z-1" is used in more than one room'], $errors);
    }
}
