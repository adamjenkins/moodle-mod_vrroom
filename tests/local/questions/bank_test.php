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

namespace mod_mavr\local\questions;

use core_question\local\bank\question_version_status;

/**
 * Tests for the MAVR question picker and entry checks.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(bank::class)]
final class bank_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \context_module The question bank module context. */
    private \context_module $bankcontext;

    /** @var \stdClass The question category. */
    private \stdClass $category;

    /** @var \core_question_generator The question generator. */
    private \core_question_generator $qgen;

    /**
     * Create a course with one named question bank and one category.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $this->course->id, 'name' => 'Bank 1']);
        $this->bankcontext = \context_module::instance($qbank->cmid);
        $this->qgen = $generator->get_plugin_generator('core_question');
        $this->category = $this->qgen->create_question_category([
            'contextid' => $this->bankcontext->id,
            'name' => 'Cat A',
        ]);
    }

    /**
     * Create a question in the test category.
     *
     * @param string $qtype The question type.
     * @param array $overrides Field overrides.
     * @return \stdClass The question data (with questionbankentryid).
     */
    private function make_question(string $qtype, array $overrides = []): \stdClass {
        return $this->qgen->create_question($qtype, null, ['category' => $this->category->id] + $overrides);
    }

    /**
     * The picker lists usable questions with a "Bank: Category: Question" label.
     */
    public function test_get_choices_lists_usable_questions_only(): void {
        global $DB;
        $this->setAdminUser();

        $tf = $this->make_question('truefalse', ['name' => 'TF one']);
        $essay = $this->make_question('essay', ['name' => 'Essay one']);
        $draft = $this->make_question('truefalse', [
            'name' => 'Draft only',
            'status' => question_version_status::QUESTION_STATUS_DRAFT,
        ]);
        $hidden = $this->make_question('truefalse', ['name' => 'Hidden one']);
        $DB->set_field(
            'question_versions',
            'status',
            question_version_status::QUESTION_STATUS_HIDDEN,
            ['questionid' => $hidden->id]
        );

        $choices = bank::get_choices((int)$this->course->id);

        $this->assertArrayHasKey((int)$tf->questionbankentryid, $choices);
        $this->assertSame('Bank 1: Cat A: TF one', $choices[(int)$tf->questionbankentryid]);
        $this->assertArrayNotHasKey((int)$essay->questionbankentryid, $choices, 'Manually graded types are excluded.');
        $this->assertArrayNotHasKey((int)$draft->questionbankentryid, $choices, 'Draft-only entries are excluded.');
        $this->assertArrayNotHasKey((int)$hidden->questionbankentryid, $choices, 'Hidden questions are excluded.');
    }

    /**
     * A newer draft version does not hide the entry: the latest ready version is used.
     */
    public function test_newer_draft_version_keeps_latest_ready_version(): void {
        $this->setAdminUser();

        $v1 = $this->make_question('truefalse', ['name' => 'Versioned']);
        $v2 = $this->qgen->update_question($v1, null, [
            'name' => 'Versioned draft',
            'status' => question_version_status::QUESTION_STATUS_DRAFT,
        ]);
        $this->assertSame((int)$v1->questionbankentryid, (int)$v2->questionbankentryid);

        $choices = bank::get_choices((int)$this->course->id);
        $this->assertSame('Bank 1: Cat A: Versioned', $choices[(int)$v1->questionbankentryid]);
        $this->assertSame((int)$v1->id, bank::resolve_question_id((int)$v1->questionbankentryid));
        $this->assertSame((int)$v2->id, bank::resolve_question_id((int)$v1->questionbankentryid, (int)$v2->version));
        $this->assertNull(bank::resolve_question_id((int)$v1->questionbankentryid, 99));
        $this->assertNull(bank::resolve_question_id(0));
    }

    /**
     * Names are passed through format_string, so markup cannot reach the picker.
     */
    public function test_get_choices_formats_names(): void {
        $this->setAdminUser();
        $q = $this->make_question('truefalse', ['name' => '<b>Bold</b> & name']);

        $choices = bank::get_choices((int)$this->course->id);

        $label = $choices[(int)$q->questionbankentryid];
        $this->assertStringNotContainsString('<b>', $label);
        $this->assertStringContainsString('Bold &amp; name', $label);
    }

    /**
     * A user who may only use their own questions sees only those.
     */
    public function test_get_choices_respects_usemine(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('moodle/question:usemine', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_course::instance($this->course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setAdminUser();
        $theirs = $this->make_question('truefalse', ['name' => 'Theirs', 'createdby' => $user->id]);
        $others = $this->make_question('truefalse', ['name' => 'Someone else\'s']);

        $this->setUser($user);
        $this->assertTrue(has_capability('moodle/question:usemine', $this->bankcontext));
        $this->assertFalse(has_capability('moodle/question:useall', $this->bankcontext));

        $choices = bank::get_choices((int)$this->course->id);
        $this->assertArrayHasKey((int)$theirs->questionbankentryid, $choices);
        $this->assertArrayNotHasKey((int)$others->questionbankentryid, $choices);

        $this->assertNull(bank::validate_entry((int)$theirs->questionbankentryid, (int)$this->course->id));
        $this->assertSame(
            'errorquestionnotallowed',
            bank::validate_entry((int)$others->questionbankentryid, (int)$this->course->id)
        );
    }

    /**
     * A user without any use capability gets no choices at all.
     */
    public function test_get_choices_empty_without_capability(): void {
        $this->setAdminUser();
        $this->make_question('truefalse');

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $this->assertSame([], bank::get_choices((int)$this->course->id));
    }

    /**
     * validate_entry() reports each failure with its own lang string key.
     */
    public function test_validate_entry(): void {
        global $DB;
        $this->setAdminUser();
        $courseid = (int)$this->course->id;

        $tf = $this->make_question('truefalse');
        $essay = $this->make_question('essay');
        $hidden = $this->make_question('truefalse');
        $DB->set_field(
            'question_versions',
            'status',
            question_version_status::QUESTION_STATUS_HIDDEN,
            ['questionid' => $hidden->id]
        );
        $draft = $this->make_question('truefalse', ['status' => question_version_status::QUESTION_STATUS_DRAFT]);

        $this->assertNull(bank::validate_entry((int)$tf->questionbankentryid, $courseid));
        $this->assertSame('errorquestionnotfound', bank::validate_entry(0, $courseid));
        $this->assertSame('errorquestionnotfound', bank::validate_entry(987654321, $courseid));
        $this->assertSame('errorquestionnotfound', bank::validate_entry((int)$draft->questionbankentryid, $courseid));
        $this->assertSame('errorquestiontype', bank::validate_entry((int)$essay->questionbankentryid, $courseid));
        $this->assertSame('errorquestionnotfound', bank::validate_entry((int)$hidden->questionbankentryid, $courseid));
        // A hidden question already bound to the activity may stay.
        $this->assertNull(bank::validate_entry(
            (int)$hidden->questionbankentryid,
            $courseid,
            [(int)$hidden->questionbankentryid]
        ));

        // A student may not use the question, unless it is already on the activity.
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->assertSame('errorquestionnotallowed', bank::validate_entry((int)$tf->questionbankentryid, $courseid));
        $this->assertNull(bank::validate_entry((int)$tf->questionbankentryid, $courseid, [(int)$tf->questionbankentryid]));
        // Keeping never excuses a type that is not allowed.
        $this->assertSame('errorquestiontype', bank::validate_entry(
            (int)$essay->questionbankentryid,
            $courseid,
            [(int)$essay->questionbankentryid]
        ));
    }

    /**
     * Only installed, real, automatically graded types are allowed.
     */
    public function test_allowed_qtype(): void {
        $this->assertTrue(bank::allowed_qtype('truefalse'));
        $this->assertTrue(bank::allowed_qtype('multichoice'));
        $this->assertTrue(bank::allowed_qtype('shortanswer'));
        $this->assertFalse(bank::allowed_qtype('essay'));
        $this->assertFalse(bank::allowed_qtype('description'));
        $this->assertFalse(bank::allowed_qtype('random'));
        $this->assertFalse(bank::allowed_qtype('missingtype'));
        $this->assertFalse(bank::allowed_qtype('nosuchqtype'));
        $this->assertFalse(bank::allowed_qtype(''));
    }
}
