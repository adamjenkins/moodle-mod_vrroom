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
 * Structural validator for mavr-graph/1 task graphs.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_mavr\local\graph;

/**
 * Structural validator for mavr-graph/1 task graphs (contract §4.4).
 *
 * The client validator validate() in mavrruntime_builtin/graph (runtime/builtin/amd/src/graph.js)
 * applies the same checks in the same order and returns the same messages.
 *
 * Messages are developer-facing English (they describe a template's compile output, not user
 * input), so they are not lang strings; callers wrap the first one in the 'invalidgraph' string.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class graph_validator {
    /** @var string The only supported schema identifier. */
    public const SCHEMA = 'mavr-graph/1';

    /** @var string Pattern for every id (objective, rule, zone, interactable, spawn, scene). */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** @var string[] Objective types. */
    public const OBJECTIVE_TYPES = ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'];

    /** @var string[] Rule trigger kinds. */
    public const TRIGGERS = ['start', 'event', 'objective_completed', 'objective_unlocked', 'all_required_completed'];

    /** @var string[] Rule action kinds. */
    public const ACTIONS = ['show', 'hide', 'unlock', 'finish', 'teleport', 'play_media', 'open_question'];

    /** @var string[] Short verbs of the mavr-vocab/1 statement vocabulary (contract §4.3). */
    public const VERBS = ['launched', 'initialized', 'terminated', 'entered', 'exited', 'interacted', 'answered',
        'completed', 'passed', 'failed'];

    /** @var string[] Object types of the mavr-vocab/1 statement vocabulary (contract §4.3). */
    public const OBJECT_TYPES = ['activity', 'scene', 'zone', 'object', 'question', 'objective'];

    /**
     * Validate a decoded graph.
     *
     * @param array $graph decoded mavr-graph/1 document (assoc arrays)
     * @return string[] developer-facing English errors; [] = valid.
     */
    public static function validate(array $graph): array {
        $errors = [];

        if (($graph['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema must be "' . self::SCHEMA . '"';
        }

        if (array_key_exists('hidden', $graph)) {
            if (!self::is_list($graph['hidden'])) {
                $errors[] = 'hidden must be an array';
            } else {
                foreach ($graph['hidden'] as $k => $id) {
                    if (!self::is_id($id)) {
                        $errors[] = "hidden[{$k}] is not a valid id";
                    }
                }
            }
        }

        $objectives = [];
        if (!array_key_exists('objectives', $graph) || !self::is_list($graph['objectives'])) {
            $errors[] = 'objectives must be an array';
        } else {
            $objectives = $graph['objectives'];
        }

        $rules = [];
        if (array_key_exists('rules', $graph)) {
            if (!self::is_list($graph['rules'])) {
                $errors[] = 'rules must be an array';
            } else {
                $rules = $graph['rules'];
            }
        }

        // Pass 1: objective ids, types and fields.
        $types = [];
        $slots = [];
        foreach ($objectives as $k => $objective) {
            $where = "objectives[{$k}]";
            if (!self::is_object($objective)) {
                $errors[] = "{$where} must be an object";
                continue;
            }
            $id = $objective['id'] ?? null;
            if (!self::is_id($id)) {
                $errors[] = "{$where}.id is not a valid id";
            } else if (array_key_exists($id, $types)) {
                $errors[] = "duplicate objective id \"{$id}\"";
            } else {
                $types[$id] = is_string($objective['type'] ?? null) ? $objective['type'] : '';
            }
            $type = $objective['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::OBJECTIVE_TYPES, true)) {
                $errors[] = "{$where}.type is unknown";
            }
            if (!is_string($objective['title'] ?? null)) {
                $errors[] = "{$where}.title must be a string";
            }
            if (array_key_exists('points', $objective)
                    && (!self::is_number($objective['points']) || $objective['points'] < 0)) {
                $errors[] = "{$where}.points must be a number >= 0";
            }
            if (array_key_exists('required', $objective) && !is_bool($objective['required'])) {
                $errors[] = "{$where}.required must be true or false";
            }
            if (array_key_exists('unlock', $objective)) {
                $unlock = $objective['unlock'];
                if (!self::is_object($unlock)) {
                    $errors[] = "{$where}.unlock must be an object";
                } else {
                    if (array_key_exists('after', $unlock) && !self::is_id_list($unlock['after'], true)) {
                        $errors[] = "{$where}.unlock.after must be an array of ids";
                    }
                    if (array_key_exists('score_at_least', $unlock) && $unlock['score_at_least'] !== null
                            && !self::is_number($unlock['score_at_least'])) {
                        $errors[] = "{$where}.unlock.score_at_least must be a number or null";
                    }
                }
            }
            switch ($type) {
                case 'visit_zone':
                    if (!self::is_id($objective['zone'] ?? null)) {
                        $errors[] = "{$where}.zone is not a valid id";
                    }
                    if (array_key_exists('scene', $objective) && $objective['scene'] !== null
                            && !self::is_id($objective['scene'])) {
                        $errors[] = "{$where}.scene is not a valid id";
                    }
                    break;
                case 'interact':
                    if (!self::is_id($objective['target'] ?? null)) {
                        $errors[] = "{$where}.target is not a valid id";
                    }
                    break;
                case 'answer_question':
                    $slot = $objective['slot'] ?? null;
                    if (!self::is_integer($slot) || $slot < 1) {
                        $errors[] = "{$where}.slot must be an integer >= 1";
                    } else if (array_key_exists((int) $slot, $slots)) {
                        $errors[] = 'duplicate question slot ' . (int) $slot;
                    } else {
                        $slots[(int) $slot] = true;
                    }
                    break;
                case 'collect':
                    $targets = $objective['targets'] ?? null;
                    if (!self::is_id_list($targets, false)) {
                        $errors[] = "{$where}.targets must be a non-empty array of distinct ids";
                        break;
                    }
                    $count = $objective['count'] ?? null;
                    if (!self::is_integer($count) || $count < 1 || $count > count($targets)) {
                        $errors[] = "{$where}.count must be an integer from 1 to the number of targets";
                    }
                    break;
                case 'sequence':
                    if (!self::is_id_list($objective['steps'] ?? null, false)) {
                        $errors[] = "{$where}.steps must be a non-empty array of distinct ids";
                    }
                    break;
            }
        }

        // Pass 2: objective references.
        $after = [];
        foreach ($objectives as $k => $objective) {
            if (!self::is_object($objective) || !self::is_id($objective['id'] ?? null)) {
                continue;
            }
            $where = "objectives[{$k}]";
            $id = $objective['id'];
            $edges = [];
            $unlock = $objective['unlock'] ?? null;
            if (self::is_object($unlock) && self::is_id_list($unlock['after'] ?? [], true)) {
                foreach ($unlock['after'] ?? [] as $ref) {
                    if (!array_key_exists($ref, $types)) {
                        $errors[] = "{$where}.unlock.after refers to unknown objective \"{$ref}\"";
                    } else {
                        $edges[] = $ref;
                    }
                }
            }
            if (!array_key_exists($id, $after)) {
                $after[$id] = $edges;
            }
            if (($objective['type'] ?? null) === 'sequence' && self::is_id_list($objective['steps'] ?? null, false)) {
                foreach ($objective['steps'] as $ref) {
                    if ($ref === $id) {
                        $errors[] = "{$where}.steps cannot contain the sequence itself";
                    } else if (!array_key_exists($ref, $types)) {
                        $errors[] = "{$where}.steps refers to unknown objective \"{$ref}\"";
                    } else if ($types[$ref] === 'sequence') {
                        $errors[] = "{$where}.steps cannot contain another sequence \"{$ref}\"";
                    }
                }
            }
        }

        // Pass 3: no cycle in the unlock.after graph (depth-first search in graph order).
        $state = [];
        foreach (array_keys($after) as $start) {
            self::find_cycles((string) $start, $after, $state, $errors);
        }

        // Rules.
        $ruleids = [];
        foreach ($rules as $k => $rule) {
            $where = "rules[{$k}]";
            if (!self::is_object($rule)) {
                $errors[] = "{$where} must be an object";
                continue;
            }
            $id = $rule['id'] ?? null;
            if (!self::is_id($id)) {
                $errors[] = "{$where}.id is not a valid id";
            } else if (array_key_exists($id, $ruleids)) {
                $errors[] = "duplicate rule id \"{$id}\"";
            } else {
                $ruleids[$id] = true;
            }
            if (array_key_exists('once', $rule) && !is_bool($rule['once'])) {
                $errors[] = "{$where}.once must be true or false";
            }
            self::validate_trigger($rule['on'] ?? null, "{$where}.on", $types, $errors);
            $actions = $rule['actions'] ?? null;
            if (!self::is_list($actions) || !$actions) {
                $errors[] = "{$where}.actions must be a non-empty array";
                continue;
            }
            foreach ($actions as $a => $action) {
                self::validate_action($action, "{$where}.actions[{$a}]", $types, $slots, $errors);
            }
        }

        return $errors;
    }

    /**
     * Validate a rule trigger.
     *
     * @param mixed $on trigger definition
     * @param string $where path for messages
     * @param array $types objective id => type
     * @param string[] $errors errors, appended to
     */
    private static function validate_trigger(mixed $on, string $where, array $types, array &$errors): void {
        if (!self::is_object($on)) {
            $errors[] = "{$where} must be an object";
            return;
        }
        $kind = $on['on'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::TRIGGERS, true)) {
            $errors[] = "{$where}.on is an unknown trigger";
            return;
        }
        switch ($kind) {
            case 'event':
                if (array_key_exists('verb', $on) && $on['verb'] !== null
                        && (!is_string($on['verb']) || !in_array($on['verb'], self::VERBS, true))) {
                    $errors[] = "{$where}.verb is unknown";
                }
                if (array_key_exists('type', $on) && $on['type'] !== null
                        && (!is_string($on['type']) || !in_array($on['type'], self::OBJECT_TYPES, true))) {
                    $errors[] = "{$where}.type is unknown";
                }
                if (array_key_exists('id', $on) && $on['id'] !== null && !self::is_id($on['id'])) {
                    $errors[] = "{$where}.id is not a valid id";
                }
                break;
            case 'objective_completed':
            case 'objective_unlocked':
                $ref = $on['objective'] ?? null;
                if (!self::is_id($ref)) {
                    $errors[] = "{$where}.objective is not a valid id";
                } else if (!array_key_exists($ref, $types)) {
                    $errors[] = "{$where}.objective refers to unknown objective \"{$ref}\"";
                }
                break;
        }
    }

    /**
     * Validate a rule action.
     *
     * @param mixed $action action definition
     * @param string $where path for messages
     * @param array $types objective id => type
     * @param array $slots question slot => true
     * @param string[] $errors errors, appended to
     */
    private static function validate_action(mixed $action, string $where, array $types, array $slots,
            array &$errors): void {
        if (!self::is_object($action)) {
            $errors[] = "{$where} must be an object";
            return;
        }
        $do = $action['do'] ?? null;
        if (!is_string($do) || !in_array($do, self::ACTIONS, true)) {
            $errors[] = "{$where}.do is an unknown action";
            return;
        }
        switch ($do) {
            case 'show':
            case 'hide':
            case 'teleport':
            case 'play_media':
                if (!self::is_id($action['target'] ?? null)) {
                    $errors[] = "{$where}.target is not a valid id";
                }
                break;
            case 'unlock':
                $ref = $action['objective'] ?? null;
                if (!self::is_id($ref)) {
                    $errors[] = "{$where}.objective is not a valid id";
                } else if (!array_key_exists($ref, $types)) {
                    $errors[] = "{$where}.objective refers to unknown objective \"{$ref}\"";
                }
                break;
            case 'open_question':
                $slot = $action['slot'] ?? null;
                if (!self::is_integer($slot) || $slot < 1) {
                    $errors[] = "{$where}.slot must be an integer >= 1";
                } else if (!array_key_exists((int) $slot, $slots)) {
                    $errors[] = "{$where}.slot " . (int) $slot . ' has no answer_question objective';
                }
                break;
        }
    }

    /**
     * Depth-first search for cycles in the unlock.after graph; one error per back edge.
     *
     * @param string $node objective id
     * @param array $after objective id => string[] ids it waits for
     * @param array $state objective id => 1 (on the stack) or 2 (done)
     * @param string[] $errors errors, appended to
     */
    private static function find_cycles(string $node, array $after, array &$state, array &$errors): void {
        if (isset($state[$node])) {
            return;
        }
        $state[$node] = 1;
        foreach ($after[$node] ?? [] as $next) {
            $next = (string) $next;
            $seen = $state[$next] ?? 0;
            if ($seen === 1) {
                $errors[] = "unlock.after cycle: \"{$node}\" waits for \"{$next}\"";
            } else if ($seen === 0) {
                self::find_cycles($next, $after, $state, $errors);
            }
        }
        $state[$node] = 2;
    }

    /**
     * Whether a value is a valid id string.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_id(mixed $value): bool {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * Whether a value is a list of distinct valid ids.
     *
     * @param mixed $value
     * @param bool $allowempty whether an empty list is accepted
     * @return bool
     */
    private static function is_id_list(mixed $value, bool $allowempty): bool {
        if (!self::is_list($value) || (!$allowempty && !$value)) {
            return false;
        }
        $seen = [];
        foreach ($value as $id) {
            if (!self::is_id($id) || array_key_exists($id, $seen)) {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    /**
     * Whether a decoded JSON value is an array (list). An empty JSON object decodes to [] too.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_list(mixed $value): bool {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Whether a decoded JSON value is an object (a non-list array, or an empty one).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_object(mixed $value): bool {
        return is_array($value) && (!array_is_list($value) || $value === []);
    }

    /**
     * Whether a value is a finite JSON number (not a boolean).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_number(mixed $value): bool {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /**
     * Whether a value is an integral JSON number (2 and 2.0 both count, as in JavaScript).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_integer(mixed $value): bool {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }
}
