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

namespace mod_mavr\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_mavr\local\instance_manager;

/**
 * Tests for the mod_mavr privacy provider.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass the course */
    private \stdClass $course;

    /** @var \stdClass the mavr row (with cmid) */
    private \stdClass $mavr;

    /** @var \context_module the activity context */
    private \context_module $context;

    /** @var \stdClass[] students keyed 1, 2 */
    private array $students = [];

    /** @var \stdClass[] attempts keyed by student key */
    private array $attempts = [];

    /**
     * Two students with an attempt (a visit and an answer) and a resume state each.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1']);

        $this->mavr = $generator->create_module('mavr', [
            'course' => $this->course->id,
            'stationtitles' => 'Vase|Portrait',
            'stationquestions' => 'Q1|',
        ]);
        $this->context = \context_module::instance($this->mavr->cmid);

        /** @var \mod_mavr_generator $mavrgen */
        $mavrgen = $generator->get_plugin_generator('mod_mavr');
        $scene = instance_manager::get_scenes((int)$this->mavr->id)[0];
        foreach ([1, 2] as $key) {
            $this->students[$key] = $generator->create_and_enrol($this->course, 'student');
            $attempt = $mavrgen->create_attempt((int)$this->mavr->id, (int)$this->students[$key]->id);
            $mavrgen->record_event($attempt, 'entered', 'zone', $scene['id'], $scene['zones'][0]['id']);
            $mavrgen->record_event(
                $attempt,
                'answered',
                'question',
                null,
                '1',
                false,
                ['fraction' => 1.0, 'state' => 'gradedright', 'mark' => 1.0, 'maxmark' => 1.0]
            );
            $this->attempts[$key] = $attempt;
            $DB->insert_record('xapi_states', (object)[
                'component' => 'mod_mavr',
                'userid' => $this->students[$key]->id,
                'itemid' => $this->context->id,
                'stateid' => 'mavr-resume',
                'statedata' => json_encode(['scene' => $scene['id'], 'position' => [0, 0, 1], 'yaw' => 0]),
                'registration' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Rows of one student, for assertions.
     *
     * @param int $key student key
     * @return array [attempts, events, usages, states]
     */
    private function counts(int $key): array {
        global $DB;
        $userid = $this->students[$key]->id;
        $attempt = $this->attempts[$key];
        return [
            $DB->count_records('mavr_attempts', ['mavrid' => $this->mavr->id, 'userid' => $userid]),
            $DB->count_records('mavr_events', ['attemptid' => $attempt->id]),
            $DB->count_records('question_usages', ['id' => $attempt->uniqueid]),
            $DB->count_records('xapi_states', ['component' => 'mod_mavr', 'userid' => $userid]),
        ];
    }

    /**
     * Metadata lists both tables and both subsystem links.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_mavr'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertEqualsCanonicalizing(['mavr_attempts', 'mavr_events', 'core_question', 'core_xapi'], $names);
    }

    /**
     * A user with an attempt, or only an xAPI state, has the module context; others none.
     */
    public function test_get_contexts_for_userid(): void {
        global $DB;
        $contextlist = provider::get_contexts_for_userid((int)$this->students[1]->id);
        $this->assertSame([(int)$this->context->id], array_values(array_map('intval', $contextlist->get_contextids())));

        $stateonly = $this->getDataGenerator()->create_user();
        $DB->insert_record('xapi_states', (object)[
            'component' => 'mod_mavr', 'userid' => $stateonly->id, 'itemid' => $this->context->id,
            'stateid' => 'mavr-resume', 'statedata' => '{}', 'registration' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $contextlist = provider::get_contexts_for_userid((int)$stateonly->id);
        $this->assertSame([(int)$this->context->id], array_values(array_map('intval', $contextlist->get_contextids())));

        $nobody = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int)$nobody->id)->get_contextids());
    }

    /**
     * Both students are found in the module context; nobody in the course context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'mod_mavr');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int)$this->students[1]->id, (int)$this->students[2]->id],
            array_map('intval', $userlist->get_userids())
        );

        $courselist = new userlist(\context_course::instance($this->course->id), 'mod_mavr');
        provider::get_users_in_context($courselist);
        $this->assertEmpty($courselist->get_userids());
    }

    /**
     * Export: attempt data, statements, the question usage and the xAPI state.
     */
    public function test_export_user_data(): void {
        $user = $this->students[1];
        $this->export_context_data_for_user((int)$user->id, $this->context, 'mod_mavr');

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $subcontext = [get_string('privacy:attemptpath', 'mod_mavr', 1)];
        $data = $writer->get_data($subcontext);
        $this->assertEquals(1, $data->attempt);
        $this->assertSame('inprogress', $data->state);

        $related = $writer->get_related_data($subcontext, 'statements');
        $verbs = array_column(array_map(fn($s) => (array)$s, $related->statements), 'verb');
        $this->assertContains('launched', $verbs);
        $this->assertContains('entered', $verbs);
        $this->assertContains('answered', $verbs);

        $questiondata = $writer->get_data(array_merge($subcontext, [get_string('questions', 'core_question'), 1]));
        $this->assertNotEmpty($questiondata);

        $state = $writer->get_data([get_string('privacy:xapistate', 'core_xapi')]);
        $this->assertNotEmpty((array)$state);
    }

    /**
     * The attempt deadline is listed in the metadata and exported: empty without a time limit,
     * as a date with one.
     */
    public function test_export_timedue(): void {
        global $DB;
        $collection = provider::get_metadata(new collection('mod_mavr'));
        $fields = [];
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'mavr_attempts') {
                $fields = $item->get_privacy_fields();
            }
        }
        $this->assertSame('privacy:metadata:mavr_attempts:timedue', $fields['timedue'] ?? null);

        $subcontext = [get_string('privacy:attemptpath', 'mod_mavr', 1)];
        $this->export_context_data_for_user((int)$this->students[1]->id, $this->context, 'mod_mavr');
        $data = writer::with_context($this->context)->get_data($subcontext);
        $this->assertTrue(property_exists($data, 'timedue'));
        $this->assertNull($data->timedue);

        $timedue = (int)$this->attempts[2]->timestart + 900;
        $DB->set_field('mavr_attempts', 'timedue', $timedue, ['id' => $this->attempts[2]->id]);
        writer::reset();
        $this->export_context_data_for_user((int)$this->students[2]->id, $this->context, 'mod_mavr');
        $data = writer::with_context($this->context)->get_data($subcontext);
        $this->assertSame(\core_privacy\local\request\transform::datetime($timedue), $data->timedue);
    }

    /**
     * Deleting a context removes every user's attempts, events, usages and states there.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->assertNotContains(0, $this->counts(1));
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertSame([0, 0, 0, 0], $this->counts(1));
        $this->assertSame([0, 0, 0, 0], $this->counts(2));
        // The activity itself stays.
        $this->assertEquals(1, $DB->count_records('mavr_slots', ['mavrid' => $this->mavr->id]));
    }

    /**
     * Deleting one user's data leaves the other user's untouched.
     */
    public function test_delete_data_for_user(): void {
        $other = $this->counts(2);
        $this->assertNotContains(0, $other);
        $contextlist = new approved_contextlist($this->students[1], 'mod_mavr', [$this->context->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame([0, 0, 0, 0], $this->counts(1));
        $this->assertSame($other, $this->counts(2));
    }

    /**
     * Deleting a userlist removes only those users' data.
     */
    public function test_delete_data_for_users(): void {
        $other = $this->counts(1);
        $this->assertNotContains(0, $other);
        $userlist = new approved_userlist($this->context, 'mod_mavr', [(int)$this->students[2]->id]);
        provider::delete_data_for_users($userlist);
        $this->assertSame($other, $this->counts(1));
        $this->assertSame([0, 0, 0, 0], $this->counts(2));
    }
}
