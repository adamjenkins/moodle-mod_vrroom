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
 * Tests for the environment registry: presets and room-pack rooms, manifest rules, choices, files.
 *
 * Uses the fixture pack tests/fixtures/envpack (rooms a and b valid, room c with three anchors, room d a
 * small room: 'small' true, 'zonesize' 2, two anchors 2.2 m apart).
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(environment_registry::class)]
final class environment_registry_test extends \advanced_testcase {
    /**
     * Directory of the fixture pack.
     *
     * @return string
     */
    private static function packdir(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/vrroom/tests/fixtures/envpack';
    }

    /**
     * The fixture manifest, decoded.
     *
     * @return array
     */
    private static function manifest(): array {
        return json_decode(file_get_contents(self::packdir() . '/environments.json'), true);
    }

    /**
     * Parse a manifest holding one room derived from fixture room a.
     *
     * @param callable $change fn(array $room): array
     * @return array parse_manifest() result
     */
    private static function parse_changed_room(callable $change): array {
        return self::parse_changed('a', $change);
    }

    /**
     * Parse a manifest holding one room derived from a fixture room.
     *
     * @param string $id fixture room id
     * @param callable $change fn(array $room): array
     * @return array parse_manifest() result
     */
    private static function parse_changed(string $id, callable $change): array {
        $manifest = self::manifest();
        $room = $change($manifest['rooms'][$id]);
        $json = json_encode(['schema' => environment_registry::SCHEMA, 'rooms' => [$id => $room]]);
        return environment_registry::parse_manifest($json, 'testpack', self::packdir());
    }

    protected function setUp(): void {
        parent::setUp();
        environment_registry::phpunit_set_packs(['testpack' => self::packdir()]);
    }

    protected function tearDown(): void {
        environment_registry::phpunit_set_packs(null);
        parent::tearDown();
    }

    /**
     * Presets come first, then the valid pack rooms; the invalid room is skipped with a debugging message.
     */
    public function test_get_all(): void {
        $all = environment_registry::get_all();
        $this->assertDebuggingCalled();
        $this->assertSame(['gallery-hall', 'courtyard', 'escape-room', 'testpack/a', 'testpack/b', 'testpack/d'], array_keys($all));

        $hall = $all['gallery-hall'];
        $this->assertSame('preset', $hall['kind']);
        $this->assertSame(get_string('preset_galleryhall', 'mod_vrroom'), $hall['name']);
        $this->assertSame(get_string('envgroup_builtin', 'mod_vrroom'), $hall['group']);
        $this->assertSame(['type', 'preset', 'shape', 'sky', 'lights', 'bounds'], array_keys($hall['environment']));
        $this->assertSame('gallery-hall', $hall['environment']['preset']);

        $a = $all['testpack/a'];
        $this->assertSame('pack', $a['kind']);
        // No lang string room_a in a vrroomenv_testpack lang file: the name falls back to the id.
        $this->assertSame('a', $a['name']);
        $this->assertSame(get_string('envcategory_learning', 'mod_vrroom'), $a['group']);
        $this->assertSame([0, 0, 8], $a['spawn']['position']);
        $this->assertCount(4, $a['anchors']);
        $this->assertSame([0, 0, -9.5], $a['exit']['position']);
        $this->assertSame([
            'type' => 'pack',
            'pack' => 'testpack',
            'room' => 'a',
            'sky' => '#112233',
            'lights' => [['type' => 'ambient', 'intensity' => 0.8]],
            'bounds' => ['min' => [-10, 0, -10], 'max' => [10, 0, 10]],
        ], $a['environment']);
        $this->assertStringContainsString('/mod/vrroom/envfile.php', $a['url']);
        $this->assertStringEndsWith('/testpack/1/a/room.glb', $a['url']);
        $this->assertStringEndsWith('/testpack/1/a/preview.jpg', $a['previewurl']);
        $this->assertFalse($a['missing']);
        $this->assertSame(realpath(self::packdir() . '/rooms/a/room.glb'), $a['path']);

        // Room b has no exit, no sky and no lights: defaults.
        $b = $all['testpack/b'];
        $this->assertArrayNotHasKey('exit', $b);
        $this->assertSame(environment_registry::DEFAULT_SKY, $b['sky']);
        $this->assertSame([], $b['lights']);
        // Without the small-room keys: the default zone size, not small.
        $this->assertSame(environment_registry::ZONE_SIZE, $b['zonesize']);
        $this->assertFalse($b['small']);
        $this->assertSame(environment_registry::ZONE_SIZE, $hall['zonesize']);
        $this->assertFalse($hall['small']);

        // Room d is a small room with two anchors and 2 m zones.
        $d = $all['testpack/d'];
        $this->assertCount(2, $d['anchors']);
        $this->assertSame(2.0, $d['zonesize']);
        $this->assertTrue($d['small']);

        // Errors are reported once per request.
        environment_registry::get_all();
        $this->assertDebuggingNotCalled();
    }

