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

/**
 * Restore structure step for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores one VR room activity from vrroom.xml.
 *
 * Scene, zone, interactable and objective ids are uuids or template-made strings and graph slots
 * are numbers, so scenes and graphs need no id remapping. Question references are remapped as
 * mod_quiz does, but onto vrroom_slots ids; attempts are inserted only once their question usage
 * has been restored (inform_new_usage_id), as mod_quiz does with quiz_attempts.
 *
 * A backup file is untrusted input: settings are cleaned as the activity form cleans them, scene and
 * graph documents are checked with the same validators as the form and the room editor (a room that
 * fails is rebuilt from its template, see after_execute()), rows that would repeat a unique key are
 * skipped and a statement id that is not a uuid is renewed. Every skip is written to the restore log.
 */
class restore_vrroom_activity_structure_step extends restore_questions_activity_structure_step {
    /** @var string Pattern of a statement id, as the xAPI handler accepts it (lowercase uuid). */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var int[] Longest value of each vrroom_events text column (db/install.xml). */
    private const EVENT_LENGTHS = ['verb' => 20, 'objecttype' => 20, 'sceneid' => 36, 'objectid' => 64];

    /** @var stdClass|null The attempt read from the XML, waiting for its new usage id. */
    protected $currentattempt = null;

    /** @var int|null New id of the attempt being restored; null while there is none (e.g. user skipped). */
    protected $currentattemptid = null;

