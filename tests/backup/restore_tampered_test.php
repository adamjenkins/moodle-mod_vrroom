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

namespace mod_vrroom\backup;

use backup;
use backup_controller;
use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\live_settings;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\scene\scene_validator;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');
require_once($CFG->dirroot . '/mod/vrroom/backup/moodle2/backup_vrroom_activity_task.class.php');
require_once($CFG->dirroot . '/mod/vrroom/backup/moodle2/restore_vrroom_activity_task.class.php');

/**
 * Restore of a tampered vrroom.xml: the restore completes and leaves a valid activity.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_vrroom_activity_structure_step::class)]
final class restore_tampered_test extends \advanced_testcase {
    /** @var string A statement id as the xAPI handler accepts it. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * A course with a one-question gallery walk and a student attempt with two zone visits.
     *
     * @return array [course, vrroom row, cm, student]
     */
    private function create_setup(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $module = $generator->create_module('vrroom', [
            'course' => $course->id,
            'name' => 'Museum walk',
            'stationtitles' => 'Vase|Portrait',
            'stationquestions' => 'Q1|',
        ]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('vrroom', $module->cmid, 0, false, MUST_EXIST);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$student->id);
        $scene = instance_manager::get_scenes((int)$vrroom->id)[0];
        $vrroomgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
        $vrroomgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][1]['id'] ?? $scene['zones'][0]['id']);

        return [$course, $vrroom, $cm, $student];
    }

    /**
     * Back up a course with user data, let $tamper edit the activity's vrroom.xml, restore it into a new course.
     *
     * @param \stdClass $course the course
     * @param \stdClass $cm the VR room course module
     * @param callable $tamper function(\DOMDocument $xml): void
     * @return int new course id
     */
    private function backup_tamper_restore(\stdClass $course, \stdClass $cm, callable $tamper): int {
        global $CFG, $USER;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $activitysetting = $bc->get_plan()->get_setting('vrroom_' . $cm->id . '_userinfo');
        $activitysetting->set_status(\backup_setting::NOT_LOCKED);
        $activitysetting->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // An import backup is left unpacked in the temp directory.
        $file = $CFG->backuptempdir . '/' . $backupid . '/activities/vrroom_' . $cm->id . '/vrroom.xml';
        $this->assertFileExists($file);
        $xml = new \DOMDocument();
        $xml->load($file);
        $tamper($xml);
        $xml->save($file);

        $newcourseid = \restore_dbops::create_new_course('Restored course', 'restored_' . $course->id, $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return (int)$newcourseid;
    }

    /**
     * The first element of a name under a node.
     *
     * @param \DOMDocument|\DOMElement $node where to look
     * @param string $name element name
     * @return \DOMElement
     */
    private function element($node, string $name): \DOMElement {
        $element = $node->getElementsByTagName($name)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element, "no <{$name}> in vrroom.xml");
        return $element;
    }

    /**
     * Set the text of a child element.
     *
     * @param \DOMElement $parent the parent element
     * @param string $name child element name
     * @param string $value new text
     * @return void
     */
    private function set_child(\DOMElement $parent, string $name, string $value): void {
        $this->element($parent, $name)->textContent = $value;
    }

    /**
     * The restored VR room row of a course.
     *
     * @param int $courseid course id
     * @return \stdClass
     */
    private function get_restored(int $courseid): \stdClass {
        global $DB;
        $instances = get_fast_modinfo($courseid)->get_instances_of('vrroom');
        $this->assertCount(1, $instances);
        return $DB->get_record('vrroom', ['id' => reset($instances)->instance], '*', MUST_EXIST);
    }

    /**
     * Assert that the stored rooms and the current graph pass the validators.
     *
     * @param \stdClass $vrroom the restored row
     * @return void
     */
    private function assert_room_valid(\stdClass $vrroom): void {
        global $DB;
        $this->assertSame([], scene_validator::validate_set(instance_manager::get_scenes((int)$vrroom->id)));
        $graph = instance_manager::get_graph((int)$vrroom->id);
        $this->assertSame([], graph_validator::validate($graph));
        $this->assertSame(
            $vrroom->graphjson,
            $DB->get_field('vrroom_graphs', 'graphjson', ['vrroomid' => $vrroom->id, 'version' => $vrroom->graphversion])
        );
    }

    /**
     * Broken and over-limit scenes, a graph with negative points, a repeated scene, slot and attempt,
     * statement ids that are not uuids and out-of-range settings: the restore completes, the room is
     * rebuilt from its template and every value is cleaned.
     */
    public function test_restore_tampered_backup(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $vrroom, $cm, $student] = $this->create_setup();
        // The visits plus the statements the attempt records itself (launched, completed).
        $sourceevents = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {vrroom_events} e JOIN {vrroom_attempts} a ON a.id = e.attemptid WHERE a.vrroomid = ?',
            [$vrroom->id]
        );

        $newcourseid = $this->backup_tamper_restore($course, $cm, function (\DOMDocument $xml): void {
            $vrroom = $this->element($xml, 'vrroom');
            $this->set_child($vrroom, 'livemode', '7');
            $this->set_child($vrroom, 'liverooms', '999');
            $this->set_child($vrroom, 'livemaxusers', '1000');
            $this->set_child($vrroom, 'questionmode', '<b>evil</b>');
            $this->set_child($vrroom, 'retrypenalty', '5');
            $this->set_child($vrroom, 'grade', '-3');
            $this->set_child($vrroom, 'grademethod', '9');
            $this->set_child($vrroom, 'maxattempts', '-1');

            // Negative points in every graph version and in the activity's own copy.
            $negative = function (string $json): string {
                $graph = json_decode($json, true);
                foreach ($graph['objectives'] as $i => $objective) {
                    $graph['objectives'][$i]['points'] = -5;
                }
                return json_encode($graph);
            };
            $this->set_child($vrroom, 'graphjson', $negative($this->element($vrroom, 'graphjson')->textContent));
            foreach ($xml->getElementsByTagName('graph') as $graph) {
                $this->set_child($graph, 'graphjson', $negative($this->element($graph, 'graphjson')->textContent));
            }

            // A repeat of the room, a room that is not JSON and a room over the interactable limit.
            $scenes = $this->element($xml, 'scenes');
            $scene = $this->element($scenes, 'scene');
            $repeat = $scene->cloneNode(true);
            $repeat->setAttribute('id', '900001');
            $scenes->appendChild($repeat);
            $broken = $scene->cloneNode(true);
            $broken->setAttribute('id', '900002');
            $this->set_child($broken, 'scenejson', '{"schema": "vrroom-scene/1", "id": ');
            $scenes->appendChild($broken);
            $big = json_decode($this->element($scene, 'scenejson')->textContent, true);
            $big['id'] = 'overlimit';
            $big['interactables'] = [];
            for ($i = 0; $i <= scene_validator::MAX_INTERACTABLES; $i++) {
                $big['interactables'][] = ['id' => 'x' . $i, 'kind' => 'text', 'position' => [0, 1, 0], 'text' => 'x'];
            }
            $overlimit = $scene->cloneNode(true);
            $overlimit->setAttribute('id', '900003');
            $this->set_child($overlimit, 'idnumber', 'overlimit');
            $this->set_child($overlimit, 'scenejson', json_encode($big));
            $scenes->appendChild($overlimit);

            // A repeat of the question slot (with its question reference).
            $slots = $this->element($xml, 'slots');
            $slot = $slots->appendChild($this->element($slots, 'slot')->cloneNode(true));
            $slot->setAttribute('id', '900004');

            // Statement ids that are not uuids, and a repeat of the attempt (user and number).
            $events = $xml->getElementsByTagName('event');
            $this->set_child($events->item(0), 'statementid', 'not-a-uuid');
            $this->set_child($events->item(1), 'statementid', str_repeat('A', 60));
            $attempts = $this->element($xml, 'attempts');
            $attempt = $attempts->appendChild($this->element($attempts, 'attempt')->cloneNode(true));
            $attempt->setAttribute('id', '900005');
        });

        $new = $this->get_restored($newcourseid);

        // Settings are cleaned as the form cleans them.
        $this->assertSame(live_settings::MODE_OFF, (int)$new->livemode);
        $this->assertSame(live_settings::MAX_ROOMS, (int)$new->liverooms);
        $this->assertSame(50, (int)$new->livemaxusers);
        $this->assertSame(mode::ONCE, $new->questionmode);
        $this->assertContainsEquals((float)$new->retrypenalty, mode::PENALTIES);
        $this->assertSame(0, (int)$new->grade);
        $this->assertSame(\mod_vrroom\local\grading\grader::GRADE_HIGHEST, (int)$new->grademethod);
        $this->assertSame(0, (int)$new->maxattempts);

        // The room was rebuilt from its template: one valid room and a valid current graph.
        $this->assert_room_valid($new);
        $this->assertSame(1, $DB->count_records('vrroom_scenes', ['vrroomid' => $new->id]));
        $this->assertFalse($DB->record_exists('vrroom_scenes', ['vrroomid' => $new->id, 'idnumber' => 'overlimit']));
        foreach ($DB->get_records('vrroom_graphs', ['vrroomid' => $new->id]) as $row) {
            $this->assertSame([], graph_validator::validate(json_decode($row->graphjson, true)));
        }

        // One slot and one question reference.
        $slots = $DB->get_records('vrroom_slots', ['vrroomid' => $new->id]);
        $this->assertCount(1, $slots);
        $slot = reset($slots);
        $this->assertEquals(1, $slot->slot);
        $this->assertSame(1, $DB->count_records(
            'question_references',
            ['component' => 'mod_vrroom', 'questionarea' => 'slot', 'itemid' => $slot->id]
        ));

        // One attempt, played under a graph version that exists; every statement id is a uuid.
        $attempts = $DB->get_records('vrroom_attempts', ['vrroomid' => $new->id]);
        $this->assertCount(1, $attempts);
        $attempt = reset($attempts);
        $this->assertEquals($student->id, $attempt->userid);
        $this->assertTrue($DB->record_exists('vrroom_graphs', ['vrroomid' => $new->id, 'version' => $attempt->graphversion]));
        $events = $DB->get_records('vrroom_events', ['attemptid' => $attempt->id]);
        $this->assertCount($sourceevents, $events);
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, $event->statementid);
        }
    }

    /**
     * A broken room of a template this site does not have, with template settings that are not JSON:
     * a plain room and an empty task graph are stored instead, and the settings are dropped.
     */
    public function test_restore_tampered_backup_without_template(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $cm] = $this->create_setup();

        $newcourseid = $this->backup_tamper_restore($course, $cm, function (\DOMDocument $xml): void {
            $vrroom = $this->element($xml, 'vrroom');
            $this->set_child($vrroom, 'template', 'nosuchtemplate');
            $this->set_child($vrroom, 'templateconfig', '{not json');
            $this->set_child($vrroom, 'graphversion', '42');
            $this->set_child($vrroom, 'graphjson', '[1, 2');
            foreach ($xml->getElementsByTagName('graph') as $graph) {
                $this->set_child($graph, 'graphjson', '{"schema": "vrroom-graph/1", "objectives": "none"}');
            }
            foreach ($xml->getElementsByTagName('scene') as $scene) {
                $this->set_child($scene, 'scenejson', '"just a string"');
            }
        });

        $new = $this->get_restored($newcourseid);
        $this->assertNull($new->templateconfig);
        $this->assert_room_valid($new);
        $this->assertSame(1, $DB->count_records('vrroom_scenes', ['vrroomid' => $new->id]));
        $this->assertSame([], instance_manager::get_graph((int)$new->id)['objectives']);
        $attempt = $DB->get_record('vrroom_attempts', ['vrroomid' => $new->id], '*', MUST_EXIST);
        $this->assertEquals($new->graphversion, $attempt->graphversion);
    }
}
