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

namespace mod_vrroom\local;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\grading\grader;
use mod_vrroom\local\graph\graph_validator;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\questions\slots;
use mod_vrroom\local\runtime\cleanup_scope;
use mod_vrroom\local\runtime\registry as runtime_registry;
use mod_vrroom\local\scene\scene_validator;
use mod_vrroom\local\template\compiled_activity;
use mod_vrroom\local\template\registry as template_registry;

/**
 * Creates, updates and deletes VR room activities and stores their compiled scenes and graphs.
 *
 * @package    mod_vrroom
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
     * @return int The new vrroom id.
     */
    public static function add_instance(\stdClass $data, ?\moodleform_mod $form = null): int {
        global $DB;

        $runtimename = (string)($data->runtime ?? 'builtin');
        $templatename = (string)($data->template ?? 'gallery');
        // Both throw a moodle_exception when the sub-plugin is not installed.
        runtime_registry::get($runtimename);
        $template = template_registry::get($templatename);

        $now = time();
        $vrroom = new \stdClass();
        $vrroom->course = (int)$data->course;
        $vrroom->name = (string)$data->name;
        $vrroom->intro = $data->intro ?? '';
        $vrroom->introformat = (int)($data->introformat ?? FORMAT_HTML);
        $vrroom->runtime = $runtimename;
        $vrroom->template = $templatename;
        $vrroom->templateconfig = null;
        $vrroom->graphjson = null;
        $vrroom->graphversion = 0;
        $vrroom->grade = self::clean_grade($data->grade ?? $template->default_grade());
        $vrroom->grademethod = self::clean_grademethod($data->grademethod ?? grader::GRADE_HIGHEST);
        $vrroom->maxattempts = max(0, (int)($data->maxattempts ?? 0));
        $vrroom->questionmode = mode::clean($data->questionmode ?? mode::ONCE);
        $vrroom->retrypenalty = mode::clean_penalty($data->retrypenalty ?? mode::DEFAULT_PENALTY);
        $vrroom->completionobjectives = empty($data->completionobjectives) ? 0 : 1;
        $vrroom->livemode = empty($data->livemode) ? 0 : 1;
        $vrroom->livemaxusers = self::clean_livemaxusers($data->livemaxusers ?? 6);
        $vrroom->customised = 0;
        $vrroom->timecreated = $now;
        $vrroom->timemodified = $now;

        $transaction = $DB->start_delegated_transaction();
        try {
            $vrroom->id = $DB->insert_record('vrroom', $vrroom);

            // No lookup by instance works inside add_instance: course_modules.instance is set
            // only after this returns, so the context comes from $data->coursemodule.
            $context = \context_module::instance($data->coursemodule);

            $template->save_files($data, $context);
            $vrroom->templateconfig = json_encode($template->extract_config($data), self::JSON_FLAGS);
            $DB->set_field('vrroom', 'templateconfig', $vrroom->templateconfig, ['id' => $vrroom->id]);
            // Compile from the stored shape, exactly as a later recompile or the form would see it.
            $config = self::decode_config($vrroom->templateconfig) ?? new \stdClass();

            $compiled = $template->compile($config, $context);
            self::validate_compiled($compiled);
            self::apply_compiled($vrroom, $context, $compiled);

            $vrroom->coursemodule = $data->coursemodule;
            $vrroom->cmidnumber = $data->cmidnumber ?? '';
            grader::grade_item_update($vrroom);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return (int)$vrroom->id;
    }

    /**
     * Update an activity from the form (or generator) data.
     *
     * The template never changes after creation. While the room has been changed in the room
     * editor (vrroom.customised > 0), only the activity's own settings are saved and the room is
     * not rebuilt, unless the form asks for it with vrroom_rebuild, which recompiles the room from
     * the template's settings and clears the customised flag.
     *
     * @param \stdClass $data Form data; $data->instance and $data->coursemodule are set by core.
     * @param \moodleform_mod|null $form The form, when there is one.
     * @return bool
     */
    public static function update_instance(\stdClass $data, ?\moodleform_mod $form = null): bool {
        global $DB;

        $vrroom = $DB->get_record('vrroom', ['id' => $data->instance], '*', MUST_EXIST);
        $template = template_registry::get($vrroom->template);
        $runtimename = (string)($data->runtime ?? $vrroom->runtime);
        runtime_registry::get($runtimename);
        $rebuild = !empty($data->vrroom_rebuild);
        $keeproom = (int)($vrroom->customised ?? 0) > 0 && !$rebuild;

        $oldgrade = (int)$vrroom->grade;

        $vrroom->name = (string)$data->name;
        if (property_exists($data, 'intro')) {
            $vrroom->intro = $data->intro;
            $vrroom->introformat = (int)($data->introformat ?? $vrroom->introformat);
        }
        $vrroom->runtime = $runtimename;
        if (property_exists($data, 'grade')) {
            $vrroom->grade = self::clean_grade($data->grade);
        }
        if (property_exists($data, 'grademethod')) {
            $vrroom->grademethod = self::clean_grademethod($data->grademethod);
        }
        if (property_exists($data, 'maxattempts')) {
            $vrroom->maxattempts = max(0, (int)$data->maxattempts);
        }
        if (property_exists($data, 'questionmode')) {
            $vrroom->questionmode = mode::clean($data->questionmode);
        }
        if (property_exists($data, 'retrypenalty')) {
            $vrroom->retrypenalty = mode::clean_penalty($data->retrypenalty);
        }
        $vrroom->completionobjectives = empty($data->completionobjectives) ? 0 : 1;
        if (property_exists($data, 'livemode')) {
            // Without these fields (no run mode can run a live room) the stored values stay.
            $vrroom->livemode = empty($data->livemode) ? 0 : 1;
            $vrroom->livemaxusers = self::clean_livemaxusers($data->livemaxusers ?? ($vrroom->livemaxusers ?? 6));
        }
        $vrroom->timemodified = time();

        $cmid = $data->coursemodule
            ?? get_coursemodule_from_instance('vrroom', $vrroom->id, $vrroom->course, false, MUST_EXIST)->id;
        $context = \context_module::instance($cmid);

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($keeproom) {
                // The room editor owns the room: the template's settings are neither saved nor compiled.
                $DB->update_record('vrroom', (object)[
                    'id' => $vrroom->id,
                    'name' => $vrroom->name,
                    'intro' => $vrroom->intro,
                    'introformat' => $vrroom->introformat,
                    'runtime' => $vrroom->runtime,
                    'grade' => $vrroom->grade,
                    'grademethod' => $vrroom->grademethod,
                    'maxattempts' => $vrroom->maxattempts,
                    'questionmode' => mode::clean($vrroom->questionmode ?? null),
                    'retrypenalty' => mode::clean_penalty($vrroom->retrypenalty ?? null),
                    'completionobjectives' => $vrroom->completionobjectives,
                    'livemode' => $vrroom->livemode,
                    'livemaxusers' => $vrroom->livemaxusers,
                    'timemodified' => $vrroom->timemodified,
                ]);
            } else {
                $template->save_files($data, $context);
                $vrroom->templateconfig = json_encode($template->extract_config($data), self::JSON_FLAGS);
                $vrroom->customised = 0;
                $DB->update_record('vrroom', $vrroom);
                $config = self::decode_config($vrroom->templateconfig) ?? new \stdClass();

                $compiled = $template->compile($config, $context);
                self::validate_compiled($compiled);
                self::apply_compiled($vrroom, $context, $compiled);
            }

            $vrroom->coursemodule = $cmid;
            $vrroom->cmidnumber = $data->cmidnumber ?? null;
            if ($vrroom->cmidnumber === null) {
                unset($vrroom->cmidnumber);
            }
            if ((int)$vrroom->grade !== $oldgrade) {
                grader::update_grades($vrroom);
            } else {
                grader::grade_item_update($vrroom);
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
     * @param int $id The vrroom id.
     * @return bool False when there is no such activity.
     */
    public static function delete_instance(int $id): bool {
        global $DB;

        $vrroom = $DB->get_record('vrroom', ['id' => $id]);
        if (!$vrroom) {
            return false;
        }
        $cm = get_coursemodule_from_instance('vrroom', $vrroom->id, $vrroom->course, false, IGNORE_MISSING);
        $context = $cm ? \context_module::instance($cm->id, IGNORE_MISSING) : false;

        attempt_manager::delete_all_for_instance($vrroom);

        if ($context) {
            slots::delete_all($vrroom, $context);
        }
        // Covers the no-context case; slots::delete_all has already removed the rows otherwise.
        $DB->delete_records('vrroom_slots', ['vrroomid' => $vrroom->id]);

        try {
            $runtime = runtime_registry::get($vrroom->runtime);
            $runtime->cleanup(new cleanup_scope(cleanup_scope::INSTANCE_DELETE, (int)$vrroom->course, (int)$vrroom->id));
        } catch (\moodle_exception $e) {
            // The run mode is not installed: there is nothing it could clean up.
            debugging('VR room run mode cleanup skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if ($context && \core_xapi\handler::supports_xapi('mod_vrroom')) {
            \core_xapi\handler::create('mod_vrroom')->wipe_states((string)$context->id);
        }

        $DB->delete_records('vrroom_scenes', ['vrroomid' => $vrroom->id]);
        $DB->delete_records('vrroom_graphs', ['vrroomid' => $vrroom->id]);
        $DB->delete_records('vrroom', ['id' => $vrroom->id]);

        grader::grade_item_delete($vrroom);

        return true;
    }

    /**
     * Persist the scenes, the graph version and the question slots of a compiled activity.
     *
     * Scenes are upserted by (vrroomid, idnumber) and scenes that vanished are deleted. A new graph
     * version is stored only when the graph or the slot bindings changed.
     *
     * @param \stdClass $vrroom The vrroom row (graphjson and graphversion are updated in place).
     * @param \context_module $context Module context.
     * @param compiled_activity $compiled The compiled activity.
     * @return int The current graph version afterwards.
     */
    public static function apply_compiled(\stdClass $vrroom, \context_module $context, compiled_activity $compiled): int {
        global $DB;

        // Scenes.
        $existing = [];
        foreach ($DB->get_records('vrroom_scenes', ['vrroomid' => $vrroom->id]) as $row) {
            $existing[$row->idnumber] = $row;
        }
        $keep = [];
        foreach (array_values($compiled->scenes) as $sortorder => $scene) {
            $idnumber = (string)$scene['id'];
            $row = $existing[$idnumber] ?? new \stdClass();
            $row->vrroomid = $vrroom->id;
            $row->idnumber = $idnumber;
            $row->name = \core_text::substr((string)$scene['name'], 0, 255);
            $row->sortorder = $sortorder;
            $row->scenejson = json_encode($scene, self::JSON_FLAGS);
            $row->schemaversion = (string)($scene['schema'] ?? scene_validator::SCHEMA);
            if (isset($row->id)) {
                $DB->update_record('vrroom_scenes', $row);
            } else {
                $row->id = $DB->insert_record('vrroom_scenes', $row);
            }
            $keep[$idnumber] = true;
        }
        foreach ($existing as $idnumber => $row) {
            if (!isset($keep[$idnumber])) {
                $DB->delete_records('vrroom_scenes', ['id' => $row->id]);
            }
        }

        // Graph and slots.
        $graphjson = json_encode($compiled->graph, self::JSON_FLAGS);
        $currentbindings = self::normalise_bindings(array_map(
            fn(\stdClass $slot): array => (array)$slot,
            array_values(slots::get_slots((int)$vrroom->id, $context))
        ));
        $newbindings = self::normalise_bindings($compiled->slots);
        $graphchanged = (int)$vrroom->graphversion === 0 || !self::same_json($graphjson, (string)$vrroom->graphjson);
        $slotschanged = $currentbindings !== $newbindings;

        if ($graphchanged || $slotschanged) {
            $version = (int)$DB->get_field_sql('SELECT MAX(version) FROM {vrroom_graphs} WHERE vrroomid = ?', [$vrroom->id]);
            $version = max($version, (int)$vrroom->graphversion) + 1;
            $DB->insert_record('vrroom_graphs', (object)[
                'vrroomid' => $vrroom->id,
                'version' => $version,
                'graphjson' => $graphjson,
                'timecreated' => time(),
            ]);
            $vrroom->graphjson = $graphjson;
            $vrroom->graphversion = $version;
            $DB->update_record('vrroom', (object)[
                'id' => $vrroom->id,
                'graphjson' => $graphjson,
                'graphversion' => $version,
            ]);
        }
        if ($slotschanged) {
            slots::sync($vrroom, $context, $compiled->slots);
        }

        return (int)$vrroom->graphversion;
    }

    /**
     * The scenes of an activity.
     *
     * @param int $vrroomid The vrroom id.
     * @return array[] Decoded scene documents ordered by sortorder.
     */
    public static function get_scenes(int $vrroomid): array {
        global $DB;
        $scenes = [];
        foreach ($DB->get_records('vrroom_scenes', ['vrroomid' => $vrroomid], 'sortorder ASC, id ASC') as $row) {
            $scenes[] = json_decode($row->scenejson, true, 512, JSON_THROW_ON_ERROR);
        }
        return $scenes;
    }

    /**
     * One version of an activity's task graph.
     *
     * @param int $vrroomid The vrroom id.
     * @param int|null $version The version; null for the current one.
     * @return array The decoded graph.
     * @throws \moodle_exception When that version does not exist.
     */
    public static function get_graph(int $vrroomid, ?int $version = null): array {
        global $DB;
        if ($version === null) {
            $version = (int)$DB->get_field('vrroom', 'graphversion', ['id' => $vrroomid], MUST_EXIST);
        }
        $json = $DB->get_field('vrroom_graphs', 'graphjson', ['vrroomid' => $vrroomid, 'version' => $version]);
        if ($json === false) {
            throw new \moodle_exception(
                'invalidgraph',
                'mod_vrroom',
                '',
                "graph version {$version} of activity {$vrroomid} does not exist"
            );
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a stored template config (vrroom.templateconfig) into the object shape templates receive.
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
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'the template produced no scene');
        }
        // Every room on its own, and the rooms together (ids unique across rooms, portal targets).
        $errors = scene_validator::validate_set(array_values($compiled->scenes));
        if ($errors) {
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', reset($errors));
        }
        $errors = graph_validator::validate($compiled->graph);
        if ($errors) {
            throw new \moodle_exception('invalidgraph', 'mod_vrroom', '', reset($errors));
        }
        $seen = [];
        foreach ($compiled->slots as $binding) {
            $slot = (int)($binding['slot'] ?? 0);
            if ($slot < 1 || isset($seen[$slot])) {
                throw new \moodle_exception(
                    'invalidgraph',
                    'mod_vrroom',
                    '',
                    'question slot numbers must be unique and at least 1'
                );
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
     * Clean the maximum number of people in a live room (storage clamp).
     *
     * The run mode caps the value again when someone joins, at what it supports.
     *
     * @param mixed $value Submitted value.
     * @return int Between 2 and 50.
     */
    private static function clean_livemaxusers($value): int {
        return min(50, max(2, (int)$value));
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
