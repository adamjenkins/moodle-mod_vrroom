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
 * Base class every mavrtemplate sub-plugin extends as \mavrtemplate_<name>\template.
 *
 * A template is a set of form fields that compiles into scene documents, a task graph and
 * question bindings.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var string Frankenstyle prefix of the sub-plugin type. */
    public const PREFIX = 'mavrtemplate_';

    /**
     * Frankenstyle component of the template, taken from the class's namespace.
     *
     * @return string e.g. 'mavrtemplate_gallery'
     */
    final public function get_component(): string {
        $component = strstr(static::class, '\\', true);
        if ($component === false || !str_starts_with($component, self::PREFIX) || $component === self::PREFIX) {
            throw new \coding_exception('A template class must live in a mavrtemplate_<name> namespace: ' . static::class);
        }
        return $component;
    }

    /**
     * Short name of the template.
     *
     * @return string e.g. 'gallery'
     */
    final public function get_name(): string {
        return substr($this->get_component(), strlen(self::PREFIX));
    }

    /**
     * Name shown to teachers.
     *
     * @return string
     */
    public function get_display_name(): string {
        return get_string('pluginname', $this->get_component());
    }

    /**
     * Add this template's elements to the activity form.
     *
     * Every element name MUST start with "<name>_".
     *
     * @param \moodleform_mod $form The activity form.
     * @param \MoodleQuickForm $mform Its QuickForm.
     * @param int $courseid The course.
     * @return string[] Names of the top-level elements or groups added; mod_form hides each one
     *     unless this template is selected.
     */
    abstract public function define_form(\moodleform_mod $form, \MoodleQuickForm $mform, int $courseid): array;

    /**
     * Fill the form defaults from a stored config and prepare draft file areas.
     *
     * @param array $defaultvalues Form defaults, changed in place.
     * @param \stdClass|null $config Stored config, null when the activity is being created.
     * @param \context_module|null $context Module context, null when the activity is being created.
     */
    abstract public function data_preprocessing(array &$defaultvalues, ?\stdClass $config, ?\context_module $context): void;

    /**
     * Validate the template's fields. Called only when this template is selected.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @param int $courseid The course.
     * @return array element name => error string
     */
    abstract public function validation(array $data, array $files, int $courseid): array;

    /**
     * Turn form data into the JSON-able config stored in mavr.templateconfig.
     *
     * Must tolerate absent file fields (generators and web services do not send them).
     *
     * @param \stdClass $formdata Form data.
     * @return \stdClass
     */
    abstract public function extract_config(\stdClass $formdata): \stdClass;

    /**
     * Save draft file areas from the form data into the module's file areas.
     *
     * A no-op for fields that are absent.
     *
     * @param \stdClass $formdata Form data.
     * @param \context_module $context Module context.
     */
    abstract public function save_files(\stdClass $formdata, \context_module $context): void;

    /**
     * Build the scenes, the task graph and the question bindings from a config.
     *
     * Pure apart from reading files.
     *
     * @param \stdClass $config The stored config.
     * @param \context_module $context Module context.
     * @return compiled_activity
     */
    abstract public function compile(\stdClass $config, \context_module $context): compiled_activity;

    /**
     * Completion rules this template offers.
     *
     * @return string[] e.g. ['completionobjectives']
     */
    abstract public function completion_rules(): array;

    /**
     * Default maximum grade for new activities.
     *
     * @return int
     */
    abstract public function default_grade(): int;
}
