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
 * Activity settings form for mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_mavr\local\grading\grader;
use mod_mavr\local\instance_manager;
use mod_mavr\local\runtime\registry as runtime_registry;
use mod_mavr\local\template\registry as template_registry;

/**
 * Activity settings form: general settings, run mode, template fields, attempts and grading.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_mavr_mod_form extends moodleform_mod {
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
        $mform->addElement('header', 'mavrsettings', get_string('mavrsettings', 'mavr'));
        $mform->setExpanded('mavrsettings');

        $mform->addElement('select', 'runtime', get_string('runtime', 'mavr'), runtime_registry::get_choices());
        $mform->setDefault('runtime', 'builtin');
        $mform->addHelpButton('runtime', 'runtime', 'mavr');

        $mform->addElement('select', 'template', get_string('template', 'mavr'), template_registry::get_choices());
        $mform->setDefault('template', 'gallery');
        $mform->addHelpButton('template', 'template', 'mavr');
        if ($this->_instance) {
            $mform->hardFreeze('template');
            $mform->addElement('static', 'templatelocked', '', get_string('templatelocked', 'mavr'));
        }

        foreach (template_registry::get_all() as $name => $template) {
            if ($this->_instance && $name !== $this->current->template) {
                // The template is fixed after creation; other templates' fields are never needed.
                continue;
            }
            foreach ($template->define_form($this, $mform, $courseid) as $elementname) {
                $mform->hideIf($elementname, 'template', 'neq', $name);
            }
        }

        // Attempts and grading.
        $mform->addElement('header', 'attemptsandgrading', get_string('attemptsandgrading', 'mavr'));

        $mform->addElement('select', 'grademethod', get_string('grademethod', 'mavr'), grader::grade_options());
        $mform->setDefault('grademethod', grader::GRADE_HIGHEST);
        $mform->addHelpButton('grademethod', 'grademethod', 'mavr');

        $attemptoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mavr'), $attemptoptions);
        $mform->setDefault('maxattempts', 0);

        $this->standard_grading_coursemodule_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepare the defaults: the stored template config fills the template's fields.
     *
     * @param array $defaultvalues Form defaults, changed in place.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if ($this->_instance && !empty($this->_cm)) {
            $templatename = $defaultvalues['template'] ?? $this->current->template ?? '';
            try {
                $template = template_registry::get((string)$templatename);
            } catch (\moodle_exception $e) {
                return;
            }
            $config = instance_manager::decode_config($defaultvalues['templateconfig'] ?? null);
            $template->data_preprocessing($defaultvalues, $config, context_module::instance($this->_cm->id));
            return;
        }

        foreach (template_registry::get_all() as $template) {
            $template->data_preprocessing($defaultvalues, null, null);
        }
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
            $errors['grade'] = get_string('errorscalenotsupported', 'mavr');
        }

        $templatename = $this->_instance ? ($this->current->template ?? '') : ($data['template'] ?? '');
        try {
            $template = template_registry::get((string)$templatename);
        } catch (\moodle_exception $e) {
            $errors['template'] = get_string('errorunknowntemplate', 'mavr', s((string)$templatename));
            return $errors;
        }
        return array_merge($errors, $template->validation($data, $files, (int)$this->current->course));
    }

    /**
     * Add the custom completion rule.
     *
     * @return string[] Names of the added elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = 'completionobjectives' . $this->get_suffix();
        $mform->addElement('advcheckbox', $name, '', get_string('completionobjectives', 'mavr'));
        $mform->addHelpButton($name, 'completionobjectives', 'mavr');
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
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? COMPLETION_TRACKING_NONE;
            if ($completion != COMPLETION_TRACKING_AUTOMATIC) {
                $data->{'completionobjectives' . $suffix} = 0;
            }
        }
    }
}
