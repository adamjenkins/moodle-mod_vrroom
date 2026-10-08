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

/**
 * Post-install steps for mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs after the tables have been created.
 *
 * Creates the launch-token secret and registers the GLB file type, which core does not know:
 * without it a filemanager restricted to .glb would reject every upload.
 *
 * @return bool
 */
function xmldb_mavr_install(): bool {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    \mod_mavr\local\token::ensure_secret();

    if (!array_key_exists('glb', get_mimetypes_array())) {
        \core_filetypes::add_type('glb', 'model/gltf-binary', 'unknown', [], '', 'GL Transmission Format binary');
        set_config('addedglbtype', 1, 'mod_mavr');
    }

    return true;
}
