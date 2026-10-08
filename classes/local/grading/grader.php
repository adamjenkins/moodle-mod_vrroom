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

namespace mod_vrroom\local\grading;

/**
 * Gradebook integration: attempt scores to grades by grading method.
 *
 * The grade_item_update / update_grades flow is adapted from mod_gear (lib.php:281-352).
 *
 * @package    mod_vrroom
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grader {
    /** @var int The highest finished attempt counts. */
    public const GRADE_HIGHEST = 1;

    /** @var int The mean of all finished attempts counts. */
    public const GRADE_AVERAGE = 2;

    /** @var int The finished attempt with the highest attempt number counts. */
    public const GRADE_LAST = 3;

    /**
     * Grading method choices for the form.
     *
     * @return array int => string
     */
    public static function grade_options(): array {
        return [
            self::GRADE_HIGHEST => get_string('gradehighest', 'mod_vrroom'),
            self::GRADE_AVERAGE => get_string('gradeaverage', 'mod_vrroom'),
            self::GRADE_LAST => get_string('gradelast', 'mod_vrroom'),
        ];
    }

    /**
     * Score fraction 0..1 for one user by grading method over finished attempts.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int $userid
     * @return float|null null when the user has no finished attempt with a score
     */
    public static function calculate_user_score(\stdClass $vrroom, int $userid): ?float {
        global $DB;
        $scores = $DB->get_records_select_menu(
            'vrroom_attempts',
            'vrroomid = :vrroomid AND userid = :userid AND state = :state AND score IS NOT NULL',
            ['vrroomid' => $vrroom->id, 'userid' => $userid, 'state' => 'finished'],
            'attempt ASC',
            'attempt, score'
        );
        return self::combine($vrroom, array_map('floatval', $scores));
    }

    /**
     * Grades for the gradebook.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int $userid one user, or 0 for every user with a finished attempt
     * @return array userid => (object)['userid' => int, 'rawgrade' => float]
     */
    public static function get_user_grades(\stdClass $vrroom, int $userid = 0): array {
        global $DB;
        $params = ['vrroomid' => $vrroom->id, 'state' => 'finished'];
        $where = 'vrroomid = :vrroomid AND state = :state AND score IS NOT NULL';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $rs = $DB->get_recordset_select(
            'vrroom_attempts',
            $where,
            $params,
            'userid ASC, attempt ASC',
            'id, userid, attempt, score'
        );
        $byuser = [];
        foreach ($rs as $row) {
            $byuser[(int)$row->userid][(int)$row->attempt] = (float)$row->score;
        }
        $rs->close();

        $grades = [];
        foreach ($byuser as $uid => $scores) {
            $score = self::combine($vrroom, $scores);
            if ($score === null) {
                continue;
            }
            $grades[$uid] = (object) [
                'userid' => $uid,
                'rawgrade' => round($score * (float)$vrroom->grade, 5),
            ];
        }
        return $grades;
    }

    /**
     * Create or update the grade item, optionally with grades.
     *
     * @param \stdClass $vrroom vrroom row (form data accepted: cmidnumber, gradepass)
     * @param mixed $grades null, a grade object/array, or 'reset'
     * @return int GRADE_UPDATE_OK or an error code
     */
    public static function grade_item_update(\stdClass $vrroom, $grades = null): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $params = ['itemname' => $vrroom->name];
        if (property_exists($vrroom, 'cmidnumber')) {
            $params['idnumber'] = (string)$vrroom->cmidnumber;
        }
        if ((int)$vrroom->grade > 0) {
            $params['gradetype'] = GRADE_TYPE_VALUE;
            $params['grademax'] = (float)$vrroom->grade;
            $params['grademin'] = 0;
        } else {
            $params['gradetype'] = GRADE_TYPE_NONE;
        }
        if (isset($vrroom->gradepass)) {
            $params['gradepass'] = $vrroom->gradepass;
        }
        if ($grades === 'reset') {
            $params['reset'] = true;
            $grades = null;
        }
        return (int)grade_update('mod/vrroom', $vrroom->course, 'mod', 'vrroom', $vrroom->id, 0, $grades, $params);
    }

    /**
     * Delete the grade item.
     *
     * @param \stdClass $vrroom vrroom row
     * @return int GRADE_UPDATE_OK or an error code
     */
    public static function grade_item_delete(\stdClass $vrroom): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        return (int)grade_update('mod/vrroom', $vrroom->course, 'mod', 'vrroom', $vrroom->id, 0, null, ['deleted' => 1]);
    }

    /**
     * Push grades to the gradebook.
     *
     * @param \stdClass $vrroom vrroom row
     * @param int $userid one user, or 0 for all
     * @param bool $nullifnone send a null grade for $userid when the user has none
     * @return void
     */
    public static function update_grades(\stdClass $vrroom, int $userid = 0, bool $nullifnone = true): void {
        if ((int)$vrroom->grade === 0) {
            self::grade_item_update($vrroom);
        } else if ($grades = self::get_user_grades($vrroom, $userid)) {
            self::grade_item_update($vrroom, $grades);
        } else if ($userid && $nullifnone) {
            self::grade_item_update($vrroom, (object)['userid' => $userid, 'rawgrade' => null]);
        } else {
            self::grade_item_update($vrroom);
        }
    }

    /**
     * Grade for a score, with two decimals.
     *
     * @param \stdClass $vrroom vrroom row
     * @param float|null $score fraction 0..1
     * @return string "75.00", or "-" when there is no score
     */
    public static function format_grade(\stdClass $vrroom, ?float $score): string {
        if ($score === null) {
            return '-';
        }
        return format_float($score * (float)$vrroom->grade, 2);
    }

    /**
     * The gradepass of the activity's grade item, 0 when none.
     *
     * @param \stdClass $vrroom vrroom row
     * @return float
     */
    public static function get_gradepass(\stdClass $vrroom): float {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch([
            'courseid' => $vrroom->course,
            'itemtype' => 'mod',
            'itemmodule' => 'vrroom',
            'iteminstance' => $vrroom->id,
            'itemnumber' => 0,
        ]);
        return $item ? (float)$item->gradepass : 0.0;
    }

    /**
     * Combine attempt scores by the activity's grading method.
     *
     * @param \stdClass $vrroom vrroom row
     * @param float[] $scores attempt number => score, any order
     * @return float|null
     */
    private static function combine(\stdClass $vrroom, array $scores): ?float {
        if (!$scores) {
            return null;
        }
        ksort($scores);
        switch ((int)$vrroom->grademethod) {
            case self::GRADE_AVERAGE:
                return array_sum($scores) / count($scores);
            case self::GRADE_LAST:
                return (float)end($scores);
            case self::GRADE_HIGHEST:
            default:
                return (float)max($scores);
        }
    }
}