    /**
     * The paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('vrroom', '/activity/vrroom');
        $paths[] = new restore_path_element('vrroom_scene', '/activity/vrroom/scenes/scene');
        $paths[] = new restore_path_element('vrroom_graph', '/activity/vrroom/graphs/graph');

        $slot = new restore_path_element('vrroom_slot', '/activity/vrroom/slots/slot');
        $paths[] = $slot;
        $this->add_question_references($slot, $paths);

        if ($userinfo) {
            $attempt = new restore_path_element('vrroom_attempt', '/activity/vrroom/attempts/attempt');
            $paths[] = $attempt;
            $this->add_question_usages($attempt, $paths);
            $paths[] = new restore_path_element('vrroom_event', '/activity/vrroom/attempts/attempt/events/event');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity row.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        unset($data->id);
        $data->name = core_text::substr((string)($data->name ?? ''), 0, 255);
        // The other settings are cleaned as the activity form and instance_manager clean them.
        $data->grade = max(0, (int)($data->grade ?? 0));
        $grademethod = (int)($data->grademethod ?? 0);
        $grademethods = [
            \mod_vrroom\local\grading\grader::GRADE_HIGHEST,
            \mod_vrroom\local\grading\grader::GRADE_AVERAGE,
            \mod_vrroom\local\grading\grader::GRADE_LAST,
        ];
        $data->grademethod = in_array($grademethod, $grademethods, true)
            ? $grademethod : \mod_vrroom\local\grading\grader::GRADE_HIGHEST;
        // 0 is unlimited; the column holds six digits.
        $data->maxattempts = min(999999, max(0, (int)($data->maxattempts ?? 0)));
        $data->questionmode = \mod_vrroom\local\questions\mode::clean((string)($data->questionmode ?? ''));
        $data->retrypenalty = \mod_vrroom\local\questions\mode::clean_penalty(
            $data->retrypenalty ?? \mod_vrroom\local\questions\mode::DEFAULT_PENALTY
        );
        $data->completionobjectives = empty($data->completionobjectives) ? 0 : 1;
        $data->customised = max(0, (int)($data->customised ?? 0));
        $data->livemaxusers = min(50, max(2, (int)($data->livemaxusers ?? 6)));
        $data->graphversion = max(0, (int)($data->graphversion ?? 0));
        // A template config that is not a JSON object could never be decoded again: drop it.
        $templateconfig = $data->templateconfig ?? null;
        if ($templateconfig !== null && $templateconfig !== '' && !(json_decode((string)$templateconfig) instanceof stdClass)) {
            $this->log('VR room template settings are not a JSON object. Dropping them', backup::LOG_WARNING);
            $data->templateconfig = null;
        }
        // Live mode 2 needs a run mode that runs several rooms; old backups have no liverooms (one room).
        $multi = \mod_vrroom\local\live_settings::runtime_has_multirooms((string)($data->runtime ?? ''));
        $data->livemode = \mod_vrroom\local\live_settings::clean_mode($data->livemode ?? 0, $multi);
        $data->liverooms = \mod_vrroom\local\live_settings::clean_rooms(
            $data->liverooms ?? \mod_vrroom\local\live_settings::DEFAULT_ROOMS,
            $data->livemode
        );

        $newitemid = $DB->insert_record('vrroom', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore a scene.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom_scene($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        $data->vrroomid = $this->get_new_parentid('vrroom');

        // The whole set of rooms is validated in after_execute(); here only what the row needs.
        $scene = json_decode((string)($data->scenejson ?? ''), true);
        $sceneid = is_array($scene) ? ($scene['id'] ?? null) : null;
        if (
            !is_string($sceneid) || preg_match(\mod_vrroom\local\scene\scene_validator::ID_PATTERN, $sceneid) !== 1
                || strlen($sceneid) > \mod_vrroom\local\scene\scene_validator::MAX_SCENE_ID_LENGTH
        ) {
            $this->log('VR room scene ' . $oldid . ' is not a valid scene document. Skipping it', backup::LOG_WARNING);
            return;
        }
        if ($DB->record_exists('vrroom_scenes', ['vrroomid' => $data->vrroomid, 'idnumber' => $sceneid])) {
            $this->log('VR room scene ' . $oldid . ' repeats the room id ' . $sceneid . '. Skipping it', backup::LOG_WARNING);
            return;
        }
        $data->idnumber = $sceneid;
        $data->name = core_text::substr(is_string($scene['name'] ?? null) ? $scene['name'] : (string)($data->name ?? ''), 0, 255);
        $data->sortorder = (int)($data->sortorder ?? 0);
        $data->schemaversion = \mod_vrroom\local\scene\scene_validator::SCHEMA;

        $newitemid = $DB->insert_record('vrroom_scenes', $data);
        $this->set_mapping('vrroom_scene', $oldid, $newitemid);
    }

    /**
     * Restore a graph version.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom_graph($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        $data->vrroomid = $this->get_new_parentid('vrroom');
        $data->version = (int)($data->version ?? 0);

        $graph = json_decode((string)($data->graphjson ?? ''), true);
        $errors = is_array($graph) ? \mod_vrroom\local\graph\graph_validator::validate($graph) : ['not a JSON object'];
        if ($data->version < 1 || $errors) {
            $this->log('VR room task graph version ' . $data->version . ' is not valid (' .
                ($errors ? reset($errors) : 'bad version number') . '). Skipping it', backup::LOG_WARNING);
            return;
        }
        if ($DB->record_exists('vrroom_graphs', ['vrroomid' => $data->vrroomid, 'version' => $data->version])) {
            $this->log('VR room task graph version ' . $data->version . ' is repeated. Skipping it', backup::LOG_WARNING);
            return;
        }

        $newitemid = $DB->insert_record('vrroom_graphs', $data);
        $this->set_mapping('vrroom_graph', $oldid, $newitemid);
    }

    /**
     * Restore a question slot.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom_slot($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        if (isset($data->slotnumber)) {
            $data->slot = (int)$data->slotnumber;
            unset($data->slotnumber);
        }
        $data->vrroomid = $this->get_new_parentid('vrroom');
        $data->slot = (int)($data->slot ?? 0);
        $data->objectiveid = (string)($data->objectiveid ?? '');
        $data->maxmark = (float)($data->maxmark ?? 1);
        if (!is_finite($data->maxmark) || $data->maxmark <= 0) {
            $data->maxmark = 1.0;
        }

        // A skipped slot maps to 0, so its question reference is skipped too rather than added to the slot before it.
        if ($data->slot < 1 || !preg_match(\mod_vrroom\local\scene\scene_validator::ID_PATTERN, $data->objectiveid)) {
            $this->log('VR room question slot ' . $data->slot . ' is not valid. Skipping it', backup::LOG_WARNING);
            $this->set_mapping('vrroom_slot', $oldid, 0);
            return;
        }
        if ($DB->record_exists('vrroom_slots', ['vrroomid' => $data->vrroomid, 'slot' => $data->slot])) {
            $this->log('VR room question slot ' . $data->slot . ' is repeated. Skipping it', backup::LOG_WARNING);
            $this->set_mapping('vrroom_slot', $oldid, 0);
            return;
        }

        $newitemid = $DB->insert_record('vrroom_slots', $data);
        $this->set_mapping('vrroom_slot', $oldid, $newitemid);
    }

    /**
     * Restore a question reference of a slot.
     *
     * Core's implementation takes the item id from the quiz slot mapping
     * (restore_stepslib.php process_question_reference); ours points at the new vrroom_slots id.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    public function process_question_reference($data) {
        global $DB;
        $data = (object)$data;
        unset($data->id);
        $data->usingcontextid = $this->get_mappingid('context', $data->usingcontextid);
        $data->itemid = $this->get_new_parentid('vrroom_slot');
        if (!$data->itemid) {
            // Its slot was skipped.
            return;
        }
        if ($entry = $this->get_mappingid('question_bank_entry', $data->questionbankentryid)) {
            $data->questionbankentryid = $entry;
        }
        $DB->insert_record('question_references', $data);
    }

    /**
     * Read an attempt; it is inserted when its question usage has been restored.
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom_attempt($data) {
        global $DB;
        $data = (object)$data;
        $this->currentattempt = null;
        $this->currentattemptid = null;

        $data->vrroomid = $this->get_new_parentid('vrroom');
        if (isset($data->attemptnum)) {
            $data->attempt = (int)$data->attemptnum;
            unset($data->attemptnum);
        }

        $olduserid = $data->userid;
        $data->userid = $this->get_mappingid('user', $olduserid, 0);
        if (!$data->userid) {
            $this->log('Mapped user ID not found for user ' . $olduserid . ', VR room ' . $data->vrroomid .
                ', attempt ' . $data->attempt . '. Skipping VR room attempt', backup::LOG_INFO);
            return;
        }
        $data->attempt = (int)($data->attempt ?? 0);
        $unique = ['vrroomid' => $data->vrroomid, 'userid' => $data->userid, 'attempt' => $data->attempt];
        if ($DB->record_exists('vrroom_attempts', $unique)) {
            $this->log(
                'VR room attempt ' . $data->attempt . ' of user ' . $olduserid . ' is repeated. Skipping it',
                backup::LOG_WARNING
            );
            return;
        }

        $this->currentattempt = clone($data);
    }

    /**
     * Insert the waiting attempt with its new question usage id.
     *
     * @param int $newusageid the new question_usages id
     * @return void
     */
    protected function inform_new_usage_id($newusageid) {
        global $DB;
        $data = $this->currentattempt;
        if ($data === null) {
            return;
        }
        $this->currentattempt = null;

        $oldid = $data->id;
        unset($data->id);
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('vrroom_attempts', $data);
        $this->currentattemptid = (int)$newitemid;
        // Logs and events map attempt ids through this.
        $this->set_mapping('vrroom_attempt', $oldid, $newitemid, false);
    }

