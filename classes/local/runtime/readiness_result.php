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

namespace mod_mavr\local\runtime;

/**
 * Whether a run mode can launch right now.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class readiness_result {
    /**
     * Constructor.
     *
     * @param bool $ready True when the run mode can launch.
     * @param string $message Plain-text reason shown to users when not ready.
     */
    public function __construct(
        /** @var bool True when the run mode can launch. */
        public readonly bool $ready,
        /** @var string Plain-text reason shown to users when not ready. */
        public readonly string $message = '',
    ) {
    }
}
