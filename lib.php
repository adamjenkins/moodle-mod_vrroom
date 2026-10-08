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

/**
 * Library of functions for mod_vrroom.
 *
 * The grade callbacks follow the flow of mod_gear's lib.php (update_grades → grade_item_update).
 *
 * @package    mod_vrroom
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_vrroom\local\grading\grader;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\reset;

/**
 * The user preferences of VR room: reversed drag-to-look directions and the up/down lock in the 3D view.
 *
 * Declaring them lets the viewer save them with core_user's preference web service; only the
 * user may change their own.
 *
 * @return array[] preference name => definition (see \core_user::fill_preferences_cache())
 */
function vrroom_user_preferences(): array {
    $definition = [
        'type' => PARAM_BOOL,
        'null' => NULL_NOT_ALLOWED,
        'default' => 0,
        'permissioncallback' => [core_user::class, 'is_current_user'],
    ];
    return [
        'mod_vrroom_invertx' => $definition,
        'mod_vrroom_inverty' => $definition,
        'mod_vrroom_locky' => $definition,
    ];
}

/**
 * Whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True or a value if supported, false if not, null if unknown.
 */
function vrroom_supports(string $feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_USES_QUESTIONS => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_INTERACTIVECONTENT,
        FEATURE_GROUPS => true,
        FEATURE_GROUPINGS => true,
        FEATURE_GRADE_OUTCOMES => false,
        default => null,
    };
}

/**
 * Add a new activity.
 *
 * @param stdClass $data Form data.
 * @param mod_vrroom_mod_form|null $mform The form.
 * @return int The new vrroom id.
 */
function vrroom_add_instance(stdClass $data, ?mod_vrroom_mod_form $mform = null): int {
    return instance_manager::add_instance($data, $mform);
}

/**
 * Update an activity.
 *
 * @param stdClass $data Form data.
 * @param mod_vrroom_mod_form|null $mform The form.
 * @return bool
 */
function vrroom_update_instance(stdClass $data, ?mod_vrroom_mod_form $mform = null): bool {
    return instance_manager::update_instance($data, $mform);
}

/**
 * Delete an activity.
 *
 * @param int $id The vrroom id.
 * @return bool
 */
function vrroom_delete_instance(int $id): bool {
    return instance_manager::delete_instance($id);
}

/**
 * Information about the activity for course listings, cached in modinfo.
 *
 * @param stdClass $coursemodule The course_modules row (with showdescription and completion).
 * @return cached_cm_info|false
 */
function vrroom_get_coursemodule_info(stdClass $coursemodule) {
    global $DB;

    $vrroom = $DB->get_record(
        'vrroom',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionobjectives'
    );
    if (!$vrroom) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $vrroom->name;
    if ($coursemodule->showdescription) {
        // Filters run at display time, so the cached copy is not filtered.
        $result->content = format_module_intro('vrroom', $vrroom, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionobjectives'] = (int)$vrroom->completionobjectives;
    }
    return $result;
}

/**
 * Descriptions of the active custom completion rules.
 *
 * @param cm_info|stdClass $cm Object with ->completion and ->customdata['customcompletionrules'].
 * @return string[]
 */
function mod_vrroom_get_completion_active_rule_descriptions($cm): array {
    if (empty($cm->customdata['customcompletionrules']) || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }
    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $value) {
        if ($key === 'completionobjectives' && !empty($value)) {
            $descriptions[] = get_string('completiondetail:objectives', 'vrroom');
        }
    }
    return $descriptions;
}

/**
 * Log the view and mark the activity viewed for completion.
 *
 * @param stdClass $vrroom The vrroom row.
 * @param stdClass $course The course.
 * @param cm_info $cm The course module.
 * @param context_module $context The module context.
 */
