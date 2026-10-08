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

namespace mavrtemplate_blank;

use mod_mavr\local\graph\graph_validator;
use mod_mavr\local\scene\scene_validator;

/**
 * Tests for the Empty room template.
 *
 * @package    mavrtemplate_blank
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template::class)]
final class template_test extends \advanced_testcase {
    /** @var string Fixed scene id used by the configs built here. */
    private const SCENEID = '3c9d1e2f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';

    /**
     * A module context (compile reads no files, but takes one).
     *
     * @return \context_module
     */
    private function make_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return \context_module::instance($page->cmid);
    }

    /**
     * Name, completion rules, default grade and chooser help.
     */
    public function test_basics(): void {
        $template = new template();
        $this->assertSame('blank', $template->get_name());
        $this->assertSame([], $template->completion_rules());
        $this->assertSame(100, $template->default_grade());
        $this->assertSame(get_string('chooserhelp', 'mavrtemplate_blank'), $template->get_chooser_help());
        $this->assertFalse($template->remap_question_entries((object)[], fn (int $old): int => $old + 1));
    }

    /**
     * One empty room from the preset and an empty graph, both valid.
     */
    public function test_compile(): void {
        $this->resetAfterTest();
        $context = $this->make_context();
        $config = (object)['schema' => template::SCHEMA, 'sceneid' => self::SCENEID, 'environment' => 'courtyard'];
        $compiled = (new template())->compile($config, $context);

        $this->assertCount(1, $compiled->scenes);
        $scene = $compiled->scenes[0];
        $this->assertSame([], scene_validator::validate($scene));
        $this->assertSame([], scene_validator::validate_set($compiled->scenes));
        $this->assertSame(self::SCENEID, $scene['id']);
        $this->assertSame(get_string('scenename', 'mavrtemplate_blank'), $scene['name']);
        $this->assertSame('courtyard', $scene['environment']['preset']);
        $this->assertSame('courtyard', $scene['environment']['shape']['kind']);
        $this->assertSame([['id' => 's-start', 'position' => [0.0, 0.0, 0.0], 'yaw' => 0.0]], $scene['spawn']);
        $this->assertSame([], $scene['zones']);
        $this->assertSame([], $scene['props']);
        $this->assertSame([], $scene['interactables']);

        $this->assertSame(['schema' => 'mavr-graph/1', 'hidden' => [], 'objectives' => [], 'rules' => []],
            $compiled->graph);
        $this->assertSame([], graph_validator::validate($compiled->graph));
        $this->assertSame([], $compiled->slots);
    }

    /**
     * Config extraction: a known preset is kept, anything else falls back; an editing activity keeps its scene id.
     */
    public function test_extract_config(): void {
        global $DB;
        $this->resetAfterTest();
        $template = new template();

        $config = $template->extract_config((object)['blank_environment' => 'escape-room']);
        $this->assertSame(template::SCHEMA, $config->schema);
        $this->assertSame('escape-room', $config->environment);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $config->sceneid);

        $this->assertSame(template::DEFAULT_ENVIRONMENT,
            $template->extract_config((object)['blank_environment' => 'nowhere'])->environment);

        $course = $this->getDataGenerator()->create_course();
        $mavrid = $DB->insert_record('mavr', (object)[
            'course' => $course->id,
            'name' => 'Empty',
            'runtime' => 'builtin',
            'template' => 'blank',
            'templateconfig' => json_encode(['schema' => template::SCHEMA, 'sceneid' => self::SCENEID,
                'environment' => 'courtyard']),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $config = $template->extract_config((object)['instance' => $mavrid, 'blank_environment' => 'gallery-hall']);
        $this->assertSame(self::SCENEID, $config->sceneid);
        $this->assertSame('gallery-hall', $config->environment);
    }

    /**
     * Validation accepts the presets only; preprocessing fills the field.
     */
    public function test_validation_and_preprocessing(): void {
        $template = new template();
        $this->assertSame([], $template->validation(['blank_environment' => 'courtyard'], [], 0));
        $this->assertSame(['blank_environment' => get_string('required')],
            $template->validation(['blank_environment' => 'moon-base'], [], 0));

        $values = [];
        $template->data_preprocessing($values, null, null);
        $this->assertSame(['blank_environment' => template::DEFAULT_ENVIRONMENT], $values);

        $values = [];
        $template->data_preprocessing($values, (object)['environment' => 'courtyard'], null);
        $this->assertSame(['blank_environment' => 'courtyard'], $values);
    }
}
