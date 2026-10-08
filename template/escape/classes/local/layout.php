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

namespace mavrtemplate_escape\local;

/**
 * Puzzle and exit placement for the Escape chain template.
 *
 * Coordinates follow mavr-scene/1: metres, Y up, yaw in degrees about Y; an object at yaw θ
 * faces (sin θ, 0, cos θ). A spawn point at yaw θ looks along (−sin θ, 0, −cos θ). Puzzles take
 * the preset's anchors in their stored order; the exit door takes the preset's "exit" anchor.
 *
 * @package    mavrtemplate_escape
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class layout {
    /** @var float Distance from the spawn point to an exit placed without a preset exit anchor, in metres. */
    public const EXIT_FALLBACK_DISTANCE = 2.0;

    /**
     * The first $count anchors of a preset, rounded and with normalised yaws.
     *
     * The caller checks that the preset has enough anchors.
     *
     * @param array $preset preset definition from \mod_mavr\local\scene\presets::get()
     * @param int $count number of puzzles
     * @return array[] list of ['position' => [x, y, z], 'yaw' => degrees]
     */
    public static function anchors(array $preset, int $count): array {
        if ($count <= 0) {
            return [];
        }
        $anchors = [];
        foreach (array_slice(array_values($preset['anchors'] ?? []), 0, $count) as $anchor) {
            $anchors[] = self::clean_anchor($anchor);
        }
        return $anchors;
    }

    /**
     * Unit vector an object at the given yaw faces: (sin θ, 0, cos θ).
     *
     * @param float $yaw yaw in degrees
     * @return float[] [x, 0, z]
     */
    public static function front(float $yaw): array {
        $rad = deg2rad($yaw);
        return [self::clean(sin($rad)), 0.0, self::clean(cos($rad))];
    }

    /**
     * Where the exit door goes.
     *
     * With a preset "exit" anchor, that anchor (and 'door' true: a door prop is drawn). Without
     * one, a point 2 m in front of the spawn point along its view direction (−sin θ, 0, −cos θ),
     * turned to face the spawn point (yaw = the spawn's yaw), with 'door' false.
     *
     * @param array $preset preset definition
     * @return array ['position' => [x, y, z], 'yaw' => degrees, 'door' => bool]
     */
    public static function exit_anchor(array $preset): array {
        if (isset($preset['exit']) && is_array($preset['exit'])) {
            return self::clean_anchor($preset['exit']) + ['door' => true];
        }
        $spawn = self::spawn($preset);
        $rad = deg2rad($spawn['yaw']);
        $distance = self::EXIT_FALLBACK_DISTANCE;
        return [
            'position' => [
                self::round3($spawn['position'][0] - $distance * sin($rad)),
                self::round3($spawn['position'][1]),
                self::round3($spawn['position'][2] - $distance * cos($rad)),
            ],
            'yaw' => $spawn['yaw'],
            'door' => false,
        ];
    }

    /**
     * The preset's spawn point, rounded.
     *
     * @param array $preset preset definition
     * @return array ['position' => [x, y, z], 'yaw' => degrees]
     */
    public static function spawn(array $preset): array {
        $spawn = $preset['spawn'] ?? [];
        return self::clean_anchor([
            'position' => $spawn['position'] ?? [0, 0, 0],
            'yaw' => $spawn['yaw'] ?? 0,
        ]);
    }

    /**
     * Point at an offset from a base position: base + k·front + (0, dy, 0), rounded.
     *
     * @param float[] $base [x, y, z]
     * @param float[] $front unit vector from front()
     * @param float $k distance along the front vector
     * @param float $dy height added
     * @return float[] [x, y, z]
     */
    public static function offset(array $base, array $front, float $k, float $dy): array {
        return [
            self::round3($base[0] + $k * $front[0]),
            self::round3($base[1] + $dy),
            self::round3($base[2] + $k * $front[2]),
        ];
    }

    /**
     * Normalise a yaw to the range (−180, 180].
     *
     * @param float $yaw degrees
     * @return float degrees in (−180, 180], rounded to 3 decimals
     */
    public static function normalise_yaw(float $yaw): float {
        $yaw = fmod($yaw, 360.0);
        if ($yaw <= -180.0) {
            $yaw += 360.0;
        } else if ($yaw > 180.0) {
            $yaw -= 360.0;
        }
        return self::round3($yaw);
    }

    /**
     * Round to 3 decimals, never returning negative zero (json_encode writes -0.0 as "-0.0").
     *
     * @param float $value
     * @return float
     */
    public static function round3(float $value): float {
        $value = round($value, 3);
        return $value == 0.0 ? 0.0 : $value;
    }

    /**
     * Rounded position and normalised yaw of an anchor definition.
     *
     * @param array $anchor ['position' => [x, y, z], 'yaw' => degrees]
     * @return array ['position' => [x, y, z], 'yaw' => degrees]
     */
    private static function clean_anchor(array $anchor): array {
        $position = array_values($anchor['position'] ?? [0, 0, 0]);
        return [
            'position' => [
                self::round3((float)($position[0] ?? 0)),
                self::round3((float)($position[1] ?? 0)),
                self::round3((float)($position[2] ?? 0)),
            ],
            'yaw' => self::normalise_yaw((float)($anchor['yaw'] ?? 0)),
        ];
    }

    /**
     * Remove floating-point noise from a trigonometric result (sin(180°) is 1.2e-16, not 0).
     *
     * @param float $value
     * @return float
     */
    private static function clean(float $value): float {
        $value = round($value, 12);
        return $value == 0.0 ? 0.0 : $value;
    }
}
