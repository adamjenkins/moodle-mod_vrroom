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

namespace mod_vrroom\plugininfo;

/**
 * Tests for the vrroomenv (room pack) sub-plugin type.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(vrroomenv::class)]
final class vrroomenv_test extends \advanced_testcase {
    /**
     * A room pack can always be uninstalled and cannot be disabled; the type is registered.
     */
    public function test_type(): void {
        $info = (new \ReflectionClass(vrroomenv::class))->newInstanceWithoutConstructor();
        $this->assertTrue($info->is_uninstall_allowed());
        $this->assertFalse(vrroomenv::plugintype_supports_disabling());

        $types = \core_component::get_plugin_types();
        $this->assertArrayHasKey('vrroomenv', $types);
        $this->assertStringEndsWith('/mod/vrroom/environment', str_replace('\\', '/', $types['vrroomenv']));
        $this->assertArrayHasKey('vrroomenv', \core_component::get_subplugins('mod_vrroom'));
    }
}