    /**
     * get() and get_pack_room() by key; unknown and invalid keys give null.
     */
    public function test_get(): void {
        $this->assertSame('preset', environment_registry::get('courtyard')['kind']);
        $this->assertSame('testpack/b', environment_registry::get('testpack/b')['key']);
        $this->assertSame('testpack/b', environment_registry::get_pack_room('testpack', 'b')['key']);
        $this->assertNull(environment_registry::get('testpack/c'));
        $this->assertNull(environment_registry::get('testpack/zz'));
        $this->assertNull(environment_registry::get('otherpack/a'));
        $this->assertNull(environment_registry::get('moon-base'));
        $this->assertNull(environment_registry::get('testpack/a/extra'));
        $this->assertNull(environment_registry::get('Test-Pack/a'));
        $this->assertNull(environment_registry::get_pack_room('testpack', 'c'));
    }

    /**
     * The fixture's valid rooms parse without errors; room c (three anchors) is reported.
     */
    public function test_parse_manifest_fixture(): void {
        $result = environment_registry::parse_manifest(
            file_get_contents(self::packdir() . '/environments.json'),
            'testpack',
            self::packdir()
        );
        $this->assertSame(['a', 'b', 'd'], array_keys($result['rooms']));
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('testpack/c: anchors must list 4 to 20 anchors, not 3', $result['errors'][0]);
    }

    /**
     * Invalid JSON or a wrong schema gives no rooms and one error.
     */
    public function test_parse_manifest_bad_document(): void {
        $documents = ['{', '{"schema": "vrroom-envpack/2", "rooms": {}}', '[]', '{"schema": "vrroom-envpack/1", "rooms": [1]}'];
        foreach ($documents as $json) {
            $result = environment_registry::parse_manifest($json, 'testpack', self::packdir());
            $this->assertSame([], $result['rooms'], $json);
            $this->assertCount(1, $result['errors'], $json);
        }
        $empty = environment_registry::parse_manifest('{"schema": "vrroom-envpack/1", "rooms": {}}', 'testpack', self::packdir());
        $this->assertSame(['rooms' => [], 'errors' => []], $empty);
    }

