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

namespace mavrruntime_builtin\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mavrruntime_builtin\local\live\room_manager;

/**
 * Leave a live room.
 *
 * Only ever removes the caller's own presence, so it needs no activity context: an unknown or
 * foreign peer id changes nothing and still answers 'ok'.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_leave extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Own peer id from live_join'),
        ]);
    }

    /**
     * Leave.
     *
     * @param string $peerid Own peer id.
     * @return array
     */
    public static function execute(string $peerid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['peerid' => $peerid]);
        room_manager::leave((string)$params['peerid'], (int)$USER->id);
        return ['status' => 'ok'];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok'),
        ]);
    }
}
