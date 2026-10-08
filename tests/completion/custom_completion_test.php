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

namespace mod_mavr\completion;

use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\instance_manager;

/**
 * Tests of the completionobjectives rule.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(custom_completion::class)]
final class custom_completion_test extends \advanced_testcase {
    /**
     * The rule is incomplete until a finished attempt completed every required objective.
     *
     * @return void
     */
    public function test_completionobjectives(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionobjectives' => 1,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => 0, 'required' => 1, 'points' => 1,
                    'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>Portrait</p>', 'questionbankentryid' => 0, 'required' => 0,
                    'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $mavr = $DB->get_record('mavr', ['id' => $module->id], '*', MUST_EXIST);
        $student = $generator->create_and_enrol($course, 'student');
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);

        $completion = new custom_completion($cm, (int)$student->id);
        $this->assertSame(['completionobjectives'], custom_completion::get_defined_custom_rules());
        $this->assertArrayHasKey('completionobjectives', $completion->get_custom_rule_descriptions());
        $this->assertSame(
            ['completionview', 'completionobjectives', 'completionusegrade', 'completionpassgrade'],
            $completion->get_sort_order()
        );
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionobjectives'));

        // A finished attempt that missed the required station does not complete the rule.
        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, (int)$student->id);
        attempt_manager::finish($attempt);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionobjectives'));

        // An in-progress attempt that visited it does not either, until it is finished.
        $scene = instance_manager::get_scenes((int)$mavr->id)[0];
        $vase = null;
        foreach ($scene['zones'] as $zone) {
            if ($zone['name'] === 'Vase') {
                $vase = $zone['id'];
            }
        }
        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, (int)$student->id);
        event_store::insert(
            (int)$attempt->id,
            \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $vase, 'scene' => $scene['id'], 'fraction' => null],
            true,
            null,
            time()
        );
        attempt_manager::recompute($attempt);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionobjectives'));

        attempt_manager::finish($attempt);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionobjectives'));

        // Finishing updated the stored completion state too.
        $info = new \completion_info($course);
        $data = $info->get_data($cm, false, (int)$student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->completionstate);
    }
}
