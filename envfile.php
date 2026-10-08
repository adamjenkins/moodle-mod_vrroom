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
 * Serves the model and preview image of a room from an installed room pack.
 *
 * URL: /mod/vrroom/envfile.php/<pack>/<revision>/<room>/<file>. Only the files a pack's manifest
 * names are served (never a path built from the request); a URL with the pack's current revision
 * is cached by the browser as immutable. Room files are not user data: login is the only gate.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable moodle specific debug messages and any errors in output.
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use mod_vrroom\local\scene\environment_registry;

$relativepath = get_file_argument();
require_login(null, false);

$parts = explode('/', ltrim((string)$relativepath, '/'));
if (count($parts) !== 4) {
    send_file_not_found();
}
[$pack, $revision, $room, $filename] = $parts;
$file = environment_registry::get_file($pack, $room, $filename);
if ($file === null) {
    send_file_not_found();
}
$current = (string)$revision === (string)environment_registry::get_revision($pack);
send_file(
    $file['path'],
    $filename,
    $current ? YEARSECS : 300,
    0,
    false,
    false,
    $file['mimetype'],
    false,
    ['immutable' => $current, 'cacheability' => 'private']
);
