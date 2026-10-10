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

namespace mod_vrroom\local\launch;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\attempt\hint_manager;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\xapi\iri;

/**
 * Builds the vrroom-launch/1 configuration a client run mode starts from.
 *
 * Every piece of teacher content leaves the server formatted: hotspot text as format_text()
 * HTML, names and titles through format_string() (tags stripped, entities decoded, because the
 * client escapes them in its templates), and file references with a pluginfile URL. Hint texts
 * never travel inside the graph: only the hints already requested in the attempt are sent, in
 * the separate `hints` key. Scenes are formatted by scene_formatter, which other plugins use too.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_builder {
    /** @var string Launch config schema. */
    public const SCHEMA = 'vrroom-launch/1';

    /** @var string xAPI state id of the resume position before it was kept per attempt; resume_stateid() gives the current one. */
    public const RESUME_STATEID = 'vrroom-resume';

    /**
     * Build the launch config (§4.5).
     *
     * @param \cm_info $cm course module
     * @param \context_module $context module context
     * @param \stdClass $vrroom vrroom row
     * @param \stdClass|null $attempt attempt row, or null for a teacher preview
     * @param \stdClass $user the user launching
     * @return array
     */
    public static function build(
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        ?\stdClass $attempt,
        \stdClass $user
    ): array {
        global $CFG;

        $preview = $attempt === null;
        $graphversion = $preview ? (int)$vrroom->graphversion : (int)$attempt->graphversion;
        $graph = instance_manager::get_graph((int)$vrroom->id, $graphversion);
        $evaluatoroptions = mode::evaluator_options($vrroom);

        $scenes = [];
        foreach (instance_manager::get_scenes((int)$vrroom->id) as $scene) {
            $scenes[] = scene_formatter::prepare_scene($scene, $context);
        }

        if ($preview) {
            $events = [];
            $result = (new evaluator($graph, $evaluatoroptions))->evaluate([]);
            $state = attempt_manager::build_state(0, 'preview', $graphversion, $result, 0, 0);
            $slotmap = [];
        } else {
            $events = event_store::get_events((int)$attempt->id);
            $result = (new evaluator($graph, $evaluatoroptions))->evaluate($events);
            $state = attempt_manager::build_state(
                (int)$attempt->id,
                (string)$attempt->state,
                $graphversion,
                $result,
                count($events),
                (int)($attempt->timedue ?? 0)
            );
            $slotmap = json_decode((string)$attempt->slotmap, true) ?: [];
        }

        $questions = [];
        foreach ($graph['objectives'] ?? [] as $objective) {
            if (($objective['type'] ?? '') !== 'answer_question') {
                continue;
            }
            $slot = (int)$objective['slot'];
            $questions[] = [
                'slot' => $slot,
                'objectiveid' => (string)$objective['id'],
                'available' => !$preview && array_key_exists((string)$slot, $slotmap),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'preview' => $preview,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'courseid' => (int)$cm->course,
            'userid' => (int)$user->id,
            'attemptid' => $preview ? 0 : (int)$attempt->id,
            'attemptnumber' => $preview ? 0 : (int)$attempt->attempt,
            'activityiri' => iri::activity((int)$cm->id),
            'actor' => [
                'objectType' => 'Agent',
                'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$user->id],
            ],
            'extbase' => iri::EXT_BASE,
            'verbs' => iri::VERBS,
            'maxbatch' => self::get_maxbatch(),
            'startscene' => $scenes ? (string)$scenes[0]['id'] : '',
            'scenes' => $scenes,
            'graph' => hint_manager::strip_texts($graph),
            'hints' => (object)hint_manager::launch_hints($graph, $events, $context),
            'state' => $state,
            'events' => $events,
            'questions' => $questions,
            'resume' => [
                'activityid' => \core_xapi\iri::generate((string)$context->id, 'activity'),
                // Per attempt: a new attempt starts at the start point, not where the last one ended.
                // A preview saves no position.
                'stateid' => $preview ? '' : self::resume_stateid((int)$attempt->id),
            ],
            'finishurl' => (new \moodle_url('/mod/vrroom/view.php', ['id' => $cm->id]))->out(false),
            'questionmode' => [
                'mode' => $evaluatoroptions['retry'] ? mode::RETRY : mode::ONCE,
                'retrypenalty' => $evaluatoroptions['retrypenalty'],
            ],
            'look' => scene_formatter::look($user),
        ];
    }

    /**
     * The xAPI state id of an attempt's resume position (where the student last stood in the 3D room).
     *
     * One state per attempt, so a new attempt never starts where an earlier one ended; the state is
     * removed when its attempt is finished or deleted. Core's state web services take the state id
     * as PARAM_ALPHAEXT (letters, '-' and '_' only), so the attempt id is written with the letters
     * a to j for the digits 0 to 9: attempt 244 is 'vrroom-resume-cee'.
     *
     * @param int $attemptid attempt id
     * @return string
     */
    public static function resume_stateid(int $attemptid): string {
        return self::RESUME_STATEID . '-' . strtr((string)$attemptid, '0123456789', 'abcdefghij');
    }

    /**
     * The attempt id of a resume state id made by resume_stateid().
     *
     * @param string $stateid xAPI state id
     * @return int|null the attempt id, or null when the state id is not one of an attempt's resume position
     */
    public static function attemptid_from_resume_stateid(string $stateid): ?int {
        if (!preg_match('/^' . preg_quote(self::RESUME_STATEID, '/') . '-([b-j][a-j]{0,9})$/', $stateid, $matches)) {
            return null;
        }
        return (int)strtr($matches[1], 'abcdefghij', '0123456789');
    }

    /**
     * The batch limit for clients.
     *
     * @return int
     */
    public static function get_maxbatch(): int {
        $max = (int)get_config('mod_vrroom', 'maxbatch');
        return $max > 0 ? $max : 50;
    }
}
