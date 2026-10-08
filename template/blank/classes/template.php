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

namespace vrroomtemplate_blank;

use mod_vrroom\local\scene\presets;
use mod_vrroom\local\template\compiled_activity;

/**
 * Empty room template: one room from a bundled environment and no tasks, built on in the room editor.
 *
 * The form has one field (the surroundings), stored as a vrroomtemplate_blank/1 config and compiled
 * into one vrroom-scene/1 room without zones, props or interactables, and an empty vrroom-graph/1.
 *
 * @package    vrroomtemplate_blank
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template extends \mod_vrroom\local\template\base {
    /** @var string Frankenstyle component of this template (string lookups). */
    public const COMPONENT = 'vrroomtemplate_blank';

    /** @var string Schema identifier written into the stored config. */
    public const SCHEMA = 'vrroomtemplate_blank/1';

    /** @var string Environment preset chosen by default. */
    public const DEFAULT_ENVIRONMENT = 'gallery-hall';

    /** @var string Scene id of the single spawn point. */
    public const SPAWN_ID = 's-start';

    /**
     * Add the empty room fields to the activity form.
     *
     * @param \moodleform_mod $form the activity form
     * @param \MoodleQuickForm $mform its QuickForm
     * @param int $courseid course the activity is in
     * @return string[] names of the non-repeated elements added (headers excluded)
     */
    public function define_form(\moodleform_mod $form, \MoodleQuickForm $mform, int $courseid): array {
        $mform->addElement('header', 'blank_heading', get_string('blankheading', self::COMPONENT));
        $mform->setExpanded('blank_heading');

        $mform->addElement(
            'select',
            'blank_environment',
            get_string('environment', self::COMPONENT),
            presets::get_choices()
        );
        $mform->setDefault('blank_environment', self::DEFAULT_ENVIRONMENT);
        $mform->addHelpButton('blank_environment', 'environment', self::COMPONENT);

        return ['blank_environment'];
    }

    /**
     * Fill the form defaults from a stored config.
     *
     * @param array $defaultvalues form defaults (by reference)
     * @param \stdClass|null $config stored config, null when creating
     * @param \context_module|null $context module context, null when creating (unused: no files)
     */
    public function data_preprocessing(array &$defaultvalues, ?\stdClass $config, ?\context_module $context): void {
        if ($config === null) {
            if (!isset($defaultvalues['blank_environment'])) {
                $defaultvalues['blank_environment'] = self::DEFAULT_ENVIRONMENT;
            }
            return;
        }
        $defaultvalues['blank_environment'] = self::clean_environment($config->environment ?? null);
    }

    /**
     * Validate the empty room fields.
     *
     * @param array $data submitted data
     * @param array $files submitted files (unused)
     * @param int $courseid course the activity is in (unused)
     * @return array element name => error message
     */
    public function validation(array $data, array $files, int $courseid): array {
        $environment = $data['blank_environment'] ?? self::DEFAULT_ENVIRONMENT;
        if (!is_string($environment) || presets::get($environment) === null) {
            return ['blank_environment' => get_string('required')];
        }
        return [];
    }

    /**
     * Turn submitted form data into the stored vrroomtemplate_blank/1 config.
     *
     * The scene id of an existing activity is kept, so a rebuild keeps the room's id.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @return \stdClass config
     */
    public function extract_config(\stdClass $formdata): \stdClass {
        $sceneid = '';
        if (!empty($formdata->instance)) {
            $existing = self::load_stored_config((int)$formdata->instance);
            if ($existing && is_string($existing->sceneid ?? null) && self::is_uuid($existing->sceneid)) {
                $sceneid = $existing->sceneid;
            }
        }
        if ($sceneid === '') {
            $sceneid = strtolower(\core\uuid::generate());
        }
        return (object)[
            'schema' => self::SCHEMA,
            'sceneid' => $sceneid,
            'environment' => self::clean_environment($formdata->blank_environment ?? null),
        ];
    }

    /**
     * Save files: the empty room template has none.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @param \context_module $context module context
     */
    public function save_files(\stdClass $formdata, \context_module $context): void {
        // The template uploads nothing; files added in the room editor live in their own area.
    }

    /**
     * Compile a stored config into one empty room and an empty task graph.
     *
     * @param \stdClass $config vrroomtemplate_blank/1 config
     * @param \context_module $context module context (unused: no files)
     * @return compiled_activity
     */
    public function compile(\stdClass $config, \context_module $context): compiled_activity {
        $environmentid = self::clean_environment($config->environment ?? null);
        $preset = presets::get($environmentid);
        if ($preset === null) {
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'Unknown environment preset.');
        }
        $sceneid = (is_string($config->sceneid ?? null) && self::is_uuid($config->sceneid))
            ? $config->sceneid : strtolower(\core\uuid::generate());

        $position = [];
        foreach (array_values($preset['spawn']['position'] ?? [0, 0, 0]) as $coordinate) {
            $position[] = self::round3((float)$coordinate);
        }

        $scene = [
            'schema' => 'vrroom-scene/1',
            'id' => $sceneid,
            'name' => get_string('scenename', self::COMPONENT),
            'environment' => [
                'type' => 'preset',
                'preset' => $environmentid,
                'shape' => $preset['shape'],
                'sky' => $preset['sky'],
                'lights' => $preset['lights'],
                'bounds' => $preset['bounds'],
            ],
            'spawn' => [[
                'id' => self::SPAWN_ID,
                'position' => $position,
                'yaw' => self::normalise_yaw((float)($preset['spawn']['yaw'] ?? 0)),
            ]],
            'zones' => [],
            'props' => [],
            'interactables' => [],
        ];
        $graph = [
            'schema' => 'vrroom-graph/1',
            'hidden' => [],
            'objectives' => [],
            'rules' => [],
        ];
        return new compiled_activity([$scene], $graph, []);
    }

    /**
     * Completion rules this template offers: none (an empty room has no tasks to complete).
     *
     * @return string[]
     */
    public function completion_rules(): array {
        return [];
    }

    /**
     * Default maximum grade.
     *
     * @return int
     */
    public function default_grade(): int {
        return 100;
    }

    /**
     * A known preset id, or the default.
     *
     * @param mixed $value submitted or stored value
     * @return string
     */
    private static function clean_environment($value): string {
        return (is_string($value) && presets::get($value) !== null) ? $value : self::DEFAULT_ENVIRONMENT;
    }

    /**
     * Load and decode the stored config of an activity.
     *
     * @param int $vrroomid activity instance id
     * @return \stdClass|null null when absent or not an empty room config
     */
    private static function load_stored_config(int $vrroomid): ?\stdClass {
        global $DB;
        $json = $DB->get_field('vrroom', 'templateconfig', ['id' => $vrroomid, 'template' => 'blank']);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $config = json_decode($json);
        if (!$config instanceof \stdClass || ($config->schema ?? '') !== self::SCHEMA) {
            return null;
        }
        return $config;
    }

    /**
     * Normalise a yaw to the range (−180, 180], rounded to 3 decimals.
     *
     * @param float $yaw degrees
     * @return float
     */
    private static function normalise_yaw(float $yaw): float {
        $yaw = fmod($yaw, 360.0);
        if ($yaw <= -180.0) {
            $yaw += 360.0;
        } else if ($yaw > 180.0) {
            $yaw -= 360.0;
        }
        return self::round3($yaw);
    }

    /**
     * Round to 3 decimals, never returning negative zero.
     *
     * @param float $value
     * @return float
     */
    private static function round3(float $value): float {
        $value = round($value, 3);
        return $value == 0.0 ? 0.0 : $value;
    }

    /**
     * Whether a string is a UUID (any case, canonical 8-4-4-4-12 form).
     *
     * @param string $value
     * @return bool
     */
    private static function is_uuid(string $value): bool {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
