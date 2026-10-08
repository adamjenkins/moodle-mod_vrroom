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
use mod_vrroom\local\xapi\iri;

/**
 * Builds the vrroom-launch/1 configuration a client run mode starts from.
 *
 * Every piece of teacher content leaves the server formatted: hotspot text as format_text()
 * HTML, names and titles through format_string() (tags stripped, entities decoded, because the
 * client escapes them in its templates), and file references with a pluginfile URL. Hint texts
 * never travel inside the graph: only the hints already requested in the attempt are sent, in
 * the separate `hints` key.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_builder {
    /** @var string Launch config schema. */
    public const SCHEMA = 'vrroom-launch/1';

    /** @var string xAPI state id of the resume position. */
    public const RESUME_STATEID = 'vrroom-resume';

    /** @var string[] File areas a scene may reference. */
    private const FILE_AREAS = ['models', 'media', 'environment', 'assets'];

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

        $scenes = [];
        foreach (instance_manager::get_scenes((int)$vrroom->id) as $scene) {
            $scenes[] = self::prepare_scene($scene, $context);
        }

        if ($preview) {
            $events = [];
            $result = (new evaluator($graph))->evaluate([]);
            $state = attempt_manager::build_state(0, 'preview', $graphversion, $result, 0, 0);
            $slotmap = [];
        } else {
            $events = event_store::get_events((int)$attempt->id);
            $result = (new evaluator($graph))->evaluate($events);
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
                'stateid' => self::RESUME_STATEID,
            ],
            'finishurl' => (new \moodle_url('/mod/vrroom/view.php', ['id' => $cm->id]))->out(false),
            'look' => [
                'invertx' => (bool)get_user_preferences('mod_vrroom_invertx', 0, $user),
                'inverty' => (bool)get_user_preferences('mod_vrroom_inverty', 0, $user),
                'locky' => (bool)get_user_preferences('mod_vrroom_locky', 0, $user),
            ],
        ];
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

    /**
     * Format a stored scene for the client.
     *
     * @param array $scene decoded vrroom-scene/1 document
     * @param \context_module $context module context
     * @return array
     */
    private static function prepare_scene(array $scene, \context_module $context): array {
        if (isset($scene['name'])) {
            $scene['name'] = self::plain((string)$scene['name'], $context);
        }
        if (isset($scene['environment']['file']) && is_array($scene['environment']['file'])) {
            $scene['environment']['file'] = self::file_ref($scene['environment']['file'], $context);
        }
        foreach (['spawn', 'zones', 'interactables'] as $list) {
            if (empty($scene[$list]) || !is_array($scene[$list])) {
                continue;
            }
            foreach ($scene[$list] as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['name'])) {
                    $item['name'] = self::plain((string)$item['name'], $context);
                }
                if (isset($item['file']) && is_array($item['file'])) {
                    $item['file'] = self::file_ref($item['file'], $context);
                }
                if (($item['kind'] ?? '') === 'text' && isset($item['text'])) {
                    $item['text'] = self::plain((string)$item['text'], $context);
                }
                if (isset($item['content']) && is_array($item['content'])) {
                    $item['content'] = self::prepare_content($item['content'], $context);
                }
                $scene[$list][$i] = $item;
            }
        }
        return $scene;
    }

    /**
     * Format hotspot content: html from text/textformat, plain title, media file URLs.
     *
     * @param array $content hotspot content
     * @param \context_module $context module context
     * @return array
     */
    private static function prepare_content(array $content, \context_module $context): array {
        $format = isset($content['textformat']) ? (string)$content['textformat'] : (string)FORMAT_HTML;
        $content['html'] = format_text((string)($content['text'] ?? ''), $format, ['context' => $context]);
        unset($content['text']);
        if (isset($content['title'])) {
            $content['title'] = self::plain((string)$content['title'], $context);
        }
        if (!empty($content['media']) && is_array($content['media'])) {
            foreach ($content['media'] as $i => $media) {
                if (is_array($media) && isset($media['area'])) {
                    $content['media'][$i] = self::file_ref($media, $context);
                } else if (is_array($media) && isset($media['file']) && is_array($media['file'])) {
                    $media['file'] = self::file_ref($media['file'], $context);
                    $content['media'][$i] = $media;
                }
            }
        }
        return $content;
    }

    /**
     * Add a pluginfile URL to a file reference (only for VR room's own areas).
     *
     * @param array $ref ['area', 'itemid', 'filepath', 'filename']
     * @param \context_module $context module context
     * @return array
     */
    private static function file_ref(array $ref, \context_module $context): array {
        $area = (string)($ref['area'] ?? '');
        if (!in_array($area, self::FILE_AREAS, true) || !isset($ref['filename'])) {
            unset($ref['url']);
            return $ref;
        }
        $filepath = (string)($ref['filepath'] ?? '/');
        $ref['url'] = \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_vrroom',
            $area,
            (int)($ref['itemid'] ?? 0),
            $filepath === '' ? '/' : $filepath,
            (string)$ref['filename']
        )->out(false);
        return $ref;
    }

    /**
     * A plain-text label: filtered, tags stripped, entities decoded (client templates escape it).
     *
     * @param string $text
     * @param \context_module $context
     * @return string
     */
    private static function plain(string $text, \context_module $context): string {
        return html_entity_decode(
            strip_tags(format_string($text, true, ['context' => $context, 'escape' => false])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
}
