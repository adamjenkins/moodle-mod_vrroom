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

namespace mavrruntime_builtin;

/**
 * Run-mode conformance test for the built-in viewer.
 *
 * The scripted reference session lives in the abstract test case of mod_mavr.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(runtime::class)]
final class conformance_test extends \mod_mavr\tests\runtime_conformance_testcase {
    /**
     * The run mode under test.
     *
     * @return string
     */
    protected function get_runtime_name(): string {
        return 'builtin';
    }

    /**
     * The registry resolves this run mode's short name to this plugin's runtime class.
     *
     * @return void
     */
    public function test_registry_resolves_runtime(): void {
        $this->assertInstanceOf(runtime::class, \mod_mavr\local\runtime\registry::get($this->get_runtime_name()));
    }
}
