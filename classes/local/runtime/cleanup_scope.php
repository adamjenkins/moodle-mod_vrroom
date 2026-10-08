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

namespace mod_vrroom\local\runtime;

/**
 * What a run mode's cleanup() must remove.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_scope {
    /** @var string A course is being reset. */
    public const COURSE_RESET = 'coursereset';
    /** @var string An activity is being deleted. */
    public const INSTANCE_DELETE = 'instancedelete';
    /** @var string A user's data is being deleted. */
    public const USER_DELETE = 'userdelete';

    /**
     * Constructor.
     *
     * @param string $type One of the constants.
     * @param int $courseid The course, when known.
     * @param int $vrroomid The activity, when known.
     * @param int $userid The user, for USER_DELETE.
     */
    public function __construct(
        /** @var string One of the constants. */
        public readonly string $type,
        /** @var int The course, when known. */
        public readonly int $courseid = 0,
        /** @var int The activity, when known. */
        public readonly int $vrroomid = 0,
        /** @var int The user, for USER_DELETE. */
        public readonly int $userid = 0,
    ) {
        if (!in_array($type, [self::COURSE_RESET, self::INSTANCE_DELETE, self::USER_DELETE], true)) {
            throw new \coding_exception('Unknown cleanup scope: ' . $type);
        }
    }
}
