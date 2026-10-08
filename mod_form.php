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
 * Activity settings form for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_vrroom\local\grading\grader;
use mod_vrroom\local\instance_manager;
use mod_vrroom\local\questions\mode;
use mod_vrroom\local\runtime\registry as runtime_registry;
use mod_vrroom\local\template\registry as template_registry;

/**
 * Activity settings form: general settings, run mode, template fields, live mode, attempts and grading.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_vrroom_mod_form extends moodleform_mod {
    /** @var string|null|false Resolved template name, null for none, false until resolved. */
    private string|null|false $resolvedtemplate = false;

    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;
        $courseid = (int)$this->current->course;

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Run mode and template.
        $mform->addElement('header', 'vrroomsettings', get_string('vrroomsettings', 'vrroom'));
        $mform->setExpanded('vrroomsettings');

        $mform->addElement('select', 'runtime', get_string('runtime', 'vrroom'), runtime_registry::get_choices());
        $mform->setDefault('runtime', 'builtin');
        $mform->addHelpButton('runtime', 'runtime', 'vrroom');

        // Registered on every request, so the reload that follows a press still counts as a
        // no-submit press although the select has become a hidden field by then.
        $mform->registerNoSubmitButton('vrroom_choosetemplate');

        $name = $this->resolve_template();
        if ($name === null && $this->_instance) {
            // The activity's template is no longer installed: nothing to show or change.
            $mform->addElement(
                'static',
                'templatemissing',
                get_string('template', 'vrroom'),
                get_string('errorunknowntemplate', 'vrroom', s((string)($this->current->template ?? '')))
            );
        } else if ($name !== null) {
            $template = template_registry::get($name);
            $mform->addElement('hidden', 'template', $name);
            $mform->setType('template', PARAM_PLUGIN);
            $mform->setDefault('template', $name);
            $mform->addElement('static', 'templatename', get_string('template', 'vrroom'), s($template->get_display_name()));
            if ($this->_instance) {
                $mform->addElement('static', 'templatelocked', '', get_string('templatelocked', 'vrroom'));
            } else if (count(template_registry::get_all()) > 1) {
                $mform->addElement('static', 'templatechosen', '', get_string('templatechosen', 'vrroom'));
            }

            if ($this->is_customised()) {
                // The room editor owns the room now: the template's fields would not be used.
                $mform->addElement('static', 'customisednotice', '', get_string('customisednotice', 'vrroom'));
                $mform->addElement('advcheckbox', 'vrroom_rebuild', get_string('rebuild', 'vrroom'));
                $mform->addHelpButton('vrroom_rebuild', 'rebuild', 'vrroom');
                $mform->setDefault('vrroom_rebuild', 0);
            } else {
                $template->define_form($this, $mform, $courseid);
            }
        } else {
            // Reached without a template (e.g. a bare modedit.php?add=vrroom link): choose one first.
            // The no-submit button reloads the form with the template's fields, as the course
            // format select does (course/edit_form.php).
            $mform->addElement('select', 'template', get_string('template', 'vrroom'), template_registry::get_choices());
            $mform->setType('template', PARAM_PLUGIN);
            $mform->addHelpButton('template', 'template', 'vrroom');
            $mform->addElement('submit', 'vrroom_choosetemplate', get_string('choosetemplatebutton', 'vrroom'));
        }

        // Live mode.
        $this->define_live_mode($mform);

        // Attempts and grading.
        $mform->addElement('header', 'attemptsandgrading', get_string('attemptsandgrading', 'vrroom'));

        $mform->addElement('select', 'grademethod', get_string('grademethod', 'vrroom'), grader::grade_options());
        $mform->setDefault('grademethod', grader::GRADE_HIGHEST);
        $mform->addHelpButton('grademethod', 'grademethod', 'vrroom');

        $attemptoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'vrroom'), $attemptoptions);
        $mform->setDefault('maxattempts', 0);

        $mform->addElement(
            'select',
            'questionmode',
            get_string('questionmode', 'vrroom'),
            mode::mode_options()
        );
        $mform->setDefault('questionmode', mode::ONCE);
        $mform->addHelpButton('questionmode', 'questionmode', 'vrroom');
        $mform->addElement(
            'select',
            'retrypenalty',
            get_string('retrypenalty', 'vrroom'),
            mode::penalty_options()
        );
        $mform->setDefault('retrypenalty', (string)mode::DEFAULT_PENALTY);
        $mform->addHelpButton('retrypenalty', 'retrypenalty', 'vrroom');
        $mform->hideIf('retrypenalty', 'questionmode', 'neq', mode::RETRY);

        $this->standard_grading_coursemodule_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepare the defaults: the stored template config fills the template's fields.
     *
     * Only the resolved template is asked, and only when its fields are on the form (not for a
     * customised room, whose template fields are not shown).
     *
     * @param array $defaultvalues Form defaults, changed in place.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        $name = $this->resolve_template();
        if ($name === null || $this->is_customised()) {
            return;
        }
        $template = template_registry::get($name);

        if ($this->_instance && !empty($this->_cm)) {
            $config = instance_manager::decode_config($defaultvalues['templateconfig'] ?? null);
            $template->data_preprocessing($defaultvalues, $config, context_module::instance($this->_cm->id));
            return;
        }
        $template->data_preprocessing($defaultvalues, null, null);
    }

    /**
     * Validate the form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array element name => error
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['grade']) && (int)$data['grade'] < 0) {
            $errors['grade'] = get_string('errorscalenotsupported', 'vrroom');
        }

        $errors = array_merge($errors, $this->validate_live_mode($data));

        $name = $this->resolve_template();
        if ($name === null && $this->_instance) {
            $errors['name'] = get_string('errorunknowntemplate', 'vrroom', s((string)($this->current->template ?? '')));
            return $errors;
        }
        if ($name === null) {
            $errors['template'] = get_string('choosetemplatefirst', 'vrroom');
            return $errors;
        }
        if ($this->is_customised()) {
            // The template's fields are not on the form, so there is nothing of it to validate.
            return $errors;
        }
        $template = template_registry::get($name);
        return array_merge($errors, $template->validation($data, $files, (int)$this->current->course));
    }

    /**
     * Add the live mode settings, offered only when an installed run mode can run a live room.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    protected function define_live_mode(\MoodleQuickForm $mform): void {
        $livecapable = [];
        $livemax = 0;
        foreach (runtime_registry::get_all() as $runtimename => $runtime) {
            $max = $runtime->capabilities()->maxusers;
            if ($max >= 2) {
                $livecapable[] = $runtimename;
                $livemax = max($livemax, $max);
            }
        }
        $mform->addElement('header', 'livemodeheader', get_string('livemodeheader', 'vrroom'));
        if (!$livecapable) {
            $mform->addElement('static', 'livemodeunavailable', '', get_string('livemodeunavailable', 'vrroom'));
            return;
        }
        $mform->addElement(
            'select',
            'livemode',
            get_string('livemode', 'vrroom'),
            [0 => get_string('livemodeoff', 'vrroom'), 1 => get_string('livemodeon', 'vrroom')]
        );
        $mform->setDefault('livemode', 0);
        $mform->addHelpButton('livemode', 'livemode', 'vrroom');
        $options = [];
        for ($i = 2; $i <= $livemax; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'livemaxusers', get_string('livemaxusers', 'vrroom'), $options);
        $mform->setDefault('livemaxusers', min(6, $livemax));
        $mform->addHelpButton('livemaxusers', 'livemaxusers', 'vrroom');
        $mform->hideIf('livemaxusers', 'livemode', 'eq', 0);
        foreach (array_diff(array_keys(runtime_registry::get_all()), $livecapable) as $other) {
            $mform->hideIf('livemode', 'runtime', 'eq', $other);
            $mform->hideIf('livemaxusers', 'runtime', 'eq', $other);
        }
    }

    /**
     * Check the live mode settings against what the chosen run mode can do.
     *
     * @param array $data Submitted data.
     * @return array element name => error
     */
    protected function validate_live_mode(array $data): array {
        if (empty($data['livemode'])) {
            return [];
        }
        $runtimename = (string)($data['runtime'] ?? ($this->current->runtime ?? 'builtin'));
        $all = runtime_registry::get_all();
        $max = isset($all[$runtimename]) ? $all[$runtimename]->capabilities()->maxusers : 0;
        if ($max < 2) {
            // The live fields are hidden for this run mode, so the error goes on the run mode select.
            return ['runtime' => get_string('errorlivenotsupported', 'vrroom')];
        }
        $users = (int)($data['livemaxusers'] ?? 0);
        if ($users < 2 || $users > $max) {
            return ['livemaxusers' => get_string('errorlivemaxusers', 'vrroom', $max)];
        }
        return [];
    }

    /**
     * Add the custom completion rule.
     *
     * @return string[] Names of the added elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = 'completionobjectives' . $this->get_suffix();
        $mform->addElement('advcheckbox', $name, '', get_string('completionobjectives', 'vrroom'));
        $mform->addHelpButton($name, 'completionobjectives', 'vrroom');
        return [$name];
    }

    /**
     * Whether the custom completion rule is enabled in the submitted data.
     *
     * @param array $data Submitted data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionobjectives' . $this->get_suffix()]);
    }

    /**
     * Clear the custom completion rule unless completion is automatic.
     *
     * @param stdClass $data Submitted data, changed in place.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $this->add_stored_template_values($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? COMPLETION_TRACKING_NONE;
            if ($completion != COMPLETION_TRACKING_AUTOMATIC) {
                $data->{'completionobjectives' . $suffix} = 0;
            }
        }
    }

    /**
     * The template this form is for.
     *
     * An existing activity keeps its template. A new one takes the template parameter (from the
     * activity chooser link, the hidden field, or the template select on a reload) when that
     * template is installed, else the only installed template, else none.
     *
     * @return string|null Template short name, or null when the teacher has to choose one first.
     */
    protected function resolve_template(): ?string {
        if ($this->resolvedtemplate !== false) {
            return $this->resolvedtemplate;
        }
        $all = template_registry::get_all();
        $name = null;
        if ($this->_instance) {
            $current = (string)($this->current->template ?? '');
            $name = isset($all[$current]) ? $current : null;
        } else {
            $requested = optional_param('template', '', PARAM_PLUGIN);
            if ($requested !== '' && isset($all[$requested])) {
                $name = $requested;
            } else if (count($all) === 1) {
                $name = (string)array_key_first($all);
            }
        }
        $this->resolvedtemplate = $name;
        return $name;
    }

    /**
     * Whether the room of the activity being edited was changed in the room editor.
     *
     * @return bool
     */
    protected function is_customised(): bool {
        return $this->_instance && (int)($this->current->customised ?? 0) > 0;
    }

    /**
     * For "Rebuild from the template" on a customised room, add the stored template settings.
     *
     * A customised room's form does not show the template's fields, so a rebuild would otherwise
     * compile from empty fields. The template's own data_preprocessing() turns the stored config
     * back into form values (draft file areas included), exactly as if the unchanged fields had
     * been submitted. Only absent "<template>_" keys are added.
     *
     * @param stdClass $data Submitted data, changed in place.
     */
    protected function add_stored_template_values(stdClass $data): void {
        if (!$this->is_customised() || empty($data->vrroom_rebuild) || empty($this->_cm)) {
            return;
        }
        $name = $this->resolve_template();
        if ($name === null) {
            return;
        }
        $template = template_registry::get($name);
        $values = [];
        $config = instance_manager::decode_config($this->current->templateconfig ?? null);
        $template->data_preprocessing($values, $config, context_module::instance($this->_cm->id));
        $prefix = $name . '_';
        foreach ($values as $key => $value) {
            if (str_starts_with((string)$key, $prefix) && !property_exists($data, $key)) {
                $data->{$key} = $value;
            }
        }
    }
}