    /**
     * Restore a statement of the attempt being restored.
     *
     * Statement ids are unique site-wide; a restore into the same site (course duplication)
     * would collide with the original rows, so a colliding id gets a fresh uuid, as does an id
     * that is not a uuid (the xAPI handler accepts uuids only).
     *
     * @param array|stdClass $data element data
     * @return void
     */
    protected function process_vrroom_event($data) {
        global $DB;
        if ($this->currentattemptid === null) {
            // The attempt was skipped (no mapped user) or has no usage in the backup.
            return;
        }
        $data = (object)$data;
        unset($data->id);
        $data->attemptid = $this->currentattemptid;
        foreach (self::EVENT_LENGTHS as $field => $length) {
            if (isset($data->$field) && core_text::strlen((string)$data->$field) > $length) {
                $this->log(
                    'VR room statement with a ' . $field . ' longer than ' . $length . ' characters. Skipping it',
                    backup::LOG_WARNING
                );
                return;
            }
        }
        $data->statementid = strtolower((string)($data->statementid ?? ''));
        if (
            preg_match(self::UUID_PATTERN, $data->statementid) !== 1
                || $DB->record_exists('vrroom_events', ['statementid' => $data->statementid])
        ) {
            $data->statementid = strtolower(\core\uuid::generate());
        }
        $DB->insert_record('vrroom_events', $data);
    }

