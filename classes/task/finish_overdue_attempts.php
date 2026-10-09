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
 * Finishes attempts whose time ran out while nobody was sending anything for them, and attempts
 * that were left complete.
 *
 * An attempt with a time limit is also finished the moment its student's browser next talks to
 * the server; this task catches the attempts that were simply left open (a closed tab, a lost
 * connection), so their grades and completion do not wait for the student to come back.
 *
 * An attempt whose required objectives are all done but whose student closed the tab instead of
 * finishing would otherwise stay in progress for good, with no grade. Once nothing has happened in
 * it for attempt_manager::ABANDON_AFTER seconds it is finished and graded like a normal finish.
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
        return get_string('taskfinishattempts', 'mod_vrroom');
    }

    /**
     * Finish every overdue attempt in progress, oldest deadline first; then every attempt left
     * complete, longest idle first.
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

        $rows = $DB->get_records_select(
            'vrroom_attempts',
            'state = :state AND requireddone = 1 AND timemodified < :idlesince',
            ['state' => attempt_manager::STATE_INPROGRESS, 'idlesince' => $now - attempt_manager::ABANDON_AFTER],
            'timemodified ASC, id ASC',
            'id',
            0,
            self::BATCH
        );
        $finished = 0;
        foreach ($rows as $row) {
            try {
                if (attempt_manager::close_if_abandoned($row, $now)) {
                    $finished++;
                }
            } catch (\Throwable $e) {
                mtrace('VR room: could not finish attempt left complete ' . (int)$row->id . ': ' . $e->getMessage());
            }
        }
        if ($finished > 0) {
            mtrace('VR room: finished ' . $finished . ' attempt(s) left complete.');
        }
    }
}
