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

namespace mod_mavr\local\scene;

/**
 * Tests for the mavr-scene/1 validator.
 *
 * @package    mod_mavr
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
  "schema": "mavr-scene/1",
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
JSON, true, 512, JSON_THROW_ON_ERROR);
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
            'wrong schema' => [fn(array &$s) => $s['schema'] = 'mavr-scene/2'],
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
            'model environment with wrong area' => [function (array &$s) {
                $s['environment'] = ['type' => 'model',
                    'file' => ['area' => 'models', 'itemid' => 0, 'filepath' => '/', 'filename' => 'a.glb']];
            }],
            'no spawn' => [fn(array &$s) => $s['spawn'] = []],
            'duplicate id across lists' => [fn(array &$s) => $s['props'][0]['id'] = 'z-1'],
            'bad id characters' => [fn(array &$s) => $s['zones'][0]['id'] = 'z 1'],
            'vector of two' => [fn(array &$s) => $s['spawn'][0]['position'] = [0, 0]],
            'vector with a string' => [fn(array &$s) => $s['zones'][0]['center'] = [0, '1', 0]],
            'vector with infinity' => [fn(array &$s) => $s['props'][0]['position'] = [0, INF, 0]],
            'zone size zero' => [fn(array &$s) => $s['zones'][0]['size'] = [3, 0, 3]],
            'prop shape' => [fn(array &$s) => $s['props'][0]['shape'] = 'sphere'],
            'reserved kind portal' => [fn(array &$s) => $s['interactables'][0]['kind'] = 'portal'],
            'reserved kind grabbable' => [fn(array &$s) => $s['interactables'][0]['kind'] = 'grabbable'],
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
     * A reserved kind is reported as reserved.
     */
    public function test_reserved_kind_message(): void {
        $scene = self::valid_scene();
        $scene['interactables'][3]['kind'] = 'portal';
        $errors = scene_validator::validate($scene);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('kind reserved', $errors[0]);
    }
}