    /**
     * Restore the file areas, let the template remap the question bank entries named in its
     * stored config, then make sure the restored room is valid.
     *
     * Times are not shifted: attempt times keep their original values (as in Phase 1), so an
     * attempt's deadline stays consistent with its start.
     *
     * @return void
     */
    protected function after_execute() {
        global $DB;
        parent::after_execute();

        $this->add_related_files('mod_vrroom', 'intro', null);
        $this->add_related_files('mod_vrroom', 'models', null);
        $this->add_related_files('mod_vrroom', 'media', null);
        $this->add_related_files('mod_vrroom', 'environment', null);
        $this->add_related_files('mod_vrroom', 'assets', null);

        $vrroomid = $this->get_new_parentid('vrroom');
        if (!$vrroomid) {
            return;
        }
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroomid], '*', MUST_EXIST);
        $this->remap_template_config($vrroom);
        $this->check_room($vrroom);
    }

    /**
     * Let the template remap the question bank entries named in its stored config.
     *
     * @param stdClass $vrroom The restored vrroom row (templateconfig is updated in place).
     * @return void
     */
    protected function remap_template_config(stdClass $vrroom): void {
        global $DB;
        if ($vrroom->templateconfig === null || $vrroom->templateconfig === '') {
            return;
        }
        $config = json_decode($vrroom->templateconfig, false);
        if (!$config instanceof stdClass) {
            return;
        }
        try {
            $template = \mod_vrroom\local\template\registry::get((string)$vrroom->template);
        } catch (\moodle_exception $e) {
            // The template is not installed on this site: its config stays as it was.
            return;
        }
        $changed = $template->remap_question_entries(
            $config,
            fn(int $old): int => (int)($this->get_mappingid('question_bank_entry', $old) ?: $old)
        );
        if ($changed) {
            // Same flags as instance_manager, so link markers awaiting decoding stay intact.
            $vrroom->templateconfig = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $DB->set_field('vrroom', 'templateconfig', $vrroom->templateconfig, ['id' => $vrroom->id]);
        }
    }

    /**
     * Make sure the restored rooms and the current task graph pass the validators.
     *
     * The graph versions were validated one by one when read. When the activity's graph version
     * names none of them, the activity's own graph copy (vrroom.graphjson) becomes a new version if
     * it is valid, otherwise the latest restored version is used. When the rooms together fail
     * scene_validator::validate_set() or no valid graph is left, the room is rebuilt from its
     * template as the activity form would build it; without a template that compiles, a plain room
     * with no tasks is stored instead. Attempts whose graph version was not restored are moved to
     * the current version, so they can still be replayed.
     *
     * @param stdClass $vrroom The restored vrroom row.
     * @return void
     */
    protected function check_room(stdClass $vrroom): void {
        global $DB;

        // The task graph.
        $versions = $DB->get_records_menu('vrroom_graphs', ['vrroomid' => $vrroom->id], 'version ASC', 'version, graphjson');
        $graphok = isset($versions[(int)$vrroom->graphversion]);
        if (!$graphok && $vrroom->graphjson !== null && $vrroom->graphjson !== '') {
            $graph = json_decode((string)$vrroom->graphjson, true);
            if (is_array($graph) && !\mod_vrroom\local\graph\graph_validator::validate($graph)) {
                $this->log('VR room graph version ' . $vrroom->graphversion . ' was not restored. Storing the ' .
                    'activity\'s current graph as a new version', backup::LOG_WARNING);
                $this->store_graph($vrroom, (string)$vrroom->graphjson);
                $graphok = true;
            }
        }
        if (!$graphok && $versions) {
            $this->log('VR room graph version ' . $vrroom->graphversion . ' was not restored. Using version ' .
                array_key_last($versions), backup::LOG_WARNING);
            $vrroom->graphversion = (int)array_key_last($versions);
            $graphok = true;
        }
        if ($graphok) {
            // The activity's copy of the current graph is the stored version.
            $vrroom->graphjson = $DB->get_field(
                'vrroom_graphs',
                'graphjson',
                ['vrroomid' => $vrroom->id, 'version' => $vrroom->graphversion],
                MUST_EXIST
            );
            $DB->update_record('vrroom', (object)[
                'id' => $vrroom->id,
                'graphjson' => $vrroom->graphjson,
                'graphversion' => $vrroom->graphversion,
            ]);
        }

        // The rooms.
        $scenes = [];
        foreach ($DB->get_records('vrroom_scenes', ['vrroomid' => $vrroom->id], 'sortorder ASC, id ASC') as $row) {
            $scenes[] = json_decode($row->scenejson, true);
        }
        $errors = \mod_vrroom\local\scene\scene_validator::validate_set($scenes);
        if ($errors) {
            $this->log('VR room rooms are not valid (' . reset($errors) . ')', backup::LOG_WARNING);
        }

        if ($errors || !$graphok) {
            if (!$this->rebuild_from_template($vrroom)) {
                $this->store_plain_room($vrroom, (bool)$errors, !$graphok);
            }
        }

        // Attempts played under a graph version that was not restored.
        $moved = $DB->count_records_select(
            'vrroom_attempts',
            'vrroomid = :vrroomid AND graphversion NOT IN (SELECT version FROM {vrroom_graphs} WHERE vrroomid = :vrroomid2)',
            ['vrroomid' => $vrroom->id, 'vrroomid2' => $vrroom->id]
        );
        if ($moved) {
            $this->log('VR room: ' . $moved . ' attempt(s) named a graph version that was not restored. Moving them to ' .
                'version ' . $vrroom->graphversion, backup::LOG_WARNING);
            $DB->execute(
                'UPDATE {vrroom_attempts} SET graphversion = :version
                  WHERE vrroomid = :vrroomid
                        AND graphversion NOT IN (SELECT version FROM {vrroom_graphs} WHERE vrroomid = :vrroomid2)',
                ['version' => $vrroom->graphversion, 'vrroomid' => $vrroom->id, 'vrroomid2' => $vrroom->id]
            );
        }
    }

    /**
     * Rebuild the rooms, the task graph and the question slots from the activity's template.
     *
     * The compiled activity is checked as instance_manager checks it before anything is written.
     *
     * @param stdClass $vrroom The restored vrroom row (graphjson and graphversion are updated in place).
     * @return bool False when the template is not installed or does not give a valid room.
     */
    protected function rebuild_from_template(stdClass $vrroom): bool {
        global $DB;
        try {
            $context = context_module::instance($this->task->get_moduleid());
            $template = \mod_vrroom\local\template\registry::get((string)$vrroom->template);
            $config = \mod_vrroom\local\instance_manager::decode_config($vrroom->templateconfig) ?? new stdClass();
            $compiled = $template->compile($config, $context);
        } catch (\Throwable $e) {
            $this->log(
                'VR room cannot be rebuilt from its template ' . $vrroom->template . ' (' . $e->getMessage() . ')',
                backup::LOG_WARNING
            );
            return false;
        }

        $errors = \mod_vrroom\local\scene\scene_validator::validate_set(array_values($compiled->scenes));
        $errors = array_merge($errors, \mod_vrroom\local\graph\graph_validator::validate($compiled->graph));
        $seen = [];
        foreach ($compiled->slots as $binding) {
            $slot = (int)($binding['slot'] ?? 0);
            $objectiveid = (string)($binding['objectiveid'] ?? '');
            if (
                $slot < 1 || isset($seen[$slot]) || (int)($binding['questionbankentryid'] ?? 0) <= 0
                    || !preg_match(\mod_vrroom\local\scene\scene_validator::ID_PATTERN, $objectiveid)
            ) {
                $errors[] = 'question slot ' . $slot . ' is not valid';
            }
            $seen[$slot] = true;
        }
        if ($errors) {
            $this->log(
                'VR room template ' . $vrroom->template . ' gives no valid room (' . reset($errors) . ')',
                backup::LOG_WARNING
            );
            return false;
        }

        if (!$DB->record_exists('vrroom_graphs', ['vrroomid' => $vrroom->id, 'version' => (int)$vrroom->graphversion])) {
            // No valid graph is left: version 0 makes apply_compiled() store the template's graph.
            $vrroom->graphversion = 0;
        }
        \mod_vrroom\local\instance_manager::apply_compiled($vrroom, $context, $compiled);
        $DB->set_field('vrroom', 'customised', 0, ['id' => $vrroom->id]);
        $this->log('VR room rebuilt from its template ' . $vrroom->template, backup::LOG_WARNING);
        return true;
    }

    /**
     * Store a plain room and an empty task graph, for an activity whose template cannot rebuild it.
     *
     * @param stdClass $vrroom The restored vrroom row (graphjson and graphversion are updated in place).
     * @param bool $scenes Replace the rooms.
     * @param bool $graph Store an empty task graph as a new version.
     * @return void
     * @throws restore_step_exception When even the plain room does not validate.
     */
    protected function store_plain_room(stdClass $vrroom, bool $scenes, bool $graph): void {
        global $DB;
        if ($scenes) {
            $preset = \mod_vrroom\local\scene\presets::get('gallery-hall');
            $scene = [
                'schema' => \mod_vrroom\local\scene\scene_validator::SCHEMA,
                'id' => \core\uuid::generate(),
                'name' => trim((string)$vrroom->name) !== '' ? (string)$vrroom->name : 'VR room',
                'environment' => ['type' => 'preset', 'preset' => 'gallery-hall', 'shape' => $preset['shape'] ?? null],
                'spawn' => [['id' => 'start', 'position' => [0, 0, 0], 'yaw' => 0]],
                'zones' => [],
                'props' => [],
                'interactables' => [],
            ];
            $errors = \mod_vrroom\local\scene\scene_validator::validate_set([$scene]);
            if ($errors) {
                throw new restore_step_exception('vrroom_invalid_scene', reset($errors));
            }
            $DB->delete_records('vrroom_scenes', ['vrroomid' => $vrroom->id]);
            $DB->insert_record('vrroom_scenes', (object)[
                'vrroomid' => $vrroom->id,
                'idnumber' => $scene['id'],
                'name' => core_text::substr($scene['name'], 0, 255),
                'sortorder' => 0,
                'scenejson' => json_encode($scene, \mod_vrroom\local\instance_manager::JSON_FLAGS),
                'schemaversion' => \mod_vrroom\local\scene\scene_validator::SCHEMA,
            ]);
            // A plain room is no editor work: a later save of the form may build it from the template.
            $DB->set_field('vrroom', 'customised', 0, ['id' => $vrroom->id]);
            $this->log('VR room rooms replaced by a plain room', backup::LOG_WARNING);
        }
        if ($graph) {
            $empty = ['schema' => \mod_vrroom\local\graph\graph_validator::SCHEMA, 'objectives' => []];
            $this->store_graph($vrroom, json_encode($empty, \mod_vrroom\local\instance_manager::JSON_FLAGS));
            $this->log('VR room task graph replaced by an empty one', backup::LOG_WARNING);
        }
    }

    /**
     * Store a graph as the activity's next graph version and make it the current one.
     *
     * @param stdClass $vrroom The restored vrroom row (graphjson and graphversion are updated in place).
     * @param string $graphjson The graph document.
     * @return void
     */
    protected function store_graph(stdClass $vrroom, string $graphjson): void {
        global $DB;
        $version = (int)$DB->get_field_sql('SELECT MAX(version) FROM {vrroom_graphs} WHERE vrroomid = ?', [$vrroom->id]);
        $version = max($version, (int)$vrroom->graphversion) + 1;
        $DB->insert_record('vrroom_graphs', (object)[
            'vrroomid' => $vrroom->id,
            'version' => $version,
            'graphjson' => $graphjson,
            'timecreated' => time(),
        ]);
        $vrroom->graphjson = $graphjson;
        $vrroom->graphversion = $version;
        $DB->update_record('vrroom', (object)['id' => $vrroom->id, 'graphjson' => $graphjson, 'graphversion' => $version]);
    }
}