    /**
     * Each manifest rule rejects the room with a message naming the rule.
     *
     * @param string $expected part of the expected error
     * @param \Closure $change fn(array $room): array
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('room_rule_provider')]
    public function test_room_rules(string $expected, \Closure $change): void {
        $result = self::parse_changed_room($change);
        $this->assertSame([], $result['rooms']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString($expected, implode("\n", $result['errors']));
    }

    /**
     * Broken variants of fixture room a.
     *
     * @return array
     */
    public static function room_rule_provider(): array {
        return [
            'too few anchors' => ['anchors must list 4 to 20', function (array $room): array {
                array_pop($room['anchors']);
                return $room;
            }],
            'too many anchors' => ['anchors must list 4 to 20', function (array $room): array {
                $room['anchors'] = [];
                for ($i = 0; $i < 21; $i++) {
                    $room['anchors'][] = ['position' => [-9 + ($i % 7) * 3, 0, -9 + intdiv($i, 7) * 3], 'yaw' => 0];
                }
                $room['bounds'] = ['min' => [-30, 0, -30], 'max' => [30, 0, 30]];
                return $room;
            }],
            'path with ..' => ['file must be rooms/a/room.glb', function (array $room): array {
                $room['file'] = 'rooms/a/../b/room.glb';
                return $room;
            }],
            'preview path with ..' => ['preview must be rooms/a/', function (array $room): array {
                $room['preview'] = 'rooms/../../preview.jpg';
                return $room;
            }],
            'missing file' => ['preview does not exist', function (array $room): array {
                $room['preview'] = 'rooms/a/preview.png';
                return $room;
            }],
            'overlapping zones' => ['the zones of anchors[0] and anchors[1] overlap', function (array $room): array {
                // Two anchors 2 m apart facing the same way: their 3 m zone squares overlap.
                $room['anchors'][1] = ['position' => [-6, 0, 4], 'yaw' => 90];
                return $room;
            }],
            'anchor outside bounds' => ['anchors[2] is outside bounds', function (array $room): array {
                $room['anchors'][2]['position'] = [-11, 0, -4];
                return $room;
            }],
            'start outside bounds' => ['start is outside bounds', function (array $room): array {
                $room['start']['position'] = [0, 0, 12];
                return $room;
            }],
            'bad category' => ['category must be one of', function (array $room): array {
                $room['category'] = 'space';
                return $room;
            }],
            'bad licence' => ['licence must be one of', function (array $room): array {
                $room['licence'] = 'CC-BY-NC-4.0';
                return $room;
            }],
            'bad name' => ['name must be a lang string key', function (array $room): array {
                $room['name'] = 'Room A';
                return $room;
            }],
            'credit too long' => ['credit must be a text', function (array $room): array {
                $room['credit'] = str_repeat('x', 401);
                return $room;
            }],
            'bad source' => ['source must be an http(s) URL', function (array $room): array {
                $room['source'] = 'javascript:alert(1)';
                return $room;
            }],
            'bad bytes' => ['bytes must be a positive integer', function (array $room): array {
                $room['bytes'] = '1540';
                return $room;
            }],
            'bounds inverted' => ['bounds.min must not exceed bounds.max', function (array $room): array {
                $room['bounds'] = ['min' => [10, 0, -10], 'max' => [-10, 0, 10]];
                return $room;
            }],
            'bad exit' => ['exit must have a position', function (array $room): array {
                $room['exit'] = ['position' => [0, 0], 'yaw' => 0];
                return $room;
            }],
            'bad sky' => ['sky must be a #rrggbb colour', function (array $room): array {
                $room['sky'] = 'blue';
                return $room;
            }],
            'bad lights' => ['lights must be a list', function (array $room): array {
                $room['lights'] = [['type' => 'spot']];
                return $room;
            }],
        ];
    }

    /**
     * A bad room id is rejected.
     */
    public function test_bad_room_id(): void {
        $manifest = self::manifest();
        $json = json_encode(['schema' => environment_registry::SCHEMA, 'rooms' => ['Room A' => $manifest['rooms']['a']]]);
        $result = environment_registry::parse_manifest($json, 'testpack', self::packdir());
        $this->assertSame([], $result['rooms']);
        $this->assertStringContainsString('room id must match', $result['errors'][0]);
    }

