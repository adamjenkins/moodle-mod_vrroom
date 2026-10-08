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
 * Validates mavr-scene/1 documents.
 *
 * Errors are developer-facing English (they describe a template bug, not a teacher mistake).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scene_validator {
    /** @var string Schema identifier. */
    public const SCHEMA = 'mavr-scene/1';
    /** @var string Pattern every id in a scene must match. */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    /** @var int Most interactables in one scene. */
    public const MAX_INTERACTABLES = 500;
    /** @var int Most zones in one scene. */
    public const MAX_ZONES = 200;
    /** @var string[] Interactable kinds supported in Phase 1. */
    public const KINDS = ['hotspot', 'model', 'image', 'text'];
    /** @var string[] Prop shapes. */
    public const PROP_SHAPES = ['box', 'cylinder'];
    /** @var string[] Procedural environment shape kinds. */
    public const SHAPE_KINDS = ['hall', 'courtyard'];
    /** @var string[] File areas a scene may reference. */
    public const FILE_AREAS = ['models', 'media', 'environment'];

    /**
     * Validate a scene document.
     *
     * @param array $scene Decoded scene document.
     * @return string[] Errors; [] means valid.
     */
    public static function validate(array $scene): array {
        $errors = [];

        if (($scene['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema must be "' . self::SCHEMA . '"';
        }
        if (!self::is_id($scene['id'] ?? null)) {
            $errors[] = 'id is missing or invalid';
        }
        if (!isset($scene['name']) || !is_string($scene['name']) || trim($scene['name']) === '') {
            $errors[] = 'name is missing';
        }

        self::validate_environment($scene['environment'] ?? null, $errors);

        $seen = [];
        $checkid = function ($id, string $where) use (&$seen, &$errors): void {
            if (!self::is_id($id)) {
                $errors[] = "{$where}: id is missing or invalid";
                return;
            }
            if (isset($seen[$id])) {
                $errors[] = "{$where}: id \"{$id}\" is not unique";
                return;
            }
            $seen[$id] = true;
        };

        // Spawn points.
        $spawns = $scene['spawn'] ?? null;
        if (!is_array($spawns) || !array_is_list($spawns) || count($spawns) < 1) {
            $errors[] = 'spawn must list at least one spawn point';
            $spawns = [];
        }
        foreach ($spawns as $i => $spawn) {
            $where = "spawn[{$i}]";
            if (!is_array($spawn)) {
                $errors[] = "{$where}: not an object";
                continue;
            }
            $checkid($spawn['id'] ?? null, $where);
            self::check_vector($spawn['position'] ?? null, "{$where}.position", $errors);
            if (array_key_exists('yaw', $spawn) && !self::is_number($spawn['yaw'])) {
                $errors[] = "{$where}.yaw must be a finite number";
            }
        }

        // Zones.
        $zones = $scene['zones'] ?? [];
        if (!is_array($zones) || !array_is_list($zones)) {
            $errors[] = 'zones must be a list';
            $zones = [];
        }
        if (count($zones) > self::MAX_ZONES) {
            $errors[] = 'too many zones (at most ' . self::MAX_ZONES . ')';
        }
        $zoneids = [];
        foreach ($zones as $i => $zone) {
            $where = "zones[{$i}]";
            if (!is_array($zone)) {
                $errors[] = "{$where}: not an object";
                continue;
            }
            $checkid($zone['id'] ?? null, $where);
            if (self::is_id($zone['id'] ?? null)) {
                $zoneids[$zone['id']] = true;
            }
            if (isset($zone['name']) && !is_string($zone['name'])) {
                $errors[] = "{$where}.name must be a string";
            }
            self::check_vector($zone['center'] ?? null, "{$where}.center", $errors);
            if (self::check_vector($zone['size'] ?? null, "{$where}.size", $errors)) {
                foreach ($zone['size'] as $component) {
                    if ($component <= 0) {
                        $errors[] = "{$where}.size components must be greater than 0";
                        break;
                    }
                }
            }
        }

        // Props.
        $props = $scene['props'] ?? [];
        if (!is_array($props) || !array_is_list($props)) {
            $errors[] = 'props must be a list';
            $props = [];
        }
        foreach ($props as $i => $prop) {
            $where = "props[{$i}]";
            if (!is_array($prop)) {
                $errors[] = "{$where}: not an object";
                continue;
            }
            $checkid($prop['id'] ?? null, $where);
            if (!in_array($prop['shape'] ?? null, self::PROP_SHAPES, true)) {
                $errors[] = "{$where}.shape must be one of " . implode(', ', self::PROP_SHAPES);
            }
            self::check_vector($prop['position'] ?? null, "{$where}.position", $errors);
            if (array_key_exists('size', $prop)) {
                self::check_vector($prop['size'], "{$where}.size", $errors);
            }
            if (array_key_exists('rotation', $prop)) {
                self::check_vector($prop['rotation'], "{$where}.rotation", $errors);
            }
            if (array_key_exists('color', $prop) && !self::is_colour($prop['color'])) {
                $errors[] = "{$where}.color must be a #rrggbb colour";
            }
        }

        // Interactables.
        $items = $scene['interactables'] ?? [];
        if (!is_array($items) || !array_is_list($items)) {
            $errors[] = 'interactables must be a list';
            $items = [];
        }
        if (count($items) > self::MAX_INTERACTABLES) {
            $errors[] = 'too many interactables (at most ' . self::MAX_INTERACTABLES . ')';
        }
        $itemids = [];
        foreach ($items as $item) {
            if (is_array($item) && self::is_id($item['id'] ?? null)) {
                $itemids[$item['id']] = true;
            }
        }
        foreach ($items as $i => $item) {
            $where = "interactables[{$i}]";
            if (!is_array($item)) {
                $errors[] = "{$where}: not an object";
                continue;
            }
            $checkid($item['id'] ?? null, $where);
            $kind = $item['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                $errors[] = "{$where}.kind \"" . (is_string($kind) ? $kind : '') . '" is reserved or unknown (kind reserved)';
                continue;
            }
            if (isset($item['name']) && !is_string($item['name'])) {
                $errors[] = "{$where}.name must be a string";
            }
            self::check_vector($item['position'] ?? null, "{$where}.position", $errors);
            foreach (['rotation', 'scale'] as $key) {
                if (array_key_exists($key, $item)) {
                    self::check_vector($item[$key], "{$where}.{$key}", $errors);
                }
            }
            foreach (['width', 'fit'] as $key) {
                if (array_key_exists($key, $item) && (!self::is_number($item[$key]) || $item[$key] <= 0)) {
                    $errors[] = "{$where}.{$key} must be a number greater than 0";
                }
            }
            if (array_key_exists('zone', $item) && $item['zone'] !== null) {
                if (!is_string($item['zone']) || !isset($zoneids[$item['zone']])) {
                    $errors[] = "{$where}.zone does not name a zone of this scene";
                }
            }
            if (array_key_exists('opens', $item) && $item['opens'] !== null) {
                if (!is_string($item['opens']) || !isset($itemids[$item['opens']]) || $item['opens'] === ($item['id'] ?? null)) {
                    $errors[] = "{$where}.opens does not name another interactable of this scene";
                }
            }
            switch ($kind) {
                case 'model':
                    self::check_file($item['file'] ?? null, 'models', "{$where}.file", $errors);
                    break;
                case 'image':
                    self::check_file($item['file'] ?? null, 'media', "{$where}.file", $errors);
                    break;
                case 'text':
                    if (!isset($item['text']) || !is_string($item['text'])) {
                        $errors[] = "{$where}.text must be a string";
                    }
                    break;
                case 'hotspot':
                    self::validate_content($item['content'] ?? null, "{$where}.content", $errors);
                    break;
            }
        }

        return $errors;
    }

    /**
     * Validate the environment block.
     *
     * @param mixed $environment The environment value.
     * @param string[] $errors Errors, appended to.
     */
    private static function validate_environment($environment, array &$errors): void {
        if (!is_array($environment)) {
            $errors[] = 'environment is missing';
            return;
        }
        $type = $environment['type'] ?? null;
        if ($type === 'preset') {
            if (!is_string($environment['preset'] ?? null) || presets::get($environment['preset']) === null) {
                $errors[] = 'environment.preset is not a known preset';
            }
            $shape = $environment['shape'] ?? null;
            if (!is_array($shape) || !in_array($shape['kind'] ?? null, self::SHAPE_KINDS, true)) {
                $errors[] = 'environment.shape.kind must be one of ' . implode(', ', self::SHAPE_KINDS);
            }
        } else if ($type === 'model') {
            self::check_file($environment['file'] ?? null, 'environment', 'environment.file', $errors);
        } else {
            $errors[] = 'environment.type must be "preset" or "model"';
        }
        if (array_key_exists('sky', $environment) && !self::is_colour($environment['sky'])) {
            $errors[] = 'environment.sky must be a #rrggbb colour';
        }
        if (array_key_exists('bounds', $environment)) {
            $bounds = $environment['bounds'];
            if (!is_array($bounds)) {
                $errors[] = 'environment.bounds must be an object';
            } else {
                $okmin = self::check_vector($bounds['min'] ?? null, 'environment.bounds.min', $errors);
                $okmax = self::check_vector($bounds['max'] ?? null, 'environment.bounds.max', $errors);
                if ($okmin && $okmax && ($bounds['min'][0] > $bounds['max'][0] || $bounds['min'][2] > $bounds['max'][2])) {
                    $errors[] = 'environment.bounds.min must not exceed environment.bounds.max';
                }
            }
        }
        if (array_key_exists('lights', $environment)) {
            if (!is_array($environment['lights']) || !array_is_list($environment['lights'])) {
                $errors[] = 'environment.lights must be a list';
            } else {
                foreach ($environment['lights'] as $i => $light) {
                    if (!is_array($light) || !in_array($light['type'] ?? null, ['ambient', 'directional', 'hemisphere'], true)) {
                        $errors[] = "environment.lights[{$i}].type must be ambient, directional or hemisphere";
                        continue;
                    }
                    if (array_key_exists('position', $light)) {
                        self::check_vector($light['position'], "environment.lights[{$i}].position", $errors);
                    }
                    if (array_key_exists('intensity', $light) && !self::is_number($light['intensity'])) {
                        $errors[] = "environment.lights[{$i}].intensity must be a finite number";
                    }
                }
            }
        }
    }

    /**
     * Validate hotspot content.
     *
     * @param mixed $content The content value.
     * @param string $where Path for messages.
     * @param string[] $errors Errors, appended to.
     */
    private static function validate_content($content, string $where, array &$errors): void {
        if ($content === null) {
            return;
        }
        if (!is_array($content)) {
            $errors[] = "{$where} must be an object";
            return;
        }
        foreach (['title', 'text'] as $key) {
            if (array_key_exists($key, $content) && !is_string($content[$key])) {
                $errors[] = "{$where}.{$key} must be a string";
            }
        }
        if (array_key_exists('textformat', $content) && !is_string($content['textformat'])) {
            $errors[] = "{$where}.textformat must be a string";
        }
        if (array_key_exists('question', $content) && $content['question'] !== null) {
            if (!is_int($content['question']) || $content['question'] < 1) {
                $errors[] = "{$where}.question must be null or an integer of at least 1";
            }
        }
        if (array_key_exists('media', $content)) {
            if (!is_array($content['media']) || !array_is_list($content['media'])) {
                $errors[] = "{$where}.media must be a list";
            } else {
                foreach ($content['media'] as $i => $file) {
                    self::check_file($file, 'media', "{$where}.media[{$i}]", $errors);
                }
            }
        }
    }

    /**
     * Check a file reference.
     *
     * @param mixed $file The file reference.
     * @param string $area Required file area.
     * @param string $where Path for messages.
     * @param string[] $errors Errors, appended to.
     * @return bool True when valid.
     */
    private static function check_file($file, string $area, string $where, array &$errors): bool {
        if (!is_array($file)) {
            $errors[] = "{$where} must be a file reference";
            return false;
        }
        $ok = true;
        if (($file['area'] ?? null) !== $area) {
            $errors[] = "{$where}.area must be \"{$area}\"";
            $ok = false;
        }
        if (!is_int($file['itemid'] ?? null) || $file['itemid'] < 0) {
            $errors[] = "{$where}.itemid must be an integer of at least 0";
            $ok = false;
        }
        $path = $file['filepath'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || !str_ends_with($path, '/') || str_contains($path, '..')) {
            $errors[] = "{$where}.filepath must start and end with /";
            $ok = false;
        }
        $name = $file['filename'] ?? null;
        if (!is_string($name) || $name === '' || $name === '.' || str_contains($name, '/')) {
            $errors[] = "{$where}.filename is missing or invalid";
            $ok = false;
        }
        return $ok;
    }

    /**
     * Check a 3-component vector of finite numbers.
     *
     * @param mixed $vector The value.
     * @param string $where Path for messages.
     * @param string[] $errors Errors, appended to.
     * @return bool True when valid.
     */
    private static function check_vector($vector, string $where, array &$errors): bool {
        if (!is_array($vector) || !array_is_list($vector) || count($vector) !== 3) {
            $errors[] = "{$where} must be 3 finite numbers";
            return false;
        }
        foreach ($vector as $component) {
            if (!self::is_number($component)) {
                $errors[] = "{$where} must be 3 finite numbers";
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is a finite int or float.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_number($value): bool {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }

    /**
     * Whether a value is a valid scene id.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_id($value): bool {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * Whether a value is a #rrggbb colour.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_colour($value): bool {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
