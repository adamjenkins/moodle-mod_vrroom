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

namespace vrroomtemplate_gallery\local;

/**
 * Station placement for the Gallery walk template.
 *
 * Coordinates follow vrroom-scene/1: metres, Y up, yaw in degrees about Y; an object at yaw θ
 * faces (sin θ, 0, cos θ). Bundled presets bring their own anchor lists; an uploaded
 * environment gets its stations on a circle around the origin, each facing the centre.
 *
 * @package    vrroomtemplate_gallery
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class layout {
    /** @var int The most stations an uploaded environment (circle layout) takes. */
    public const MAX_UPLOAD_STATIONS = 12;

    /** @var float Circle radius used for up to six stations, in metres. */
    public const MIN_RADIUS = 6.0;

    /** @var float Free space kept between the circle and the bounds, in metres. */
    public const BOUNDS_MARGIN = 3.0;

    /**
     * Anchors for $count stations.
     *
     * With a preset, its anchors are returned in their stored order (at most $count of them;
     * the caller checks that the preset has enough). Without a preset (uploaded environment),
     * the stations are spread evenly on a circle: station k at φ = k·360/count, position
     * (r sin φ, 0, −r cos φ), yaw −φ, where r = circle_radius($count).
     *
     * @param array|null $preset environment_registry definition (preset or pack room), or null for uploads
     * @param int $count number of stations
     * @return array[] list of ['position' => [x, y, z], 'yaw' => degrees]
     */
    public static function anchors(?array $preset, int $count): array {
        if ($count <= 0) {
            return [];
        }
        if ($preset !== null) {
            $anchors = [];
            foreach (array_slice(array_values($preset['anchors'] ?? []), 0, $count) as $anchor) {
                $position = array_values($anchor['position']);
                $anchors[] = [
                    'position' => [
                        self::round3((float)$position[0]),
                        self::round3((float)$position[1]),
                        self::round3((float)$position[2]),
                    ],
                    'yaw' => self::normalise_yaw((float)$anchor['yaw']),
                ];
            }
            return $anchors;
        }

        $radius = self::circle_radius($count);
        $anchors = [];
        for ($k = 0; $k < $count; $k++) {
            $phi = $k * 360.0 / $count;
            $rad = deg2rad($phi);
            $anchors[] = [
                'position' => [
                    self::round3($radius * sin($rad)),
                    0.0,
                    self::round3(-$radius * cos($rad)),
                ],
                'yaw' => self::normalise_yaw(-$phi),
            ];
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
     * Radius of the circle layout for uploaded environments.
     *
     * @param int $count number of stations
     * @return float 6 m for up to six stations, otherwise $count metres
     */
    public static function circle_radius(int $count): float {
        return $count <= 6 ? self::MIN_RADIUS : (float)$count;
    }

    /**
     * Walkable bounds of the circle layout: ±(r + 3) in x and z, at floor level.
     *
     * @param int $count number of stations
     * @return array ['min' => [x, 0, z], 'max' => [x, 0, z]]
     */
    public static function circle_bounds(int $count): array {
        $extent = self::circle_radius($count) + self::BOUNDS_MARGIN;
        return [
            'min' => [-$extent, 0.0, -$extent],
            'max' => [$extent, 0.0, $extent],
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