    /**
     * Choices: presets, then one group per category in category order, then the kept missing key.
     */
    public function test_get_choices(): void {
        $builtin = get_string('envgroup_builtin', 'mod_vrroom');
        $learning = get_string('envcategory_learning', 'mod_vrroom');
        $culture = get_string('envcategory_culture', 'mod_vrroom');
        $home = get_string('envcategory_home', 'mod_vrroom');
        $missing = get_string('envgroup_missing', 'mod_vrroom');

        $choices = environment_registry::get_choices();
        $this->assertSame([$builtin, $learning, $culture, $home], array_keys($choices));
        $this->assertSame(['gallery-hall', 'courtyard', 'escape-room'], array_keys($choices[$builtin]));
        $this->assertSame(['testpack/a' => 'a'], $choices[$learning]);
        $this->assertSame(['testpack/b' => 'b'], $choices[$culture]);
        $this->assertSame(['testpack/d' => 'd'], $choices[$home]);

        $this->assertSame([$builtin], array_keys(environment_registry::get_choices(null, false)));

        // An installed key or a preset is not repeated; a missing pack key gets its own group.
        $this->assertSame(array_keys($choices), array_keys(environment_registry::get_choices('testpack/a')));
        $this->assertSame(array_keys($choices), array_keys(environment_registry::get_choices('moon-base')));
        $kept = environment_registry::get_choices('rooms/atrium');
        $this->assertSame([$builtin, $learning, $culture, $home, $missing], array_keys($kept));
        $this->assertSame(
            ['rooms/atrium' => get_string('envmissingoption', 'mod_vrroom', 'rooms/atrium')],
            $kept[$missing]
        );
    }

    /**
     * resolve(): the installed room wins; a matching snapshot stands in for a missing room; else null.
     */
    public function test_resolve_and_snapshot(): void {
        $def = environment_registry::get('testpack/a');
        $snapshot = environment_registry::snapshot($def);
        $this->assertSame(
            ['key', 'pack', 'room', 'name', 'spawn', 'bounds', 'anchors', 'zonesize', 'small', 'exit', 'sky', 'lights'],
            array_keys($snapshot)
        );
        $this->assertNull(environment_registry::snapshot(environment_registry::get('gallery-hall')));

        // Stored as JSON in the template config and decoded as objects.
        $stored = json_decode(json_encode($snapshot));
        $this->assertSame($def, environment_registry::resolve('testpack/a', $stored));
        $this->assertSame('preset', environment_registry::resolve('courtyard', $stored)['kind']);

        environment_registry::phpunit_set_packs([]);
        $this->assertNull(environment_registry::get('testpack/a'));
        $this->assertNull(environment_registry::resolve('testpack/a'));
        $this->assertNull(environment_registry::resolve('testpack/b', $stored));

        $missing = environment_registry::resolve('testpack/a', $stored);
        $this->assertTrue($missing['missing']);
        $this->assertNull($missing['url']);
        $this->assertSame('', $missing['credit']);
        $this->assertSame(get_string('envgroup_missing', 'mod_vrroom'), $missing['group']);
        $this->assertSame('a', $missing['name']);
        $this->assertSame($def['environment'], $missing['environment']);
        $this->assertSame($def['anchors'], $missing['anchors']);
        $this->assertSame($def['spawn'], $missing['spawn']);
        $this->assertSame($def['exit'], $missing['exit']);
        // The rebuilt definition snapshots to the same snapshot (round trip).
        $this->assertSame($snapshot, environment_registry::snapshot($missing));

        // A damaged snapshot is not used.
        $broken = json_decode(json_encode($snapshot), true);
        $broken['anchors'] = [];
        $this->assertNull(environment_registry::resolve('testpack/a', $broken));
    }

