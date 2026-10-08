<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Objective hints: hidden from students until requested, released one by one by the server.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_mavr\local\attempt;

use mod_mavr\local\graph\evaluator;
use mod_mavr\local\instance_manager;

/**
 * Objective hints (Phase 2 contract §3.2, §4.2.2).
 *
 * A hint request is a server-asserted statement (verb `requested`, object type `hint`), so a
 * browser cannot suppress it to avoid the penalty. The graph sent to students carries no hint
 * texts; each text is released by {@see self::request()} and, after a reload, by
 * {@see self::launch_hints()} for the hints already requested.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hint_manager {
    /** @var int Most hints on one objective (graph_validator::MAX_HINTS). */
    public const MAX_HINTS = 5;

    /**
     * The graph without hint texts (ids and penalties kept).
     *
     * @param array $graph decoded mavr-graph/1
     * @return array the same graph with every objective's hints[].text removed
     */
    public static function strip_texts(array $graph): array {
        if (!isset($graph['objectives']) || !is_array($graph['objectives'])) {
            return $graph;
        }
        foreach ($graph['objectives'] as $k => $objective) {
            if (!is_array($objective) || !isset($objective['hints']) || !is_array($objective['hints'])) {
                continue;
            }
            foreach ($objective['hints'] as $j => $hint) {
                if (is_array($hint)) {
                    unset($hint['text']);
                    $graph['objectives'][$k]['hints'][$j] = $hint;
                }
            }
        }
        return $graph;
    }

    /**
     * The hints already released in an attempt, for the launch config.
     *
     * @param array $graph the full graph (with hint texts) of the attempt's version
     * @param array[] $events the attempt's §4.4.1 events (event_store::get_events()); [] for a preview
     * @param \context_module $context module context (for filters)
     * @return array objectiveid => ['total' => int, 'used' => [['id' => string, 'text' => string]]],
     *     objectives with hints only, in graph order
     */
    public static function launch_hints(array $graph, array $events, \context_module $context): array {
        $result = (new evaluator($graph))->evaluate($events);
        $hints = [];
        foreach (self::objectives_with_hints($graph) as $id => $list) {
            $used = (int)($result->objectives[$id]['hintsused'] ?? 0);
            $released = [];
            foreach (array_slice($list, 0, $used) as $hint) {
                $released[] = [
                    'id' => (string)$hint['id'],
                    'text' => self::plain_text((string)($hint['text'] ?? ''), $context),
                ];
            }
            $hints[(string)$id] = ['total' => count($list), 'used' => $released];
        }
        return $hints;
    }

    /**
     * Reveal the next hint of an objective in an open attempt and record its request.
     *
     * The caller has checked that the attempt is the user's own, open and within its time
     * (attempt_manager::require_own_open_attempt()).
     *
     * @param \stdClass $attempt mavr_attempts row (open)
     * @param string $objectiveid objective id from the client
     * @param \context_module $context module context of the attempt
     * @return array ['hintid' => string, 'text' => string, 'hintsused' => int, 'hintstotal' => int,
     *     'result' => \mod_mavr\local\graph\evaluation_result]
     * @throws \moodle_exception errorhintunknown, errorhintlocked, errorhintcompleted, errornomorehints
     */
    public static function request(\stdClass $attempt, string $objectiveid, \context_module $context): array {
        $graph = instance_manager::get_graph((int)$attempt->mavrid, (int)$attempt->graphversion);
        $objectives = self::objectives_with_hints($graph);
        if (!array_key_exists($objectiveid, $objectives)) {
            throw new \moodle_exception('errorhintunknown', 'mod_mavr');
        }
        $hints = $objectives[$objectiveid];

        $result = attempt_manager::recompute($attempt);
        $status = (string)($result->objectives[$objectiveid]['status'] ?? evaluator::OBJECTIVE_LOCKED);
        if ($status === evaluator::OBJECTIVE_LOCKED) {
            throw new \moodle_exception('errorhintlocked', 'mod_mavr');
        }
        if ($status === evaluator::OBJECTIVE_COMPLETED) {
            throw new \moodle_exception('errorhintcompleted', 'mod_mavr');
        }
        $used = (int)($result->objectives[$objectiveid]['hintsused'] ?? 0);
        if ($used >= count($hints)) {
            throw new \moodle_exception('errornomorehints', 'mod_mavr');
        }
        $hint = $hints[$used];
        $penalty = $hint['penalty'] ?? 0;
        $penalty = (is_int($penalty) || is_float($penalty)) ? (float)$penalty : 0.0;

        event_store::record_server($attempt, 'requested', 'hint', null, (string)$hint['id'],
            ['penalty' => $penalty, 'objective' => $objectiveid]);
        $result = attempt_manager::recompute($attempt);

        return [
            'hintid' => (string)$hint['id'],
            'text' => self::plain_text((string)($hint['text'] ?? ''), $context),
            'hintsused' => (int)($result->objectives[$objectiveid]['hintsused'] ?? $used + 1),
            'hintstotal' => count($hints),
            'result' => $result,
        ];
    }

    /**
     * A hint text as plain text: filtered like a name, tags stripped, entities decoded.
     *
     * The client shows it with textContent, so nothing in it is ever parsed as HTML.
     *
     * @param string $text hint text from the graph
     * @param \context_module $context module context (for filters)
     * @return string
     */
    public static function plain_text(string $text, \context_module $context): string {
        $text = format_string($text, true, ['context' => $context, 'escape' => false]);
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Objectives of a graph that have a non-empty hints list, with their hints.
     *
     * Hints without a string id are left out (a validated graph has none).
     *
     * @param array $graph decoded mavr-graph/1
     * @return array objective id => list of hint arrays, in graph order
     */
    private static function objectives_with_hints(array $graph): array {
        $objectives = [];
        foreach ((array)($graph['objectives'] ?? []) as $objective) {
            if (!is_array($objective) || !isset($objective['id']) || !is_string($objective['id'])
                    || !isset($objective['hints']) || !is_array($objective['hints'])) {
                continue;
            }
            $hints = [];
            foreach ($objective['hints'] as $hint) {
                if (is_array($hint) && isset($hint['id']) && is_string($hint['id'])) {
                    $hints[] = $hint;
                }
            }
            if ($hints && !array_key_exists($objective['id'], $objectives)) {
                $objectives[$objective['id']] = $hints;
            }
        }
        return $objectives;
    }
}
