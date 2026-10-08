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

namespace mod_vrroom\local\questions;

use core_question\local\bank\question_bank_helper;
use core_question\local\bank\question_version_status;

/**
 * Question bank access for VR room: the station question picker and entry checks.
 *
 * VR room never stores a question id. It stores a question bank entry and always
 * uses the latest non-draft version of it, exactly as a quiz slot that is set to
 * "always latest" does. Everything that turns an entry into a question goes
 * through this class so the rule is applied in one place.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bank {
    /** @var string[] Question types that can never be placed in a room. */
    private const EXCLUDED_QTYPES = ['random', 'missingtype'];

    /**
     * Load the question engine, which is not autoloaded.
     */
    private static function require_engine(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/questionlib.php');
    }

    /**
     * Choices for the station question picker.
     *
     * Only the shareable question banks of the course the current user can use
     * questions from are listed. In a bank where the user holds only
     * moodle/question:usemine, only the questions they created are offered.
     * Hidden questions, draft-only entries, subquestions and question types
     * that cannot be graded automatically are left out.
     *
     * @param int $courseid The course the activity lives in.
     * @return array Question bank entry id => "Bank: Category: Question" (HTML-safe).
     */
    public static function get_choices(int $courseid): array {
        global $DB, $USER;

        self::require_engine();
        if ($courseid <= 0) {
            return [];
        }

        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(
            [$courseid],
            [],
            ['moodle/question:useall', 'moodle/question:usemine'],
            true
        );

        $choices = [];
        foreach ($banks as $bank) {
            $bankcontext = \context_module::instance($bank->cminfo->id);
            $useall = has_capability('moodle/question:useall', $bankcontext);
            $usemine = has_capability('moodle/question:usemine', $bankcontext);
            if (!$useall && !$usemine) {
                continue;
            }

            $categories = [];
            foreach ($bank->questioncategories as $category) {
                $categories[(int)$category->id] = $category;
            }
            if (!$categories) {
                continue;
            }

            [$insql, $params] = $DB->get_in_or_equal(array_keys($categories), SQL_PARAMS_NAMED, 'cat');
            $params['draft'] = question_version_status::QUESTION_STATUS_DRAFT;
            $params['hidden'] = question_version_status::QUESTION_STATUS_HIDDEN;
            $ownersql = '';
            if (!$useall) {
                $ownersql = ' AND q.createdby = :userid';
                $params['userid'] = $USER->id;
            }

            $sql = "SELECT qbe.id AS qbeid, qbe.questioncategoryid, q.name, q.qtype
                      FROM {question_bank_entries} qbe
                      JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                      JOIN {question} q ON q.id = qv.questionid
                     WHERE qbe.questioncategoryid {$insql}
                       AND q.parent = 0
                       AND qv.status <> :hidden
                       AND qv.version = (
                               SELECT MAX(v.version)
                                 FROM {question_versions} v
                                WHERE v.questionbankentryid = qbe.id
                                  AND v.status <> :draft
                           )
                       {$ownersql}
                  ORDER BY q.name, qbe.id";
            $rows = $DB->get_records_sql($sql, $params);

            $bankname = $bank->cminfo->get_formatted_name();
            foreach ($rows as $row) {
                if (!self::allowed_qtype((string)$row->qtype)) {
                    continue;
                }
                $category = $categories[(int)$row->questioncategoryid];
                $choices[(int)$row->qbeid] = get_string('questionchoice', 'mod_vrroom', (object)[
                    'bank' => $bankname,
                    'category' => format_string($category->name, true, ['context' => $bankcontext]),
                    'name' => format_string($row->name, true, ['context' => $bankcontext]),
                ]);
            }
        }

        return $choices;
    }

    /**
     * Check that a question bank entry may be placed in an activity.
     *
     * The entry must exist with a usable (latest non-draft, not hidden) version
     * of an allowed type, and the current user must be allowed to use it: either
     * moodle/question:useall on the bank, or moodle/question:usemine on a
     * question they created. Entries already stored on this activity ($keep) skip
     * the use check, so a colleague without access to the bank can still edit the
     * activity without losing its questions. A kept entry may also stay when it
     * has since been hidden in the bank.
     *
     * @param int $qbeid The question bank entry id from the form.
     * @param int $courseid The course of the activity (the picker's scope; the use check is per bank).
     * @param int[] $keep Entry ids already bound to this activity.
     * @return string|null Null when allowed, else a mod_vrroom lang string key.
     */
    public static function validate_entry(int $qbeid, int $courseid, array $keep = []): ?string {
        global $USER;

        self::require_engine();
        if ($qbeid <= 0) {
            return 'errorquestionnotfound';
        }

        $kept = in_array($qbeid, array_map('intval', $keep), true);

        $record = self::get_usable_version($qbeid);
        if ($record === null) {
            return 'errorquestionnotfound';
        }
        if (!$kept && $record->status === question_version_status::QUESTION_STATUS_HIDDEN) {
            return 'errorquestionnotfound';
        }
        if (!self::allowed_qtype((string)$record->qtype)) {
            return 'errorquestiontype';
        }
        if ($kept) {
            return null;
        }

        $context = \context::instance_by_id((int)$record->contextid, IGNORE_MISSING);
        if (!$context) {
            return 'errorquestionnotfound';
        }
        if (has_capability('moodle/question:useall', $context)) {
            return null;
        }
        if ((int)$record->createdby === (int)$USER->id && has_capability('moodle/question:usemine', $context)) {
            return null;
        }
        return 'errorquestionnotallowed';
    }

    /**
     * Resolve a question bank entry to the question id to put in a usage.
     *
     * @param int $qbeid The question bank entry id.
     * @param int|null $version A fixed version, or null for the latest non-draft version.
     * @return int|null The question id, or null when there is no such (usable) version.
     */
    public static function resolve_question_id(int $qbeid, ?int $version = null): ?int {
        global $DB;

        if ($qbeid <= 0) {
            return null;
        }
        if ($version !== null) {
            $questionid = $DB->get_field('question_versions', 'questionid', [
                'questionbankentryid' => $qbeid,
                'version' => $version,
            ]);
            return $questionid ? (int)$questionid : null;
        }

        $record = self::get_usable_version($qbeid);
        return $record ? (int)$record->questionid : null;
    }

    /**
     * Whether questions of a type may be placed in a VR room room.
     *
     * The question engine grades in-world answers immediately, so only
     * installed, real, automatically graded types qualify. Random questions are
     * excluded because a room slot always shows one fixed question.
     *
     * @param string $qtype The question type name, e.g. 'truefalse'.
     * @return bool True when allowed.
     */
    public static function allowed_qtype(string $qtype): bool {
        self::require_engine();
        if ($qtype === '' || in_array($qtype, self::EXCLUDED_QTYPES, true)) {
            return false;
        }
        if (!\question_bank::is_qtype_installed($qtype)) {
            return false;
        }
        $type = \question_bank::get_qtype($qtype, false);
        return $type->is_real_question_type() && !$type->is_manual_graded();
    }

    /**
     * The latest non-draft version of an entry, with the fields the checks need.
     *
     * @param int $qbeid The question bank entry id.
     * @return \stdClass|null Fields questionid, version, status, qtype, createdby, contextid; null if none.
     */
    private static function get_usable_version(int $qbeid): ?\stdClass {
        global $DB;

        $sql = "SELECT qv.questionid, qv.version, qv.status, q.qtype, q.createdby, qc.contextid
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.id = :qbeid
                   AND q.parent = 0
                   AND qv.status <> :draft
              ORDER BY qv.version DESC";
        $records = $DB->get_records_sql($sql, [
            'qbeid' => $qbeid,
            'draft' => question_version_status::QUESTION_STATUS_DRAFT,
        ], 0, 1);
        $record = reset($records);
        return $record ?: null;
    }
}