    /**
     * Small rooms: 'small' allows two anchors, 'zonesize' sets the zone edge used by the overlap test.
     */
    public function test_small_room_rules(): void {
        $keep = fn (array $room): array => $room;
        $this->assertSame(['d'], array_keys(self::parse_changed('d', $keep)['rooms']));

        $cases = [
            'without small' => ['anchors must list 4 to 20 anchors, not 2', function (array $room): array {
                unset($room['small']);
                return $room;
            }],
            'zone size 3' => ['the zones of anchors[0] and anchors[1] overlap', function (array $room): array {
                $room['zonesize'] = 3.0;
                return $room;
            }],
            'zone size 1.4' => ['zonesize must be a number from 1.5 to 3', function (array $room): array {
                $room['zonesize'] = 1.4;
                return $room;
            }],
            'zone size 3.1' => ['zonesize must be a number from 1.5 to 3', function (array $room): array {
                $room['zonesize'] = 3.1;
                return $room;
            }],
            'zone size string' => ['zonesize must be a number from 1.5 to 3', function (array $room): array {
                $room['zonesize'] = '2';
                return $room;
            }],
            'small not a bool' => ['small must be true or false', function (array $room): array {
                $room['small'] = 'yes';
                return $room;
            }],
            'one anchor' => ['anchors must list 2 to 20 anchors, not 1', function (array $room): array {
                array_pop($room['anchors']);
                return $room;
            }],
        ];
        foreach ($cases as $name => [$expected, $change]) {
            $result = self::parse_changed('d', $change);
            $this->assertSame([], $result['rooms'], $name);
            $this->assertStringContainsString($expected, implode("\n", $result['errors']), $name);
        }

        // A large room may set a zone size too: room a with 2 m zones and an anchor 2.5 m from another.
        $result = self::parse_changed_room(function (array $room): array {
            $room['zonesize'] = 2;
            $room['anchors'][1] = ['position' => [-8, 0, 1.5], 'yaw' => 90];
            return $room;
        });
        $this->assertSame([], $result['errors']);
        $this->assertSame(['a'], array_keys($result['rooms']));
    }

    /**
     * A small room's snapshot keeps its zone size and flag, so it still resolves without its pack;
     * an old snapshot without those keys resolves with the defaults.
     */
    public function test_small_room_snapshot(): void {
        $def = environment_registry::get('testpack/d');
        $snapshot = environment_registry::snapshot($def);
        $this->assertSame(2.0, $snapshot['zonesize']);
        $this->assertTrue($snapshot['small']);
        $stored = json_decode(json_encode($snapshot));

        $old = json_decode(json_encode(environment_registry::snapshot(environment_registry::get('testpack/a'))), true);
        unset($old['zonesize'], $old['small']);

        environment_registry::phpunit_set_packs([]);
        $missing = environment_registry::resolve('testpack/d', $stored);
        $this->assertNotNull($missing);
        $this->assertTrue($missing['missing']);
        $this->assertCount(2, $missing['anchors']);
        $this->assertSame(2.0, $missing['zonesize']);
        $this->assertTrue($missing['small']);
        $this->assertSame($snapshot, environment_registry::snapshot($missing));

        // The same snapshot without its small flag is not a valid layout.
        $unflagged = json_decode(json_encode($snapshot), true);
        unset($unflagged['small']);
        $this->assertNull(environment_registry::resolve('testpack/d', $unflagged));

        $resolved = environment_registry::resolve('testpack/a', $old);
        $this->assertNotNull($resolved);
        $this->assertSame(environment_registry::ZONE_SIZE, $resolved['zonesize']);
        $this->assertFalse($resolved['small']);
    }

    /**
     * preview_url(): a preset's picture in pix/environments, a pack room's through envfile.php, none for a missing room.
     */
    public function test_preview_url(): void {
        $hall = environment_registry::preview_url(environment_registry::get('gallery-hall'));
        $this->assertNotNull($hall);
        $this->assertStringContainsString('/mod_vrroom/', $hall);
        $this->assertStringContainsString('environments/gallery-hall', $hall);
        $this->assertNotNull(environment_registry::preview_url(environment_registry::get('courtyard')));
        $this->assertNotNull(environment_registry::preview_url(environment_registry::get('escape-room')));

        $a = environment_registry::get('testpack/a');
        $this->assertSame($a['previewurl'], environment_registry::preview_url($a));
        $this->assertStringContainsString('/mod/vrroom/envfile.php', environment_registry::preview_url($a));

        $snapshot = environment_registry::snapshot($a);
        environment_registry::phpunit_set_packs([]);
        $this->assertNull(environment_registry::preview_url(environment_registry::resolve('testpack/a', $snapshot)));

        // A preset without a picture has none.
        $this->assertNull(environment_registry::preview_url(['key' => 'moon-base', 'kind' => 'preset']));
    }

