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
 * Upgrade steps of the VR room built-in viewer run mode.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the run mode's tables.
 *
 * @param int $oldversion The version upgraded from.
 * @return bool
 */
function xmldb_vrroomruntime_builtin_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100801) {
        // Live rooms get a number. Presences and signals live for seconds only: drop them, so
        // nobody is left in a room without a number (everyone joins again).
        $DB->delete_records('vrroomruntime_builtin_signals');
        $DB->delete_records('vrroomruntime_builtin_peers');

        $table = new xmldb_table('vrroomruntime_builtin_peers');
        $field = new xmldb_field('room', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'groupid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $old = new xmldb_index('vrroomid-groupid', XMLDB_INDEX_NOTUNIQUE, ['vrroomid', 'groupid']);
        if ($dbman->index_exists($table, $old)) {
            $dbman->drop_index($table, $old);
        }
        $new = new xmldb_index('vrroomid-groupid-room', XMLDB_INDEX_NOTUNIQUE, ['vrroomid', 'groupid', 'room']);
        if (!$dbman->index_exists($table, $new)) {
            $dbman->add_index($table, $new);
        }
        upgrade_plugin_savepoint(true, 2026100801, 'vrroomruntime', 'builtin');
    }

    return true;
}
