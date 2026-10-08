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

namespace mod_mavr\local\questions;

/**
 * The question slots of a MAVR activity and their question references.
 *
 * A slot is a graph slot number (what an answer_question objective names) bound
 * to a question bank entry. Each slot row owns one core question_references row,
 * keyed exactly as mod_quiz keys its slots (usingcontextid = module context,
 * component mod_mavr, questionarea 'slot', itemid = mavr_slots.id, version null
 * for "always latest"). Those rows are what make core treat the questions as in
 * use, and what core backup writes under each slot element.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class slots {
    /** @var string Component recorded in question_references. */
    public const COMPONENT = 'mod_mavr';

    /** @var string Question area recorded in question_references. */
    public const QUESTIONAREA = 'slot';

    /**
     * Replace the activity's slots and question references with a new binding set.
     *
     * Slots are matched by slot number: a matching slot keeps its row (and so
     * its id, which is the reference itemid) and has its objective, mark and
     * entry updated in place; new numbers are inserted; numbers no longer bound
     * are deleted together with their references.
     *
     * @param \stdClass $mavr The mavr record (needs id).
     * @param \context_module $context The activity's module context.
     * @param array $bindings compiled_activity::$slots, each
     *      ['slot' => int, 'questionbankentryid' => int, 'maxmark' => float, 'objectiveid' => string].
     */
    public static function sync(\stdClass $mavr, \context_module $context, array $bindings): void {
        global $DB;

        $wanted = [];
        foreach ($bindings as $binding) {
            $binding = (array)$binding;
            $slot = (int)($binding['slot'] ?? 0);
            $qbeid = (int)($binding['questionbankentryid'] ?? 0);
            $objectiveid = (string)($binding['objectiveid'] ?? '');
            $maxmark = (float)($binding['maxmark'] ?? 1);
            if ($slot < 1) {
                throw new \coding_exception('Question slot numbers must be 1 or more.');
            }
            if (isset($wanted[$slot])) {
                throw new \coding_exception('Question slot ' . $slot . ' is bound twice.');
            }
            if ($qbeid <= 0) {
                throw new \coding_exception('Question slot ' . $slot . ' has no question bank entry.');
            }
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $objectiveid)) {
                throw new \coding_exception('Question slot ' . $slot . ' has an invalid objective id.');
            }
            if (!is_finite($maxmark) || $maxmark <= 0) {
                $maxmark = 1.0;
            }
            $wanted[$slot] = (object)[
                'slot' => $slot,
                'questionbankentryid' => $qbeid,
                'objectiveid' => $objectiveid,
                'maxmark' => $maxmark,
            ];
        }

        $transaction = $DB->start_delegated_transaction();

        $existing = $DB->get_records_menu('mavr_slots', ['mavrid' => $mavr->id], '', 'slot, id');
        $existing = array_map('intval', $existing);

        foreach ($wanted as $slot => $binding) {
            if (isset($existing[$slot])) {
                $slotid = $existing[$slot];
                $DB->update_record('mavr_slots', (object)[
                    'id' => $slotid,
                    'objectiveid' => $binding->objectiveid,
                    'maxmark' => $binding->maxmark,
                ]);
            } else {
                $slotid = (int)$DB->insert_record('mavr_slots', (object)[
                    'mavrid' => $mavr->id,
                    'slot' => $slot,
                    'objectiveid' => $binding->objectiveid,
                    'maxmark' => $binding->maxmark,
                ]);
            }
            self::set_reference($context, $slotid, $binding->questionbankentryid);
        }

        foreach ($existing as $slot => $slotid) {
            if (isset($wanted[$slot])) {
                continue;
            }
            $DB->delete_records('question_references', [
                'usingcontextid' => $context->id,
                'component' => self::COMPONENT,
                'questionarea' => self::QUESTIONAREA,
                'itemid' => $slotid,
            ]);
            $DB->delete_records('mavr_slots', ['id' => $slotid]);
        }

        $transaction->allow_commit();
    }

    /**
     * The activity's slots with their bound entries.
     *
     * @param int $mavrid The mavr id.
     * @param \context_module $context The activity's module context.
     * @return \stdClass[] Keyed by slot number, ascending; each has slot, objectiveid,
     *      maxmark, slotid, questionbankentryid (0 when the reference is missing) and
     *      version (null = always latest).
     */
    public static function get_slots(int $mavrid, \context_module $context): array {
        global $DB;

        $sql = "SELECT s.slot, s.objectiveid, s.maxmark, s.id AS slotid,
                       qr.questionbankentryid, qr.version
                  FROM {mavr_slots} s
             LEFT JOIN {question_references} qr ON qr.itemid = s.id
                                               AND qr.usingcontextid = :contextid
                                               AND qr.component = :component
                                               AND qr.questionarea = :questionarea
                 WHERE s.mavrid = :mavrid
              ORDER BY s.slot";
        $records = $DB->get_records_sql($sql, [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'questionarea' => self::QUESTIONAREA,
            'mavrid' => $mavrid,
        ]);

        $result = [];
        foreach ($records as $record) {
            $result[(int)$record->slot] = (object)[
                'slot' => (int)$record->slot,
                'objectiveid' => (string)$record->objectiveid,
                'maxmark' => (float)$record->maxmark,
                'slotid' => (int)$record->slotid,
                'questionbankentryid' => (int)($record->questionbankentryid ?? 0),
                'version' => $record->version === null ? null : (int)$record->version,
            ];
        }
        ksort($result);
        return $result;
    }

    /**
     * Delete every slot and question reference of an activity.
     *
     * Core does not remove a module's question references when the module goes
     * (quiz removes its own), so the activity's delete path calls this.
     *
     * @param \stdClass $mavr The mavr record (needs id).
     * @param \context_module $context The activity's module context.
     */
    public static function delete_all(\stdClass $mavr, \context_module $context): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $slotids = $DB->get_fieldset_select('mavr_slots', 'id', 'mavrid = ?', [$mavr->id]);
        if ($slotids) {
            [$insql, $params] = $DB->get_in_or_equal($slotids, SQL_PARAMS_NAMED, 'slot');
            $params['contextid'] = $context->id;
            $params['component'] = self::COMPONENT;
            $params['questionarea'] = self::QUESTIONAREA;
            $DB->delete_records_select(
                'question_references',
                "usingcontextid = :contextid AND component = :component AND questionarea = :questionarea AND itemid {$insql}",
                $params
            );
        }
        // Any reference left in this context by an older slot row is ours too.
        $DB->delete_records('question_references', [
            'usingcontextid' => $context->id,
            'component' => self::COMPONENT,
            'questionarea' => self::QUESTIONAREA,
        ]);
        $DB->delete_records('mavr_slots', ['mavrid' => $mavr->id]);
        $transaction->allow_commit();
    }

    /**
     * Point one slot's reference at an entry, inserting or updating it in place.
     *
     * @param \context_module $context The activity's module context.
     * @param int $slotid The mavr_slots id (reference itemid).
     * @param int $qbeid The question bank entry id.
     */
    private static function set_reference(\context_module $context, int $slotid, int $qbeid): void {
        global $DB;

        $conditions = [
            'usingcontextid' => $context->id,
            'component' => self::COMPONENT,
            'questionarea' => self::QUESTIONAREA,
            'itemid' => $slotid,
        ];
        $references = $DB->get_records('question_references', $conditions, 'id ASC');
        $reference = array_shift($references);
        // A slot owns exactly one reference; anything beyond the first is stale.
        foreach ($references as $extra) {
            $DB->delete_records('question_references', ['id' => $extra->id]);
        }

        if ($reference) {
            if ((int)$reference->questionbankentryid !== $qbeid || $reference->version !== null) {
                $DB->update_record('question_references', (object)[
                    'id' => $reference->id,
                    'questionbankentryid' => $qbeid,
                    'version' => null,
                ]);
            }
            return;
        }

        $DB->insert_record('question_references', (object)($conditions + [
            'questionbankentryid' => $qbeid,
            'version' => null,
        ]));
    }
}
