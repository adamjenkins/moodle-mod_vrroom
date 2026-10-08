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
     * The admin tree node of a run mode's settings page.
     *
     * @return string
     */
    public function get_settings_section_name() {
        return 'mavrruntimesetting' . $this->name;
    }

    /**
     * Add the run mode's settings page (its settings.php) below the MAVR settings category.
     *
     * @param \part_of_admin_tree $adminroot The admin tree.
     * @param string $parentnodename The category to add the page to.
     * @param bool $hassiteconfig Whether the current user has moodle/site:config.
     * @return void
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE; // In case settings.php wants to refer to them.
        /** @var \admin_root $ADMIN */
        $ADMIN = $adminroot; // May be used in settings.php.
        $plugininfo = $this; // Also can be used inside settings.php.

        if (!$this->is_installed_and_upgraded()) {
            return;
        }
        if (!$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();
        $settings = new \admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);
        include($this->full_path('settings.php')); // This may also set $settings to null.

        if ($settings) {
            $ADMIN->add($parentnodename, $settings);
        }
    }

    /**
     * Pre-uninstall hook.
     */
    public function uninstall_cleanup() {
        parent::uninstall_cleanup();
    }
}
