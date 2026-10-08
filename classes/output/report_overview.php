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

namespace mod_mavr\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use mod_mavr\local\grading\grader;

/**
 * The attempts overview of the MAVR report: one row per attempt.
 *
 * The idea of a teacher report over an activity's tracked actions is adapted from mod_gear's
 * analytics report.
 *
 * Text is handed to the template raw and escaped there ({{ }}), so names reach the page escaped
 * exactly once.
 *
 * @package    mod_mavr
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_overview implements renderable, templatable {

    /**
     * Constructor.
     *
     * @param \stdClass $mavr mavr row
     * @param \cm_info $cm course module
     * @param \context_module $context module context
     */
    public function __construct(
        /** @var \stdClass mavr row */
        protected \stdClass $mavr,
        /** @var \cm_info course module */
        protected \cm_info $cm,
        /** @var \context_module module context */
        protected \context_module $context,
    ) {
    }

    /**
     * Data for mod_mavr/report_overview.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        $candelete = has_capability('mod/mavr:deleteattempts', $this->context);
        $userfields = \core_user\fields::for_name()->get_sql('u', true, '', '', true);
        $sql = "SELECT a.id, a.userid, a.attempt, a.state, a.score, a.timestart, a.timefinish
                       {$userfields->selects}
                  FROM {mavr_attempts} a
                  JOIN {user} u ON u.id = a.userid
                       {$userfields->joins}
                 WHERE a.mavrid = :mavrid
              ORDER BY u.lastname ASC, u.firstname ASC, a.userid ASC, a.attempt ASC";
        $records = $DB->get_records_sql($sql, ['mavrid' => $this->mavr->id] + $userfields->params);

        $baseurl = new \moodle_url('/mod/mavr/report.php', ['id' => $this->cm->id]);
        $rows = [];
        foreach ($records as $record) {
            $score = $record->score === null ? null : (float)$record->score;
            $row = [
                'attemptid' => (int)$record->id,
                'user' => fullname($record),
                'userurl' => (new \moodle_url('/user/view.php',
                    ['id' => $record->userid, 'course' => $this->cm->course]))->out(false),
                'attempt' => (int)$record->attempt,
                'state' => self::state_label((string)$record->state),
                'score' => self::format_score($score),
                'grade' => grader::format_grade($this->mavr, $score),
                'timestart' => $record->timestart ? userdate((int)$record->timestart) : '-',
                'timefinish' => $record->timefinish ? userdate((int)$record->timefinish) : '-',
                'viewurl' => (new \moodle_url($baseurl, ['attemptid' => $record->id]))->out(false),
                'candelete' => $candelete,
                'deleteurl' => $candelete ? (new \moodle_url($baseurl, ['delete' => $record->id]))->out(false) : '',
            ];
            $rows[] = $row;
        }

        return [
            'cmid' => (int)$this->cm->id,
            'hasattempts' => !empty($rows),
            'attempts' => $rows,
        ];
    }

    /**
     * Label of an attempt state.
     *
     * @param string $state inprogress, finished or abandoned
     * @return string
     */
    public static function state_label(string $state): string {
        if (get_string_manager()->string_exists('state_' . $state, 'mod_mavr')) {
            return get_string('state_' . $state, 'mod_mavr');
        }
        return $state;
    }

    /**
     * A score fraction as a percentage, "-" when there is none.
     *
     * @param float|null $score fraction 0..1
     * @return string
     */
    public static function format_score(?float $score): string {
        if ($score === null) {
            return '-';
        }
        return format_float($score * 100, 2) . ' %';
    }
}
