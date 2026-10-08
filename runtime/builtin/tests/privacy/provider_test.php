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

namespace vrroomruntime_builtin\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider of the built-in run mode (live rooms).
 *
 * @package    vrroomruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string This component. */
    private const COMPONENT = 'vrroomruntime_builtin';

    /** @var \stdClass the activity with two people in its live room */
    private \stdClass $vrroom;

    /** @var \stdClass another activity with one person in its live room */
    private \stdClass $othervrroom;

    /** @var \context_module context of $vrroom */
    private \context_module $context;

    /** @var \context_module context of $othervrroom */
    private \context_module $othercontext;

    /** @var \stdClass[] users keyed 1, 2 (in $vrroom) and 3 (in $othervrroom) */
    private array $users = [];

    /** @var \stdClass[] peer rows keyed like $users */
    private array $peers = [];

    /**
     * Two activities: students 1 and 2 in the room of the first (with messages both ways and a
     * leftover message of a presence already gone), student 3 in the room of the second.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->vrroom = $generator->create_module('vrroom', ['course' => $course->id, 'livemode' => 1]);
        $this->othervrroom = $generator->create_module('vrroom', ['course' => $course->id, 'livemode' => 1]);
        $this->context = \context_module::instance($this->vrroom->cmid);
        $this->othercontext = \context_module::instance($this->othervrroom->cmid);

        /** @var \vrroomruntime_builtin_generator $livegen */
        $livegen = $generator->get_plugin_generator(self::COMPONENT);
        foreach ([1, 2, 3] as $key) {
            $this->users[$key] = $generator->create_and_enrol($course, 'student');
            $vrroomid = $key === 3 ? $this->othervrroom->id : $this->vrroom->id;
            $this->peers[$key] = $livegen->create_peer(['vrroomid' => $vrroomid, 'userid' => $this->users[$key]->id,
                'room' => $key + 1]);
        }

        $livegen->create_signal([
            'vrroomid' => $this->vrroom->id,
            'frompeer' => $this->peers[1]->peerid,
            'topeer' => $this->peers[2]->peerid,
            'type' => 'description',
            'payload' => '{"gen":1,"type":"offer","sdp":"v=0\r\n"}',
        ]);
        $livegen->create_signal([
            'vrroomid' => $this->vrroom->id,
            'frompeer' => $this->peers[2]->peerid,
            'topeer' => $this->peers[1]->peerid,
        ]);
        // A message of a presence that has already gone.
        $livegen->create_signal([
            'vrroomid' => $this->vrroom->id,
            'frompeer' => str_repeat('a', 32),
            'topeer' => str_repeat('b', 32),
        ]);
        $livegen->create_signal([
            'vrroomid' => $this->othervrroom->id,
            'frompeer' => $this->peers[3]->peerid,
            'topeer' => str_repeat('c', 32),
        ]);
    }

    /**
     * Rows of one user: [presences, messages sent, messages received].
     *
     * @param int $key user key
     * @return int[]
     */
    private function counts(int $key): array {
        global $DB;
        $peerid = $this->peers[$key]->peerid;
        return [
            $DB->count_records('vrroomruntime_builtin_peers', ['userid' => $this->users[$key]->id]),
            $DB->count_records('vrroomruntime_builtin_signals', ['frompeer' => $peerid]),
            $DB->count_records('vrroomruntime_builtin_signals', ['topeer' => $peerid]),
        ];
    }

    /**
     * Metadata: the two tables and the two external locations, with every field described.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection(self::COMPONENT));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }
        $this->assertEqualsCanonicalizing(
            ['vrroomruntime_builtin_peers', 'vrroomruntime_builtin_signals', 'livepeers', 'iceservers'],
            array_keys($items)
        );
        $this->assertInstanceOf(
            \core_privacy\local\metadata\types\database_table::class,
            $items['vrroomruntime_builtin_peers']
        );
        $this->assertInstanceOf(\core_privacy\local\metadata\types\external_location::class, $items['livepeers']);
        $this->assertEqualsCanonicalizing(
            ['userid', 'groupid', 'room', 'timejoined', 'timeseen'],
            array_keys($items['vrroomruntime_builtin_peers']->get_privacy_fields())
        );
        $this->assertEqualsCanonicalizing(
            ['frompeer', 'topeer', 'payload', 'timecreated'],
            array_keys($items['vrroomruntime_builtin_signals']->get_privacy_fields())
        );
        $this->assertEqualsCanonicalizing(
            ['fullname', 'ipaddress', 'pose', 'voice'],
            array_keys($items['livepeers']->get_privacy_fields())
        );
        $this->assertSame(['ipaddress'], array_keys($items['iceservers']->get_privacy_fields()));

        // Every string exists.
        $strings = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($strings->string_exists($item->get_summary(), self::COMPONENT), $item->get_summary());
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($strings->string_exists($identifier, self::COMPONENT), $identifier);
            }
        }
    }

    /**
     * A user in a live room has that activity's context; anyone else none.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid((int)$this->users[1]->id);
        $this->assertSame([(int)$this->context->id], array_values(array_map('intval', $contextlist->get_contextids())));

        $contextlist = provider::get_contexts_for_userid((int)$this->users[3]->id);
        $this->assertSame(
            [(int)$this->othercontext->id],
            array_values(array_map('intval', $contextlist->get_contextids()))
        );

        $nobody = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int)$nobody->id)->get_contextids());
    }

    /**
     * The users in a context are those in its live room; nobody in a course context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int)$this->users[1]->id, (int)$this->users[2]->id],
            array_map('intval', $userlist->get_userids())
        );

        $courselist = new userlist(\context_course::instance($this->vrroom->course), self::COMPONENT);
        provider::get_users_in_context($courselist);
        $this->assertEmpty($courselist->get_userids());
    }

    /**
     * Export: the presence and only the messages the user's browser sent.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user((int)$this->users[1]->id, $this->context, self::COMPONENT);

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('privacy:livepath', self::COMPONENT)]);

        $this->assertCount(1, $data->presence);
        $presence = $data->presence[0];
        $this->assertSame(0, $presence->groupid);
        $this->assertSame(2, $presence->room);
        $this->assertSame('attempt', $presence->kind);
        $this->assertSame(transform::datetime($this->peers[1]->timejoined), $presence->timejoined);
        $this->assertSame(transform::datetime($this->peers[1]->timeseen), $presence->timeseen);

        $this->assertCount(1, $data->signals);
        $this->assertSame('description', $data->signals[0]->type);
        $this->assertStringContainsString('"offer"', $data->signals[0]->payload);

        // Nothing is exported for another activity's context.
        writer::reset();
        $this->export_context_data_for_user((int)$this->users[1]->id, $this->othercontext, self::COMPONENT);
        $this->assertFalse(writer::with_context($this->othercontext)->has_any_data());
    }

    /**
     * Deleting a context removes every presence and message of that activity only.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_peers', ['vrroomid' => $this->vrroom->id]));
        $this->assertSame(0, $DB->count_records('vrroomruntime_builtin_signals', ['vrroomid' => $this->vrroom->id]));
        $this->assertSame([1, 1, 0], $this->counts(3));

        // A course context is ignored.
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->vrroom->course));
        $this->assertSame([1, 1, 0], $this->counts(3));
    }

    /**
     * Deleting one user's data removes the presence and the messages from and to it.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $contextlist = new approved_contextlist($this->users[1], self::COMPONENT, [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame([0, 0, 0], $this->counts(1));
        // User 2's presence stays; the messages between 1 and 2 went with user 1's presence.
        $this->assertSame([1, 0, 0], $this->counts(2));
        $this->assertSame([1, 1, 0], $this->counts(3));
        // The leftover message of a gone presence is nobody's in particular and stays.
        $this->assertSame(1, $DB->count_records('vrroomruntime_builtin_signals', ['frompeer' => str_repeat('a', 32)]));
    }

    /**
     * Deleting a userlist removes only those users' data in that context.
     */
    public function test_delete_data_for_users(): void {
        $userlist = new approved_userlist($this->context, self::COMPONENT, [(int)$this->users[2]->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame([1, 0, 0], $this->counts(1));
        $this->assertSame([0, 0, 0], $this->counts(2));
        $this->assertSame([1, 1, 0], $this->counts(3));

        // User 3 is not in this context: nothing of theirs is touched.
        $userlist = new approved_userlist($this->context, self::COMPONENT, [(int)$this->users[3]->id]);
        provider::delete_data_for_users($userlist);
        $this->assertSame([1, 1, 0], $this->counts(3));
    }
}
