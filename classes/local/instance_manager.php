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

namespace mod_mavr\local;

use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\grading\grader;
use mod_mavr\local\graph\graph_validator;
use mod_mavr\local\questions\slots;
use mod_mavr\local\runtime\cleanup_scope;
use mod_mavr\local\runtime\registry as runtime_registry;
use mod_mavr\local\scene\scene_validator;
use mod_mavr\local\template\compiled_activity;
use mod_mavr\local\template\registry as template_registry;

/**
 * Creates, updates and deletes MAVR activities and stores their compiled scenes and graphs.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instance_manager {
    /** @var int Flags for every JSON document this class stores. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Create an activity from the form (or generator) data.
     *
     * @param \stdClass $data Form data; $data->coursemodule is set by core.
     * @param \moodleform_mod|null $form The form, when there is one.
     * @return int The new mavr id.
     */
    public static function add_instance(\stdClass $data, ?\moodleform_mod $form = null): int {
        global $DB;

        $runtimename = (string)($data->runtime ?? 'builtin');
        $templatename = (string)($data->template ?? 'gallery');
        // Both throw a moodle_exception when the sub-plugin is not installed.
        runtime_registry::get($runtimename);
        $template = template_registry::get($templatename);

        $now = time();
        $mavr = new \stdClass();
        $mavr->course = (int)$data->course;
        $mavr->name = (string)$data->name;
        $mavr->intro = $data->intro ?? '';
        $mavr->introformat = (int)($data->introformat ?? FORMAT_HTML);
        $mavr->runtime = $runtimename;
        $mavr->template = $templatename;
        $mavr->templateconfig = null;
        $mavr->graphjson = null;
        $mavr->graphversion = 0;
        $mavr->grade = self::clean_grade($data->grade ?? $template->default_grade());
        $mavr->grademethod = self::clean_grademethod($data->grademethod ?? grader::GRADE_HIGHEST);
        $mavr->maxattempts = max(0, (int)($data->maxattempts ?? 0));
        $mavr->completionobjectives = empty($data->completionobjectives) ? 0 : 1;
        $mavr->customised = 0;
        $mavr->timecreated = $now;
        $mavr->timemodified = $now;

        $transaction = $DB->start_delegated_transaction();
        try {
            $mavr->id = $DB->insert_record('mavr', $mavr);

            // No lookup by instance works inside add_instance: course_modules.instance is set
            // only after this returns, so the context comes from $data->coursemodule.
            $context = \context_module::instance($data->coursemodule);

            $template->save_files($data, $context);
            $mavr->templateconfig = json_encode($template->extract_config($data), self::JSON_FLAGS);
            $DB->set_field('mavr', 'templateconfig', $mavr->templateconfig, ['id' => $mavr->id]);
            // Compile from the stored shape, exactly as a later recompile or the form would see it.
            $config = self::decode_config($mavr->templateconfig) ?? new \stdClass();

            $compiled = $template->compile($config, $context);
            self::validate_compiled($compiled);
            self::apply_compiled($mavr, $context, $compiled);

            $mavr->coursemodule = $data->coursemodule;
            $mavr->cmidnumber = $data->cmidnumber ?? '';
            grader::grade_item_update($mavr);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return (int)$mavr->id;
    }

    /**
     * Update an activity from the form (or generator) data.
     *
     * The template never changes after creation. While the room has been changed in the room
     * editor (mavr.customised > 0), only the activity's own settings are saved and the room is
     * not rebuilt, unless the form asks for it with mavr_rebuild, which recompiles the room from
     * the template's settings and clears the customised flag.
     *
     * @param \stdClass $data Form data; $data->instance and $data->coursemodule are set by core.
     * @param \moodleform_mod|null $form The form, when there is one.
     * @return bool
     */
    public static function update_instance(\stdClass $data, ?\moodleform_mod $form = null): bool {
        global $DB;

        $mavr = $DB->get_record('mavr', ['id' => $data->instance], '*', MUST_EXIST);
        $template = template_registry::get($mavr->template);
        $runtimename = (string)($data->runtime ?? $mavr->runtime);
        runtime_registry::get($runtimename);
        $rebuild = !empty($data->mavr_rebuild);
        $keeproom = (int)($mavr->customised ?? 0) > 0 && !$rebuild;

        $oldgrade = (int)$mavr->grade;

        $mavr->name = (string)$data->name;
        if (property_exists($data, 'intro')) {
            $mavr->intro = $data->intro;
            $mavr->introformat = (int)($data->introformat ?? $mavr->introformat);
        }
        $mavr->runtime = $runtimename;
        if (property_exists($data, 'grade')) {
            $mavr->grade = self::clean_grade($data->grade);
        }
        if (property_exists($data, 'grademethod')) {
            $mavr->grademethod = self::clean_grademethod($data->grademethod);
        }
        if (property_exists($data, 'maxattempts')) {
            $mavr->maxattempts = max(0, (int)$data->maxattempts);
        }
        $mavr->completionobjectives = empty($data->completionobjectives) ? 0 : 1;
        $mavr->timemodified = time();

        $cmid = $data->coursemodule ?? get_coursemodule_from_instance('mavr', $mavr->id, $mavr->course, false, MUST_EXIST)->id;
        $context = \context_module::instance($cmid);

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($keeproom) {
                // The room editor owns the room: the template's settings are neither saved nor compiled.
                $DB->update_record('mavr', (object)[
                    'id' => $mavr->id,
                    'name' => $mavr->name,
                    'intro' => $mavr->intro,
                    'introformat' => $mavr->introformat,
                    'runtime' => $mavr->runtime,
                    'grade' => $mavr->grade,
                    'grademethod' => $mavr->grademethod,
                    'maxattempts' => $mavr->maxattempts,
                    'completionobjectives' => $mavr->completionobjectives,
                    'timemodified' => $mavr->timemodified,
                ]);
            } else {
                $template->save_files($data, $context);
                $mavr->templateconfig = json_encode($template->extract_config($data), self::JSON_FLAGS);
                $mavr->customised = 0;
                $DB->update_record('mavr', $mavr);
                $config = self::decode_config($mavr->templateconfig) ?? new \stdClass();

                $compiled = $template->compile($config, $context);
                self::validate_compiled($compiled);
                self::apply_compiled($mavr, $context, $compiled);
            }

            $mavr->coursemodule = $cmid;
            $mavr->cmidnumber = $data->cmidnumber ?? null;
            if ($mavr->cmidnumber === null) {
                unset($mavr->cmidnumber);
            }
            if ((int)$mavr->grade !== $oldgrade) {
                grader::update_grades($mavr);
            } else {
                grader::grade_item_update($mavr);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return true;
    }

    /**
     * Delete an activity and everything that belongs to it.
     *
     * Files go with the module context (core deletes them).
     *
     * @param int $id The mavr id.
     * @return bool False when there is no such activity.
     */
    public static function delete_instance(int $id): bool {
        global $DB;

        $mavr = $DB->get_record('mavr', ['id' => $id]);
        if (!$mavr) {
            return false;
        }
        $cm = get_coursemodule_from_instance('mavr', $mavr->id, $mavr->course, false, IGNORE_MISSING);
        $context = $cm ? \context_module::instance($cm->id, IGNORE_MISSING) : false;

        attempt_manager::delete_all_for_instance($mavr);

        if ($context) {
            slots::delete_all($mavr, $context);
        }
        // Covers the no-context case; slots::delete_all has already removed the rows otherwise.
        $DB->delete_records('mavr_slots', ['mavrid' => $mavr->id]);

        try {
            $runtime = runtime_registry::get($mavr->runtime);
            $runtime->cleanup(new cleanup_scope(cleanup_scope::INSTANCE_DELETE, (int)$mavr->course, (int)$mavr->id));
        } catch (\moodle_exception $e) {
            // The run mode is not installed: there is nothing it could clean up.
            debugging('MAVR run mode cleanup skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if ($context && \core_xapi\handler::supports_xapi('mod_mavr')) {
            \core_xapi\handler::create('mod_mavr')->wipe_states((string)$context->id);
        }

        $DB->delete_records('mavr_scenes', ['mavrid' => $mavr->id]);
        $DB->delete_records('mavr_graphs', ['mavrid' => $mavr->id]);
        $DB->delete_records('mavr', ['id' => $mavr->id]);

        grader::grade_item_delete($mavr);

        return true;
    }

    /**
     * Persist the scenes, the graph version and the question slots of a compiled activity.
     *
     * Scenes are upserted by (mavrid, idnumber) and scenes that vanished are deleted. A new graph
     * version is stored only when the graph or the slot bindings changed.
     *
     * @param \stdClass $mavr The mavr row (graphjson and graphversion are updated in place).
     * @param \context_module $context Module context.
     * @param compiled_activity $compiled The compiled activity.
     * @return int The current graph version afterwards.
     */
    public static function apply_compiled(\stdClass $mavr, \context_module $context, compiled_activity $compiled): int {
        global $DB;

        // Scenes.
        $existing = [];
        foreach ($DB->get_records('mavr_scenes', ['mavrid' => $mavr->id]) as $row) {
            $existing[$row->idnumber] = $row;
        }
        $keep = [];
        foreach (array_values($compiled->scenes) as $sortorder => $scene) {
            $idnumber = (string)$scene['id'];
            $row = $existing[$idnumber] ?? new \stdClass();
            $row->mavrid = $mavr->id;
            $row->idnumber = $idnumber;
            $row->name = \core_text::substr((string)$scene['name'], 0, 255);
            $row->sortorder = $sortorder;
            $row->scenejson = json_encode($scene, self::JSON_FLAGS);
            $row->schemaversion = (string)($scene['schema'] ?? scene_validator::SCHEMA);
            if (isset($row->id)) {
                $DB->update_record('mavr_scenes', $row);
            } else {
                $row->id = $DB->insert_record('mavr_scenes', $row);
            }
            $keep[$idnumber] = true;
        }
        foreach ($existing as $idnumber => $row) {
            if (!isset($keep[$idnumber])) {
                $DB->delete_records('mavr_scenes', ['id' => $row->id]);
            }
        }

        // Graph and slots.
        $graphjson = json_encode($compiled->graph, self::JSON_FLAGS);
        $currentbindings = self::normalise_bindings(array_map(
            fn(\stdClass $slot): array => (array)$slot,
            array_values(slots::get_slots((int)$mavr->id, $context))
        ));
        $newbindings = self::normalise_bindings($compiled->slots);
        $graphchanged = (int)$mavr->graphversion === 0 || !self::same_json($graphjson, (string)$mavr->graphjson);
        $slotschanged = $currentbindings !== $newbindings;

        if ($graphchanged || $slotschanged) {
            $version = (int)$DB->get_field_sql('SELECT MAX(version) FROM {mavr_graphs} WHERE mavrid = ?', [$mavr->id]);
            $version = max($version, (int)$mavr->graphversion) + 1;
            $DB->insert_record('mavr_graphs', (object)[
                'mavrid' => $mavr->id,
                'version' => $version,
                'graphjson' => $graphjson,
                'timecreated' => time(),
            ]);
            $mavr->graphjson = $graphjson;
            $mavr->graphversion = $version;
            $DB->update_record('mavr', (object)[
                'id' => $mavr->id,
                'graphjson' => $graphjson,
                'graphversion' => $version,
            ]);
        }
        if ($slotschanged) {
            slots::sync($mavr, $context, $compiled->slots);
        }

        return (int)$mavr->graphversion;
    }

    /**
     * The scenes of an activity.
     *
     * @param int $mavrid The mavr id.
     * @return array[] Decoded scene documents ordered by sortorder.
     */
    public static function get_scenes(int $mavrid): array {
        global $DB;
        $scenes = [];
        foreach ($DB->get_records('mavr_scenes', ['mavrid' => $mavrid], 'sortorder ASC, id ASC') as $row) {
            $scenes[] = json_decode($row->scenejson, true, 512, JSON_THROW_ON_ERROR);
        }
        return $scenes;
    }

    /**
     * One version of an activity's task graph.
     *
     * @param int $mavrid The mavr id.
     * @param int|null $version The version; null for the current one.
     * @return array The decoded graph.
     * @throws \moodle_exception When that version does not exist.
     */
    public static function get_graph(int $mavrid, ?int $version = null): array {
        global $DB;
        if ($version === null) {
            $version = (int)$DB->get_field('mavr', 'graphversion', ['id' => $mavrid], MUST_EXIST);
        }
        $json = $DB->get_field('mavr_graphs', 'graphjson', ['mavrid' => $mavrid, 'version' => $version]);
        if ($json === false) {
            throw new \moodle_exception('invalidgraph', 'mod_mavr', '',
                "graph version {$version} of activity {$mavrid} does not exist");
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a stored template config (mavr.templateconfig) into the object shape templates receive.
     *
     * @param string|null $json The stored JSON.
     * @return \stdClass|null Null when nothing is stored.
     */
    public static function decode_config(?string $json): ?\stdClass {
        if ($json === null || $json === '') {
            return null;
        }
        $config = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        return $config instanceof \stdClass ? $config : null;
    }

    /**
     * Validate every compiled scene and the graph.
     *
     * @param compiled_activity $compiled The compiled activity.
     * @throws \moodle_exception invalidscene or invalidgraph with the first error.
     */
    private static function validate_compiled(compiled_activity $compiled): void {
        if (count($compiled->scenes) < 1) {
            throw new \moodle_exception('invalidscene', 'mod_mavr', '', 'the template produced no scene');
        }
        // Every room on its own, and the rooms together (ids unique across rooms, portal targets).
        $errors = scene_validator::validate_set(array_values($compiled->scenes));
        if ($errors) {
            throw new \moodle_exception('invalidscene', 'mod_mavr', '', reset($errors));
        }
        $errors = graph_validator::validate($compiled->graph);
        if ($errors) {
            throw new \moodle_exception('invalidgraph', 'mod_mavr', '', reset($errors));
        }
        $seen = [];
        foreach ($compiled->slots as $binding) {
            $slot = (int)($binding['slot'] ?? 0);
            if ($slot < 1 || isset($seen[$slot])) {
                throw new \moodle_exception('invalidgraph', 'mod_mavr', '',
                    'question slot numbers must be unique and at least 1');
            }
            $seen[$slot] = true;
        }
    }

    /**
     * Whether two JSON documents hold the same data.
     *
     * Whole floats and integers count as equal, because a document that went through a browser
     * comes back with 1 where PHP wrote 1.0; everything else must match exactly, key order included.
     *
     * @param string $a JSON document.
     * @param string $b JSON document.
     * @return bool
     */
    private static function same_json(string $a, string $b): bool {
        if ($a === $b) {
            return true;
        }
        try {
            $left = json_decode($a, true, 512, JSON_THROW_ON_ERROR);
            $right = json_decode($b, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }
        return self::canonical($left) === self::canonical($right);
    }

    /**
     * A decoded JSON value with whole floats turned into integers.
     *
     * @param mixed $value Decoded JSON.
     * @return mixed
     */
    private static function canonical($value) {
        if (is_array($value)) {
            return array_map(fn($item) => self::canonical($item), $value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int)$value;
        }
        return $value;
    }

    /**
     * Comparable form of slot bindings.
     *
     * @param array[] $bindings Bindings (compiled_activity::$slots or slots::get_slots() rows as arrays).
     * @return array slot => [questionbankentryid, maxmark, objectiveid], sorted by slot.
     */
    private static function normalise_bindings(array $bindings): array {
        $normal = [];
        foreach ($bindings as $binding) {
            $normal[(int)$binding['slot']] = [
                (int)$binding['questionbankentryid'],
                round((float)$binding['maxmark'], 7),
                (string)$binding['objectiveid'],
            ];
        }
        ksort($normal);
        return $normal;
    }

    /**
     * Clean the grade field: points only (a negative value is a scale id, rejected by the form).
     *
     * @param mixed $grade Submitted grade.
     * @return int
     */
    private static function clean_grade($grade): int {
        return max(0, (int)$grade);
    }

    /**
     * Clean the grading method.
     *
     * @param mixed $method Submitted method.
     * @return int One of the grader::GRADE_ constants.
     */
    private static function clean_grademethod($method): int {
        $method = (int)$method;
        $allowed = [grader::GRADE_HIGHEST, grader::GRADE_AVERAGE, grader::GRADE_LAST];
        return in_array($method, $allowed, true) ? $method : grader::GRADE_HIGHEST;
    }
}
