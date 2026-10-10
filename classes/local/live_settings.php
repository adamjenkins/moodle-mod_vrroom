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

namespace mod_vrroom\local;

use mod_vrroom\local\runtime\registry as runtime_registry;

/**
 * Live mode settings of an activity: the mode, the number of rooms, webcams and their cleaning.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class live_settings {
    /** @var int Live mode off. */
    public const MODE_OFF = 0;
    /** @var int Live mode on with a fixed number of rooms (also the only mode before rooms existed). */
    public const MODE_FIXED = 1;
    /** @var int Live mode on, students open rooms (the number of rooms is a maximum, 0 = no limit). */
    public const MODE_DYNAMIC = 2;
    /** @var int Most rooms a teacher can set. */
    public const MAX_ROOMS = 20;
    /** @var int Default number of rooms. */
    public const DEFAULT_ROOMS = 1;
    /** @var int Webcams in live rooms by default (vrroom.livevideo). */
    public const DEFAULT_VIDEO = 1;
    /** @var string The run mode that sends webcam video in live rooms. */
    public const VIDEO_RUNTIME = 'builtin';

    /**
     * Clean a live mode value.
     *
     * @param mixed $value Submitted or restored value.
     * @param bool $multirooms Whether the activity's run mode can run several rooms.
     * @return int MODE_OFF, MODE_FIXED or MODE_DYNAMIC; MODE_DYNAMIC becomes MODE_FIXED without $multirooms.
     */
    public static function clean_mode($value, bool $multirooms): int {
        $mode = (int)$value;
        if (!in_array($mode, [self::MODE_OFF, self::MODE_FIXED, self::MODE_DYNAMIC], true)) {
            return self::MODE_OFF;
        }
        if ($mode === self::MODE_DYNAMIC && !$multirooms) {
            return self::MODE_FIXED;
        }
        return $mode;
    }

    /**
     * Clean a number of rooms.
     *
     * @param mixed $value Submitted or restored value.
     * @param int $mode Cleaned live mode.
     * @return int 0..MAX_ROOMS; 0 ("no limit") only in MODE_DYNAMIC, else at least 1.
     */
    public static function clean_rooms($value, int $mode): int {
        $rooms = min(self::MAX_ROOMS, max(0, (int)$value));
        if ($rooms === 0 && $mode !== self::MODE_DYNAMIC) {
            return self::DEFAULT_ROOMS;
        }
        return $rooms;
    }

    /**
     * Clean the "Webcams in live rooms" value.
     *
     * @param mixed $value Submitted or restored value.
     * @return int 0 or 1.
     */
    public static function clean_video($value): int {
        return empty($value) ? 0 : 1;
    }

    /**
     * Whether a run mode can run several live rooms (false when it is not installed).
     *
     * @param string $runtimename Run mode short name.
     * @return bool
     */
    public static function runtime_has_multirooms(string $runtimename): bool {
        $all = runtime_registry::get_all();
        return isset($all[$runtimename]) && $all[$runtimename]->capabilities()->multirooms;
    }
}
