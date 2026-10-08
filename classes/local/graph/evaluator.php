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
 * Server-side evaluator for mavr-graph/1 task graphs.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_mavr\local\graph;

/**
 * Server-side evaluator for mavr-graph/1 task graphs (contract §4.4.1, normative).
 *
 * Pure: no database, no globals. The client evaluator (mavrruntime_builtin/graph, file
 * runtime/builtin/amd/src/graph.js) implements the same semantics step by step; the golden
 * fixtures in tests/fixtures/graph are run against both. Any change here must be made there too.
 *
 * Events are arrays {verb, type, id, scene, fraction}; see {@see self::event_from_record()}.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class evaluator {
    /** @var string Event matched an objective that is available or completed. */
    public const STATUS_APPLIED = 'applied';

    /** @var string Event matched no objective. */
    public const STATUS_IGNORED = 'ignored';

    /** @var string Event matched only locked objectives (rejected by trust rule 3). */
    public const STATUS_LOCKED = 'locked';

    /** @var string Objective unlock conditions are not met yet. */
    public const OBJECTIVE_LOCKED = 'locked';

    /** @var string Objective can be worked on. */
    public const OBJECTIVE_AVAILABLE = 'available';

    /** @var string Objective is done. */
    public const OBJECTIVE_COMPLETED = 'completed';

    /** @var int Most rule triggers processed for one event; the rest are dropped. */
    public const TRIGGER_CAP = 100;

    /** @var array[] Normalised objectives, in graph order. */
    private array $objectives = [];

    /** @var array Objective id (string) => position in $objectives. */
    private array $index = [];

    /** @var array[] Normalised rules, in graph order. */
    private array $rules = [];

    /** @var string[] Initially hidden interactable ids. */
    private array $initialhidden = [];

    /** @var string[] Run state: status per objective position. */
    private array $status = [];

    /** @var float[] Run state: earned points per objective position. */
    private array $earned = [];

    /** @var array Run state: objective position => [target id => true] for collect objectives. */
    private array $collected = [];

    /** @var int[] Run state: objective position => next step index for sequence objectives. */
    private array $pointer = [];

    /** @var array Run state: hidden id => true. */
    private array $hidden = [];

    /** @var bool Run state: whether a finish action has run. */
    private bool $finished = false;

    /** @var array[] Run state: effects produced so far. */
    private array $effects = [];

    /** @var bool Run state: whether all_required_completed has been enqueued. */
    private bool $allreqfired = false;

    /** @var bool[] Run state: rule position => fired. */
    private array $fired = [];

    /** @var array[] Run state: pending triggers (FIFO). */
    private array $queue = [];

    /** @var string[] Run state: status per processed event. */
    private array $eventstatus = [];

    /**
     * Constructor.
     *
     * @param array $graph decoded, validated mavr-graph/1 (assoc arrays). Pure: no DB, no globals.
     */
    public function __construct(array $graph) {
        $position = 0;
        foreach (self::as_list($graph['objectives'] ?? []) as $raw) {
            if (!is_array($raw) || !isset($raw['id'])) {
                continue;
            }
            $objective = self::normalise_objective($raw);
            if (array_key_exists($objective['id'], $this->index)) {
                continue;
            }
            $this->objectives[$position] = $objective;
            $this->index[$objective['id']] = $position;
            $position++;
        }
        foreach (self::as_list($graph['rules'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $this->rules[] = [
                'id' => self::str($raw['id'] ?? ''),
                'on' => is_array($raw['on'] ?? null) ? $raw['on'] : [],
                'once' => !array_key_exists('once', $raw) || $raw['once'] !== false,
                'actions' => array_values(array_filter(self::as_list($raw['actions'] ?? []), 'is_array')),
            ];
        }
        foreach (self::as_list($graph['hidden'] ?? []) as $id) {
            $this->initialhidden[] = self::str($id);
        }
    }

    /**
     * Evaluate the graph against an ordered event list.
     *
     * @param array[] $events §4.4.1 events in order.
     * @return evaluation_result
     */
    public function evaluate(array $events): evaluation_result {
        $this->initialise();
        $i = 0;
        foreach ($events as $event) {
            $this->process($i, self::normalise_event((array) $event));
            $i++;
        }
        return $this->build_result();
    }

    /**
     * Status the candidate event would get after $events (§4.4.1 Classify).
     *
     * @param array[] $events §4.4.1 events already accepted, in order.
     * @param array $candidate the §4.4.1 event to classify.
     * @return string one of the STATUS_* constants
     */
    public function classify(array $events, array $candidate): string {
        $this->initialise();
        $i = 0;
        foreach ($events as $event) {
            $this->process($i, self::normalise_event((array) $event));
            $i++;
        }
        $candidate = self::normalise_event($candidate);
        return $this->classify_matches($this->matching($candidate));
    }

    /**
     * Convert a mavr_events row into a §4.4.1 event (fraction from resultjson for 'answered').
     *
     * @param \stdClass $record mavr_events row (verb, objecttype, sceneid, objectid, resultjson)
     * @return array {verb, type, id, scene, fraction}
     */
    public static function event_from_record(\stdClass $record): array {
        $fraction = null;
        if (($record->verb ?? '') === 'answered' && isset($record->resultjson) && $record->resultjson !== '') {
            $result = json_decode((string) $record->resultjson, true);
            if (is_array($result) && isset($result['fraction']) && is_numeric($result['fraction'])) {
                $fraction = (float) $result['fraction'];
            }
        }
        $scene = $record->sceneid ?? null;
        return [
            'verb' => (string) ($record->verb ?? ''),
            'type' => (string) ($record->objecttype ?? ''),
            'id' => (string) ($record->objectid ?? ''),
            'scene' => ($scene === null || $scene === '') ? null : (string) $scene,
            'fraction' => $fraction,
        ];
    }

    /**
     * Round half-up to 7 decimal places (JS: Math.round(x * 1e7) / 1e7).
     *
     * @param float $value
     * @return float
     */
    public static function round7(float $value): float {
        return round($value, 7);
    }

    /**
     * Round half-up to 5 decimal places (JS: Math.round(x * 1e5) / 1e5).
     *
     * @param float $value
     * @return float
     */
    public static function round5(float $value): float {
        return round($value, 5);
    }

    /**
     * Normalise one event to {verb, type, id, scene, fraction} with string ids.
     *
     * @param array $event raw event
     * @return array
     */
    private static function normalise_event(array $event): array {
        $fraction = $event['fraction'] ?? null;
        $scene = $event['scene'] ?? null;
        return [
            'verb' => self::str($event['verb'] ?? ''),
            'type' => self::str($event['type'] ?? ''),
            'id' => self::str($event['id'] ?? ''),
            'scene' => ($scene === null) ? null : self::str($scene),
            'fraction' => (is_int($fraction) || is_float($fraction)) ? (float) $fraction : null,
        ];
    }

    /**
     * Normalise one objective, applying the §4.4 defaults.
     *
     * @param array $raw objective from the graph
     * @return array
     */
    private static function normalise_objective(array $raw): array {
        $unlock = is_array($raw['unlock'] ?? null) ? $raw['unlock'] : [];
        $scoreatleast = $unlock['score_at_least'] ?? null;
        $points = $raw['points'] ?? 0;
        return [
            'id' => self::str($raw['id']),
            'type' => self::str($raw['type'] ?? ''),
            'points' => (is_int($points) || is_float($points)) ? (float) $points : 0.0,
            'required' => ($raw['required'] ?? false) === true,
            'after' => array_map([self::class, 'str'], self::as_list($unlock['after'] ?? [])),
            'score_at_least' => (is_int($scoreatleast) || is_float($scoreatleast)) ? (float) $scoreatleast : null,
            'zone' => self::str($raw['zone'] ?? ''),
            'target' => self::str($raw['target'] ?? ''),
            'slot' => self::slot_string($raw['slot'] ?? null),
            'targets' => array_map([self::class, 'str'], self::as_list($raw['targets'] ?? [])),
            'count' => (is_int($raw['count'] ?? null) || is_float($raw['count'] ?? null)) ? (int) $raw['count'] : 0,
            'steps' => array_map([self::class, 'str'], self::as_list($raw['steps'] ?? [])),
        ];
    }

    /**
     * Reset the run state and run the §4.4.1 initialisation.
     */
    private function initialise(): void {
        $this->status = [];
        $this->earned = [];
        $this->collected = [];
        $this->pointer = [];
        foreach ($this->objectives as $pos => $objective) {
            $this->status[$pos] = self::OBJECTIVE_LOCKED;
            $this->earned[$pos] = 0.0;
            $this->collected[$pos] = [];
            $this->pointer[$pos] = 0;
        }
        $this->hidden = [];
        foreach ($this->initialhidden as $id) {
            $this->hidden[$id] = true;
        }
        $this->finished = false;
        $this->effects = [];
        $this->allreqfired = false;
        $this->fired = array_fill(0, count($this->rules), false);
        $this->queue = [];
        $this->eventstatus = [];

        $this->unlock_pass(false);
        $this->queue[] = ['on' => 'start'];
        $this->process_queue(-1);
    }

    /**
     * Process one event (§4.4.1 Process, steps 1-6).
     *
     * @param int $i event index
     * @param array $event normalised event
     */
    private function process(int $i, array $event): void {
        $matches = $this->matching($event);
        $status = $this->classify_matches($matches);
        $this->eventstatus[] = $status;
        if ($status === self::STATUS_LOCKED) {
            return;
        }
        $this->queue = [];
        $this->queue[] = ['on' => 'event', 'event' => $event];

        if ($status === self::STATUS_APPLIED) {
            foreach ($matches as $pos) {
                if ($this->status[$pos] !== self::OBJECTIVE_AVAILABLE) {
                    continue;
                }
                $objective = $this->objectives[$pos];
                switch ($objective['type']) {
                    case 'visit_zone':
                    case 'interact':
                        $this->complete($pos, $objective['points']);
                        break;
                    case 'answer_question':
                        $fraction = $event['fraction'] ?? 0.0;
                        $fraction = max(0.0, min(1.0, $fraction));
                        $this->complete($pos, self::round7($objective['points'] * $fraction));
                        break;
                    case 'collect':
                        $this->collected[$pos][$event['id']] = true;
                        if (count($this->collected[$pos]) >= $objective['count']) {
                            $this->complete($pos, $objective['points']);
                        }
                        break;
                }
            }
        }

        $this->unlock_pass(true);

        if (!$this->allreqfired && $this->all_required_completed()) {
            $this->allreqfired = true;
            $this->queue[] = ['on' => 'all_required_completed'];
        }

        $this->process_queue($i);
    }

    /**
     * Positions of the objectives the event matches, in graph order (§4.4.1 Matching).
     *
     * @param array $event normalised event
     * @return int[]
     */
    private function matching(array $event): array {
        $matches = [];
        foreach ($this->objectives as $pos => $objective) {
            $match = false;
            switch ($objective['type']) {
                case 'visit_zone':
                    $match = ($event['verb'] === 'entered' || $event['verb'] === 'interacted')
                        && $event['type'] === 'zone' && $event['id'] === $objective['zone'];
                    break;
                case 'interact':
                    $match = $event['verb'] === 'interacted' && $event['type'] === 'object'
                        && $event['id'] === $objective['target'];
                    break;
                case 'answer_question':
                    $match = $event['verb'] === 'answered' && $event['type'] === 'question'
                        && $event['id'] === $objective['slot'];
                    break;
                case 'collect':
                    $match = $event['verb'] === 'interacted' && $event['type'] === 'object'
                        && in_array($event['id'], $objective['targets'], true);
                    break;
            }
            if ($match) {
                $matches[] = $pos;
            }
        }
        return $matches;
    }

    /**
     * Classify a set of matching objectives against the current state (§4.4.1 Classify).
     *
     * @param int[] $matches objective positions
     * @return string STATUS_* constant
     */
    private function classify_matches(array $matches): string {
        if (!$matches) {
            return self::STATUS_IGNORED;
        }
        foreach ($matches as $pos) {
            if ($this->status[$pos] === self::OBJECTIVE_AVAILABLE || $this->status[$pos] === self::OBJECTIVE_COMPLETED) {
                return self::STATUS_APPLIED;
            }
        }
        return self::STATUS_LOCKED;
    }

    /**
     * Complete an objective, then advance sequences that wait for it (depth-first).
     *
     * @param int $pos objective position
     * @param float $earned points earned
     */
    private function complete(int $pos, float $earned): void {
        $this->status[$pos] = self::OBJECTIVE_COMPLETED;
        $this->earned[$pos] = $earned;
        $id = $this->objectives[$pos]['id'];
        $this->queue[] = ['on' => 'objective_completed', 'objective' => $id];
        foreach ($this->objectives as $spos => $sequence) {
            if ($sequence['type'] !== 'sequence' || $this->status[$spos] !== self::OBJECTIVE_AVAILABLE) {
                continue;
            }
            $steps = $sequence['steps'];
            if (($steps[$this->pointer[$spos]] ?? null) !== $id) {
                continue;
            }
            $this->pointer[$spos]++;
            if ($this->pointer[$spos] === count($steps)) {
                $this->complete($spos, $sequence['points']);
            }
        }
    }

    /**
     * Sum of earned points of completed objectives, rounded to 7 places.
     *
     * @return float
     */
    private function earned_points(): float {
        $sum = 0.0;
        foreach ($this->objectives as $pos => $objective) {
            if ($this->status[$pos] === self::OBJECTIVE_COMPLETED) {
                $sum += $this->earned[$pos];
            }
        }
        return self::round7($sum);
    }

    /**
     * Whether an objective's unlock conditions are met (§4.4.1 Conditions met).
     *
     * @param array $objective normalised objective
     * @param float $earnedpoints current earned points
     * @return bool
     */
    private function conditions_met(array $objective, float $earnedpoints): bool {
        foreach ($objective['after'] as $id) {
            if (!array_key_exists($id, $this->index) || $this->status[$this->index[$id]] !== self::OBJECTIVE_COMPLETED) {
                return false;
            }
        }
        return $objective['score_at_least'] === null || $earnedpoints >= $objective['score_at_least'];
    }

    /**
     * Unlock pass: make locked objectives with met conditions available until nothing changes.
     *
     * @param bool $triggers whether to enqueue objective_unlocked triggers
     */
    private function unlock_pass(bool $triggers): void {
        do {
            $changed = false;
            $earnedpoints = $this->earned_points();
            foreach ($this->objectives as $pos => $objective) {
                if ($this->status[$pos] !== self::OBJECTIVE_LOCKED || !$this->conditions_met($objective, $earnedpoints)) {
                    continue;
                }
                $this->status[$pos] = self::OBJECTIVE_AVAILABLE;
                $changed = true;
                if ($triggers) {
                    $this->queue[] = ['on' => 'objective_unlocked', 'objective' => $objective['id']];
                }
            }
        } while ($changed);
    }

    /**
     * Counts of required objectives: [done, total].
     *
     * @return int[]
     */
    private function required_counts(): array {
        $done = 0;
        $total = 0;
        foreach ($this->objectives as $pos => $objective) {
            if ($objective['required']) {
                $total++;
                if ($this->status[$pos] === self::OBJECTIVE_COMPLETED) {
                    $done++;
                }
            }
        }
        return [$done, $total];
    }

    /**
     * Whether there is at least one required objective and all are completed.
     *
     * @return bool
     */
    private function all_required_completed(): bool {
        [$done, $total] = $this->required_counts();
        return $total > 0 && $done === $total;
    }

    /**
     * Process the trigger queue FIFO, at most TRIGGER_CAP triggers; the rest are dropped.
     *
     * @param int $i index of the event being processed (-1 during initialisation)
     */
    private function process_queue(int $i): void {
        $processed = 0;
        while ($this->queue && $processed < self::TRIGGER_CAP) {
            $trigger = array_shift($this->queue);
            $processed++;
            foreach ($this->rules as $rpos => $rule) {
                if (!$this->trigger_matches($trigger, $rule['on'])) {
                    continue;
                }
                if ($rule['once'] && $this->fired[$rpos]) {
                    continue;
                }
                $this->fired[$rpos] = true;
                foreach ($rule['actions'] as $action) {
                    $this->run_action($i, $rule['id'], $action);
                }
            }
        }
        $this->queue = [];
    }

    /**
     * Whether a trigger matches a rule's "on" definition.
     *
     * @param array $trigger queued trigger
     * @param array $on rule trigger definition
     * @return bool
     */
    private function trigger_matches(array $trigger, array $on): bool {
        $kind = $on['on'] ?? null;
        if ($kind !== $trigger['on']) {
            return false;
        }
        switch ($kind) {
            case 'event':
                foreach (['verb', 'type', 'id'] as $field) {
                    if (array_key_exists($field, $on) && $on[$field] !== null
                            && self::str($on[$field]) !== $trigger['event'][$field]) {
                        return false;
                    }
                }
                return true;
            case 'objective_completed':
            case 'objective_unlocked':
                return self::str($on['objective'] ?? '') === $trigger['objective'];
            case 'start':
            case 'all_required_completed':
                return true;
        }
        return false;
    }

    /**
     * Run one rule action.
     *
     * @param int $i event index
     * @param string $ruleid rule id
     * @param array $action action definition
     */
    private function run_action(int $i, string $ruleid, array $action): void {
        $do = self::str($action['do'] ?? '');
        switch ($do) {
            case 'show':
                unset($this->hidden[self::str($action['target'] ?? '')]);
                break;
            case 'hide':
                $this->hidden[self::str($action['target'] ?? '')] = true;
                break;
            case 'unlock':
                $id = self::str($action['objective'] ?? '');
                if (array_key_exists($id, $this->index) && $this->status[$this->index[$id]] === self::OBJECTIVE_LOCKED) {
                    $this->status[$this->index[$id]] = self::OBJECTIVE_AVAILABLE;
                    $this->queue[] = ['on' => 'objective_unlocked', 'objective' => $id];
                }
                break;
            case 'finish':
                $this->finished = true;
                break;
            case 'teleport':
            case 'play_media':
                $this->effects[] = ['event' => $i, 'rule' => $ruleid, 'do' => $do,
                    'target' => self::str($action['target'] ?? '')];
                break;
            case 'open_question':
                $this->effects[] = ['event' => $i, 'rule' => $ruleid, 'do' => $do,
                    'target' => self::slot_string($action['slot'] ?? null)];
                break;
        }
    }

    /**
     * Build the result from the current run state (§4.4.1 Outputs).
     *
     * @return evaluation_result
     */
    private function build_result(): evaluation_result {
        $objectives = [];
        $maxpoints = 0.0;
        foreach ($this->objectives as $pos => $objective) {
            $maxpoints += $objective['points'];
            $objectives[$objective['id']] = [
                'status' => $this->status[$pos],
                'earned' => $this->status[$pos] === self::OBJECTIVE_COMPLETED ? (float) $this->earned[$pos] : 0.0,
                'points' => $objective['points'],
                'required' => $objective['required'],
            ];
        }
        $maxpoints = self::round7($maxpoints);
        $earnedpoints = $this->earned_points();
        [$done, $total] = $this->required_counts();
        if ($maxpoints > 0) {
            $score = self::round5($earnedpoints / $maxpoints);
        } else if ($total > 0) {
            $score = self::round5($done / $total);
        } else {
            $score = 0.0;
        }
        $hidden = array_map('strval', array_keys($this->hidden));
        sort($hidden, SORT_STRING);

        return new evaluation_result(
            eventstatus: $this->eventstatus,
            objectives: $objectives,
            earnedpoints: $earnedpoints,
            maxpoints: $maxpoints,
            score: (float) $score,
            requireddone: $done,
            requiredtotal: $total,
            finished: $this->finished,
            allrequiredcompleted: $total > 0 && $done === $total,
            hidden: $hidden,
            effects: $this->effects,
        );
    }

    /**
     * Values of an array if it is one, else an empty list.
     *
     * @param mixed $value
     * @return array
     */
    private static function as_list(mixed $value): array {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * String form of a question slot number (truncated to an integer); '' when not a number.
     *
     * @param mixed $slot
     * @return string
     */
    private static function slot_string(mixed $slot): string {
        return (is_int($slot) || is_float($slot)) ? (string) (int) $slot : '';
    }

    /**
     * String form of a scalar id (null and non-scalars become '').
     *
     * @param mixed $value
     * @return string
     */
    private static function str(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return (string) $value;
        }
        return '';
    }
}
