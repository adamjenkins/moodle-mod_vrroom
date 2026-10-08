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

namespace mod_mavr\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_mavr\local\editor\editor_manager;

/**
 * Validate and save the room editor document of an activity.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor_save extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'documentjson' => new external_value(PARAM_RAW, 'JSON mavr-editdoc/1 document'),
        ]);
    }

    /**
     * Validate the document and store it; nothing is stored when anything is wrong.
     *
     * @param int $cmid course module id
     * @param string $documentjson the document
     * @return array
     */
    public static function execute(int $cmid, string $documentjson): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'documentjson' => $documentjson,
        ]);

        [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'mavr');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/mavr:manage', $context);

        $mavr = $DB->get_record('mavr', ['id' => $cm->instance], '*', MUST_EXIST);
        return editor_manager::save($cm, $context, $mavr, $params['documentjson']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revision' => new external_value(PARAM_ALPHANUM, 'Revision of what is now stored'),
            'graphversion' => new external_value(PARAM_INT, 'Current task graph version'),
            'scenesupdated' => new external_value(PARAM_BOOL, 'Whether the rooms changed'),
            'graphupdated' => new external_value(PARAM_BOOL, 'Whether a new task graph version was made'),
            'customised' => new external_value(PARAM_INT, 'Time of the first editor save'),
        ]);
    }
}
