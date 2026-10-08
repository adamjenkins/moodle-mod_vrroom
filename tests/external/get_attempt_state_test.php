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

namespace mod_mavr\external;

use core_external\external_api;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\instance_manager;

/**
 * Tests of mod_mavr_get_attempt_state.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_attempt_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(helper::class)]
final class get_attempt_state_test extends \advanced_testcase {
    /**
     * The owner gets the server evaluation; others are refused.
     *
     * @return void
     */
    public function test_state_and_ownership(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('mavr', [
            'course' => $course->id,
            'stations' => [
                ['title' => 'Vase', 'text' => '<p>Vase</p>', 'questionbankentryid' => 0, 'required' => 1, 'points' => 1,
                    'mediatype' => 'none'],
                ['title' => 'Portrait', 'text' => '<p>Portrait</p>', 'questionbankentryid' => 0, 'required' => 1,
                    'points' => 1, 'mediatype' => 'none'],
            ],
        ]);
        $student = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');

        $this->setUser($student);
        $start = start_attempt::execute((int)$module->cmid);
        $attemptid = (int)$start['attemptid'];

        $state = external_api::clean_returnvalue(
            get_attempt_state::execute_returns(),
            get_attempt_state::execute($attemptid)
        );
        $this->assertSame($attemptid, $state['attemptid']);
        $this->assertSame('inprogress', $state['state']);
        $this->assertEqualsWithDelta(0.0, $state['score'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $state['maxpoints'], 1e-6);
        $graph = instance_manager::get_graph((int)$module->id);
        $this->assertSame(array_column($graph['objectives'], 'id'), array_column($state['objectives'], 'id'));
        // No time limit: no deadline; the server time lets the client correct its clock.
        $this->assertSame(0, $state['timedue']);
        $this->assertGreaterThan(0, $state['servertime']);
        $this->assertSame([0, 0], array_column($state['objectives'], 'hintsused'));

        $scene = instance_manager::get_scenes((int)$module->id)[0];
        $zone = $scene['zones'][0];
        event_store::insert(
            $attemptid,
            \core\uuid::generate(),
            ['verb' => 'entered', 'type' => 'zone', 'id' => $zone['id'], 'scene' => $scene['id'], 'fraction' => null],
            true,
            null,
            time()
        );
        $state = external_api::clean_returnvalue(
            get_attempt_state::execute_returns(),
            get_attempt_state::execute($attemptid)
        );
        $this->assertEqualsWithDelta(1.0, $state['earnedpoints'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $state['score'], 1e-6);

        // Still readable once finished.
        finish_attempt::execute($attemptid);
        $state = external_api::clean_returnvalue(
            get_attempt_state::execute_returns(),
            get_attempt_state::execute($attemptid)
        );
        $this->assertSame('finished', $state['state']);

        $this->setUser($other);
        try {
            get_attempt_state::execute($attemptid);
            $this->fail('Another student must not read the attempt');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotyourattempt', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('mavr_attempts', ['id' => $attemptid]));

        $this->setUser($generator->create_user());
        $this->expectException(\require_login_exception::class);
        get_attempt_state::execute($attemptid);
    }
}
