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

namespace mod_vrroom\task;

use mod_vrroom\local\attempt\attempt_manager;

/**
 * Finishes attempts whose time ran out while nobody was sending anything for them.
 *
 * An attempt with a time limit is also finished the moment its student's browser next talks to
 * the server; this task catches the attempts that were simply left open (a closed tab, a lost
 * connection), so their grades and completion do not wait for the student to come back.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_overdue_attempts extends \core\task\scheduled_task {
    /** @var int Most attempts finished in one run; the rest wait for the next run. */
    public const BATCH = 500;

    /**
     * Task name shown to administrators.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskfinishoverdue', 'mod_vrroom');
    }

    /**
     * Finish every overdue attempt in progress, oldest deadline first.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $now = time();
        $cutoff = $now - attempt_manager::DEADLINE_GRACE;
        $rows = $DB->get_records_select(
            'vrroom_attempts',
            'state = :state AND timedue > 0 AND timedue < :cutoff',
            ['state' => attempt_manager::STATE_INPROGRESS, 'cutoff' => $cutoff],
            'timedue ASC, id ASC',
            'id',
            0,
            self::BATCH
        );

        $finished = 0;
        foreach ($rows as $row) {
            try {
                if (attempt_manager::close_if_overdue($row, $now)) {
                    $finished++;
                }
            } catch (\Throwable $e) {
                mtrace('VR room: could not finish overdue attempt ' . (int)$row->id . ': ' . $e->getMessage());
            }
        }
        if ($finished > 0) {
            mtrace('VR room: finished ' . $finished . ' overdue attempt(s).');
        }
    }
}
