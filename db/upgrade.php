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
 * Upgrade steps for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin from an older version.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_vrroom_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100801) {
        // Question mode: one try per question, or retries until fully correct with a penalty per wrong try.
        $table = new xmldb_table('vrroom');
        $field = new xmldb_field('questionmode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'once', 'maxattempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'retrypenalty',
            XMLDB_TYPE_NUMBER,
            '10, 7',
            null,
            XMLDB_NOTNULL,
            null,
            '0.3333333',
            'questionmode'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026100801, 'vrroom');
    }

    if ($oldversion < 2026100803) {
        // Several live rooms: in fixed mode the number of rooms, in dynamic mode the most open at once.
        // Existing activities get 1, so live mode 1 keeps today's behaviour: one fixed room.
        $table = new xmldb_table('vrroom');
        $field = new xmldb_field('liverooms', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'livemaxusers');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026100803, 'vrroom');
    }

    return true;
}
