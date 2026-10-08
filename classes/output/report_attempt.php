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

namespace mod_vrroom\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\grading\grader;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\questions\mode;

/**
 * One attempt in the VR room report: its objectives and its statement timeline.
 *
 * The objective states come from a fresh replay of the stored statements (nothing is persisted).
 * Statements the browser reported are labelled as such, because a browser report can be faked.
 * The statement timeline over tracked actions is adapted from mod_gear's analytics report.
 *
 * Escaping: plain values (names, labels, numbers) are escaped by the template ({{ }}); values
 * that are already HTML-safe (format_string() output, the help icon) use {{{ }}}.
 *
 * @package    mod_vrroom
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_attempt implements renderable, templatable {
    /** @var array<string, string> scene id => name, zone/interactable id => name (raw). */
    protected array $names = [];

    /** @var array<string, string> objective id => title (raw). */
    protected array $objectivetitles = [];

    /** @var array<string, string> question slot => objective title (raw). */
    protected array $slottitles = [];

    /**
     * Constructor.
     *
     * @param \stdClass $vrroom vrroom row
     * @param \cm_info $cm course module
     * @param \context_module $context module context
     * @param \stdClass $attempt vrroom_attempts row
     */
    public function __construct(
        /** @var \stdClass vrroom row */
        protected \stdClass $vrroom,
        /** @var \cm_info course module */
        protected \cm_info $cm,
        /** @var \context_module module context */
        protected \context_module $context,
        /** @var \stdClass vrroom_attempts row */
        protected \stdClass $attempt,
    ) {
    }

    /**
     * Data for mod_vrroom/report_attempt.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $user = \core_user::get_user((int)$this->attempt->userid);
        $fullname = $user ? fullname($user) : '';
        $score = $this->attempt->score === null ? null : (float)$this->attempt->score;

        $this->load_names();
        $objectives = $this->export_objectives();
        $statements = $this->export_statements();

        $baseurl = new \moodle_url('/mod/vrroom/report.php', ['id' => $this->cm->id]);
        $candelete = has_capability('mod/vrroom:deleteattempts', $this->context);

        return [
            'cmid' => (int)$this->cm->id,
            'attemptid' => (int)$this->attempt->id,
            'heading' => get_string(
                'reportattemptdetails',
                'mod_vrroom',
                (object)['attempt' => (int)$this->attempt->attempt, 'user' => $fullname]
            ),
            'user' => $fullname,
            'state' => report_overview::state_label((string)$this->attempt->state),
            'score' => report_overview::format_score($score),
            'grade' => grader::format_grade($this->vrroom, $score),
            'timestart' => $this->attempt->timestart ? userdate((int)$this->attempt->timestart) : '-',
            'timefinish' => $this->attempt->timefinish ? userdate((int)$this->attempt->timefinish) : '-',
            'hasobjectives' => !empty($objectives),
            'objectives' => $objectives,
            'hasstatements' => !empty($statements),
            'statements' => $statements,
            'clienthelp' => $output->help_icon('reportclientasserted', 'mod_vrroom'),
            'backurl' => $baseurl->out(false),
            'candelete' => $candelete,
            'deleteurl' => $candelete ? (new \moodle_url($baseurl, ['delete' => $this->attempt->id]))->out(false) : '',
        ];
    }

    /**
     * Objective rows from a replay of the attempt under its own graph version.
     *
     * @return array[]
     */
    protected function export_objectives(): array {
        try {
            $graph = instance_manager::get_graph((int)$this->attempt->vrroomid, (int)$this->attempt->graphversion);
        } catch (\moodle_exception $e) {
            return [];
        }
        $options = mode::evaluator_options_for((int)$this->attempt->vrroomid);
        $result = (new evaluator($graph, $options))->evaluate(event_store::get_events((int)$this->attempt->id));

        $rows = [];
        foreach ($result->objectives as $id => $objective) {
            $id = (string)$id;
            $status = (string)$objective['status'];
            $rows[] = [
                'id' => $id,
                'title' => $this->format_name($this->objectivetitles[$id] ?? $id),
                'status' => $status,
                'statuslabel' => get_string('status_' . $status, 'mod_vrroom'),
                'badgeclass' => match ($status) {
                    evaluator::OBJECTIVE_COMPLETED => 'bg-success',
                    evaluator::OBJECTIVE_AVAILABLE => 'bg-info text-dark',
                    default => 'bg-secondary',
                },
                'required' => !empty($objective['required']),
                'earned' => format_float((float)$objective['earned'], 2),
                'points' => format_float((float)$objective['points'], 2),
            ];
        }
        return $rows;
    }

    /**
     * Statement rows, in the order they were accepted.
     *
     * @return array[]
     */
    protected function export_statements(): array {
        $rows = [];
        foreach (event_store::get_rows((int)$this->attempt->id) as $row) {
            $verb = (string)$row->verb;
            $type = (string)$row->objecttype;
            $client = (int)$row->clientasserted === 1;
            $rows[] = [
                'time' => userdate((int)$row->timestamp),
                'verb' => self::label('verb_' . $verb, $verb),
                'objecttype' => self::label('objecttype_' . $type, $type),
                'objectname' => $this->object_name($type, $row->sceneid, $row->objectid),
                'result' => $this->result_text($verb, $type, $row->resultjson),
                'clientasserted' => $client,
                'source' => get_string($client ? 'reportclientasserted' : 'reportserverasserted', 'mod_vrroom'),
            ];
        }
        return $rows;
    }

    /**
     * Collect display names of scenes, zones, interactables and objectives.
     *
     * @return void
     */
    protected function load_names(): void {
        foreach (instance_manager::get_scenes((int)$this->attempt->vrroomid) as $scene) {
            if (isset($scene['id'])) {
                $this->names[(string)$scene['id']] = (string)($scene['name'] ?? '');
            }
            foreach (['zones', 'interactables'] as $list) {
                foreach (($scene[$list] ?? []) as $item) {
                    if (isset($item['id']) && isset($item['name'])) {
                        $this->names[(string)$item['id']] = (string)$item['name'];
                    }
                }
            }
        }
        try {
            $graph = instance_manager::get_graph((int)$this->attempt->vrroomid, (int)$this->attempt->graphversion);
        } catch (\moodle_exception $e) {
            return;
        }
        foreach (($graph['objectives'] ?? []) as $objective) {
            if (!isset($objective['id'])) {
                continue;
            }
            $title = (string)($objective['title'] ?? $objective['id']);
            $this->objectivetitles[(string)$objective['id']] = $title;
            if (($objective['type'] ?? '') === 'answer_question' && isset($objective['slot'])) {
                $this->slottitles[(string)$objective['slot']] = $title;
            }
        }
    }

    /**
     * HTML-safe display name of a statement's object.
     *
     * @param string $type object type
     * @param string|null $sceneid scene id
     * @param string|null $objectid object id
     * @return string HTML-safe text
     */
    protected function object_name(string $type, ?string $sceneid, ?string $objectid): string {
        $raw = null;
        switch ($type) {
            case 'activity':
                $raw = (string)$this->vrroom->name;
                break;
            case 'scene':
                $raw = $this->names[(string)($objectid ?? $sceneid)] ?? null;
                break;
            case 'zone':
            case 'object':
                $raw = $this->names[(string)$objectid] ?? null;
                break;
            case 'question':
                $raw = $this->slottitles[(string)$objectid] ?? null;
                break;
            case 'objective':
                $raw = $this->objectivetitles[(string)$objectid] ?? null;
                break;
        }
        if ($raw === null || $raw === '') {
            return s((string)$objectid);
        }
        return $this->format_name($raw);
    }

    /**
     * Plain-text summary of a server result.
     *
     * @param string $verb short verb
     * @param string $type object type
     * @param string|null $json stored result JSON
     * @return string
     */
    protected function result_text(string $verb, string $type, ?string $json): string {
        if ($json === null || $json === '') {
            return '';
        }
        $result = json_decode($json, true);
        if (!is_array($result)) {
            return '';
        }
        if ($verb === 'answered') {
            if (isset($result['mark']) && isset($result['maxmark'])) {
                return format_float((float)$result['mark'], 2) . ' / ' . format_float((float)$result['maxmark'], 2);
            }
            if (isset($result['fraction'])) {
                return format_float((float)$result['fraction'] * 100, 2) . ' %';
            }
            return '';
        }
        if ($type === 'objective' && isset($result['earned'])) {
            return format_float((float)$result['earned'], 2);
        }
        if ($type === 'activity' && isset($result['grade'])) {
            return format_float((float)$result['grade'], 2);
        }
        return '';
    }

    /**
     * A teacher-entered name, filtered and made HTML-safe.
     *
     * @param string $raw the stored name
     * @return string
     */
    protected function format_name(string $raw): string {
        return format_string($raw, true, ['context' => $this->context]);
    }

    /**
     * A mod_vrroom string when it exists, else the fallback.
     *
     * @param string $identifier string key
     * @param string $fallback raw value
     * @return string
     */
    protected static function label(string $identifier, string $fallback): string {
        if (get_string_manager()->string_exists($identifier, 'mod_vrroom')) {
            return get_string($identifier, 'mod_vrroom');
        }
        return $fallback;
    }
}
