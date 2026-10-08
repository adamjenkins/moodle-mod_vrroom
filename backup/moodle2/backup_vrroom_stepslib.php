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
 * Backup structure step for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the structure of vrroom.xml.
 *
 * Activity, scenes, every graph version and the question slots (each with its core question
 * reference) always; with user data, attempts with their question usages and statements.
 * Two columns are written under other names so no XML element contains a child of its own
 * name: vrroom_slots.slot as slotnumber and vrroom_attempts.attempt as attemptnum (as mod_quiz).
 */
class backup_vrroom_activity_structure_step extends backup_questions_activity_structure_step {
    /**
     * Build the element tree.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $vrroom = new backup_nested_element('vrroom', ['id'], [
            'name', 'intro', 'introformat', 'runtime', 'template', 'templateconfig', 'graphjson',
            'graphversion', 'grade', 'grademethod', 'maxattempts', 'questionmode', 'retrypenalty', 'completionobjectives',
            'customised', 'livemode', 'livemaxusers', 'liverooms', 'timecreated', 'timemodified',
        ]);

        $scenes = new backup_nested_element('scenes');
        $scene = new backup_nested_element('scene', ['id'], [
            'idnumber', 'name', 'sortorder', 'scenejson', 'schemaversion',
        ]);

        $graphs = new backup_nested_element('graphs');
        $graph = new backup_nested_element('graph', ['id'], [
            'version', 'graphjson', 'timecreated',
        ]);

        $slots = new backup_nested_element('slots');
        $slot = new backup_nested_element('slot', ['id'], [
            'slotnumber', 'objectiveid', 'maxmark',
        ]);
        // The reference's itemid is the slot id, read from the parent element (as quiz_slots).
        $this->add_question_references($slot, 'mod_vrroom', 'slot');

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attemptnum', 'state', 'uniqueid', 'slotmap', 'graphversion', 'score',
            'requireddone', 'timestart', 'timefinish', 'timedue', 'timemodified',
        ]);
        // Added before the statements, so each usage precedes its attempt's events in the XML
        // and the restored attempt exists (it is inserted with its new usage id) before them.
        $this->add_question_usages($attempt, 'uniqueid');

        $events = new backup_nested_element('events');
        $event = new backup_nested_element('event', ['id'], [
            'statementid', 'verb', 'objecttype', 'sceneid', 'objectid', 'resultjson',
            'clientasserted', 'timestamp', 'timecreated',
        ]);

        // Build the tree.
        $vrroom->add_child($scenes);
        $scenes->add_child($scene);
        $vrroom->add_child($graphs);
        $graphs->add_child($graph);
        $vrroom->add_child($slots);
        $slots->add_child($slot);
        $vrroom->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($events);
        $events->add_child($event);

        // Sources.
        $vrroom->set_source_table('vrroom', ['id' => backup::VAR_ACTIVITYID]);
        $scene->set_source_table('vrroom_scenes', ['vrroomid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        $graph->set_source_table('vrroom_graphs', ['vrroomid' => backup::VAR_PARENTID], 'version ASC');
        $slot->set_source_table('vrroom_slots', ['vrroomid' => backup::VAR_PARENTID], 'slot ASC');
        $slot->set_source_alias('slot', 'slotnumber');

        if ($userinfo) {
            $attempt->set_source_table('vrroom_attempts', ['vrroomid' => backup::VAR_PARENTID], 'id ASC');
            $attempt->set_source_alias('attempt', 'attemptnum');
            $event->set_source_table('vrroom_events', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }

        // Id annotations.
        $attempt->annotate_ids('user', 'userid');

        // File annotations (none of these areas is keyed by an element id).
        $vrroom->annotate_files('mod_vrroom', 'intro', null);
        $vrroom->annotate_files('mod_vrroom', 'models', null);
        $vrroom->annotate_files('mod_vrroom', 'media', null);
        $vrroom->annotate_files('mod_vrroom', 'environment', null);
        $vrroom->annotate_files('mod_vrroom', 'assets', null);

        return $this->prepare_activity_structure($vrroom);
    }
}
