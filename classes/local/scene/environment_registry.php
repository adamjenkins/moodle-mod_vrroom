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
 * Every environment a room can use: the bundled presets and the rooms of installed room packs.
 *
 * One key space: a preset is keyed by its id ('gallery-hall'), a pack room by '<pack>/<room>'
 * ('rooms/atrium'). A room pack (sub-plugin type vrroomenv) ships an environments.json manifest
 * (schema vrroom-envpack/1) naming its rooms, their GLB model and preview image, the layout the
 * templates use (start point, walkable bounds, station anchors, optional exit) and the credit line.
 *
 * Definitions of both kinds have the same layout keys as a preset ('spawn', 'bounds', 'anchors',
 * 'exit', 'sky', 'lights'), so the templates' layout code accepts either unchanged, plus 'key',
 * 'kind', 'name', 'group', 'category', the 'environment' block a scene stores, and the small-room
 * keys 'zonesize' (station zone edge in metres, MIN_ZONE_SIZE to ZONE_SIZE, default ZONE_SIZE) and
 * 'small' (true allows MIN_ANCHORS_SMALL anchors instead of MIN_ANCHORS).
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class environment_registry {
    /** @var string Manifest schema. */
    public const SCHEMA = 'vrroom-envpack/1';
    /** @var string Manifest file name at the pack root. */
    public const MANIFEST = 'environments.json';
    /** @var string Sub-plugin type of room packs. */
    public const PLUGINTYPE = 'vrroomenv';
    /** @var string Pattern of a room id. */
    public const ROOM_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
    /** @var string Pattern of a room's name string key in the pack's lang file. */
    public const NAME_PATTERN = '/^room_[a-z0-9_]{1,60}$/';
    /** @var string[] Room categories, in the order the choice lists show them. */
    public const CATEGORIES = ['learning', 'culture', 'work', 'home', 'historic', 'outdoors', 'scifi', 'other'];
    /** @var string[] Licences a room may carry. */
    public const LICENCES = ['CC0-1.0', 'PD', 'CC-BY-3.0', 'CC-BY-4.0', 'MPL-2.0', 'MIT', 'Apache-2.0'];
    /** @var int Fewest station anchors of a room. */
    public const MIN_ANCHORS = 4;
    /** @var int Fewest station anchors of a small room ('small' => true). */
    public const MIN_ANCHORS_SMALL = 2;
    /** @var int Most station anchors of a room. */
    public const MAX_ANCHORS = 20;
    /** @var float Default and largest gallery zone edge in metres (zones are squares centred half an edge in front). */
    public const ZONE_SIZE = 3.0;
    /** @var float Smallest gallery zone edge in metres a room may declare ('zonesize'). */
    public const MIN_ZONE_SIZE = 1.5;
    /** @var string File name of a room's model. */
    public const MODEL_NAME = 'room.glb';
    /** @var string[] Accepted file names of a room's preview image. */
    public const PREVIEW_NAMES = ['preview.jpg', 'preview.png', 'preview.webp'];
    /** @var int Largest room model in bytes (5 MiB, the room standard's budget). */
    public const MAX_MODEL_BYTES = 5242880;
    /** @var int Longest credit line in characters. */
    public const MAX_CREDIT_LENGTH = 400;
    /** @var string Sky colour of a room without one (indoor). */
    public const DEFAULT_SKY = '#20242a';

    /** @var array|null Parsed manifests: pack => ['dir' => string, 'rooms' => id => room, 'errors' => string[]]. */
    private static ?array $packs = null;

    /** @var array|null PHPUnit only: pack => dir replacing discovery. */
    private static ?array $testpacks = null;

    /** @var bool Whether manifest errors were already reported in this request. */
    private static bool $reported = false;

    /**
     * Every environment: presets first (presets.json order), then installed pack rooms (pack, then room id order).
     *
     * Manifest errors are reported once per request with debugging(DEBUG_DEVELOPER).
     *
     * @return array key => definition
     */
    public static function get_all(): array {
        $all = [];
        foreach (presets::get_all() as $id => $preset) {
            $all[$id] = self::preset_def($id, $preset);
        }
        foreach (self::get_packs() as $pack => $info) {
            foreach ($info['rooms'] as $room => $raw) {
                $all["{$pack}/{$room}"] = self::pack_def($pack, $room, $raw, $info['dir']);
            }
        }
        if (!self::$reported) {
            self::$reported = true;
            $errors = [];
            foreach (self::get_packs() as $pack => $info) {
                foreach ($info['errors'] as $error) {
                    $errors[] = "vrroomenv_{$pack}: {$error}";
                }
            }
            if ($errors) {
                debugging('Room pack manifest errors: ' . implode('; ', $errors), DEBUG_DEVELOPER);
            }
        }
        return $all;
    }

    /**
     * One environment by key.
     *
     * @param string $key preset id ('gallery-hall') or '<pack>/<room>' ('rooms/atrium')
     * @return array|null the definition, or null when there is no such environment on this site
     */
    public static function get(string $key): ?array {
        $parsed = self::split_key($key);
        if ($parsed !== null) {
            return self::get_pack_room($parsed[0], $parsed[1]);
        }
        $preset = presets::get($key);
        return $preset === null ? null : self::preset_def($key, $preset);
    }

    /**
     * One installed pack room.
     *
     * @param string $pack pack name ('rooms' for vrroomenv_rooms)
     * @param string $room room id
     * @return array|null the definition, or null when the pack or the room is not installed
     */
    public static function get_pack_room(string $pack, string $room): ?array {
        $packs = self::get_packs();
        if (!isset($packs[$pack]['rooms'][$room])) {
            return null;
        }
        return self::pack_def($pack, $room, $packs[$pack]['rooms'][$room], $packs[$pack]['dir']);
    }

    /**
     * Resolve a stored environment key.
     *
     * The installed definition wins (the pack's current layout). Otherwise, when a template stored a
     * snapshot of that pack room, a definition rebuilt from it with 'missing' => true (no model URL,
     * no credit), so the activity still compiles and the viewer shows a plain hall. Otherwise null.
     *
     * @param string $key environment key
     * @param array|\stdClass|null $snapshot what snapshot() returned when the room was chosen
     * @return array|null
     */
    public static function resolve(string $key, $snapshot = null): ?array {
        $def = self::get($key);
        if ($def !== null) {
            return $def;
        }
        $parsed = self::split_key($key);
        if ($parsed === null || $snapshot === null) {
            return null;
        }
        $snap = self::to_array($snapshot);
        if (!is_array($snap) || ($snap['key'] ?? null) !== $key) {
            return null;
        }
        $errors = [];
        $layout = [
            'start' => $snap['spawn'] ?? null,
            'bounds' => $snap['bounds'] ?? null,
            'anchors' => $snap['anchors'] ?? null,
        ];
        if (isset($snap['exit'])) {
            $layout['exit'] = $snap['exit'];
        }
        foreach (['zonesize', 'small'] as $field) {
            if (isset($snap[$field])) {
                $layout[$field] = $snap[$field];
            }
        }
        self::check_layout($layout, $errors);
        if (isset($snap['sky']) && !self::is_colour($snap['sky'])) {
            $errors[] = 'sky';
        }
        if (isset($snap['lights']) && !self::check_lights($snap['lights'])) {
            $errors[] = 'lights';
        }
        if ($errors) {
            return null;
        }
        [$pack, $room] = $parsed;
        $name = is_string($snap['name'] ?? null) ? clean_param($snap['name'], PARAM_TEXT) : '';
        $sky = $snap['sky'] ?? self::DEFAULT_SKY;
        $lights = $snap['lights'] ?? [];
        $def = [
            'key' => $key,
            'kind' => 'pack',
            'name' => $name !== '' ? $name : $key,
            'group' => get_string('envgroup_missing', 'mod_vrroom'),
            'category' => 'other',
            'spawn' => $snap['spawn'],
            'bounds' => $snap['bounds'],
            'anchors' => array_values($snap['anchors']),
            'zonesize' => (float)($snap['zonesize'] ?? self::ZONE_SIZE),
            'small' => !empty($snap['small']),
            'sky' => $sky,
            'lights' => $lights,
            'environment' => self::pack_environment($pack, $room, $sky, $lights, $snap['bounds']),
            'pack' => $pack,
            'room' => $room,
            'credit' => '',
            'licence' => '',
            'source' => '',
            'lightmap' => false,
            'path' => null,
            'previewpath' => null,
            'url' => null,
            'previewurl' => null,
            'missing' => true,
        ];
        if (isset($snap['exit'])) {
            $def['exit'] = $snap['exit'];
        }
        return $def;
    }

    /**
     * What a template stores so it can recompile the activity without the pack.
     *
     * @param array $def a definition from get() or resolve()
     * @return array|null the snapshot, or null for a preset (presets always exist)
     */
    public static function snapshot(array $def): ?array {
        if (($def['kind'] ?? null) !== 'pack') {
            return null;
        }
        return [
            'key' => $def['key'],
            'pack' => $def['pack'],
            'room' => $def['room'],
            'name' => (string)$def['name'],
            'spawn' => $def['spawn'],
            'bounds' => $def['bounds'],
            'anchors' => $def['anchors'],
            'zonesize' => (float)($def['zonesize'] ?? self::ZONE_SIZE),
            'small' => !empty($def['small']),
            'exit' => $def['exit'] ?? null,
            'sky' => $def['sky'],
            'lights' => $def['lights'],
        ];
    }

    /**
     * URL of an environment's preview image, for the settings forms and the room editor.
     *
     * A pack room's preview is served by envfile.php; a preset's is pix/environments/<id>.jpg of
     * this plugin, served by the theme image script. A room without one (a missing pack room, a
     * preset without a picture) has none.
     *
     * @param array $def a definition from get(), get_all() or resolve()
     * @return string|null the URL, or null when there is no preview
     */
    public static function preview_url(array $def): ?string {
        global $OUTPUT;
        if (($def['kind'] ?? null) === 'pack') {
            return isset($def['previewurl']) && is_string($def['previewurl']) ? $def['previewurl'] : null;
        }
        $key = (string)($def['key'] ?? '');
        if ($key === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $key) !== 1) {
            return null;
        }
        if (!is_readable(dirname(__DIR__, 3) . "/pix/environments/{$key}.jpg")) {
            return null;
        }
        return $OUTPUT->image_url('environments/' . $key, 'mod_vrroom')->out(false);
    }

    /**
     * Options for a 'selectgroups' form element.
     *
     * Groups: the presets, then one group per category (CATEGORIES order, rooms sorted by name,
     * empty groups left out), then, when $keep names a pack room this site lacks, a "Not installed"
     * group holding it (selectgroups drops any submitted value that is not an option).
     *
     * @param string|null $keep the stored key, kept as a choice even when not installed
     * @param bool $includepacks false lists the presets only
     * @return array group label => [key => name], labels and names plain text
     */
    public static function get_choices(?string $keep = null, bool $includepacks = true): array {
        $choices = [];
        $builtin = [];
        foreach (presets::get_all() as $id => $preset) {
            $builtin[$id] = get_string($preset['string'], 'mod_vrroom');
        }
        $choices[get_string('envgroup_builtin', 'mod_vrroom')] = $builtin;

        if ($includepacks) {
            $bycategory = array_fill_keys(self::CATEGORIES, []);
            foreach (self::get_packs() as $pack => $info) {
                foreach ($info['rooms'] as $room => $raw) {
                    $bycategory[$raw['category']]["{$pack}/{$room}"] = self::room_name($pack, $room, $raw);
                }
            }
            foreach ($bycategory as $category => $rooms) {
                if (!$rooms) {
                    continue;
                }
                \core_collator::asort($rooms);
                $choices[get_string('envcategory_' . $category, 'mod_vrroom')] = $rooms;
            }
        }

        if ($keep !== null && self::split_key($keep) !== null && self::get($keep) === null) {
            $choices[get_string('envgroup_missing', 'mod_vrroom')] = [
                $keep => get_string('envmissingoption', 'mod_vrroom', $keep),
            ];
        }
        return $choices;
    }

    /**
     * A file of an installed pack room, by the name the manifest gives it.
     *
     * Only the model ('room.glb') and the manifest's preview image are served; no path is ever
     * built from the request.
     *
     * @param string $pack pack name
     * @param string $room room id
     * @param string $filename 'room.glb' or the preview's file name
     * @return array|null ['path' => absolute path, 'mimetype' => string], or null
     */
    public static function get_file(string $pack, string $room, string $filename): ?array {
        $packs = self::get_packs();
        if (!isset($packs[$pack]['rooms'][$room])) {
            return null;
        }
        $raw = $packs[$pack]['rooms'][$room];
        $dir = $packs[$pack]['dir'];
        if ($filename === self::MODEL_NAME) {
            $path = self::inside($dir, $raw['file']);
            return $path === null ? null : ['path' => $path, 'mimetype' => 'model/gltf-binary'];
        }
        if (in_array($filename, self::PREVIEW_NAMES, true) && $filename === basename($raw['preview'])) {
            $path = self::inside($dir, $raw['preview']);
            $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            return $path === null ? null : ['path' => $path, 'mimetype' => $types[$extension]];
        }
        return null;
    }

    /**
     * URL of a pack room file through envfile.php (the pack revision makes it cacheable forever).
     *
     * @param string $pack pack name
     * @param string $room room id
     * @param string $filename 'room.glb' or the preview's file name
     * @return \moodle_url
     */
    public static function file_url(string $pack, string $room, string $filename): \moodle_url {
        $revision = self::get_revision($pack);
        return \moodle_url::make_file_url('/mod/vrroom/envfile.php', "/{$pack}/{$revision}/{$room}/{$filename}");
    }

    /**
     * Installed version of a pack, used as the URL revision.
     *
     * @param string $pack pack name
     * @return int the pack's version, 0 when not installed (1 for a PHPUnit test pack without one)
     */
    public static function get_revision(string $pack): int {
        $packs = self::get_packs();
        if (!isset($packs[$pack])) {
            return 0;
        }
        $version = (int)get_config(self::PLUGINTYPE . '_' . $pack, 'version');
        return $version > 0 ? $version : 1;
    }

    /**
     * Credits and missing rooms of a set of scenes.
     *
     * @param array $scenes decoded vrroom-scene/1 documents
     * @return array ['credits' => string[] plain credit lines, 'missing' => string[] keys of rooms not installed]
     */
    public static function describe_scenes(array $scenes): array {
        $credits = [];
        $missing = [];
        foreach ($scenes as $scene) {
            $environment = is_array($scene) ? ($scene['environment'] ?? null) : null;
            if (!is_array($environment) || ($environment['type'] ?? null) !== 'pack') {
                continue;
            }
            $pack = $environment['pack'] ?? null;
            $room = $environment['room'] ?? null;
            if (!is_string($pack) || !is_string($room)) {
                continue;
            }
            $def = self::get_pack_room($pack, $room);
            if ($def === null) {
                $missing["{$pack}/{$room}"] = "{$pack}/{$room}";
            } else if ($def['credit'] !== '') {
                $credits[$def['credit']] = $def['credit'];
            }
        }
        return ['credits' => array_values($credits), 'missing' => array_values($missing)];
    }

    /**
     * Parse and check one manifest.
     *
     * Invalid JSON or a wrong schema gives no rooms and one error; an invalid room is left out
     * with its errors.
     *
     * @param string $json manifest text
     * @param string $pack pack name (for the room keys in messages)
     * @param string $dir pack directory (files must resolve inside it)
     * @return array ['rooms' => id => raw room, 'errors' => string[]]
     */
    public static function parse_manifest(string $json, string $pack, string $dir): array {
        $data = json_decode($json, true, 64);
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
            return ['rooms' => [], 'errors' => [self::MANIFEST . ' is not a ' . self::SCHEMA . ' document']];
        }
        if (!isset($data['rooms']) || !is_array($data['rooms']) || ($data['rooms'] !== [] && array_is_list($data['rooms']))) {
            return ['rooms' => [], 'errors' => ['rooms must be an object']];
        }
        $rooms = [];
        $errors = [];
        foreach ($data['rooms'] as $id => $room) {
            $roomerrors = self::check_room((string)$id, $room, $dir);
            if ($roomerrors) {
                foreach ($roomerrors as $error) {
                    $errors[] = "{$pack}/{$id}: {$error}";
                }
                continue;
            }
            $rooms[(string)$id] = $room;
        }
        ksort($rooms, SORT_STRING);
        return ['rooms' => $rooms, 'errors' => $errors];
    }

    /**
     * Forget cached manifests (and report manifest errors again).
     */
    public static function reset_caches(): void {
        self::$packs = null;
        self::$reported = false;
    }

    /**
     * PHPUnit only: replace the installed packs.
     *
     * @param array|null $packs pack name => directory; null restores discovery
     */
    public static function phpunit_set_packs(?array $packs): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('environment_registry::phpunit_set_packs() is for PHPUnit only.');
        }
        self::$testpacks = $packs;
        self::reset_caches();
    }

    /**
     * Installed packs with their parsed manifests.
     *
     * @return array pack => ['dir' => string, 'rooms' => id => raw room, 'errors' => string[]]
     */
    private static function get_packs(): array {
        if (self::$packs !== null) {
            return self::$packs;
        }
        if (self::$testpacks !== null) {
            $dirs = self::$testpacks;
        } else {
            $dirs = [];
            foreach (\core_component::get_plugin_list(self::PLUGINTYPE) as $name => $dir) {
                if (get_config(self::PLUGINTYPE . '_' . $name, 'version')) {
                    $dirs[$name] = $dir;
                }
            }
        }
        $packs = [];
        foreach ($dirs as $name => $dir) {
            $file = $dir . '/' . self::MANIFEST;
            if (!is_readable($file)) {
                continue;
            }
            $json = file_get_contents($file);
            $parsed = $json === false
                ? ['rooms' => [], 'errors' => ['cannot read ' . self::MANIFEST]]
                : self::parse_manifest($json, (string)$name, $dir);
            $packs[(string)$name] = ['dir' => $dir] + $parsed;
        }
        ksort($packs, SORT_STRING);
        self::$packs = $packs;
        return self::$packs;
    }

    /**
     * Check one manifest room against every rule of the schema.
     *
     * @param string $id room id
     * @param mixed $room room value
     * @param string $dir pack directory
     * @return string[] errors, [] when valid
     */
    private static function check_room(string $id, $room, string $dir): array {
        if (preg_match(self::ROOM_PATTERN, $id) !== 1) {
            return ['room id must match ' . self::ROOM_PATTERN];
        }
        if (!is_array($room) || array_is_list($room)) {
            return ['must be an object'];
        }
        $errors = [];
        if (!is_string($room['name'] ?? null) || preg_match(self::NAME_PATTERN, $room['name']) !== 1) {
            $errors[] = 'name must be a lang string key matching ' . self::NAME_PATTERN;
        }
        if (!in_array($room['category'] ?? null, self::CATEGORIES, true)) {
            $errors[] = 'category must be one of ' . implode(', ', self::CATEGORIES);
        }
        if (!in_array($room['licence'] ?? null, self::LICENCES, true)) {
            $errors[] = 'licence must be one of ' . implode(', ', self::LICENCES);
        }
        $credit = $room['credit'] ?? null;
        if (!is_string($credit) || trim($credit) === '' || \core_text::strlen($credit) > self::MAX_CREDIT_LENGTH) {
            $errors[] = 'credit must be a text of 1 to ' . self::MAX_CREDIT_LENGTH . ' characters';
        }
        if (!is_string($room['source'] ?? null) || preg_match('~^https?://\S+$~', $room['source']) !== 1) {
            $errors[] = 'source must be an http(s) URL';
        }
        if (!is_int($room['bytes'] ?? null) || $room['bytes'] < 1) {
            $errors[] = 'bytes must be a positive integer';
        }

        $file = $room['file'] ?? null;
        if (!is_string($file) || $file !== "rooms/{$id}/" . self::MODEL_NAME) {
            $errors[] = "file must be rooms/{$id}/" . self::MODEL_NAME;
        } else {
            $path = self::inside($dir, $file);
            if ($path === null) {
                $errors[] = 'file does not exist inside the pack';
            } else if (filesize($path) > self::MAX_MODEL_BYTES) {
                $errors[] = 'file is larger than ' . self::MAX_MODEL_BYTES . ' bytes';
            }
        }
        $preview = $room['preview'] ?? null;
        $previewnames = implode('|', array_map('preg_quote', self::PREVIEW_NAMES));
        if (!is_string($preview) || preg_match('~^rooms/' . preg_quote($id, '~') . '/(' . $previewnames . ')$~', $preview) !== 1) {
            $errors[] = "preview must be rooms/{$id}/ followed by one of " . implode(', ', self::PREVIEW_NAMES);
        } else if (self::inside($dir, $preview) === null) {
            $errors[] = 'preview does not exist inside the pack';
        }

        self::check_layout($room, $errors);

        if (array_key_exists('sky', $room) && !self::is_colour($room['sky'])) {
            $errors[] = 'sky must be a #rrggbb colour';
        }
        if (array_key_exists('lights', $room) && !self::check_lights($room['lights'])) {
            $errors[] = 'lights must be a list of ambient, directional or hemisphere lights';
        }
        if (array_key_exists('lightmap', $room) && !is_bool($room['lightmap'])) {
            $errors[] = 'lightmap must be true or false';
        }
        if (array_key_exists('stats', $room) && !is_array($room['stats'])) {
            $errors[] = 'stats must be an object';
        }
        return $errors;
    }

    /**
     * Check a room layout: start, bounds, anchors (count, inside bounds, zones apart) and exit.
     *
     * @param array $room manifest room (or a snapshot mapped to 'start', 'bounds', 'anchors', 'exit')
     * @param string[] $errors errors, appended to
     */
    private static function check_layout(array $room, array &$errors): void {
        $bounds = $room['bounds'] ?? null;
        $okbounds = is_array($bounds) && self::is_vector($bounds['min'] ?? null) && self::is_vector($bounds['max'] ?? null);
        if (!$okbounds) {
            $errors[] = 'bounds must have min and max of 3 finite numbers';
        } else if ($bounds['min'][0] > $bounds['max'][0] || $bounds['min'][2] > $bounds['max'][2]) {
            $errors[] = 'bounds.min must not exceed bounds.max';
            $okbounds = false;
        }

        $inbounds = function (array $position) use ($bounds): bool {
            return $position[0] >= $bounds['min'][0] && $position[0] <= $bounds['max'][0]
                && $position[2] >= $bounds['min'][2] && $position[2] <= $bounds['max'][2];
        };

        if (!self::is_placement($room['start'] ?? null)) {
            $errors[] = 'start must have a position of 3 finite numbers and a yaw';
        } else if ($okbounds && !$inbounds($room['start']['position'])) {
            $errors[] = 'start is outside bounds';
        }

        if (array_key_exists('exit', $room) && !self::is_placement($room['exit'])) {
            $errors[] = 'exit must have a position of 3 finite numbers and a yaw';
        }

        $zone = self::ZONE_SIZE;
        if (array_key_exists('zonesize', $room)) {
            $value = $room['zonesize'];
            if (!self::is_number($value) || $value < self::MIN_ZONE_SIZE || $value > self::ZONE_SIZE) {
                $errors[] = 'zonesize must be a number from ' . self::MIN_ZONE_SIZE . ' to ' . self::ZONE_SIZE;
            } else {
                $zone = (float)$value;
            }
        }
        if (array_key_exists('small', $room) && !is_bool($room['small'])) {
            $errors[] = 'small must be true or false';
        }
        $min = !empty($room['small']) && is_bool($room['small']) ? self::MIN_ANCHORS_SMALL : self::MIN_ANCHORS;

        $anchors = $room['anchors'] ?? null;
        if (!is_array($anchors) || !array_is_list($anchors)) {
            $errors[] = 'anchors must be a list';
            return;
        }
        $count = count($anchors);
        if ($count < $min || $count > self::MAX_ANCHORS) {
            $errors[] = 'anchors must list ' . $min . ' to ' . self::MAX_ANCHORS . " anchors, not {$count}";
        }
        $zones = [];
        foreach ($anchors as $i => $anchor) {
            if (!self::is_placement($anchor)) {
                $errors[] = "anchors[{$i}] must have a position of 3 finite numbers and a yaw";
                continue;
            }
            if ($okbounds && !$inbounds($anchor['position'])) {
                $errors[] = "anchors[{$i}] is outside bounds";
            }
            $zones[$i] = self::zone_centre($anchor, $zone);
        }
        $edge = $zone - 1e-6;
        $indexes = array_keys($zones);
        foreach ($indexes as $a => $i) {
            foreach (array_slice($indexes, $a + 1) as $j) {
                if (abs($zones[$i][0] - $zones[$j][0]) < $edge && abs($zones[$i][1] - $zones[$j][1]) < $edge) {
                    $errors[] = "the zones of anchors[{$i}] and anchors[{$j}] overlap";
                }
            }
        }
    }

    /**
     * Centre (x, z) of the gallery zone of an anchor: half a zone edge along its front (sin θ, 0, cos θ).
     *
     * @param array $anchor ['position' => [x, y, z], 'yaw' => degrees]
     * @param float $zone zone edge in metres
     * @return float[] [x, z]
     */
    private static function zone_centre(array $anchor, float $zone): array {
        $rad = deg2rad((float)$anchor['yaw']);
        $offset = $zone / 2;
        return [
            (float)$anchor['position'][0] + $offset * sin($rad),
            (float)$anchor['position'][2] + $offset * cos($rad),
        ];
    }

    /**
     * Definition of a preset.
     *
     * @param string $id preset id
     * @param array $preset presets.json entry
     * @return array
     */
    private static function preset_def(string $id, array $preset): array {
        $def = $preset;
        $def['key'] = $id;
        $def['kind'] = 'preset';
        $def['name'] = get_string($preset['string'], 'mod_vrroom');
        $def['group'] = get_string('envgroup_builtin', 'mod_vrroom');
        $def['category'] = 'builtin';
        $def['environment'] = [
            'type' => 'preset',
            'preset' => $id,
            'shape' => $preset['shape'],
            'sky' => $preset['sky'],
            'lights' => $preset['lights'],
            'bounds' => $preset['bounds'],
        ];
        $def['zonesize'] = self::ZONE_SIZE;
        $def['small'] = false;
        $def['missing'] = false;
        return $def;
    }

    /**
     * Definition of an installed pack room.
     *
     * @param string $pack pack name
     * @param string $room room id
     * @param array $raw manifest room (already checked)
     * @param string $dir pack directory
     * @return array
     */
    private static function pack_def(string $pack, string $room, array $raw, string $dir): array {
        $sky = $raw['sky'] ?? self::DEFAULT_SKY;
        $lights = $raw['lights'] ?? [];
        $previewname = basename($raw['preview']);
        $def = [
            'key' => "{$pack}/{$room}",
            'kind' => 'pack',
            'name' => self::room_name($pack, $room, $raw),
            'group' => get_string('envcategory_' . $raw['category'], 'mod_vrroom'),
            'category' => $raw['category'],
            'spawn' => $raw['start'],
            'bounds' => $raw['bounds'],
            'anchors' => $raw['anchors'],
            'zonesize' => (float)($raw['zonesize'] ?? self::ZONE_SIZE),
            'small' => !empty($raw['small']),
            'sky' => $sky,
            'lights' => $lights,
            'environment' => self::pack_environment($pack, $room, $sky, $lights, $raw['bounds']),
            'pack' => $pack,
            'room' => $room,
            'credit' => $raw['credit'],
            'licence' => $raw['licence'],
            'source' => $raw['source'],
            'lightmap' => !empty($raw['lightmap']),
            'path' => self::inside($dir, $raw['file']),
            'previewpath' => self::inside($dir, $raw['preview']),
            'url' => self::file_url($pack, $room, self::MODEL_NAME)->out(false),
            'previewurl' => self::file_url($pack, $room, $previewname)->out(false),
            'missing' => false,
        ];
        if (isset($raw['exit'])) {
            $def['exit'] = $raw['exit'];
        }
        return $def;
    }

    /**
     * The environment block a scene stores for a pack room.
     *
     * @param string $pack pack name
     * @param string $room room id
     * @param string $sky sky colour
     * @param array $lights lights ([] = the viewer's default lights)
     * @param array $bounds walkable bounds (also the size of the fallback hall)
     * @return array
     */
    private static function pack_environment(string $pack, string $room, string $sky, array $lights, array $bounds): array {
        return [
            'type' => 'pack',
            'pack' => $pack,
            'room' => $room,
            'sky' => $sky,
            'lights' => $lights,
            'bounds' => $bounds,
        ];
    }

    /**
     * Display name of a pack room: its string in the pack's lang file, else the room id.
     *
     * @param string $pack pack name
     * @param string $room room id
     * @param array $raw manifest room
     * @return string plain text
     */
    private static function room_name(string $pack, string $room, array $raw): string {
        $component = self::PLUGINTYPE . '_' . $pack;
        if (get_string_manager()->string_exists($raw['name'], $component)) {
            return get_string($raw['name'], $component);
        }
        return $room;
    }

    /**
     * Split a pack key into pack and room.
     *
     * @param string $key environment key
     * @return array|null [pack, room], or null when the key is not a valid pack key
     */
    private static function split_key(string $key): ?array {
        $parts = explode('/', $key);
        if (count($parts) !== 2) {
            return null;
        }
        [$pack, $room] = $parts;
        if (!\core_component::is_valid_plugin_name(self::PLUGINTYPE, $pack) || preg_match(self::ROOM_PATTERN, $room) !== 1) {
            return null;
        }
        return [$pack, $room];
    }

    /**
     * Absolute path of a relative manifest path, only when it is an existing file inside the pack.
     *
     * @param string $dir pack directory
     * @param string $relative manifest path
     * @return string|null
     */
    private static function inside(string $dir, string $relative): ?string {
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || $relative[0] === '/') {
            return null;
        }
        $root = realpath($dir);
        $path = realpath($dir . '/' . $relative);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $path;
    }

    /**
     * Whether lights are a valid list (same rules as scene_validator).
     *
     * @param mixed $lights
     * @return bool
     */
    private static function check_lights($lights): bool {
        if (!is_array($lights) || !array_is_list($lights)) {
            return false;
        }
        foreach ($lights as $light) {
            if (!is_array($light) || !in_array($light['type'] ?? null, ['ambient', 'directional', 'hemisphere'], true)) {
                return false;
            }
            if (array_key_exists('position', $light) && !self::is_vector($light['position'])) {
                return false;
            }
            if (array_key_exists('intensity', $light) && !self::is_number($light['intensity'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is ['position' => 3 numbers, 'yaw' => number].
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_placement($value): bool {
        return is_array($value) && self::is_vector($value['position'] ?? null) && self::is_number($value['yaw'] ?? null);
    }

    /**
     * Whether a value is a list of 3 finite numbers.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_vector($value): bool {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            return false;
        }
        foreach ($value as $component) {
            if (!self::is_number($component)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is a finite int or float.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_number($value): bool {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }

    /**
     * Whether a value is a #rrggbb colour.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_colour($value): bool {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * Turn a decoded JSON value (objects as stdClass) into arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function to_array($value) {
        if (is_array($value) || is_object($value)) {
            return json_decode(json_encode($value), true);
        }
        return $value;
    }
}
