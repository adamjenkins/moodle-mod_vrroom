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

namespace mod_mavr\local\attempt;

use mod_mavr\local\graph\evaluator;

/**
 * Storage of accepted statements (the mavr_events table).
 *
 * The tracking-table shape (one row per recorded action of an attempt, with a JSON payload and
 * server time) is adapted from mod_gear's gear_tracking table.
 *
 * @package    mod_mavr
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_store {

    /** @var string[] Server verbs derived from the evaluation itself; never fed back to the evaluator. */
    public const DERIVED_VERBS = ['completed', 'passed', 'failed'];

    /**
     * Whether a statement id has already been stored.
     *
     * @param string $statementid lowercase uuid
     * @return bool
     */
    public static function exists(string $statementid): bool {
        global $DB;
        return $DB->record_exists('mavr_events', ['statementid' => strtolower($statementid)]);
    }

    /**
     * Number of stored statements of an attempt.
     *
     * @param int $attemptid
     * @return int
     */
    public static function count(int $attemptid): int {
        global $DB;
        return $DB->count_records('mavr_events', ['attemptid' => $attemptid]);
    }

    /**
     * Insert one row.
     *
     * @param int $attemptid attempt id
     * @param string $statementid lowercase uuid
     * @param array $event §4.4.1 event: verb, type, id, scene
     * @param bool $clientasserted whether the browser reported it
     * @param array|null $result server result, stored as JSON
     * @param int $timestamp time the action happened (client time, already clamped)
     * @return int new row id
     */
    public static function insert(int $attemptid, string $statementid, array $event, bool $clientasserted,
            ?array $result, int $timestamp): int {
        global $DB;
        $type = (string)$event['type'];
        $id = isset($event['id']) && $event['id'] !== '' ? (string)$event['id'] : null;
        $scene = isset($event['scene']) && $event['scene'] !== '' ? (string)$event['scene'] : null;
        if ($type === 'activity') {
            $id = null;
            $scene = null;
        } else if ($type === 'scene') {
            $scene = $scene ?? $id;
            $id = $id ?? $scene;
        }
        $record = (object) [
            'attemptid' => $attemptid,
            'statementid' => strtolower($statementid),
            'verb' => (string)$event['verb'],
            'objecttype' => $type,
            'sceneid' => $scene,
            'objectid' => $id,
            'resultjson' => $result === null ? null : json_encode($result),
            'clientasserted' => $clientasserted ? 1 : 0,
            'timestamp' => $timestamp,
            'timecreated' => time(),
        ];
        return (int)$DB->insert_record('mavr_events', $record);
    }

    /**
     * Record a server-asserted statement with a fresh statement id.
     *
     * @param \stdClass $attempt mavr_attempts row
     * @param string $verb short verb
     * @param string $type object type
     * @param string|null $sceneid scene id
     * @param string|null $id object id
     * @param array|null $result server result
     * @return string the new statement id
     */
    public static function record_server(\stdClass $attempt, string $verb, string $type, ?string $sceneid, ?string $id,
            ?array $result = null): string {
        $statementid = strtolower(\core\uuid::generate());
        $now = time();
        self::insert((int)$attempt->id, $statementid,
            ['verb' => $verb, 'type' => $type, 'id' => $id, 'scene' => $sceneid, 'fraction' => null],
            false, $result, $now);
        return $statementid;
    }

    /**
     * The attempt's events in evaluation order, as §4.4.1 events.
     *
     * Rows with a derived verb (completed, passed, failed) are outputs of the evaluation and are
     * left out, so recording them never changes the next replay.
     *
     * @param int $attemptid
     * @return array[]
     */
    public static function get_events(int $attemptid): array {
        $events = [];
        foreach (self::get_rows($attemptid) as $row) {
            if (in_array($row->verb, self::DERIVED_VERBS, true)) {
                continue;
            }
            $events[] = evaluator::event_from_record($row);
        }
        return $events;
    }

    /**
     * Raw rows of an attempt ordered by id (for reports).
     *
     * @param int $attemptid
     * @return \stdClass[]
     */
    public static function get_rows(int $attemptid): array {
        global $DB;
        return array_values($DB->get_records('mavr_events', ['attemptid' => $attemptid], 'id ASC'));
    }

    /**
     * Delete the events of some attempts.
     *
     * @param int[] $attemptids
     * @return void
     */
    public static function delete_for_attempts(array $attemptids): void {
        global $DB;
        $attemptids = array_values(array_unique(array_map('intval', $attemptids)));
        if (!$attemptids) {
            return;
        }
        foreach (array_chunk($attemptids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->delete_records_select('mavr_events', "attemptid $insql", $params);
        }
    }
}
