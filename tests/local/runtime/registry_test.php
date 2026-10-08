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

namespace mod_mavr\local\runtime;

/**
 * Tests for run mode discovery and the run mode value classes.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(launch_descriptor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_scope::class)]
final class registry_test extends \advanced_testcase {
    /**
     * Start every test with fresh discovery.
     */
    protected function setUp(): void {
        parent::setUp();
        registry::reset_caches();
    }

    /**
     * The bundled built-in run mode is discovered.
     */
    public function test_discovery(): void {
        $all = registry::get_all();
        $this->assertArrayHasKey('builtin', $all);
        $names = array_keys($all);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        foreach ($all as $name => $runtime) {
            $this->assertInstanceOf(base::class, $runtime);
            $this->assertSame($name, $runtime->get_name());
            $this->assertSame('mavrruntime_' . $name, $runtime->get_component());
        }

        $builtin = registry::get('builtin');
        $this->assertSame($all['builtin'], $builtin);
        $this->assertSame(get_string('pluginname', 'mavrruntime_builtin'), $builtin->get_display_name());
        $this->assertSame(get_string('pluginname', 'mavrruntime_builtin'), registry::get_choices()['builtin']);
        $this->assertContains($builtin->statement_channel(), ['browser', 'server']);
        $entry = $builtin->client_entry();
        $this->assertArrayHasKey('module', $entry);
        $this->assertArrayHasKey('function', $entry);
    }

    /**
     * An unknown name throws errorunknownruntime.
     */
    public function test_unknown(): void {
        try {
            registry::get('nosuchmode');
            $this->fail('An unknown run mode was returned.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorunknownruntime', $e->errorcode);
            $this->assertSame('mod_mavr', $e->module);
        }
    }

    /**
     * Launch descriptors and cleanup scopes check their type.
     */
    public function test_value_classes(): void {
        $inline = new launch_descriptor(launch_descriptor::TYPE_INLINE, ['schema' => 'mavr-launch/1']);
        $this->assertSame(['type' => 'inline', 'config' => ['schema' => 'mavr-launch/1'], 'url' => null],
            $inline->to_array());

        $url = new \moodle_url('/mod/mavr/view.php', ['id' => 3]);
        $external = new launch_descriptor(launch_descriptor::TYPE_EXTERNAL, [], $url);
        $this->assertSame($url->out(false), $external->to_array()['url']);

        $scope = new cleanup_scope(cleanup_scope::COURSE_RESET, 2);
        $this->assertSame(2, $scope->courseid);
        $this->assertSame(0, $scope->mavrid);

        $this->expectException(\coding_exception::class);
        new cleanup_scope('everything');
    }

    /**
     * An external launch without a URL is a coding error.
     */
    public function test_external_launch_needs_url(): void {
        $this->expectException(\coding_exception::class);
        new launch_descriptor(launch_descriptor::TYPE_EXTERNAL);
    }
}