    /**
     * get_file() serves only room.glb and the manifest's preview, of installed valid rooms.
     */
    public function test_get_file(): void {
        $glb = environment_registry::get_file('testpack', 'a', 'room.glb');
        $this->assertSame(['path' => realpath(self::packdir() . '/rooms/a/room.glb'), 'mimetype' => 'model/gltf-binary'], $glb);
        $preview = environment_registry::get_file('testpack', 'b', 'preview.jpg');
        $this->assertSame(['path' => realpath(self::packdir() . '/rooms/b/preview.jpg'), 'mimetype' => 'image/jpeg'], $preview);

        $this->assertNull(environment_registry::get_file('testpack', 'a', 'preview.png'));
        $this->assertNull(environment_registry::get_file('testpack', 'a', 'environments.json'));
        $this->assertNull(environment_registry::get_file('testpack', 'a', '../../environments.json'));
        $this->assertNull(environment_registry::get_file('testpack', 'c', 'room.glb'));
        $this->assertNull(environment_registry::get_file('testpack', '..', 'room.glb'));
        $this->assertNull(environment_registry::get_file('otherpack', 'a', 'room.glb'));
        $this->assertNull(environment_registry::get_file('../testpack', 'a', 'room.glb'));
    }

    /**
     * file_url() and get_revision().
     */
    public function test_file_url_and_revision(): void {
        $this->resetAfterTest();
        $this->assertSame(1, environment_registry::get_revision('testpack'));
        $this->assertSame(0, environment_registry::get_revision('otherpack'));
        set_config('version', 2026100800, 'vrroomenv_testpack');
        $this->assertSame(2026100800, environment_registry::get_revision('testpack'));
        $url = environment_registry::file_url('testpack', 'a', 'room.glb')->out(false);
        $this->assertStringContainsString('/mod/vrroom/envfile.php', $url);
        $this->assertStringEndsWith('testpack/2026100800/a/room.glb', $url);
    }

    /**
     * describe_scenes(): credits of installed pack rooms (each once), keys of missing ones.
     */
    public function test_describe_scenes(): void {
        $pack = fn (string $pack, string $room): array => ['environment' => ['type' => 'pack', 'pack' => $pack, 'room' => $room]];
        $scenes = [
            $pack('testpack', 'a'),
            $pack('testpack', 'b'),
            $pack('testpack', 'a'),
            $pack('rooms', 'atrium'),
            ['environment' => ['type' => 'preset', 'preset' => 'gallery-hall']],
            ['environment' => 'broken'],
        ];
        $manifest = self::manifest();
        $this->assertSame([
            'credits' => [$manifest['rooms']['a']['credit'], $manifest['rooms']['b']['credit']],
            'missing' => ['rooms/atrium'],
        ], environment_registry::describe_scenes($scenes));
        $this->assertSame(['credits' => [], 'missing' => []], environment_registry::describe_scenes([]));
    }

    /**
     * With an empty pack list only the presets are listed, and no pack has a revision.
     */
    public function test_discovery_without_packs(): void {
        environment_registry::phpunit_set_packs([]);
        $this->assertSame(['gallery-hall', 'courtyard', 'escape-room'], array_keys(environment_registry::get_all()));
        $this->assertSame(0, environment_registry::get_revision('testpack'));
    }
}