function vrroom_view(stdClass $vrroom, stdClass $course, cm_info $cm, context_module $context): void {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $event = \mod_vrroom\event\course_module_viewed::create([
        'objectid' => $vrroom->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('vrroom', $vrroom);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Grades of one or all users.
 *
 * @param stdClass $vrroom The vrroom row.
 * @param int $userid One user, or 0 for all.
 * @return array userid => grade object
 */
function vrroom_get_user_grades(stdClass $vrroom, int $userid = 0): array {
    return grader::get_user_grades($vrroom, $userid);
}

/**
 * Create or update the grade item, optionally with grades.
 *
 * @param stdClass $vrroom The vrroom row.
 * @param mixed $grades Grades, or 'reset' to reset the gradebook.
 * @return int 0 when OK, an error code otherwise.
 */
function vrroom_grade_item_update(stdClass $vrroom, $grades = null): int {
    return grader::grade_item_update($vrroom, $grades);
}

/**
 * Delete the grade item.
 *
 * @param stdClass $vrroom The vrroom row.
 * @return int 0 when OK, an error code otherwise.
 */
function vrroom_grade_item_delete(stdClass $vrroom): int {
    return grader::grade_item_delete($vrroom);
}

/**
 * Push grades to the gradebook.
 *
 * @param stdClass $vrroom The vrroom row.
 * @param int $userid One user, or 0 for all.
 * @param bool $nullifnone Send a null grade when the user has none.
 */
function vrroom_update_grades(stdClass $vrroom, int $userid = 0, bool $nullifnone = true): void {
    grader::update_grades($vrroom, $userid, $nullifnone);
}

/**
 * Whether a scale is used by an activity. VR room uses point grades only.
 *
 * @param int $vrroomid The vrroom id.
 * @param int $scaleid The scale id.
 * @return bool
 */
function vrroom_scale_used(int $vrroomid, int $scaleid): bool {
    return false;
}

/**
 * Whether a scale is used by any VR room activity. VR room uses point grades only.
 *
 * @param int $scaleid The scale id.
 * @return bool
 */
function vrroom_scale_used_anywhere(int $scaleid): bool {
    return false;
}

/**
 * Add the course reset elements.
 *
 * @param MoodleQuickForm $mform The reset form.
 */
function vrroom_reset_course_form_definition(&$mform): void {
    reset::course_form_definition($mform);
}

/**
 * Defaults for the course reset elements.
 *
 * @param stdClass $course The course.
 * @return array
 */
function vrroom_reset_course_form_defaults(stdClass $course): array {
    return reset::course_form_defaults($course);
}

/**
 * Reset the grades of every VR room activity in a course.
 *
 * @param int $courseid The course.
 * @param string $type Unused (all VR room activities have one grade item).
 */
function vrroom_reset_gradebook(int $courseid, string $type = ''): void {
    reset::reset_gradebook($courseid);
}

/**
 * Delete user data on course reset.
 *
 * @param stdClass $data The reset form data.
 * @return array Status rows.
 */
function vrroom_reset_userdata(stdClass $data): array {
    return reset::reset_userdata($data);
}

/**
 * Serve the files of the models, media, environment and assets areas.
 *
 * GLB files and anything that is not a plain raster image are sent as attachments, so no
 * uploaded active content is ever rendered inline; fetch() from the viewer still works.
 *
 * @param stdClass $course The course.
 * @param stdClass $cm The course module.
 * @param context $context The context.
 * @param string $filearea The file area.
 * @param array $args itemid, then the path.
 * @param bool $forcedownload Whether the caller asked for a download.
 * @param array $options Extra options for send_stored_file().
 * @return bool False when the file is not found.
 */
function vrroom_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);
    require_capability('mod/vrroom:view', $context);

    if (!in_array($filearea, ['models', 'media', 'environment', 'assets'], true)) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    if ($filename === null || $filename === '') {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_vrroom', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    $inline = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    $force = !in_array($file->get_mimetype(), $inline, true) || $forcedownload;
    send_stored_file($file, null, 0, $force, $options);
}

/**
 * Serve files that belong to questions in a VR room attempt.
 *
 * @param stdClass $course The course.
 * @param context $context The context.
 * @param string $component The question component.
 * @param string $filearea The file area.
 * @param int $qubaid The question usage id.
 * @param int $slot The usage slot.
 * @param array $args The remaining path.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Extra options for send_stored_file().
 */
function vrroom_question_pluginfile(
    $course,
    $context,
    $component,
    $filearea,
    $qubaid,
    $slot,
    $args,
    $forcedownload,
    array $options = []
) {
    \mod_vrroom\local\questions\files::serve_question_file(
        $course,
        $context,
        $component,
        $filearea,
        $qubaid,
        $slot,
        $args,
        $forcedownload,
        $options
    );
}

/**
 * Add the Room editor and Reports links to the activity's settings navigation.
 *
 * @param settings_navigation $settings The settings navigation.
 * @param navigation_node $node The activity's node.
 */
function vrroom_extend_settings_navigation(settings_navigation $settings, navigation_node $node): void {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }
    if (has_capability('mod/vrroom:manage', $cm->context)) {
        $url = new moodle_url('/mod/vrroom/edit.php', ['id' => $cm->id]);
        $node->add_node(navigation_node::create(
            get_string('editor', 'vrroom'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'mod_vrroom_editor',
            new pix_icon('t/edit', '')
        ));
    }
    if (has_capability('mod/vrroom:viewreports', $cm->context)) {
        $url = new moodle_url('/mod/vrroom/report.php', ['id' => $cm->id]);
        $node->add_node(navigation_node::create(
            get_string('reports', 'vrroom'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'mod_vrroom_reports',
            new pix_icon('i/report', '')
        ));
    }
}

/**
 * File areas for the file browser.
 *
 * @param stdClass $course The course.
 * @param stdClass $cm The course module.
 * @param context $context The context.
 * @return array area => name
 */
function vrroom_get_file_areas($course, $cm, $context): array {
    return [
        'models' => get_string('area_models', 'vrroom'),
        'media' => get_string('area_media', 'vrroom'),
        'environment' => get_string('area_environment', 'vrroom'),
        'assets' => get_string('area_assets', 'vrroom'),
    ];
}

/**
 * Activity chooser items for a course: one per installed template.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The module's default item.
 * @param \stdClass $user The user the chooser is built for.
 * @param \stdClass $course The course.
 * @return \core_course\local\entity\content_item[]
 */
function mod_vrroom_get_course_content_items(
    \core_course\local\entity\content_item $defaultmodulecontentitem,
    \stdClass $user,
    \stdClass $course
): array {
    return \mod_vrroom\local\template\chooser::content_items($defaultmodulecontentitem);
}

/**
 * Activity chooser items of the module for every course (admin "recommended activities" and favourites).
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The module's default item.
 * @return \core_course\local\entity\content_item[]
 */
function mod_vrroom_get_all_content_items(\core_course\local\entity\content_item $defaultmodulecontentitem): array {
    return \mod_vrroom\local\template\chooser::content_items($defaultmodulecontentitem);
}
