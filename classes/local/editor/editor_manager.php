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

namespace mod_vrroom\local\editor;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\panorama;
use mod_vrroom\local\questions\bank;
use mod_vrroom\local\questions\slots;
use mod_vrroom\local\scene\environment_registry;
use mod_vrroom\local\scene\scene_validator;
use mod_vrroom\local\template\compiled_activity;

/**
 * The room editor's document: loading it, and validating and saving it as one unit.
 *
 * The editor saves rooms, tasks and question bindings together. The save is a trust boundary
 * (mod/vrroom:manage carries RISK_XSS): everything the browser sends is validated again here,
 * whatever the editor's own checks said.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editor_manager {
    /** @var string Schema of the editor document. */
    public const DOC_SCHEMA = 'vrroom-editdoc/1';

    /** @var int Largest editor document the save accepts, in bytes. */
    public const MAX_DOCUMENT_BYTES = 2097152;

    /** @var string File area of the files uploaded in the editor. */
    public const ASSET_AREA = 'assets';

    /** @var int Most question bindings in one activity. */
    public const MAX_BINDINGS = 200;

    /** @var float Largest mark of one question binding. */
    public const MAX_MARK = 1000.0;

    /** @var string[] File areas whose files the editor lists and may reference. */
    public const AREAS = ['models', 'media', 'environment', 'assets'];

    /** @var string[] Text formats a hotspot text may have (FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN). */
    public const TEXT_FORMATS = ['0', '1', '2', '4'];

    /**
     * @var array Asset kind => file extensions. asset_kind() takes the first kind that matches, so a
     * 360° photo (panorama, listed last) is an image by extension; uploads and list_assets() mark it.
     */
    public const KIND_EXTENSIONS = [
        'model' => ['glb'],
        'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
        'audio' => ['mp3', 'ogg', 'oga', 'm4a'],
        'video' => ['mp4', 'webm'],
        'panorama' => ['jpg', 'jpeg', 'png', 'webp'],
    ];

    /** @var int Seconds a save waits for another save of the same activity to finish. */
    private const LOCK_TIMEOUT = 10;

    /** @var int Flags of the JSON sent to the editor. */
    private const CLIENT_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Everything the editor starts from.
     *
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @param \stdClass $vrroom The vrroom row.
     * @return array ['documentjson', 'assetsjson', 'presetsjson', 'questionsjson', 'infojson'] as JSON strings.
     */
    public static function load(\cm_info $cm, \context_module $context, \stdClass $vrroom): array {
        global $DB;

        // Scenes and the graph are decoded as objects, so that empty JSON objects stay objects.
        $scenes = [];
        foreach ($DB->get_records('vrroom_scenes', ['vrroomid' => $vrroom->id], 'sortorder ASC, id ASC') as $row) {
            $scenes[] = json_decode((string)$row->scenejson, false, 512, JSON_THROW_ON_ERROR);
        }
        $graph = null;
        if ((string)$vrroom->graphjson !== '') {
            $graph = json_decode((string)$vrroom->graphjson, false, 512, JSON_THROW_ON_ERROR);
        }
        if (!$graph instanceof \stdClass) {
            $graph = (object)['schema' => graph_validator::SCHEMA, 'hidden' => [], 'objectives' => [], 'rules' => []];
        }

        $bindings = [];
        $boundids = [];
        foreach (slots::get_slots((int)$vrroom->id, $context) as $slot) {
            $bindings[] = [
                'slot' => (int)$slot->slot,
                'questionbankentryid' => (int)$slot->questionbankentryid,
                'maxmark' => round((float)$slot->maxmark, 7),
            ];
            if ((int)$slot->questionbankentryid > 0) {
                $boundids[(int)$slot->questionbankentryid] = true;
            }
        }

        $document = [
            'schema' => self::DOC_SCHEMA,
            'revision' => self::revision($vrroom, $context),
            'template' => (string)$vrroom->template,
            'customised' => (int)($vrroom->customised ?? 0),
            'hasattempts' => $DB->record_exists('vrroom_attempts', ['vrroomid' => $vrroom->id]),
            'scenes' => $scenes,
            'graph' => $graph,
            'bindings' => $bindings,
        ];

        // Presets and installed pack rooms under one key space (environment_registry), plus an entry
        // for every pack room a room uses that this site lacks (the editor offers it as "not installed").
        $presets = [];
        foreach (environment_registry::get_all() as $key => $def) {
            $presets[$key] = self::client_environment($def);
        }
        foreach ($scenes as $scene) {
            $environment = $scene instanceof \stdClass ? ($scene->environment ?? null) : null;
            if (!$environment instanceof \stdClass || ($environment->type ?? null) !== 'pack') {
                continue;
            }
            if (!is_string($environment->pack ?? null) || !is_string($environment->room ?? null)) {
                continue;
            }
            $key = $environment->pack . '/' . $environment->room;
            if (!isset($presets[$key])) {
                $presets[$key] = [
                    'kind' => 'pack',
                    'pack' => $environment->pack,
                    'room' => $environment->room,
                    'name' => $key,
                    'group' => get_string('envgroup_missing', 'mod_vrroom'),
                    'missing' => true,
                    'bounds' => $environment->bounds ?? null,
                    'previewurl' => null,
                ];
            }
        }

        $questions = [];
        foreach (bank::get_choices((int)$cm->course) as $qbeid => $label) {
            $questions[] = ['id' => (int)$qbeid, 'label' => self::plain_label($label), 'available' => true];
            unset($boundids[(int)$qbeid]);
        }
        foreach (array_keys($boundids) as $qbeid) {
            $questions[] = [
                'id' => (int)$qbeid,
                'label' => self::plain_label(get_string('questionmissing', 'mod_vrroom')),
                'available' => false,
            ];
        }

        $info = [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$cm->course,
            'contextid' => (int)$context->id,
            'viewurl' => (new \moodle_url('/mod/vrroom/view.php', ['id' => $cm->id]))->out(false),
            'maxdocumentbytes' => self::MAX_DOCUMENT_BYTES,
            'maxscenes' => scene_validator::MAX_SCENES,
            'maxinteractables' => scene_validator::MAX_INTERACTABLES,
            'maxzones' => scene_validator::MAX_ZONES,
            'maxhints' => graph_validator::MAX_HINTS,
            'maxbytes' => self::get_max_bytes(),
            'panorama' => panorama::limits(),
            // What this editor offers; another plugin embedding the editor turns parts off.
            'features' => ['logic' => true, 'questions' => true],
        ];

        return [
            'documentjson' => json_encode($document, self::CLIENT_JSON_FLAGS),
            'assetsjson' => json_encode(self::list_assets($context), self::CLIENT_JSON_FLAGS),
            'presetsjson' => json_encode((object)$presets, self::CLIENT_JSON_FLAGS),
            'questionsjson' => json_encode($questions, self::CLIENT_JSON_FLAGS),
            'infojson' => json_encode($info, self::CLIENT_JSON_FLAGS),
        ];
    }

    /**
     * The optimistic-lock revision of what is stored: a sha1 of the rooms, the graph and the bindings.
     *
     * @param \stdClass $vrroom The vrroom row (graphjson is read from it).
     * @param \context_module $context The module context.
     * @return string 40 hex characters.
     */
    public static function revision(\stdClass $vrroom, \context_module $context): string {
        global $DB;

        $rows = [];
        foreach (
            $DB->get_records(
                'vrroom_scenes',
                ['vrroomid' => $vrroom->id],
                'sortorder ASC, id ASC',
                'id, idnumber, sortorder, scenejson'
            ) as $row
        ) {
            $rows[] = [(string)$row->idnumber, (int)$row->sortorder, (string)$row->scenejson];
        }
        $bindings = [];
        foreach (slots::get_slots((int)$vrroom->id, $context) as $slot) {
            $bindings[] = [(int)$slot->slot, (int)$slot->questionbankentryid, round((float)$slot->maxmark, 7)];
        }
        return sha1(json_encode([$rows, (string)$vrroom->graphjson, $bindings], instance_manager::JSON_FLAGS));
    }

    /**
     * Validate an editor document and store it.
     *
     * Any failure throws and nothing is written. The check of the revision and the write happen under
     * a per-activity lock, so of two saves made from the same revision at the same moment only the
     * first is stored and the second gets the normal conflict message.
     *
     * Teacher-facing messages name rooms, zones and objectives by their names; the validators'
     * developer English (with ids and paths) goes into the exception's debug information only.
     *
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @param \stdClass $vrroom The vrroom row, as currently stored (refreshed in place from the database).
     * @param string $documentjson The vrroom-editdoc/1 document from the editor.
     * @return array ['revision' => string, 'graphversion' => int, 'scenesupdated' => bool, 'graphupdated' => bool,
     *     'customised' => int]
     * @throws \moodle_exception erroreditortoolarge, erroreditorinvalid, erroreditorconflict, erroreditorasset,
     *     erroreditorbadglb, erroreditorpanorama, errorquestionduplicate
     */
    public static function save(\cm_info $cm, \context_module $context, \stdClass $vrroom, string $documentjson): array {
        global $DB;

        // 1. Size and shape.
        if (strlen($documentjson) > self::MAX_DOCUMENT_BYTES) {
            throw new \moodle_exception('erroreditortoolarge', 'mod_vrroom');
        }
        try {
            $document = json_decode($documentjson, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', 'document');
        }
        if (!is_array($document) || array_is_list($document) || ($document['schema'] ?? null) !== self::DOC_SCHEMA) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', 'document');
        }

        // Saves of one activity run one at a time: another save holding the lock for longer than the
        // timeout means the room is being changed somewhere else.
        $lock = \core\lock\lock_config::get_lock_factory('mod_vrroom')->get_lock(
            'editor_' . (int)$vrroom->id,
            self::LOCK_TIMEOUT
        );
        if (!$lock) {
            throw new \moodle_exception('erroreditorconflict', 'mod_vrroom');
        }
        try {
            // The row the caller read may predate a save that finished while this one waited.
            foreach ((array)$DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST) as $field => $value) {
                $vrroom->$field = $value;
            }
            [$version, $versionbefore, $scenesupdated, $scenes, $customised] =
                self::validate_and_store($cm, $context, $vrroom, $document);
        } finally {
            $lock->release();
        }

        // 8. Events.
        $graphupdated = $version !== $versionbefore;
        if ($scenesupdated) {
            \mod_vrroom\event\scene_updated::create([
                'objectid' => $vrroom->id,
                'context' => $context,
                'other' => ['scenecount' => count($scenes)],
            ])->trigger();
        }
        if ($graphupdated) {
            $graphid = (int)$DB->get_field('vrroom_graphs', 'id', ['vrroomid' => $vrroom->id, 'version' => $version], MUST_EXIST);
            \mod_vrroom\event\graph_updated::create([
                'objectid' => $graphid,
                'context' => $context,
                'other' => ['vrroomid' => (int)$vrroom->id, 'version' => $version],
            ])->trigger();
        }

        $fresh = $DB->get_record('vrroom', ['id' => $vrroom->id], '*', MUST_EXIST);
        return [
            'revision' => self::revision($fresh, $context),
            'graphversion' => $version,
            'scenesupdated' => $scenesupdated,
            'graphupdated' => $graphupdated,
            'customised' => $customised,
        ];
    }

    /**
     * The part of save() that runs under the activity's lock: the revision check, validation and the write.
     *
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @param \stdClass $vrroom The vrroom row, freshly read.
     * @param array $document The decoded document (schema already checked).
     * @return array [graph version after, graph version before, whether the rooms changed, saved scenes, customised time]
     * @throws \moodle_exception As save().
     */
    private static function validate_and_store(\cm_info $cm, \context_module $context, \stdClass $vrroom, array $document): array {
        global $DB;

        // 2. Nobody saved since this document was loaded.
        $revisionbefore = self::revision($vrroom, $context);
        if (!is_string($document['revision'] ?? null) || !hash_equals($revisionbefore, $document['revision'])) {
            throw new \moodle_exception('erroreditorconflict', 'mod_vrroom');
        }

        // 3. Rooms.
        $scenes = $document['scenes'] ?? null;
        if (!is_array($scenes) || !array_is_list($scenes)) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', 'scenes must be a list');
        }
        foreach ($scenes as $i => $scene) {
            if (is_array($scene)) {
                $scenes[$i] = self::strip_runtime_keys($scene);
            }
        }
        $errors = scene_validator::validate_set($scenes);
        if ($errors) {
            $teacher = self::scene_set_problem($scenes, $context);
            throw new \moodle_exception(
                'erroreditorinvalid',
                'mod_vrroom',
                '',
                self::message($teacher ?? reset($errors)),
                $teacher !== null ? implode('; ', array_slice($errors, 0, 5)) : null
            );
        }
        $fs = get_file_storage();
        foreach (self::file_refs($scenes) as $ref) {
            $file = $fs->get_file(
                $context->id,
                'mod_vrroom',
                (string)$ref['area'],
                (int)$ref['itemid'],
                (string)$ref['filepath'],
                (string)$ref['filename']
            );
            if (!$file || $file->is_directory()) {
                throw new \moodle_exception('erroreditorasset', 'mod_vrroom', '', s((string)$ref['filename']));
            }
        }
        // 3D models and room models must really be binary glTF 2.0; anything else only fails in the viewer.
        foreach (self::model_refs($scenes) as $ref) {
            $file = $fs->get_file(
                $context->id,
                'mod_vrroom',
                (string)$ref['area'],
                (int)$ref['itemid'],
                (string)$ref['filepath'],
                (string)$ref['filename']
            );
            if (!$file || !self::is_glb($file)) {
                throw new \moodle_exception('erroreditorbadglb', 'mod_vrroom', '', s((string)$ref['filename']));
            }
        }
        // 360° photos must be images within the site's size limits (any shape: 2:1 looks best).
        foreach ($scenes as $i => $scene) {
            $environment = $scene['environment'] ?? null;
            if (!scene_validator::is_panorama($environment)) {
                continue;
            }
            $ref = $environment['file'];
            $file = $fs->get_file(
                $context->id,
                'mod_vrroom',
                (string)$ref['area'],
                (int)$ref['itemid'],
                (string)$ref['filepath'],
                (string)$ref['filename']
            );
            $problem = $file ? panorama::check($file) : 'panoramabadtype';
            if ($problem !== null) {
                throw new \moodle_exception(
                    'erroreditorinvalid',
                    'mod_vrroom',
                    '',
                    self::message(self::panorama_problem($scene, $problem, $context)),
                    "scenes[{$i}].environment.file: {$problem}"
                );
            }
        }
        foreach ($scenes as $i => $scene) {
            foreach ($scene['interactables'] ?? [] as $j => $item) {
                if (
                    !isset($item['content']) || !is_array($item['content'])
                        || !array_key_exists('textformat', $item['content'])
                ) {
                    continue;
                }
                if (!in_array($item['content']['textformat'], self::TEXT_FORMATS, true)) {
                    throw new \moodle_exception(
                        'erroreditorinvalid',
                        'mod_vrroom',
                        '',
                        "scenes[{$i}].interactables[{$j}].content.textformat is not a known text format"
                    );
                }
            }
        }
        $stored = instance_manager::get_scenes((int)$vrroom->id);
        self::check_bounds($scenes, $stored, $context);

        // 4. Tasks.
        $graph = $document['graph'] ?? null;
        if (!is_array($graph) || ($graph !== [] && array_is_list($graph))) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', 'graph must be an object');
        }
        // PHP decodes an empty JSON object ({}) as []. An empty 'unlock' means "no condition", so it
        // is dropped; stored as [] it would fail the client-side validator (graph.js isObject).
        if (isset($graph['objectives']) && is_array($graph['objectives'])) {
            foreach ($graph['objectives'] as $i => $objective) {
                if (is_array($objective) && array_key_exists('unlock', $objective) && $objective['unlock'] === []) {
                    unset($graph['objectives'][$i]['unlock']);
                }
            }
        }
        $errors = graph_validator::validate($graph);
        if ($errors) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', self::message(reset($errors)));
        }

        // 5. Question bindings.
        $bindings = self::clean_bindings($document['bindings'] ?? null, $graph);
        $keep = [];
        foreach (slots::get_slots((int)$vrroom->id, $context) as $slot) {
            if ((int)$slot->questionbankentryid > 0) {
                $keep[] = (int)$slot->questionbankentryid;
            }
        }
        $used = [];
        foreach ($bindings as $binding) {
            $qbeid = $binding['questionbankentryid'];
            if (isset($used[$qbeid])) {
                throw new \moodle_exception('errorquestionduplicate', 'mod_vrroom');
            }
            $used[$qbeid] = true;
            $key = bank::validate_entry($qbeid, (int)$cm->course, $keep);
            if ($key !== null) {
                throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', get_string($key, 'mod_vrroom'));
            }
        }

        // 6. Rooms and tasks agree, for the new tasks and for the tasks of every attempt in progress.
        $problems = self::cross_check_problems($scenes, $graph, $bindings, $context);
        if ($problems) {
            $problem = reset($problems);
            throw new \moodle_exception(
                'erroreditorinvalid',
                'mod_vrroom',
                '',
                self::message($problem['message']),
                $problem['debug']
            );
        }
        self::check_open_attempts($vrroom, $scenes, $stored, $context);

        // 7. Store.
        $scenesbefore = self::scene_signature((int)$vrroom->id);
        $versionbefore = (int)$vrroom->graphversion;
        $customised = (int)($vrroom->customised ?? 0);
        $now = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $version = instance_manager::apply_compiled($vrroom, $context, new compiled_activity($scenes, $graph, $bindings));
            // Only a save that changed the rooms, the tasks or the questions customises the room (and
            // so locks the template); saving what was loaded leaves the activity as it was.
            if (self::revision($vrroom, $context) !== $revisionbefore) {
                $customised = $customised > 0 ? $customised : $now;
                $DB->set_field('vrroom', 'customised', $customised, ['id' => $vrroom->id]);
                $DB->set_field('vrroom', 'timemodified', $now, ['id' => $vrroom->id]);
                $vrroom->customised = $customised;
                $vrroom->timemodified = $now;
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        // Files are not part of the transaction, so unreferenced uploads go only once the rows are safe.
        self::prune_assets($context, $scenes);

        return [$version, $versionbefore, self::scene_signature((int)$vrroom->id) !== $scenesbefore, $scenes, $customised];
    }

    /**
     * Check that the tasks only refer to things the rooms contain, and that questions line up.
     *
     * @param array $scenes Valid decoded scene documents.
     * @param array $graph Valid decoded graph.
     * @param array $bindings Clean bindings, each with 'slot'.
     * @return string[] Developer English; [] = consistent.
     */
    public static function cross_check(array $scenes, array $graph, array $bindings): array {
        return array_column(self::cross_check_problems($scenes, $graph, $bindings), 'debug');
    }

    /**
     * The problems cross_check() reports, each with a teacher-facing message.
     *
     * @param array $scenes Valid decoded scene documents.
     * @param array $graph Valid decoded graph.
     * @param array|null $bindings Clean bindings, each with 'slot'; null skips the question checks (an older
     *     graph version, whose questions are kept by the attempts that use it).
     * @param \context|null $context Context to format names in; null takes them as plain text.
     * @return array[] Each ['debug' => developer English, 'message' => plain-text teacher message,
     *     'missing' => id of the room, zone, object or start point that is not there, or null].
     */
    private static function cross_check_problems(array $scenes, array $graph, ?array $bindings, ?\context $context = null): array {
        $sceneids = [];
        $zones = [];
        $zonesbyscene = [];
        $interactables = [];
        $props = [];
        $spawns = [];
        foreach ($scenes as $scene) {
            $sceneid = (string)($scene['id'] ?? '');
            $sceneids[$sceneid] = true;
            $zonesbyscene[$sceneid] = [];
            foreach ($scene['zones'] ?? [] as $zone) {
                $zones[(string)$zone['id']] = true;
                $zonesbyscene[$sceneid][(string)$zone['id']] = true;
            }
            foreach ($scene['interactables'] ?? [] as $item) {
                $interactables[(string)$item['id']] = (string)($item['kind'] ?? '');
            }
            foreach ($scene['props'] ?? [] as $prop) {
                $props[(string)$prop['id']] = true;
            }
            foreach ($scene['spawn'] ?? [] as $spawn) {
                $spawns[(string)$spawn['id']] = true;
            }
        }
        $objectives = is_array($graph['objectives'] ?? null) ? $graph['objectives'] : [];
        $rules = is_array($graph['rules'] ?? null) ? $graph['rules'] : [];
        $problems = [];
        $add = function (string $debug, string $key, $a = null, ?string $missing = null) use (&$problems): void {
            $problems[] = ['debug' => $debug, 'message' => get_string($key, 'mod_vrroom', $a), 'missing' => $missing];
        };
        $title = fn(array $objective): string => self::label($objective['title'] ?? '', $context);

        // Zones of visit_zone objectives.
        foreach ($objectives as $k => $objective) {
            if (($objective['type'] ?? null) !== 'visit_zone') {
                continue;
            }
            $zone = (string)($objective['zone'] ?? '');
            $scene = $objective['scene'] ?? null;
            if ($scene !== null && $scene !== '') {
                if (!isset($zonesbyscene[(string)$scene][$zone])) {
                    $add(
                        "objectives[{$k}].zone \"{$zone}\" is not a zone of room \"{$scene}\"",
                        'erroreditorobjectivezone',
                        $title($objective),
                        $zone
                    );
                }
            } else if (!isset($zones[$zone])) {
                $add(
                    "objectives[{$k}].zone \"{$zone}\" is not a zone of any room",
                    'erroreditorobjectivezone',
                    $title($objective),
                    $zone
                );
            }
        }

        // Objects of interact and collect objectives.
        foreach ($objectives as $k => $objective) {
            $type = $objective['type'] ?? null;
            if ($type === 'interact') {
                $target = (string)($objective['target'] ?? '');
                if (!isset($interactables[$target])) {
                    $add(
                        "objectives[{$k}].target \"{$target}\" is not an object in any room",
                        'erroreditorobjectivetarget',
                        $title($objective),
                        $target
                    );
                }
            } else if ($type === 'collect') {
                foreach ($objective['targets'] ?? [] as $j => $target) {
                    if (!isset($interactables[(string)$target])) {
                        $add(
                            "objectives[{$k}].targets[{$j}] \"{$target}\" is not an object in any room",
                            'erroreditorobjectivetarget',
                            $title($objective),
                            (string)$target
                        );
                    }
                }
            }
        }

        // Hidden objects and show/hide targets.
        foreach ($graph['hidden'] ?? [] as $k => $id) {
            if (!isset($interactables[(string)$id]) && !isset($props[(string)$id])) {
                $add("hidden[{$k}] \"{$id}\" is not an object or block in any room", 'erroreditorhiddenmissing', null, (string)$id);
            }
        }
        foreach ($rules as $k => $rule) {
            foreach ($rule['actions'] ?? [] as $a => $action) {
                $do = $action['do'] ?? null;
                $target = (string)($action['target'] ?? '');
                if (($do === 'show' || $do === 'hide') && !isset($interactables[$target]) && !isset($props[$target])) {
                    $add(
                        "rules[{$k}].actions[{$a}].target \"{$target}\" is not an object or block in any room",
                        'erroreditorruleobject',
                        $k + 1,
                        $target
                    );
                }
            }
        }

        // Media to play.
        foreach ($rules as $k => $rule) {
            foreach ($rule['actions'] ?? [] as $a => $action) {
                if (($action['do'] ?? null) !== 'play_media') {
                    continue;
                }
                $target = (string)($action['target'] ?? '');
                if (($interactables[$target] ?? null) !== 'media') {
                    $add(
                        "rules[{$k}].actions[{$a}].target \"{$target}\" is not an audio or video object",
                        'erroreditorrulemedia',
                        $k + 1,
                        isset($interactables[$target]) ? null : $target
                    );
                }
            }
        }

        // Teleport targets.
        foreach ($rules as $k => $rule) {
            foreach ($rule['actions'] ?? [] as $a => $action) {
                if (($action['do'] ?? null) !== 'teleport') {
                    continue;
                }
                $target = (string)($action['target'] ?? '');
                if (!isset($spawns[$target])) {
                    $add(
                        "rules[{$k}].actions[{$a}].target \"{$target}\" is not a start point of any room",
                        'erroreditorruleteleport',
                        $k + 1,
                        $target
                    );
                }
            }
        }

        // Event triggers naming a zone, an object or a room.
        foreach ($rules as $k => $rule) {
            $on = $rule['on'] ?? null;
            if (!is_array($on) || ($on['on'] ?? null) !== 'event' || !isset($on['id']) || $on['id'] === null) {
                continue;
            }
            $id = (string)$on['id'];
            $type = $on['type'] ?? null;
            if ($type === 'zone' && !isset($zones[$id])) {
                $add("rules[{$k}].on.id \"{$id}\" is not a zone of any room", 'erroreditorruletrigger', $k + 1, $id);
            } else if ($type === 'object' && !isset($interactables[$id])) {
                $add("rules[{$k}].on.id \"{$id}\" is not an object in any room", 'erroreditorruletrigger', $k + 1, $id);
            } else if ($type === 'scene' && !isset($sceneids[$id])) {
                $add("rules[{$k}].on.id \"{$id}\" is not a room of this activity", 'erroreditorruletrigger', $k + 1, $id);
            }
        }

        if ($bindings === null) {
            return $problems;
        }

        // Question slots: one binding per answer_question objective, and nothing else.
        $objectiveslots = [];
        foreach ($objectives as $objective) {
            if (($objective['type'] ?? null) === 'answer_question') {
                $objectiveslots[(int)$objective['slot']] = $objective;
            }
        }
        $bindingslots = [];
        foreach ($bindings as $binding) {
            $bindingslots[(int)$binding['slot']] = true;
        }
        foreach ($objectiveslots as $slot => $objective) {
            if (!isset($bindingslots[$slot])) {
                $add("question slot {$slot} has no question", 'erroreditorslotnoquestion', $title($objective));
            }
        }
        foreach (array_keys($bindingslots) as $slot) {
            if (!isset($objectiveslots[$slot])) {
                $add("question slot {$slot} is bound but no objective answers it", 'erroreditorslotunused');
            }
        }

        // Questions opened by information points.
        foreach ($scenes as $i => $scene) {
            foreach ($scene['interactables'] ?? [] as $j => $item) {
                $question = $item['content']['question'] ?? null;
                if ($question !== null && !isset($objectiveslots[(int)$question])) {
                    $add(
                        "scenes[{$i}].interactables[{$j}].content.question names slot {$question}, which no objective answers",
                        'erroreditorinfoquestion',
                        self::label($item['name'] ?? '', $context)
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * Refuse rooms that the task graph of an attempt in progress still needs.
     *
     * Rooms are not versioned: an attempt keeps the graph version it started with, but plays in the
     * rooms as they are now. Every graph version that an attempt in progress (whose time has not run
     * out) uses must still find its zones, objects and start points in the new rooms.
     *
     * @param \stdClass $vrroom The vrroom row.
     * @param array $scenes The new rooms, validated.
     * @param array $stored The rooms as stored before this save (for the names of what is gone).
     * @param \context_module $context The module context.
     * @throws \moodle_exception erroreditorinvalid naming what is missing and how many students need it.
     */
    private static function check_open_attempts(\stdClass $vrroom, array $scenes, array $stored, \context_module $context): void {
        global $DB;

        $versions = $DB->get_records_sql_menu(
            'SELECT graphversion, COUNT(DISTINCT userid) AS students
               FROM {vrroom_attempts}
              WHERE vrroomid = :vrroomid AND state = :state AND (timedue = 0 OR timedue > :now)
           GROUP BY graphversion',
            ['vrroomid' => $vrroom->id, 'state' => attempt_manager::STATE_INPROGRESS, 'now' => time()]
        );
        $needs = [];
        $first = null;
        foreach ($versions as $version => $students) {
            try {
                $graph = instance_manager::get_graph((int)$vrroom->id, (int)$version);
            } catch (\moodle_exception $e) {
                continue;
            }
            $missing = [];
            foreach (self::cross_check_problems($scenes, $graph, null, $context) as $problem) {
                if ($problem['missing'] === null || isset($missing[$problem['missing']])) {
                    continue;
                }
                $missing[$problem['missing']] = $problem;
                $needs[$problem['missing']] = ($needs[$problem['missing']] ?? 0) + (int)$students;
                $first = $first ?? $problem;
            }
        }
        if ($first === null) {
            return;
        }

        // Name what is gone by its name in the stored rooms, else by the objective that uses it.
        $name = self::item_names($stored, $context)[$first['missing']] ?? null;
        if ($name === null) {
            $name = self::item_names($scenes, $context)[$first['missing']] ?? null;
        }
        $a = (object)['count' => $needs[$first['missing']], 'name' => $name ?? get_string('erroreditorunnamed', 'mod_vrroom')];
        throw new \moodle_exception(
            'erroreditorinvalid',
            'mod_vrroom',
            '',
            self::message(get_string($a->count === 1 ? 'erroreditorinprogressone' : 'erroreditorinprogress', 'mod_vrroom', $a)),
            'attempts in progress: ' . $first['debug']
        );
    }

    /**
     * Refuse start points and zone centres outside the walkable area of their room.
     *
     * The walkable area is what the viewer clamps the student to (scene_validator::walkable_bounds()).
     * Rooms whose area is not known (an uploaded room model without stored bounds) are not checked, nor
     * are 360° photo rooms (their area is the zero box at the centre).
     * A start point or zone that is stored outside already, in a room whose area did not change, is
     * left alone, so that rooms saved before this check stay editable.
     *
     * @param array $scenes The new rooms, validated.
     * @param array $stored The rooms as stored before this save.
     * @param \context_module $context The module context.
     * @throws \moodle_exception erroreditorinvalid naming the start point or zone.
     */
    private static function check_bounds(array $scenes, array $stored, \context_module $context): void {
        $before = [];
        foreach ($stored as $scene) {
            if (!is_array($scene) || !is_string($scene['id'] ?? null)) {
                continue;
            }
            $positions = [];
            foreach ($scene['spawn'] ?? [] as $spawn) {
                $positions['spawn'][(string)($spawn['id'] ?? '')] = $spawn['position'] ?? null;
            }
            foreach ($scene['zones'] ?? [] as $zone) {
                $positions['zones'][(string)($zone['id'] ?? '')] = $zone['center'] ?? null;
            }
            $before[$scene['id']] = [
                'bounds' => scene_validator::walkable_bounds($scene['environment'] ?? null),
                'positions' => $positions,
            ];
        }

        foreach ($scenes as $i => $scene) {
            $bounds = scene_validator::walkable_bounds($scene['environment']);
            // Unknown, or the zero box of a 360° photo room (nobody walks there, so nothing is outside).
            if ($bounds === null || ($bounds['min'] == [0.0, 0.0, 0.0] && $bounds['max'] == [0.0, 0.0, 0.0])) {
                continue;
            }
            $old = $before[$scene['id']] ?? null;
            $samearea = $old !== null && $old['bounds'] == $bounds;
            $room = self::label($scene['name'], $context);
            foreach (['spawn' => 'position', 'zones' => 'center'] as $list => $key) {
                foreach ($scene[$list] ?? [] as $j => $item) {
                    // The 3D room tests the walker's feet (y = 0) against a zone, so a zone must reach the floor.
                    $reachesfloor = $list !== 'zones' || abs((float)($item[$key][1] ?? 0))
                        <= abs((float)($item['size'][1] ?? 0)) / 2 + 1e-6;
                    if ($reachesfloor && scene_validator::inside_bounds($item[$key], $bounds)) {
                        continue;
                    }
                    if ($samearea && ($old['positions'][$list][$item['id']] ?? null) == $item[$key]) {
                        continue;
                    }
                    $name = trim(self::plain_label(format_string((string)($item['name'] ?? ''), true, ['context' => $context])));
                    $stringkey = ($list === 'spawn' ? 'erroreditorspawnoutside' : 'erroreditorzoneoutside')
                        . ($name === '' ? 'unnamed' : '');
                    throw new \moodle_exception(
                        'erroreditorinvalid',
                        'mod_vrroom',
                        '',
                        self::message(get_string($stringkey, 'mod_vrroom', (object)['name' => $name, 'room' => $room])),
                        "scenes[{$i}].{$list}[{$j}].{$key} is outside environment bounds"
                    );
                }
            }
        }
    }

    /**
     * A teacher-facing message for the scene validator errors that editing can cause.
     *
     * Most validator errors describe a document the editor never sends; those keep the developer
     * message. The ones a teacher can reach (too many rooms or objects, a room without a start point,
     * a portal into a deleted room) are explained with the names of the rooms and objects.
     *
     * Other plugins that store rooms (the virtual tour assignment submission) use it for their own saves.
     *
     * @param array $scenes The rooms as sent.
     * @param \context $context Context to format names in.
     * @return string|null Plain-text message, or null when none of these is the problem.
     */
    public static function scene_set_problem(array $scenes, \context $context): ?string {
        if (!array_is_list($scenes) || count($scenes) < 1 || count($scenes) > scene_validator::MAX_SCENES) {
            return get_string('erroreditorroomcount', 'mod_vrroom', scene_validator::MAX_SCENES);
        }
        $spawnsbyscene = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene) || !is_string($scene['id'] ?? null)) {
                continue;
            }
            foreach (is_array($scene['spawn'] ?? null) ? $scene['spawn'] : [] as $spawn) {
                if (is_array($spawn) && is_string($spawn['id'] ?? null)) {
                    $spawnsbyscene[$scene['id']][$spawn['id']] = true;
                }
            }
        }
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $room = self::label($scene['name'] ?? '', $context);
            if (!is_array($scene['spawn'] ?? null) || count($scene['spawn']) < 1) {
                return get_string('erroreditornospawn', 'mod_vrroom', $room);
            }
            $items = is_array($scene['interactables'] ?? null) ? $scene['interactables'] : [];
            if (count($items) > scene_validator::MAX_INTERACTABLES) {
                $a = (object)['room' => $room, 'max' => scene_validator::MAX_INTERACTABLES];
                return get_string('erroreditortoomanyobjects', 'mod_vrroom', $a);
            }
            if (is_array($scene['zones'] ?? null) && count($scene['zones']) > scene_validator::MAX_ZONES) {
                $a = (object)['room' => $room, 'max' => scene_validator::MAX_ZONES];
                return get_string('erroreditortoomanyzones', 'mod_vrroom', $a);
            }
            foreach ($items as $item) {
                if (!is_array($item) || ($item['kind'] ?? null) !== 'portal') {
                    continue;
                }
                $target = is_array($item['target'] ?? null) ? $item['target'] : [];
                $targetscene = $target['scene'] ?? null;
                $targetspawn = $target['spawn'] ?? null;
                if (!is_string($targetscene) || !is_string($targetspawn) || !isset($spawnsbyscene[$targetscene][$targetspawn])) {
                    $a = (object)['name' => self::label($item['name'] ?? '', $context), 'room' => $room];
                    return get_string('erroreditorportaltarget', 'mod_vrroom', $a);
                }
            }
        }
        return null;
    }

    /**
     * A teacher-facing message for a 360° photo that panorama::check() refused.
     *
     * @param array $scene The room whose photo it is.
     * @param string $problem The string identifier panorama::check() returned.
     * @param \context $context Context to format the room name in.
     * @return string Plain text.
     */
    public static function panorama_problem(array $scene, string $problem, \context $context): string {
        $a = (object)[
            'room' => self::label($scene['name'] ?? '', $context),
            'problem' => panorama::message($problem),
        ];
        return get_string('erroreditorpanorama', 'mod_vrroom', $a);
    }

    /**
     * Names of every start point, zone, block and object of a set of rooms, and of the rooms.
     *
     * @param array $scenes Decoded scene documents.
     * @param \context $context Context to format names in.
     * @return string[] id => plain-text name (unnamed things are left out).
     */
    private static function item_names(array $scenes, \context $context): array {
        $names = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $lists = [[$scene]];
            foreach (['spawn', 'zones', 'props', 'interactables'] as $list) {
                $lists[] = is_array($scene[$list] ?? null) ? $scene[$list] : [];
            }
            foreach ($lists as $list) {
                foreach ($list as $item) {
                    if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['name'] ?? null)) {
                        continue;
                    }
                    $name = trim(self::plain_label(format_string($item['name'], true, ['context' => $context])));
                    if ($name !== '') {
                        $names[$item['id']] = $name;
                    }
                }
            }
        }
        return $names;
    }

    /**
     * A name for a teacher-facing message: plain text, or "(no name)" when empty.
     *
     * @param mixed $name The stored name (may hold HTML or multi-language spans).
     * @param \context|null $context Context to format it in; null takes it as plain text.
     * @return string
     */
    private static function label($name, ?\context $context): string {
        $name = is_string($name) ? $name : '';
        if ($context !== null) {
            $name = format_string($name, true, ['context' => $context]);
        }
        $name = trim(self::plain_label($name));
        return $name !== '' ? $name : get_string('erroreditorunnamed', 'mod_vrroom');
    }

    /**
     * Whether a stored file is binary glTF 2.0: the "glTF" magic, version 2 and a length that fits the file.
     *
     * The room editor uses this for 3D models and room models. Uploads should be checked with it as well,
     * so that a renamed file is refused when it is added and not only when the room is saved.
     *
     * @param \stored_file $file The file.
     * @return bool
     */
    public static function is_glb(\stored_file $file): bool {
        $size = (int)$file->get_filesize();
        if ($size < 12) {
            return false;
        }
        try {
            $handle = $file->get_content_file_handle();
        } catch (\Throwable $e) {
            return false;
        }
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 12);
        fclose($handle);
        if (!is_string($header) || strlen($header) !== 12) {
            return false;
        }
        $values = unpack('a4magic/Vversion/Vlength', $header);
        return $values['magic'] === 'glTF' && $values['version'] === 2 && $values['length'] >= 12 && $values['length'] <= $size;
    }

    /**
     * The file references of a set of rooms that must be GLB models: room models, 3D models and grabbables with a model.
     *
     * @param array $scenes Valid decoded scene documents.
     * @return array[] File references.
     */
    public static function model_refs(array $scenes): array {
        $refs = [];
        foreach ($scenes as $scene) {
            if (($scene['environment']['type'] ?? null) === 'model' && is_array($scene['environment']['file'] ?? null)) {
                $refs[] = $scene['environment']['file'];
            }
            foreach ($scene['interactables'] ?? [] as $item) {
                if (in_array($item['kind'] ?? null, ['model', 'grabbable'], true) && is_array($item['file'] ?? null)) {
                    $refs[] = $item['file'];
                }
            }
        }
        return $refs;
    }

    /**
     * Every file of the areas the editor can use.
     *
     * The smaller copies of 360° photos (in variants/ folders) are never listed. An image that has
     * such copies, that is in the environment area (only 360° photos are uploaded there) or that is
     * at least 1.5 times as wide as it is high, is marked 'panorama' => true (its kind stays image).
     *
     * @param \context_module $context The module context.
     * @return array[] Each ['area', 'itemid', 'filepath', 'filename', 'url', 'mimetype', 'kind', 'filesize'],
     *     plus 'panorama' => true for a 360° photo.
     */
    public static function list_assets(\context_module $context): array {
        $fs = get_file_storage();
        $assets = [];
        foreach (self::AREAS as $area) {
            $files = $fs->get_area_files($context->id, 'mod_vrroom', $area, false, 'itemid, filepath, filename', false);
            // Folders holding smaller copies, by item id and the original's filepath.
            $withvariants = [];
            foreach ($files as $file) {
                if (panorama::is_variant($file)) {
                    $parent = substr($file->get_filepath(), 0, -strlen(panorama::VARIANTS_DIR . '/'));
                    $withvariants[(int)$file->get_itemid()][$parent] = true;
                }
            }
            foreach ($files as $file) {
                if (panorama::is_variant($file)) {
                    continue;
                }
                $entry = self::asset_entry($file);
                if (
                    $entry['kind'] === 'image' && in_array($entry['mimetype'], panorama::IMAGE_MIMETYPES, true)
                        && ($area === 'environment' || isset($withvariants[(int)$file->get_itemid()][$file->get_filepath()])
                            || panorama::has_ratio($file))
                ) {
                    $entry['panorama'] = true;
                }
                $assets[] = $entry;
            }
        }
        return $assets;
    }

    /**
     * The editor's description of one stored file.
     *
     * @param \stored_file $file The file.
     * @return array ['area', 'itemid', 'filepath', 'filename', 'url', 'mimetype', 'kind', 'filesize']
     */
    public static function asset_entry(\stored_file $file): array {
        return [
            'area' => $file->get_filearea(),
            'itemid' => (int)$file->get_itemid(),
            'filepath' => $file->get_filepath(),
            'filename' => $file->get_filename(),
            'url' => \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                'mod_vrroom',
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out(false),
            'mimetype' => (string)$file->get_mimetype(),
            'kind' => self::asset_kind($file->get_filename()),
            'filesize' => (int)$file->get_filesize(),
        ];
    }

    /**
     * The kind of an asset from its file name.
     *
     * @param string $filename The file name.
     * @return string model, image, audio, video or other.
     */
    public static function asset_kind(string $filename): string {
        $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        foreach (self::KIND_EXTENSIONS as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }
        return 'other';
    }

    /**
     * The item id for the next editor upload.
     *
     * @param \context_module $context The module context.
     * @return int 1 + the highest item id in the assets area, 1 when it is empty.
     */
    public static function next_asset_itemid(\context_module $context): int {
        global $DB;
        $max = $DB->get_field_sql(
            'SELECT MAX(itemid) FROM {files} WHERE contextid = :contextid AND component = :component AND filearea = :filearea',
            ['contextid' => $context->id, 'component' => 'mod_vrroom', 'filearea' => self::ASSET_AREA]
        );
        return max(0, (int)$max) + 1;
    }

    /**
     * Delete the editor uploads no room refers to.
     *
     * Uploads are pruned by item id, so the smaller copies of a 360° photo (same item id, variants/
     * folder) stay while the photo is used and go with it.
     *
     * @param \context_module $context The module context.
     * @param array $scenes The rooms as saved.
     */
    public static function prune_assets(\context_module $context, array $scenes): void {
        $referenced = [];
        foreach (self::file_refs($scenes) as $ref) {
            if (($ref['area'] ?? null) === self::ASSET_AREA) {
                $referenced[(int)$ref['itemid']] = true;
            }
        }
        $fs = get_file_storage();
        $itemids = [];
        foreach ($fs->get_area_files($context->id, 'mod_vrroom', self::ASSET_AREA, false, 'itemid', true) as $file) {
            $itemids[(int)$file->get_itemid()] = true;
        }
        foreach (array_keys($itemids) as $itemid) {
            if (!isset($referenced[$itemid])) {
                $fs->delete_area_files($context->id, 'mod_vrroom', self::ASSET_AREA, $itemid);
            }
        }
    }

    /**
     * Every file reference in a set of rooms: environment files, interactable files and hotspot media.
     *
     * @param array $scenes Decoded scene documents.
     * @return array[] File references.
     */
    public static function file_refs(array $scenes): array {
        $refs = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            if (isset($scene['environment']['file']) && is_array($scene['environment']['file'])) {
                $refs[] = $scene['environment']['file'];
            }
            foreach ($scene['interactables'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['file']) && is_array($item['file'])) {
                    $refs[] = $item['file'];
                }
                foreach ($item['content']['media'] ?? [] as $media) {
                    if (is_array($media)) {
                        $refs[] = $media;
                    }
                }
            }
        }
        return array_values(array_filter($refs, fn($ref): bool => isset($ref['area'], $ref['filename'])));
    }

    /**
     * Largest editor upload in bytes, from the mod_vrroom maxmodelsize setting (MB, default 20).
     *
     * @return int
     */
    public static function get_max_bytes(): int {
        $mb = (int)get_config('mod_vrroom', 'maxmodelsize');
        return ($mb > 0 ? $mb : 20) * 1048576;
    }

    /**
     * What the room editor needs of an environment definition (presets keep every presets.json key).
     *
     * Every entry has 'previewurl': the room's preview image URL, or null when it has none.
     *
     * @param array $def definition from environment_registry
     * @return array
     */
    private static function client_environment(array $def): array {
        if ($def['kind'] === 'preset') {
            unset($def['environment'], $def['missing']);
            $def['previewurl'] = environment_registry::preview_url($def);
            return $def;
        }
        return [
            'kind' => 'pack',
            'pack' => $def['pack'],
            'room' => $def['room'],
            'name' => $def['name'],
            'group' => $def['group'],
            'sky' => $def['sky'],
            'lights' => $def['lights'],
            'bounds' => $def['bounds'],
            'spawn' => $def['spawn'],
            'url' => $def['url'],
            'previewurl' => $def['previewurl'],
            'credit' => $def['credit'],
            'creditlabel' => get_string('editorenvironmentcredit', 'mod_vrroom', $def['credit']),
        ];
    }

    /**
     * Remove what only the launch config adds: file URLs, formatted hotspot HTML, a pack room's
     * URL, name, credit and missing flag, a 360° photo's variants, and hotspot media kinds.
     *
     * Other plugins that store rooms (the virtual tour assignment submission) use it for their own saves.
     *
     * @param array $scene A decoded scene document.
     * @return array
     */
    public static function strip_runtime_keys(array $scene): array {
        if (isset($scene['environment']['file']) && is_array($scene['environment']['file'])) {
            unset($scene['environment']['file']['url']);
        }
        if (isset($scene['environment']) && is_array($scene['environment'])) {
            unset($scene['environment']['variants']);
        }
        if (isset($scene['environment']) && is_array($scene['environment']) && ($scene['environment']['type'] ?? null) === 'pack') {
            unset(
                $scene['environment']['url'],
                $scene['environment']['roomname'],
                $scene['environment']['credit'],
                $scene['environment']['missing']
            );
        }
        if (isset($scene['interactables']) && is_array($scene['interactables'])) {
            foreach ($scene['interactables'] as $j => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['file']) && is_array($item['file'])) {
                    unset($item['file']['url']);
                }
                if (isset($item['content']) && is_array($item['content'])) {
                    unset($item['content']['html']);
                    if (isset($item['content']['media']) && is_array($item['content']['media'])) {
                        foreach ($item['content']['media'] as $m => $media) {
                            if (is_array($media)) {
                                unset($media['url'], $media['kind']);
                                if (isset($media['file']) && is_array($media['file'])) {
                                    unset($media['file']['url']);
                                }
                                $item['content']['media'][$m] = $media;
                            }
                        }
                    }
                }
                $scene['interactables'][$j] = $item;
            }
        }
        return $scene;
    }

    /**
     * Check the bindings and take each one's objective from the graph.
     *
     * @param mixed $bindings The document's bindings.
     * @param array $graph The validated graph.
     * @return array[] Each ['slot' => int, 'questionbankentryid' => int, 'maxmark' => float, 'objectiveid' => string].
     * @throws \moodle_exception erroreditorinvalid
     */
    private static function clean_bindings($bindings, array $graph): array {
        if ($bindings === null) {
            $bindings = [];
        }
        if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) > self::MAX_BINDINGS) {
            throw new \moodle_exception(
                'erroreditorinvalid',
                'mod_vrroom',
                '',
                'bindings must be a list of at most ' . self::MAX_BINDINGS . ' questions'
            );
        }
        $objectivebyslot = [];
        foreach ($graph['objectives'] ?? [] as $objective) {
            if (($objective['type'] ?? null) === 'answer_question') {
                $objectivebyslot[(int)$objective['slot']] = (string)$objective['id'];
            }
        }
        $clean = [];
        $seen = [];
        foreach ($bindings as $k => $binding) {
            $slot = is_array($binding) ? ($binding['slot'] ?? null) : null;
            $qbeid = is_array($binding) ? ($binding['questionbankentryid'] ?? null) : null;
            $maxmark = is_array($binding) ? ($binding['maxmark'] ?? null) : null;
            if (!is_int($slot) || $slot < 1 || isset($seen[$slot])) {
                throw new \moodle_exception(
                    'erroreditorinvalid',
                    'mod_vrroom',
                    '',
                    "bindings[{$k}].slot must be a unique integer of at least 1"
                );
            }
            if (!is_int($qbeid) || $qbeid <= 0) {
                throw new \moodle_exception(
                    'erroreditorinvalid',
                    'mod_vrroom',
                    '',
                    "bindings[{$k}].questionbankentryid must be a question"
                );
            }
            if (
                (!is_int($maxmark) && !is_float($maxmark)) || !is_finite((float)$maxmark)
                    || $maxmark <= 0 || $maxmark > self::MAX_MARK
            ) {
                throw new \moodle_exception(
                    'erroreditorinvalid',
                    'mod_vrroom',
                    '',
                    "bindings[{$k}].maxmark must be a number greater than 0 and at most 1000"
                );
            }
            $seen[$slot] = true;
            // The client's objective id is ignored: the graph says which objective answers the slot.
            $clean[] = [
                'slot' => $slot,
                'questionbankentryid' => $qbeid,
                'maxmark' => (float)$maxmark,
                'objectiveid' => $objectivebyslot[$slot] ?? '',
            ];
        }
        return $clean;
    }

    /**
     * The stored rooms as a comparable list of [idnumber, scenejson].
     *
     * @param int $vrroomid The vrroom id.
     * @return array
     */
    private static function scene_signature(int $vrroomid): array {
        global $DB;
        $signature = [];
        foreach (
            $DB->get_records(
                'vrroom_scenes',
                ['vrroomid' => $vrroomid],
                'sortorder ASC, id ASC',
                'id, idnumber, scenejson'
            ) as $row
        ) {
            $signature[] = [(string)$row->idnumber, (string)$row->scenejson];
        }
        return $signature;
    }

    /**
     * A question choice label as plain text (the editor escapes it).
     *
     * @param string $label HTML-safe label.
     * @return string
     */
    private static function plain_label(string $label): string {
        return html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A validator message, made safe to show inside a lang string (exception messages are HTML).
     *
     * @param string $message Developer English that may quote ids.
     * @return string
     */
    private static function message(string $message): string {
        return s($message);
    }
}
