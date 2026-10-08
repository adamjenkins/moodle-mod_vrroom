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
 * What a run mode can do.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runtime_capabilities {
    /**
     * Constructor.
     *
     * @param int $maxusers Maximum users per room.
     * @param bool $voice Whether voice chat is offered.
     * @param bool $avatars Whether other users are shown as avatars.
     * @param string[] $xrmodes Supported XR modes ('vr', 'ar', 'mr').
     * @param string[] $objectivetypes Objective types the run mode can report events for.
     */
    public function __construct(
        /** @var int Maximum users per room. */
        public readonly int $maxusers,
        /** @var bool Whether voice chat is offered. */
        public readonly bool $voice,
        /** @var bool Whether other users are shown as avatars. */
        public readonly bool $avatars,
        /** @var string[] Supported XR modes. */
        public readonly array $xrmodes,
        /** @var string[] Objective types the run mode can report events for. */
        public readonly array $objectivetypes,
    ) {
    }
}
