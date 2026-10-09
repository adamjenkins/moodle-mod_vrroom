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
 * Tests for who may fetch the question files of a VR room attempt.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(files::class)]
final class files_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity's course module. */
    private \stdClass $cm;

    /** @var \stdClass The attempt of the student in group A. */
    private \stdClass $attempt;

    /** @var \stdClass The student in group A who owns the attempt. */
    private \stdClass $owner;

    /** @var \stdClass Another student, in group A too. */
    private \stdClass $otherstudent;

    /** @var \stdClass A non-editing teacher in group A. */
    private \stdClass $teachersame;

    /** @var \stdClass A non-editing teacher in group B. */
    private \stdClass $teacherother;

    /** @var \stdClass An editing teacher (has "access all groups") in no group. */
    private \stdClass $editingteacher;

    /**
     * An activity in separate groups with groups A and B, and an attempt by a student in group A.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $groupa = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Group B']);
        $this->owner = $generator->create_and_enrol($this->course, 'student');
        $this->otherstudent = $generator->create_and_enrol($this->course, 'student');
        $this->teachersame = $generator->create_and_enrol($this->course, 'teacher');
        $this->teacherother = $generator->create_and_enrol($this->course, 'teacher');
        $this->editingteacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->owner->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->otherstudent->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teachersame->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->teacherother->id]);

        $module = $generator->create_module('vrroom', ['course' => $this->course->id, 'groupmode' => SEPARATEGROUPS]);
        $vrroom = $DB->get_record('vrroom', ['id' => $module->id], '*', MUST_EXIST);
        // The same lookup serve_question_file() makes.
        $this->cm = get_coursemodule_from_instance('vrroom', $vrroom->id, 0, false, MUST_EXIST);

        /** @var \mod_vrroom_generator $vrroomgen */
        $vrroomgen = $generator->get_plugin_generator('mod_vrroom');
        $this->attempt = $vrroomgen->create_attempt((int)$vrroom->id, (int)$this->owner->id);
    }

    /**
     * The owner of an open attempt may fetch its files, without feedback.
     */
    public function test_owner_of_open_attempt_allowed(): void {
        $this->setUser($this->owner);
        $this->assertSame('inprogress', $this->attempt->state);
        $this->assertSame(files::ACCESS_OWN, files::get_access($this->attempt, $this->cm, $this->course));
    }

    /**
     * The owner of a closed attempt is a reviewer and needs the report capability, which a student lacks.
     */
    public function test_owner_of_closed_attempt_refused(): void {
        $this->setUser($this->owner);
        $attempt = clone $this->attempt;
        $attempt->state = 'finished';
        $this->expectException(\required_capability_exception::class);
        files::get_access($attempt, $this->cm, $this->course);
    }

    /**
     * Another student, even in the same group, may not fetch the files of someone else's attempt.
     */
    public function test_other_student_refused(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        files::get_access($this->attempt, $this->cm, $this->course);
    }

    /**
     * A teacher in another separate group is refused.
     */
    public function test_teacher_in_other_group_refused(): void {
        $this->setUser($this->teacherother);
        $this->assertNull(files::get_access($this->attempt, $this->cm, $this->course));
    }

    /**
     * A teacher who shares a group with the attempt's owner reviews it.
     */
    public function test_teacher_in_same_group_allowed(): void {
        $this->setUser($this->teachersame);
        $this->assertSame(files::ACCESS_REVIEW, files::get_access($this->attempt, $this->cm, $this->course));
    }

    /**
     * A teacher with "access all groups" reviews any attempt, whatever their groups.
     */
    public function test_teacher_with_accessallgroups_allowed(): void {
        $context = \context_module::instance($this->cm->id);
        $this->assertTrue(has_capability('moodle/site:accessallgroups', $context, $this->editingteacher));
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context, $this->teacherother));

        $this->setUser($this->editingteacher);
        $this->assertSame(files::ACCESS_REVIEW, files::get_access($this->attempt, $this->cm, $this->course));
    }
}
