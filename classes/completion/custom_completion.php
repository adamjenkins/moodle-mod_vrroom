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

declare(strict_types=1);

namespace mod_mavr\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules of MAVR activities.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Completion state of a rule for $this->userid.
     *
     * completionobjectives: complete when a finished attempt completed every required objective.
     *
     * @param string $rule the rule name
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $done = $DB->record_exists('mavr_attempts', [
            'mavrid' => $this->cm->instance,
            'userid' => $this->userid,
            'state' => 'finished',
            'requireddone' => 1,
        ]);
        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Rules this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionobjectives'];
    }

    /**
     * Descriptions of the custom rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return ['completionobjectives' => get_string('completiondetail:objectives', 'mod_mavr')];
    }

    /**
     * Display order of all completion rules.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionobjectives', 'completionusegrade', 'completionpassgrade'];
    }
}
