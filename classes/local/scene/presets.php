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
 * The bundled procedural environments (environments/presets.json).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class presets {
    /** @var string Schema of the presets file. */
    public const SCHEMA = 'mavr-presets/1';

    /** @var array|null Decoded presets map, once read. */
    private static ?array $presets = null;

    /**
     * Path of the presets file.
     *
     * @return string
     */
    public static function get_file_path(): string {
        return dirname(__DIR__, 3) . '/environments/presets.json';
    }

    /**
     * Every preset definition.
     *
     * @return array id => preset definition (decoded 'presets' map of presets.json).
     */
    public static function get_all(): array {
        if (self::$presets !== null) {
            return self::$presets;
        }
        $json = file_get_contents(self::get_file_path());
        if ($json === false) {
            throw new \coding_exception('Cannot read ' . self::get_file_path());
        }
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== self::SCHEMA || !isset($data['presets']) || !is_array($data['presets'])) {
            throw new \coding_exception('environments/presets.json is not a ' . self::SCHEMA . ' document.');
        }
        self::$presets = $data['presets'];
        return self::$presets;
    }

    /**
     * One preset definition.
     *
     * @param string $id Preset id, e.g. 'gallery-hall'.
     * @return array|null The definition, or null when there is no such preset.
     */
    public static function get(string $id): ?array {
        $all = self::get_all();
        return $all[$id] ?? null;
    }

    /**
     * Choices for forms.
     *
     * @return array id => display name
     */
    public static function get_choices(): array {
        $choices = [];
        foreach (self::get_all() as $id => $preset) {
            $choices[$id] = get_string($preset['string'], 'mod_mavr');
        }
        return $choices;
    }
}
