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

namespace mod_mavr\plugininfo;

use core\plugininfo\base;

/**
 * Plugin info for the mavrruntime sub-plugin type (run modes).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mavrruntime extends base {
    /**
     * A run mode can be uninstalled only while no activity uses it.
     *
     * @return bool
     */
    public function is_uninstall_allowed(): bool {
        global $DB;
        return !$DB->record_exists('mavr', ['runtime' => $this->name]);
    }

    /**
     * Run modes cannot be enabled or disabled in Phase 1 (only one exists).
     *
     * @return bool
     */
    public static function plugintype_supports_disabling(): bool {
        return false;
    }

    /**
     * Pre-uninstall hook.
     */
    public function uninstall_cleanup() {
        parent::uninstall_cleanup();
    }
}
