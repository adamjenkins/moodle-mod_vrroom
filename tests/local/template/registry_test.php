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

namespace mod_mavr\local\template;

/**
 * Tests for template discovery.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
final class registry_test extends \advanced_testcase {
    /**
     * Start every test with fresh discovery.
     */
    protected function setUp(): void {
        parent::setUp();
        registry::reset_caches();
    }

    /**
     * The bundled gallery template is discovered.
     */
    public function test_discovery(): void {
        $all = registry::get_all();
        $this->assertArrayHasKey('gallery', $all);
        $names = array_keys($all);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        foreach ($all as $name => $template) {
            $this->assertInstanceOf(base::class, $template);
            $this->assertSame($name, $template->get_name());
            $this->assertSame('mavrtemplate_' . $name, $template->get_component());
        }

        $gallery = registry::get('gallery');
        $this->assertSame($all['gallery'], $gallery);
        $this->assertSame(get_string('pluginname', 'mavrtemplate_gallery'), $gallery->get_display_name());
        $this->assertSame(get_string('pluginname', 'mavrtemplate_gallery'), registry::get_choices()['gallery']);
        $this->assertSame(['completionobjectives'], $gallery->completion_rules());
        $this->assertSame(100, $gallery->default_grade());
    }

    /**
     * An unknown name throws errorunknowntemplate.
     */
    public function test_unknown(): void {
        try {
            registry::get('nosuchtemplate');
            $this->fail('An unknown template was returned.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorunknowntemplate', $e->errorcode);
            $this->assertSame('mod_mavr', $e->module);
        }
    }

    /**
     * The compiled activity keeps what it was given.
     */
    public function test_compiled_activity(): void {
        $compiled = new compiled_activity([['id' => 's']], ['schema' => 'mavr-graph/1'], [['slot' => 1]]);
        $this->assertSame([['id' => 's']], $compiled->scenes);
        $this->assertSame(['schema' => 'mavr-graph/1'], $compiled->graph);
        $this->assertSame([['slot' => 1]], $compiled->slots);
    }
}
