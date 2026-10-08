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

namespace mod_vrroom\plugininfo;

use core\plugininfo\base;

/**
 * Plugin info for the vrroomenv sub-plugin type (room packs: 3D rooms with their layouts).
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vrroomenv extends base {
    /**
     * A room pack can always be uninstalled: activities using its rooms fall back to a plain hall.
     *
     * @return bool
     */
    public function is_uninstall_allowed(): bool {
        return true;
    }

    /**
     * Room packs cannot be enabled or disabled; install or uninstall them instead.
     *
     * @return bool
     */
    public static function plugintype_supports_disabling(): bool {
        return false;
    }
}
