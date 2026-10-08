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
 */
class restore_vrroom_activity_structure_step extends restore_questions_activity_structure_step {
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
     * would collide with the original rows, so a colliding id gets a fresh uuid.
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
        $data->statementid = strtolower((string)$data->statementid);
        if ($data->statementid === '' || $DB->record_exists('vrroom_events', ['statementid' => $data->statementid])) {
            $data->statementid = strtolower(\core\uuid::generate());
        }
        $DB->insert_record('vrroom_events', $data);
    }

    /**
     * Restore the file areas and let the template remap the question bank entries named in its
     * stored config.
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
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroomid], 'id, template, templateconfig');
        if (!$vrroom || $vrroom->templateconfig === null || $vrroom->templateconfig === '') {
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
            $DB->set_field(
                'vrroom',
                'templateconfig',
                json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ['id' => $vrroomid]
            );
        }
    }
}
