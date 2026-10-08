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
 * Uninstall steps for mod_mavr.
 *
 * Core drops the plugin's tables and its file areas go with the module contexts. What core does
 * not remove is data MAVR keeps in core tables: question usages, question references and xAPI
 * states, plus the glb file type the plugin registered at install.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove MAVR's rows from core tables.
 *
 * @return bool
 */
function xmldb_mavr_uninstall(): bool {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/question/engine/lib.php');

    // Question usages of every attempt. The ids are read first, so the delete never selects
    // from the table it deletes from.
    $qubaids = $DB->get_fieldset_select('question_usages', 'id', 'component = :component', ['component' => 'mod_mavr']);
    foreach (array_chunk($qubaids, 500) as $chunk) {
        question_engine::delete_questions_usage_by_activities(new qubaid_list($chunk));
    }

    // Slot references; core never deletes a module's references for it.
    $DB->delete_records('question_references', ['component' => 'mod_mavr']);

    // Resume positions stored in the core xAPI state store.
    try {
        \core_xapi\handler::create('mod_mavr')->wipe_states();
    } catch (\Throwable $e) {
        $DB->delete_records('xapi_states', ['component' => 'mod_mavr']);
    }

    // The glb file type, only when this plugin added it.
    if (get_config('mod_mavr', 'addedglbtype')) {
        try {
            \core_filetypes::delete_type('glb');
        } catch (\Throwable $e) {
            debugging('Could not remove the glb file type: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    return true;
}
