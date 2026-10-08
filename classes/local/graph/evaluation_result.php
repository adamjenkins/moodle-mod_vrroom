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
 * Immutable result of evaluating a mavr-graph/1 task graph against an event list.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_mavr\local\graph;

/**
 * Immutable result of evaluating a mavr-graph/1 task graph against an event list.
 *
 * The shape returned by {@see self::to_array()} is identical to the value returned by the
 * client evaluator's evaluate() in mavrruntime_builtin/graph.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class evaluation_result {
    /**
     * Constructor.
     *
     * @param string[] $eventstatus status of each input event, in input order
     *     (evaluator::STATUS_APPLIED, STATUS_IGNORED or STATUS_LOCKED)
     * @param array $objectives objective id => ['status' => string, 'earned' => float,
     *     'points' => float, 'required' => bool, 'hintsused' => int], in graph order
     * @param float $earnedpoints sum of earned points of completed objectives (rounded to 7 places)
     * @param float $maxpoints sum of points of all objectives (rounded to 7 places)
     * @param float $score attempt score 0..1 (rounded to 5 places)
     * @param int $requireddone number of required objectives completed
     * @param int $requiredtotal number of required objectives
     * @param bool $finished whether a finish action has run
     * @param bool $allrequiredcompleted whether there are required objectives and all are completed
     * @param string[] $hidden ids of hidden interactables, sorted ascending (byte order)
     * @param array[] $effects client effects ['event' => int, 'rule' => string, 'do' => string,
     *     'target' => string], in the order they were produced
     */
    public function __construct(
        /** @var string[] status of each input event, in input order */
        public readonly array $eventstatus,
        /** @var array objective id => status, earned, points, required, hintsused; graph order */
        public readonly array $objectives,
        /** @var float sum of earned points of completed objectives */
        public readonly float $earnedpoints,
        /** @var float sum of points of all objectives */
        public readonly float $maxpoints,
        /** @var float attempt score 0..1 */
        public readonly float $score,
        /** @var int number of required objectives completed */
        public readonly int $requireddone,
        /** @var int number of required objectives */
        public readonly int $requiredtotal,
        /** @var bool whether a finish action has run */
        public readonly bool $finished,
        /** @var bool whether all required objectives are completed (and there is at least one) */
        public readonly bool $allrequiredcompleted,
        /** @var string[] ids of hidden interactables, sorted */
        public readonly array $hidden,
        /** @var array[] client effects in production order */
        public readonly array $effects,
    ) {
    }

    /**
     * The result as the plain array shape shared with the client evaluator (contract §4.4.1).
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'eventstatus' => array_values($this->eventstatus),
            'objectives' => $this->objectives,
            'earnedpoints' => $this->earnedpoints,
            'maxpoints' => $this->maxpoints,
            'score' => $this->score,
            'requireddone' => $this->requireddone,
            'requiredtotal' => $this->requiredtotal,
            'finished' => $this->finished,
            'allrequiredcompleted' => $this->allrequiredcompleted,
            'hidden' => array_values($this->hidden),
            'effects' => array_values($this->effects),
        ];
    }

    /**
     * Ids of the completed objectives, in graph order.
     *
     * @return string[]
     */
    public function completed_ids(): array {
        $ids = [];
        foreach ($this->objectives as $id => $objective) {
            if ($objective['status'] === evaluator::OBJECTIVE_COMPLETED) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }
}
