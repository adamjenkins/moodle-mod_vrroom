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

namespace mod_vrroom\local\questions;

/**
 * Tests for VR room question slots and their question references.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(slots::class)]
final class slots_test extends \advanced_testcase {
    /** @var \stdClass The vrroom record. */
    private \stdClass $vrroom;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass[] Three truefalse questions. */
    private array $questions = [];

    /**
     * Create a course, a bank with three questions and a VR room activity with no question slots.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        foreach (['A', 'B', 'C'] as $name) {
            $this->questions[$name] = $qgen->create_question('truefalse', null, [
                'category' => $category->id,
                'name' => 'Question ' . $name,
            ]);
        }

        $module = $generator->create_module('vrroom', ['course' => $course->id]);
        $this->vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = \context_module::instance($module->cmid);
        // Start from a clean slate whatever the default stations compiled to.
        slots::delete_all($this->vrroom, $this->context);
    }

    /**
     * A binding for a question.
     *
     * @param int $slot The graph slot.
     * @param string $name The question key (A, B, C).
     * @param float $maxmark The mark.
     * @return array The binding.
     */
    private function binding(int $slot, string $name, float $maxmark = 1.0): array {
        return [
            'slot' => $slot,
            'questionbankentryid' => (int)$this->questions[$name]->questionbankentryid,
            'maxmark' => $maxmark,
            'objectiveid' => 'q-' . $slot,
        ];
    }

    /**
     * The references of this activity, keyed by itemid.
     *
     * @return \stdClass[] Reference rows.
     */
    private function references(): array {
        global $DB;
        $rows = $DB->get_records('question_references', [
            'usingcontextid' => $this->context->id,
            'component' => 'mod_vrroom',
            'questionarea' => 'slot',
        ]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->itemid] = $row;
        }
        return $result;
    }

    /**
     * Sync creates one slot row and one quiz-style reference per binding.
     */
    public function test_sync_creates_slots_and_references(): void {
        slots::sync($this->vrroom, $this->context, [$this->binding(2, 'B', 3.0), $this->binding(1, 'A')]);

        $slots = slots::get_slots((int)$this->vrroom->id, $this->context);
        $this->assertSame([1, 2], array_keys($slots));
        $this->assertSame('q-1', $slots[1]->objectiveid);
        $this->assertEqualsWithDelta(3.0, $slots[2]->maxmark, 1e-7);
        $this->assertSame((int)$this->questions['A']->questionbankentryid, $slots[1]->questionbankentryid);
        $this->assertNull($slots[1]->version);

        $references = $this->references();
        $this->assertCount(2, $references);
        $reference = $references[$slots[2]->slotid];
        $this->assertSame((int)$this->questions['B']->questionbankentryid, (int)$reference->questionbankentryid);
        $this->assertNull($reference->version);

        // The references make core treat the questions as in use.
        $this->assertTrue(questions_in_use([$this->questions['A']->id]));
        $this->assertFalse(questions_in_use([$this->questions['C']->id]));
    }

    /**
     * Re-syncing updates in place, deletes vanished slots and adds new ones.
     */
    public function test_sync_updates_and_deletes(): void {
        slots::sync($this->vrroom, $this->context, [$this->binding(1, 'A'), $this->binding(2, 'B')]);
        $before = slots::get_slots((int)$this->vrroom->id, $this->context);

        slots::sync($this->vrroom, $this->context, [$this->binding(1, 'C', 2.0), $this->binding(3, 'B')]);
        $after = slots::get_slots((int)$this->vrroom->id, $this->context);

        $this->assertSame([1, 3], array_keys($after));
        // Slot 1 kept its row, so its reference itemid is unchanged.
        $this->assertSame($before[1]->slotid, $after[1]->slotid);
        $this->assertSame((int)$this->questions['C']->questionbankentryid, $after[1]->questionbankentryid);
        $this->assertEqualsWithDelta(2.0, $after[1]->maxmark, 1e-7);

        $references = $this->references();
        $this->assertCount(2, $references);
        $this->assertArrayNotHasKey($before[2]->slotid, $references, 'The vanished slot\'s reference is deleted.');
        $this->assertArrayHasKey($after[3]->slotid, $references);
        $this->assertFalse(questions_in_use([$this->questions['A']->id]));

        // Syncing to nothing removes everything.
        slots::sync($this->vrroom, $this->context, []);
        $this->assertSame([], slots::get_slots((int)$this->vrroom->id, $this->context));
        $this->assertSame([], $this->references());
    }

    /**
     * A slot number bound twice is a programming error.
     */
    public function test_sync_rejects_duplicate_slot(): void {
        $this->expectException(\coding_exception::class);
        slots::sync($this->vrroom, $this->context, [$this->binding(1, 'A'), $this->binding(1, 'B')]);
    }

    /**
     * A slot below 1 is a programming error.
     */
    public function test_sync_rejects_bad_slot_number(): void {
        $this->expectException(\coding_exception::class);
        slots::sync($this->vrroom, $this->context, [$this->binding(0, 'A')]);
    }

    /**
     * delete_all removes every slot and reference of the activity, and only of it.
     */
    public function test_delete_all(): void {
        global $DB;

        // A second activity whose references must survive.
        $othermodule = $this->getDataGenerator()->create_module('vrroom', ['course' => $this->vrroom->course]);
        $othervrroom = $DB->get_record('vrroom', ['id' => $othermodule->id], '*', MUST_EXIST);
        $othercontext = \context_module::instance($othermodule->cmid);
        slots::sync($othervrroom, $othercontext, [$this->binding(1, 'C')]);

        slots::sync($this->vrroom, $this->context, [$this->binding(1, 'A'), $this->binding(2, 'B')]);
        slots::delete_all($this->vrroom, $this->context);

        $this->assertFalse($DB->record_exists('vrroom_slots', ['vrroomid' => $this->vrroom->id]));
        $this->assertSame([], $this->references());
        $this->assertCount(1, slots::get_slots((int)$othervrroom->id, $othercontext));
        $this->assertTrue($DB->record_exists('question_references', [
            'usingcontextid' => $othercontext->id,
            'component' => 'mod_vrroom',
        ]));
    }
}
