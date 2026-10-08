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
     * @param int $groupid group to show (0 = all groups the viewer may see)
     */
    public function __construct(
        /** @var \stdClass mavr row */
        protected \stdClass $mavr,
        /** @var \cm_info course module */
        protected \cm_info $cm,
        /** @var \context_module module context */
        protected \context_module $context,
        /** @var int group to show (0 = all groups the viewer may see) */
        protected int $groupid = 0,
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
        $groupmode = groups_get_activity_groupmode($this->cm);
        $records = [];
        if (
            $this->groupid > 0 || $groupmode != SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $this->context)
        ) {
            // In separate groups "all groups" is only for those who may access all groups; anyone
            // else without a group of their own sees no attempts.
            $userfields = \core_user\fields::for_name()->get_sql('u', true, '', '', true);
            $groupjoin = '';
            $params = ['mavrid' => $this->mavr->id];
            if ($this->groupid > 0) {
                $groupjoin = 'JOIN {groups_members} gm ON gm.userid = a.userid AND gm.groupid = :groupid';
                $params['groupid'] = $this->groupid;
            }
            $sql = "SELECT a.id, a.userid, a.attempt, a.state, a.score, a.timestart, a.timefinish
                           {$userfields->selects}
                      FROM {mavr_attempts} a
                      JOIN {user} u ON u.id = a.userid
                           {$groupjoin}
                           {$userfields->joins}
                     WHERE a.mavrid = :mavrid
                  ORDER BY u.lastname ASC, u.firstname ASC, a.userid ASC, a.attempt ASC";
            $records = $DB->get_records_sql($sql, $params + $userfields->params);
        }

        $baseurl = new \moodle_url('/mod/mavr/report.php', ['id' => $this->cm->id]);
        $rows = [];
        foreach ($records as $record) {
            $score = $record->score === null ? null : (float)$record->score;
            $row = [
                'attemptid' => (int)$record->id,
                'user' => fullname($record),
                'userurl' => (new \moodle_url(
                    '/user/view.php',
                    ['id' => $record->userid, 'course' => $this->cm->course]
                ))->out(false),
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
     * Refuse an attempt of someone the current user may not see in separate groups.
     *
     * Used by report.php before an attempt is shown or deleted: in separate groups without
     * "access all groups" a teacher only reaches attempts of people sharing a group with them.
     * Without groups, and in visible groups, every attempt of the activity is allowed.
     *
     * @param \stdClass $course the course
     * @param \cm_info $cm course module
     * @param \stdClass $attempt mavr_attempts row (already checked to belong to the activity)
     * @return void
     * @throws \moodle_exception errorattemptnotingroup
     */
    public static function require_attempt_in_groups(\stdClass $course, \cm_info $cm, \stdClass $attempt): void {
        if (!groups_user_groups_visible($course, (int)$attempt->userid, $cm)) {
            throw new \moodle_exception('errorattemptnotingroup', 'mod_mavr');
        }
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
