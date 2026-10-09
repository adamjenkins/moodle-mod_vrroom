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

namespace vrroomtemplate_escape;

use vrroomtemplate_escape\local\layout;
use mod_vrroom\form\environment_preview;
use mod_vrroom\local\questions\bank;
use mod_vrroom\local\scene\environment_registry;
use mod_vrroom\local\template\compiled_activity;

/**
 * Escape chain template: a room of puzzles solved one after the other, then an exit door.
 *
 * The form fields are stored as a vrroomtemplate_escape/1 config in vrroom.templateconfig and compiled
 * into one vrroom-scene/1 room plus a vrroom-graph/1 task graph. Each puzzle has a zone with a desk
 * and a clue; finding the clue (visiting the zone) is followed by its lock: nothing, a key that
 * appears on the desk and must be taken, or a question (a code lock that can be retried until it
 * is right). Each puzzle opens only when the previous one is solved; the exit door appears when
 * every puzzle is solved, and using it finishes the attempt. Hints, a hint penalty and an
 * optional time limit go into the graph.
 *
 * @package    vrroomtemplate_escape
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template extends \mod_vrroom\local\template\base {
    /** @var string Frankenstyle component of this template (string and file lookups). */
    public const COMPONENT = 'vrroomtemplate_escape';

    /** @var string Schema identifier written into the stored config. */
    public const SCHEMA = 'vrroomtemplate_escape/1';

    /** @var string Environment preset chosen by default. */
    public const DEFAULT_ENVIRONMENT = 'escape-room';

    /** @var int Hints per puzzle. */
    public const MAX_HINTS = 2;

    /** @var int Puzzle groups shown on a new form. */
    public const DEFAULT_REPEATS = 3;

    /** @var int Puzzle groups added by the "Add more puzzles" button. */
    public const ADD_REPEATS = 2;

    /** @var int Longest puzzle title or key name, in characters. */
    public const MAX_TITLE_LENGTH = 255;

    /** @var int Longest hint text, in characters (the graph validator's limit). */
    public const MAX_HINT_LENGTH = 1000;

    /** @var int Longest time limit, in seconds (seven days, the graph validator's limit). */
    public const MAX_TIMELIMIT = 604800;

    /**
     * @var float Most points a puzzle or a hint penalty can be: a puzzle's question slot stores its
     *      points in vrroom_slots.maxmark, number(12,7), whose largest value is 99999.9999999.
     */
    public const MAX_POINTS = 99999.0;

    /** @var string Clue exhibit: text only. */
    public const MEDIA_NONE = 'none';

    /** @var string Clue exhibit: an image. */
    public const MEDIA_IMAGE = 'image';

    /** @var string Clue exhibit: a GLB model. */
    public const MEDIA_MODEL = 'model';

    /** @var string Lock: finding the clue is enough. */
    public const LOCK_NONE = 'none';

    /** @var string Lock: find and take a key. */
    public const LOCK_KEY = 'key';

    /** @var string Lock: answer a question. */
    public const LOCK_QUESTION = 'question';

    /** @var string[] Image types a clue accepts (all are served inline by vrroom_pluginfile). */
    public const IMAGE_TYPES = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    /** @var string Scene id of the single spawn point. */
    public const SPAWN_ID = 's-start';

    /** @var string Colour of a puzzle's desk. */
    public const DESK_COLOUR = '#6b4f3a';

    /** @var string Colour of a key. */
    public const KEY_COLOUR = '#d4af37';

    /** @var string Colour of the exit door. */
    public const DOOR_COLOUR = '#6b2f2f';

    /** @var int|null Instance id of the activity being edited, captured from the form in define_form(). */
    private ?int $editinginstance = null;

    /** @var int|null Course id that $editinginstance was captured for. */
    private ?int $editingcourse = null;

    // Form.

    /**
     * Add the escape chain fields to the activity form.
     *
     * @param \moodleform_mod $form the activity form (owner of repeat_elements())
     * @param \MoodleQuickForm $mform its QuickForm
     * @param int $courseid course the activity is in
     * @return string[] names of the non-repeated elements added (headers excluded)
     */
    public function define_form(\moodleform_mod $form, \MoodleQuickForm $mform, int $courseid): array {
        $instance = (int)$form->get_instance();
        $this->editinginstance = $instance > 0 ? $instance : null;
        $this->editingcourse = $courseid;
        $config = $this->editinginstance ? self::load_stored_config($this->editinginstance, $courseid) : null;
        $puzzles = $config ? self::config_puzzles($config) : [];

        $mform->addElement('header', 'escape_heading', get_string('escapeheading', self::COMPONENT));
        $mform->setExpanded('escape_heading');

        // Presets and installed pack rooms grouped by category; a stored room this site lacks stays a
        // choice (selectgroups drops any submitted value that is not an option).
        $storedenvironment = $config !== null && is_string($config->environment ?? null) ? $config->environment : null;
        $environments = environment_registry::get_choices($storedenvironment);
        $mform->addElement('selectgroups', 'escape_environment', get_string('environment', self::COMPONENT), $environments);
        $mform->setDefault('escape_environment', self::DEFAULT_ENVIRONMENT);
        $mform->addHelpButton('escape_environment', 'environment', self::COMPONENT);
        environment_preview::add(
            $mform,
            'escape_environment',
            $environments,
            $storedenvironment ?? self::DEFAULT_ENVIRONMENT,
            ['previewplaces', self::COMPONENT]
        );

        $mform->addElement('textarea', 'escape_story', get_string('story', self::COMPONENT), ['rows' => 3, 'cols' => 60]);
        $mform->setType('escape_story', PARAM_TEXT);
        $mform->addHelpButton('escape_story', 'story', self::COMPONENT);

        $mform->addElement(
            'duration',
            'escape_timelimit',
            get_string('timelimit', self::COMPONENT),
            ['optional' => true, 'defaultunit' => 60]
        );
        $mform->addHelpButton('escape_timelimit', 'timelimit', self::COMPONENT);

        $mform->addElement('float', 'escape_hintpenalty', get_string('hintpenalty', self::COMPONENT));
        $mform->setDefault('escape_hintpenalty', 0);
        $mform->addHelpButton('escape_hintpenalty', 'hintpenalty', self::COMPONENT);

        // Question choices: the course's banks, plus questions this activity already uses that the
        // current teacher cannot list (so editing never silently drops them).
        $questions = [0 => get_string('noquestion', self::COMPONENT)] + bank::get_choices($courseid);
        foreach ($puzzles as $puzzle) {
            $qbeid = (int)($puzzle->questionbankentryid ?? 0);
            if ($qbeid > 0 && !array_key_exists($qbeid, $questions)) {
                $questions[$qbeid] = self::question_label($qbeid);
            }
        }

        $repeated = [
            $mform->createElement('static', 'escape_puzzleheader', get_string('puzzle', self::COMPONENT), ''),
            $mform->createElement('hidden', 'escape_puzzleid', ''),
            $mform->createElement(
                'text',
                'escape_title',
                get_string('puzzletitle', self::COMPONENT),
                ['size' => 50, 'maxlength' => self::MAX_TITLE_LENGTH]
            ),
            $mform->createElement(
                'editor',
                'escape_clue',
                get_string('clue', self::COMPONENT),
                ['rows' => 5],
                ['maxfiles' => 0, 'noclean' => false]
            ),
            $mform->createElement('select', 'escape_cluemedia', get_string('cluemedia', self::COMPONENT), [
                self::MEDIA_NONE => get_string('cluemedia_none', self::COMPONENT),
                self::MEDIA_IMAGE => get_string('cluemedia_image', self::COMPONENT),
                self::MEDIA_MODEL => get_string('cluemedia_model', self::COMPONENT),
            ]),
            $mform->createElement(
                'filemanager',
                'escape_clueimage',
                get_string('clueimage', self::COMPONENT),
                null,
                self::image_file_options()
            ),
            $mform->createElement(
                'filemanager',
                'escape_cluemodel',
                get_string('cluemodel', self::COMPONENT),
                null,
                self::model_file_options()
            ),
            $mform->createElement('select', 'escape_locktype', get_string('locktype', self::COMPONENT), [
                self::LOCK_NONE => get_string('locktype_none', self::COMPONENT),
                self::LOCK_KEY => get_string('locktype_key', self::COMPONENT),
                self::LOCK_QUESTION => get_string('locktype_question', self::COMPONENT),
            ]),
            $mform->createElement(
                'text',
                'escape_keyname',
                get_string('keyname', self::COMPONENT),
                ['size' => 30, 'maxlength' => self::MAX_TITLE_LENGTH]
            ),
            $mform->createElement(
                'autocomplete',
                'escape_question',
                get_string('question', self::COMPONENT),
                $questions,
                ['multiple' => false]
            ),
            $mform->createElement('textarea', 'escape_hint1', get_string('hint1', self::COMPONENT), ['rows' => 2, 'cols' => 60]),
            $mform->createElement('textarea', 'escape_hint2', get_string('hint2', self::COMPONENT), ['rows' => 2, 'cols' => 60]),
            $mform->createElement('float', 'escape_points', get_string('points', self::COMPONENT)),
        ];
        $options = [
            'escape_puzzleid' => ['type' => PARAM_ALPHANUMEXT],
            'escape_title' => ['type' => PARAM_TEXT],
            'escape_clue' => ['type' => PARAM_RAW],
            'escape_cluemedia' => ['type' => PARAM_ALPHA, 'default' => self::MEDIA_NONE],
            'escape_clueimage' => ['hideif' => ['escape_cluemedia', 'neq', self::MEDIA_IMAGE]],
            'escape_cluemodel' => ['hideif' => ['escape_cluemedia', 'neq', self::MEDIA_MODEL]],
            'escape_locktype' => [
                'type' => PARAM_ALPHA,
                'default' => self::LOCK_KEY,
                'helpbutton' => ['locktype', self::COMPONENT],
            ],
            'escape_keyname' => [
                'type' => PARAM_TEXT,
                'default' => get_string('keyname_default', self::COMPONENT),
                'hideif' => ['escape_locktype', 'neq', self::LOCK_KEY],
            ],
            'escape_question' => [
                'type' => PARAM_INT,
                'default' => 0,
                'helpbutton' => ['question', self::COMPONENT],
                'hideif' => ['escape_locktype', 'neq', self::LOCK_QUESTION],
            ],
            'escape_hint1' => ['type' => PARAM_TEXT, 'helpbutton' => ['hint1', self::COMPONENT]],
            'escape_hint2' => ['type' => PARAM_TEXT],
            'escape_points' => ['default' => 1, 'helpbutton' => ['points', self::COMPONENT]],
        ];
        $form->repeat_elements(
            $repeated,
            max(count($puzzles), self::DEFAULT_REPEATS),
            $options,
            'escape_puzzlecount',
            'escape_addpuzzles',
            self::ADD_REPEATS,
            get_string('addpuzzles', self::COMPONENT)
        );

        return ['escape_environment', 'escape_story', 'escape_timelimit', 'escape_hintpenalty', 'escape_addpuzzles'];
    }

    /**
     * Fill the form defaults from a stored config and prepare the draft file areas.
     *
     * @param array $defaultvalues form defaults (by reference)
     * @param \stdClass|null $config stored config, null when creating
     * @param \context_module|null $context module context, null when creating
     */
    public function data_preprocessing(array &$defaultvalues, ?\stdClass $config, ?\context_module $context): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($config === null) {
            if (!isset($defaultvalues['escape_environment'])) {
                $defaultvalues['escape_environment'] = self::DEFAULT_ENVIRONMENT;
            }
            return;
        }

        $defaultvalues['escape_environment'] = (string)($config->environment ?? self::DEFAULT_ENVIRONMENT);
        $defaultvalues['escape_story'] = (string)($config->story ?? '');
        $defaultvalues['escape_timelimit'] = self::clean_timelimit($config->timelimit ?? 0);
        $defaultvalues['escape_hintpenalty'] = self::clean_points($config->hintpenalty ?? 0);

        $puzzles = self::config_puzzles($config);
        foreach ($puzzles as $i => $puzzle) {
            $hints = self::clean_hints($puzzle->hints ?? []);
            $defaultvalues['escape_puzzleid'][$i] = (string)$puzzle->id;
            $defaultvalues['escape_title'][$i] = (string)($puzzle->title ?? '');
            $defaultvalues['escape_clue'][$i] = [
                'text' => (string)($puzzle->clue ?? ''),
                'format' => (string)($puzzle->clueformat ?? FORMAT_HTML),
            ];
            $defaultvalues['escape_cluemedia'][$i] = self::clean_cluemedia($puzzle->cluemedia ?? null);
            $defaultvalues['escape_locktype'][$i] = self::clean_locktype($puzzle->locktype ?? null);
            $defaultvalues['escape_keyname'][$i] = (string)($puzzle->keyname ?? '');
            $defaultvalues['escape_question'][$i] = (int)($puzzle->questionbankentryid ?? 0);
            $defaultvalues['escape_hint1'][$i] = $hints[0] ?? '';
            $defaultvalues['escape_hint2'][$i] = $hints[1] ?? '';
            $defaultvalues['escape_points'][$i] = self::clean_points($puzzle->points ?? 0);

            // The repeat_elements() 'default' options are stored under flat keys such as
            // 'escape_locktype[0]', and QuickForm looks a flat key up before the nested array,
            // so the stored values must be given under the same flat keys to win (as mod_quiz does).
            $defaulted = ['escape_cluemedia', 'escape_locktype', 'escape_keyname', 'escape_question', 'escape_points'];
            foreach ($defaulted as $field) {
                $defaultvalues["{$field}[{$i}]"] = $defaultvalues[$field][$i];
            }

            if ($context !== null) {
                $itemid = (int)($puzzle->fileitemid ?? ($i + 1));
                $imagedraft = 0;
                file_prepare_draft_area(
                    $imagedraft,
                    $context->id,
                    'mod_vrroom',
                    'media',
                    $itemid,
                    self::image_file_options()
                );
                $defaultvalues['escape_clueimage'][$i] = $imagedraft;
                $modeldraft = 0;
                file_prepare_draft_area(
                    $modeldraft,
                    $context->id,
                    'mod_vrroom',
                    'models',
                    $itemid,
                    self::model_file_options()
                );
                $defaultvalues['escape_cluemodel'][$i] = $modeldraft;
            }
        }
        $defaultvalues['escape_puzzlecount'] = max(count($puzzles), self::DEFAULT_REPEATS);
    }

    /**
     * Validate the escape chain fields.
     *
     * @param array $data submitted data
     * @param array $files submitted files (unused: draft areas are read from $data)
     * @param int $courseid course the activity is in
     * @return array element name => error message
     */
    public function validation(array $data, array $files, int $courseid): array {
        $errors = [];

        $environment = (string)($data['escape_environment'] ?? self::DEFAULT_ENVIRONMENT);
        $preset = environment_registry::resolve($environment, $this->stored_snapshot($environment, $courseid));
        if ($preset === null) {
            $errors['escape_environment'] = get_string('required');
            return $errors;
        }

        // Compared as floats: a huge value (1e30 weeks) must not wrap around as an integer. Core's
        // duration element hands over whole seconds, already rounded with an integer cast that turns
        // such a value into 0 (no limit), so the number as typed is checked too.
        $timelimit = $data['escape_timelimit'] ?? 0;
        if (
            !is_numeric($timelimit) || !is_finite((float)$timelimit)
                || (float)$timelimit < 0 || (float)$timelimit > self::MAX_TIMELIMIT
                || self::typed_timelimit() > self::MAX_TIMELIMIT
        ) {
            $errors['escape_timelimit'] = get_string('errortimelimit', self::COMPONENT);
        }
        $penalty = $data['escape_hintpenalty'] ?? 0;
        if (!is_numeric($penalty) || (float)$penalty < 0) {
            $errors['escape_hintpenalty'] = get_string('errorhintpenalty', self::COMPONENT);
        } else if (!self::points_in_range($penalty)) {
            $errors['escape_hintpenalty'] = get_string('errorhintpenaltyrange', self::COMPONENT, format_float(self::MAX_POINTS, 0));
        }

        // Rows without a title but with other content are a mistake, not a blank row.
        foreach (self::all_row_indexes($data) as $i) {
            if (self::row_title($data, $i) === '' && self::row_has_content($data, $i)) {
                $errors["escape_title[{$i}]"] = get_string('errortitlerequired', self::COMPONENT);
            }
        }

        $rows = self::puzzle_rows($data);
        if (!$rows) {
            $errors['escape_title[0]'] = get_string('errornopuzzles', self::COMPONENT);
            return $errors;
        }
        $limit = count($preset['anchors'] ?? []);
        if (count($rows) > $limit) {
            $errors['escape_environment'] = get_string('errortoomanypuzzles', self::COMPONENT, $limit);
        }

        $keep = [];
        if ($this->editinginstance !== null && $this->editingcourse === $courseid) {
            $stored = self::load_stored_config($this->editinginstance, $courseid);
            foreach ($stored ? self::config_puzzles($stored) : [] as $puzzle) {
                if (!empty($puzzle->questionbankentryid)) {
                    $keep[] = (int)$puzzle->questionbankentryid;
                }
            }
        }

        $usedquestions = [];
        foreach ($rows as $i) {
            if (\core_text::strlen(self::row_title($data, $i)) > self::MAX_TITLE_LENGTH) {
                $errors["escape_title[{$i}]"] = get_string('maximumchars', '', self::MAX_TITLE_LENGTH);
            }

            $cluemedia = self::clean_cluemedia($data['escape_cluemedia'][$i] ?? null);
            if ($cluemedia === self::MEDIA_IMAGE && !self::draft_has_files((int)($data['escape_clueimage'][$i] ?? 0))) {
                $errors["escape_clueimage[{$i}]"] = get_string('errorimagerequired', self::COMPONENT);
            }
            if ($cluemedia === self::MEDIA_MODEL && !self::draft_has_files((int)($data['escape_cluemodel'][$i] ?? 0))) {
                $errors["escape_cluemodel[{$i}]"] = get_string('errormodelrequired', self::COMPONENT);
            }

            $locktype = self::clean_locktype($data['escape_locktype'][$i] ?? null);
            if (
                $locktype === self::LOCK_KEY
                    && \core_text::strlen(self::row_keyname($data, $i)) > self::MAX_TITLE_LENGTH
            ) {
                $errors["escape_keyname[{$i}]"] = get_string('maximumchars', '', self::MAX_TITLE_LENGTH);
            }
            if ($locktype === self::LOCK_QUESTION) {
                $qbeid = (int)($data['escape_question'][$i] ?? 0);
                if ($qbeid <= 0) {
                    $errors["escape_question[{$i}]"] = get_string('errorquestionrequired', self::COMPONENT);
                } else if (isset($usedquestions[$qbeid])) {
                    $errors["escape_question[{$i}]"] = get_string('errorquestionduplicate', 'mod_vrroom');
                } else {
                    $usedquestions[$qbeid] = true;
                    $error = bank::validate_entry($qbeid, $courseid, $keep);
                    if ($error !== null) {
                        $errors["escape_question[{$i}]"] = get_string($error, 'mod_vrroom');
                    }
                }
            }

            // Longer hints would be cut short when saved.
            foreach (['escape_hint1', 'escape_hint2'] as $field) {
                $hint = trim(clean_param((string)($data[$field][$i] ?? ''), PARAM_TEXT));
                if (\core_text::strlen($hint) > self::MAX_HINT_LENGTH) {
                    $errors["{$field}[{$i}]"] = get_string('maximumchars', '', self::MAX_HINT_LENGTH);
                }
            }

            $points = $data['escape_points'][$i] ?? 1;
            if (!is_numeric($points) || (float)$points < 0) {
                $errors["escape_points[{$i}]"] = get_string('errorpoints', self::COMPONENT);
            } else if (!self::points_in_range($points)) {
                $max = format_float(self::MAX_POINTS, 0);
                $errors["escape_points[{$i}]"] = get_string('errorpointsrange', self::COMPONENT, $max);
            }
        }

        return $errors;
    }

    // Config and files.

    /**
     * Turn submitted form data into the stored vrroomtemplate_escape/1 config.
     *
     * Rows without a title are dropped; the remaining puzzles are numbered in form order and
     * their clue files live at itemid index + 1. Puzzle ids posted back in hidden fields are kept
     * only when the stored config of this activity already has them.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @return \stdClass config
     */
    public function extract_config(\stdClass $formdata): \stdClass {
        $data = (array)$formdata;
        $existing = !empty($data['instance']) ? self::load_stored_config((int)$data['instance']) : null;

        $sceneid = '';
        if ($existing && is_string($existing->sceneid ?? null) && self::is_uuid($existing->sceneid)) {
            $sceneid = $existing->sceneid;
        }
        if ($sceneid === '') {
            $sceneid = self::new_uuid();
        }

        $knownids = [];
        foreach ($existing ? self::config_puzzles($existing) : [] as $puzzle) {
            $knownids[$puzzle->id] = true;
        }

        // An installed environment, or the stored pack room this site lacks (kept from its snapshot).
        $environment = (string)($data['escape_environment'] ?? self::DEFAULT_ENVIRONMENT);
        $storedsnapshot = ($existing && ($existing->environment ?? null) === $environment)
            ? ($existing->envsnapshot ?? null) : null;
        $def = environment_registry::resolve($environment, $storedsnapshot);
        if ($def === null) {
            $environment = self::DEFAULT_ENVIRONMENT;
            $def = environment_registry::get($environment);
        }

        $puzzles = [];
        $usedids = [];
        foreach (self::puzzle_rows($data) as $index => $i) {
            $id = clean_param((string)($data['escape_puzzleid'][$i] ?? ''), PARAM_ALPHANUMEXT);
            if ($id === '' || !isset($knownids[$id]) || isset($usedids[$id])) {
                $id = self::new_uuid();
            }
            $usedids[$id] = true;

            $clue = $data['escape_clue'][$i] ?? '';
            if (is_array($clue)) {
                $format = $clue['format'] ?? FORMAT_HTML;
                $clue = $clue['text'] ?? '';
            } else {
                $format = FORMAT_HTML;
            }

            $locktype = self::clean_locktype($data['escape_locktype'][$i] ?? null);
            $keyname = self::row_keyname($data, $i);
            if ($keyname === '') {
                $keyname = get_string('keyname_default', self::COMPONENT);
            }

            $puzzles[] = (object)[
                'id' => $id,
                'title' => self::row_title($data, $i),
                'clue' => (string)$clue,
                'clueformat' => (string)clean_param($format, PARAM_INT),
                'cluemedia' => self::clean_cluemedia($data['escape_cluemedia'][$i] ?? null),
                'fileitemid' => $index + 1,
                'locktype' => $locktype,
                'keyname' => $keyname,
                'questionbankentryid' => $locktype === self::LOCK_QUESTION
                    ? max(0, (int)($data['escape_question'][$i] ?? 0)) : 0,
                'hints' => self::clean_hints([$data['escape_hint1'][$i] ?? '', $data['escape_hint2'][$i] ?? '']),
                'points' => self::clean_points($data['escape_points'][$i] ?? 1),
            ];
        }

        return (object)[
            'schema' => self::SCHEMA,
            'sceneid' => $sceneid,
            'environment' => $environment,
            'envsnapshot' => environment_registry::snapshot($def),
            'story' => trim(clean_param((string)($data['escape_story'] ?? ''), PARAM_TEXT)),
            'timelimit' => self::clean_timelimit($data['escape_timelimit'] ?? 0),
            'hintpenalty' => self::clean_points($data['escape_hintpenalty'] ?? 0),
            'puzzles' => $puzzles,
        ];
    }

    /**
     * Save the form's draft file areas into the module's file areas.
     *
     * Puzzle k (in config order) keeps its clue image in area media or its clue model in area
     * models at itemid k + 1; the other area at that itemid is emptied, and itemids beyond the
     * puzzle count are removed. A draft field that is absent (e.g. generator data) leaves the
     * stored files untouched.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @param \context_module $context module context
     */
    public function save_files(\stdClass $formdata, \context_module $context): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $data = (array)$formdata;
        $fs = get_file_storage();

        $rows = self::puzzle_rows($data);
        foreach ($rows as $index => $i) {
            $itemid = $index + 1;
            $cluemedia = self::clean_cluemedia($data['escape_cluemedia'][$i] ?? null);

            if ($cluemedia === self::MEDIA_IMAGE) {
                if (!empty($data['escape_clueimage'][$i])) {
                    file_save_draft_area_files(
                        (int)$data['escape_clueimage'][$i],
                        $context->id,
                        'mod_vrroom',
                        'media',
                        $itemid,
                        self::image_file_options()
                    );
                }
            } else {
                $fs->delete_area_files($context->id, 'mod_vrroom', 'media', $itemid);
            }

            if ($cluemedia === self::MEDIA_MODEL) {
                if (!empty($data['escape_cluemodel'][$i])) {
                    file_save_draft_area_files(
                        (int)$data['escape_cluemodel'][$i],
                        $context->id,
                        'mod_vrroom',
                        'models',
                        $itemid,
                        self::model_file_options()
                    );
                }
            } else {
                $fs->delete_area_files($context->id, 'mod_vrroom', 'models', $itemid);
            }
        }

        $count = count($rows);
        foreach (['models', 'media'] as $area) {
            foreach ($fs->get_area_files($context->id, 'mod_vrroom', $area, false, 'itemid, filepath, filename', true) as $file) {
                if ((int)$file->get_itemid() > $count) {
                    $file->delete();
                }
            }
        }
    }

    // Compile.

    /**
     * Compile a stored config into one scene, the task graph and the question slot bindings.
     *
     * @param \stdClass $config vrroomtemplate_escape/1 config
     * @param \context_module $context module context (file areas are read from it)
     * @return compiled_activity
     */
    public function compile(\stdClass $config, \context_module $context): compiled_activity {
        $fs = get_file_storage();
        $puzzles = self::config_puzzles($config);
        $count = count($puzzles);
        $environmentid = (string)($config->environment ?? self::DEFAULT_ENVIRONMENT);
        $sceneid = (is_string($config->sceneid ?? null) && self::is_uuid($config->sceneid))
            ? $config->sceneid : self::new_uuid();
        $hintpenalty = self::clean_points($config->hintpenalty ?? 0);
        $timelimit = self::clean_timelimit($config->timelimit ?? 0);
        $story = trim((string)($config->story ?? ''));

        $preset = environment_registry::resolve($environmentid, $config->envsnapshot ?? null);
        if ($preset === null) {
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'Unknown environment.');
        }
        $available = count($preset['anchors'] ?? []);
        if ($count > $available) {
            throw new \moodle_exception(
                'invalidscene',
                'mod_vrroom',
                '',
                "The environment has room for {$available} puzzles."
            );
        }
        $environment = $preset['environment'];
        $spawn = layout::spawn($preset);
        $anchors = layout::anchors($preset, $count);
        $zone = layout::zone_size($preset);
        $zoneoffset = $zone / 2;

        $zones = [];
        $props = [];
        $interactables = [];
        $hidden = [];
        $objectives = [];
        $rules = [];
        $slots = [];
        $solveids = [];
        $slot = 0;

        foreach ($puzzles as $i => $puzzle) {
            $pid = (string)$puzzle->id;
            $title = (string)($puzzle->title ?? '');
            $plaintitle = self::plain($title, $context);
            $a = $anchors[$i]['position'];
            $yaw = $anchors[$i]['yaw'];
            $front = layout::front($yaw);
            $rotation = [0.0, $yaw, 0.0];
            $points = self::clean_points($puzzle->points ?? 0);
            $locktype = self::clean_locktype($puzzle->locktype ?? null);
            $qbeid = (int)($puzzle->questionbankentryid ?? 0);
            if ($locktype === self::LOCK_QUESTION && $qbeid <= 0) {
                // A question lock without a question cannot be solved: it falls back to no lock.
                $locktype = self::LOCK_NONE;
            }

            $zoneid = "z-{$pid}";
            $hotspotid = "h-{$pid}";
            $zones[] = [
                'id' => $zoneid,
                'name' => $title,
                'center' => [
                    layout::round3($a[0] + $zoneoffset * $front[0]),
                    1.5,
                    layout::round3($a[2] + $zoneoffset * $front[2]),
                ],
                'size' => [$zone, 3.0, $zone],
            ];
            $props[] = [
                'id' => "p-{$pid}",
                'shape' => 'box',
                'position' => layout::offset($a, $front, 0.3, 0.4),
                'size' => [1.2, 0.8, 0.6],
                'rotation' => $rotation,
                'color' => self::DESK_COLOUR,
            ];

            $itemid = (int)($puzzle->fileitemid ?? ($i + 1));
            $cluemedia = self::clean_cluemedia($puzzle->cluemedia ?? null);
            if ($cluemedia === self::MEDIA_IMAGE && ($file = self::file_ref($fs, $context, 'media', $itemid))) {
                $interactables[] = [
                    'id' => "i-{$pid}",
                    'kind' => 'image',
                    'name' => $title,
                    'zone' => $zoneid,
                    'position' => layout::offset($a, $front, -0.35, 1.7),
                    'rotation' => $rotation,
                    'width' => 1.0,
                    'file' => $file,
                    'opens' => $hotspotid,
                ];
            } else if ($cluemedia === self::MEDIA_MODEL && ($file = self::file_ref($fs, $context, 'models', $itemid))) {
                $interactables[] = [
                    'id' => "m-{$pid}",
                    'kind' => 'model',
                    'name' => $title,
                    'zone' => $zoneid,
                    'position' => layout::offset($a, $front, 0.3, 0.8),
                    'rotation' => $rotation,
                    'fit' => 0.5,
                    'file' => $file,
                    'opens' => $hotspotid,
                ];
            }

            $questionslot = null;
            if ($locktype === self::LOCK_QUESTION) {
                $slot++;
                $questionslot = $slot;
            }

            $interactables[] = [
                'id' => $hotspotid,
                'kind' => 'hotspot',
                'name' => $title,
                'zone' => $zoneid,
                'position' => layout::offset($a, $front, 0.9, 1.3),
                'rotation' => $rotation,
                'content' => [
                    'title' => $title,
                    'text' => (string)($puzzle->clue ?? ''),
                    'textformat' => (string)($puzzle->clueformat ?? FORMAT_HTML),
                    'media' => [],
                    'question' => $questionslot,
                ],
            ];

            $keyid = "k-{$pid}";
            $keyname = trim((string)($puzzle->keyname ?? ''));
            if ($keyname === '') {
                $keyname = get_string('keyname_default', self::COMPONENT);
            }
            if ($locktype === self::LOCK_KEY) {
                $interactables[] = [
                    'id' => $keyid,
                    'kind' => 'grabbable',
                    'name' => $keyname,
                    'zone' => $zoneid,
                    'position' => layout::offset($a, $front, 0.45, 0.83),
                    'rotation' => $rotation,
                    'shape' => 'box',
                    'size' => [0.24, 0.05, 0.08],
                    'color' => self::KEY_COLOUR,
                ];
                $hidden[] = $keyid;
            }

            $hints = [];
            foreach (self::clean_hints($puzzle->hints ?? []) as $h => $text) {
                $hints[] = ['id' => "hn-{$pid}-" . ($h + 1), 'text' => $text, 'penalty' => $hintpenalty];
            }

            $visitid = "v-{$pid}";
            $visit = [
                'id' => $visitid,
                'type' => 'visit_zone',
                'title' => get_string('objectivefind', self::COMPONENT, $plaintitle),
                'zone' => $zoneid,
                'scene' => $sceneid,
                'points' => $locktype === self::LOCK_NONE ? $points : 0.0,
                'required' => true,
            ];
            if ($i > 0) {
                $visit['unlock'] = ['after' => [end($solveids)]];
            }
            if ($locktype === self::LOCK_NONE && $hints) {
                $visit['hints'] = $hints;
            }
            $objectives[] = $visit;

            $solveid = $visitid;
            if ($locktype === self::LOCK_KEY) {
                $solveid = "x-{$pid}";
                $solve = [
                    'id' => $solveid,
                    'type' => 'interact',
                    'title' => get_string('objectivekey', self::COMPONENT, self::plain($keyname, $context)),
                    'target' => $keyid,
                    'points' => $points,
                    'required' => true,
                    'unlock' => ['after' => [$visitid]],
                ];
                if ($hints) {
                    $solve['hints'] = $hints;
                }
                $objectives[] = $solve;
                $rules[] = [
                    'id' => "r-show-{$pid}",
                    'on' => ['on' => 'objective_completed', 'objective' => $visitid],
                    'once' => true,
                    'actions' => [['do' => 'show', 'target' => $keyid]],
                ];
                $rules[] = [
                    'id' => "r-take-{$pid}",
                    'on' => ['on' => 'objective_completed', 'objective' => $solveid],
                    'once' => true,
                    'actions' => [['do' => 'hide', 'target' => $keyid]],
                ];
            } else if ($locktype === self::LOCK_QUESTION) {
                $solveid = "x-{$pid}";
                $solve = [
                    'id' => $solveid,
                    'type' => 'answer_question',
                    'title' => get_string('objectivecode', self::COMPONENT, $plaintitle),
                    'slot' => $questionslot,
                    'points' => $points,
                    'required' => true,
                    'pass_fraction' => 1,
                    'unlock' => ['after' => [$visitid]],
                ];
                if ($hints) {
                    $solve['hints'] = $hints;
                }
                $objectives[] = $solve;
                $slots[] = [
                    'slot' => $questionslot,
                    'questionbankentryid' => $qbeid,
                    'maxmark' => $points > 0 ? $points : 1.0,
                    'objectiveid' => $solveid,
                ];
                $rules[] = [
                    'id' => "r-open-{$pid}",
                    'on' => ['on' => 'objective_completed', 'objective' => $visitid],
                    'once' => true,
                    'actions' => [['do' => 'open_question', 'slot' => $questionslot]],
                ];
            }
            $solveids[] = $solveid;
        }

        // The exit door: hidden until every puzzle is solved; using it finishes the attempt.
        $exit = layout::exit_anchor($preset);
        $exitfront = layout::front($exit['yaw']);
        $exitrotation = [0.0, $exit['yaw'], 0.0];
        if ($exit['door']) {
            $props[] = [
                'id' => 'p-exit',
                'shape' => 'box',
                'position' => [
                    $exit['position'][0],
                    layout::round3($exit['position'][1] + 1.1),
                    layout::round3($exit['position'][2] - 0.15),
                ],
                'size' => [1.2, 2.2, 0.1],
                'rotation' => $exitrotation,
                'color' => self::DOOR_COLOUR,
            ];
        }
        $doorname = get_string('exitdoor', self::COMPONENT);
        $interactables[] = [
            'id' => 'd-exit',
            'kind' => 'hotspot',
            'name' => $doorname,
            'position' => layout::offset($exit['position'], $exitfront, 0.3, 1.3),
            'rotation' => $exitrotation,
            'content' => [
                'title' => $doorname,
                'text' => '<p>' . s(get_string('exittext', self::COMPONENT)) . '</p>',
                'textformat' => (string)FORMAT_HTML,
                'media' => [],
                'question' => null,
            ],
        ];
        $hidden[] = 'd-exit';
        $objectives[] = [
            'id' => 'o-exit',
            'type' => 'interact',
            'title' => get_string('objectiveexit', self::COMPONENT),
            'target' => 'd-exit',
            'points' => 0.0,
            'required' => true,
            'unlock' => ['after' => $solveids],
        ];
        $rules[] = [
            'id' => 'r-exit',
            'on' => ['on' => 'objective_unlocked', 'objective' => 'o-exit'],
            'once' => true,
            'actions' => [['do' => 'show', 'target' => 'd-exit']],
        ];
        $rules[] = [
            'id' => 'r-finish',
            'on' => ['on' => 'objective_completed', 'objective' => 'o-exit'],
            'once' => true,
            'actions' => [['do' => 'finish']],
        ];

        if ($story !== '') {
            // 1.9 m ahead of the start along its view direction (−sin θ, 0, −cos θ), turned to face
            // back at the start point (a text at yaw θ faces (sin θ, 0, cos θ)).
            $storyrad = deg2rad($spawn['yaw']);
            $interactables[] = [
                'id' => 't-story',
                'kind' => 'text',
                'name' => get_string('storyname', self::COMPONENT),
                'position' => [
                    layout::round3($spawn['position'][0] - 1.9 * sin($storyrad)),
                    2.2,
                    layout::round3($spawn['position'][2] - 1.9 * cos($storyrad)),
                ],
                'rotation' => [0.0, $spawn['yaw'], 0.0],
                'width' => 2.4,
                'text' => $story,
            ];
        }

        $scene = [
            'schema' => 'vrroom-scene/1',
            'id' => $sceneid,
            'name' => get_string('scenename', self::COMPONENT),
            'environment' => $environment,
            'spawn' => [['id' => self::SPAWN_ID, 'position' => $spawn['position'], 'yaw' => $spawn['yaw']]],
            'zones' => $zones,
            'props' => $props,
            'interactables' => $interactables,
        ];
        $graph = [
            'schema' => 'vrroom-graph/1',
            'timelimit' => $timelimit,
            'hidden' => $hidden,
            'objectives' => $objectives,
            'rules' => $rules,
        ];

        return new compiled_activity([$scene], $graph, $slots);
    }

    /**
     * Replace the puzzles' question bank entry ids after a restore.
     *
     * @param \stdClass $config decoded vrroomtemplate_escape/1 config, changed in place
     * @param callable $map fn(int $old): int, returns $old when there is no mapping
     * @return bool whether any id changed
     */
    public function remap_question_entries(\stdClass $config, callable $map): bool {
        if (empty($config->puzzles) || !is_array($config->puzzles)) {
            return false;
        }
        $changed = false;
        foreach ($config->puzzles as $puzzle) {
            if (!is_object($puzzle) || empty($puzzle->questionbankentryid)) {
                continue;
            }
            $old = (int)$puzzle->questionbankentryid;
            $new = (int)$map($old);
            if ($new > 0 && $new !== $old) {
                $puzzle->questionbankentryid = $new;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Completion rules this template offers.
     *
     * @return string[]
     */
    public function completion_rules(): array {
        return ['completionobjectives'];
    }

    /**
     * Default maximum grade.
     *
     * @return int
     */
    public function default_grade(): int {
        return 100;
    }

    // Helpers.

    /**
     * File options for the clue model file managers (GLB only, one file, size from settings).
     *
     * @return array
     */
    public static function model_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => self::max_upload_bytes(),
            'accepted_types' => ['.glb'],
        ];
    }

    /**
     * File options for the clue image file managers.
     *
     * @return array
     */
    public static function image_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => self::max_upload_bytes(),
            'accepted_types' => self::IMAGE_TYPES,
        ];
    }

    /**
     * Largest upload in bytes, from the mod_vrroom maxmodelsize setting (MB, default 20).
     *
     * @return int
     */
    public static function max_upload_bytes(): int {
        $mb = (int)get_config('mod_vrroom', 'maxmodelsize');
        if ($mb <= 0) {
            $mb = 20;
        }
        return $mb * 1048576;
    }

    /**
     * Puzzles of a config as a list of objects with valid ids.
     *
     * @param \stdClass $config stored or freshly extracted config
     * @return \stdClass[]
     */
    private static function config_puzzles(\stdClass $config): array {
        $puzzles = [];
        foreach (array_values((array)($config->puzzles ?? [])) as $puzzle) {
            if (is_array($puzzle) || is_object($puzzle)) {
                $puzzle = (object)$puzzle;
                if (isset($puzzle->id) && is_string($puzzle->id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $puzzle->id)) {
                    $puzzles[] = $puzzle;
                }
            }
        }
        return $puzzles;
    }

    /**
     * Load and decode the stored config of an activity.
     *
     * @param int $vrroomid activity instance id
     * @param int|null $courseid when given, the activity must belong to this course
     * @return \stdClass|null null when absent or not an escape chain config
     */
    private static function load_stored_config(int $vrroomid, ?int $courseid = null): ?\stdClass {
        global $DB;
        $conditions = ['id' => $vrroomid, 'template' => 'escape'];
        if ($courseid !== null) {
            $conditions['course'] = $courseid;
        }
        $json = $DB->get_field('vrroom', 'templateconfig', $conditions);
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
     * The environment snapshot stored with the activity being edited, when it is for this environment.
     *
     * @param string $environment environment key chosen in the form
     * @param int $courseid course of the form
     * @return \stdClass|array|null
     */
    private function stored_snapshot(string $environment, int $courseid) {
        if ($this->editinginstance === null || $this->editingcourse !== $courseid) {
            return null;
        }
        $stored = self::load_stored_config($this->editinginstance, $courseid);
        if ($stored === null || ($stored->environment ?? null) !== $environment) {
            return null;
        }
        return $stored->envsnapshot ?? null;
    }

    /**
     * Indexes of every submitted puzzle row.
     *
     * @param array $data form data
     * @return int[]
     */
    private static function all_row_indexes(array $data): array {
        $titles = $data['escape_title'] ?? [];
        return is_array($titles) ? array_map('intval', array_keys($titles)) : [];
    }

    /**
     * Indexes of the rows that make a puzzle (a non-empty title), in form order.
     *
     * @param array $data form data
     * @return int[] list of row indexes; list position = puzzle index
     */
    private static function puzzle_rows(array $data): array {
        $rows = [];
        foreach (self::all_row_indexes($data) as $i) {
            if (self::row_title($data, $i) !== '') {
                $rows[] = $i;
            }
        }
        sort($rows);
        return $rows;
    }

    /**
     * Cleaned, trimmed title of a row.
     *
     * @param array $data form data
     * @param int $i row index
     * @return string
     */
    private static function row_title(array $data, int $i): string {
        return trim(clean_param((string)($data['escape_title'][$i] ?? ''), PARAM_TEXT));
    }

    /**
     * Cleaned, trimmed key name of a row.
     *
     * @param array $data form data
     * @param int $i row index
     * @return string
     */
    private static function row_keyname(array $data, int $i): string {
        return trim(clean_param((string)($data['escape_keyname'][$i] ?? ''), PARAM_TEXT));
    }

    /**
     * Whether a row has content besides its title (a clue, an exhibit, a question or a hint).
     *
     * @param array $data form data
     * @param int $i row index
     * @return bool
     */
    private static function row_has_content(array $data, int $i): bool {
        $clue = $data['escape_clue'][$i] ?? '';
        if (is_array($clue)) {
            $clue = $clue['text'] ?? '';
        }
        if (trim(strip_tags((string)$clue)) !== '') {
            return true;
        }
        if (self::clean_cluemedia($data['escape_cluemedia'][$i] ?? null) !== self::MEDIA_NONE) {
            return true;
        }
        if (
            self::clean_locktype($data['escape_locktype'][$i] ?? null) === self::LOCK_QUESTION
                && (int)($data['escape_question'][$i] ?? 0) > 0
        ) {
            return true;
        }
        return self::clean_hints([$data['escape_hint1'][$i] ?? '', $data['escape_hint2'][$i] ?? '']) !== [];
    }

    /**
     * Normalise a clue exhibit type to one of the MEDIA_* constants.
     *
     * @param mixed $value submitted or stored value
     * @return string
     */
    private static function clean_cluemedia($value): string {
        $value = is_string($value) ? $value : '';
        return in_array($value, [self::MEDIA_IMAGE, self::MEDIA_MODEL], true) ? $value : self::MEDIA_NONE;
    }

    /**
     * Normalise a lock type to one of the LOCK_* constants (unknown values mean no lock).
     *
     * @param mixed $value submitted or stored value
     * @return string
     */
    private static function clean_locktype($value): string {
        $value = is_string($value) ? $value : '';
        return in_array($value, [self::LOCK_KEY, self::LOCK_QUESTION], true) ? $value : self::LOCK_NONE;
    }

    /**
     * Non-empty plain hint texts, at most MAX_HINTS, each at most MAX_HINT_LENGTH characters.
     *
     * @param mixed $hints list of submitted or stored hint texts
     * @return string[]
     */
    private static function clean_hints($hints): array {
        $clean = [];
        foreach (is_array($hints) ? array_values($hints) : [] as $hint) {
            if (!is_string($hint)) {
                continue;
            }
            $hint = trim(clean_param($hint, PARAM_TEXT));
            if ($hint === '') {
                continue;
            }
            $clean[] = \core_text::substr($hint, 0, self::MAX_HINT_LENGTH);
            if (count($clean) === self::MAX_HINTS) {
                break;
            }
        }
        return $clean;
    }

    /**
     * A non-negative points value rounded to 7 decimals, at most MAX_POINTS (0 for anything else).
     *
     * @param mixed $value submitted or stored value
     * @return float
     */
    private static function clean_points($value): float {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            return 0.0;
        }
        return min(self::MAX_POINTS, max(0.0, round((float)$value, 7)));
    }

    /**
     * Whether a non-negative points value can be stored as it was entered.
     *
     * It must be finite and at most MAX_POINTS, and a value above 0 must not round to 0 at the
     * 7 decimals the question slot keeps (below 0.0000001).
     *
     * @param mixed $value submitted value, numeric and at least 0
     * @return bool
     */
    private static function points_in_range($value): bool {
        $value = (float)$value;
        if (!is_finite($value) || $value > self::MAX_POINTS) {
            return false;
        }
        return $value == 0 || round($value, 7) > 0;
    }

    /**
     * The time limit as typed in the form (number times unit, in seconds), before core's duration
     * element rounds it; 0 when the field is not enabled or not in this request.
     *
     * @return float
     */
    private static function typed_timelimit(): float {
        $raw = optional_param_array('escape_timelimit', [], PARAM_RAW);
        if (empty($raw['enabled']) || !isset($raw['number'], $raw['timeunit'])) {
            return 0.0;
        }
        $number = str_replace(',', '.', (string)$raw['number']);
        if (!is_numeric($number) || !is_numeric($raw['timeunit'])) {
            return 0.0;
        }
        $seconds = (float)$number * (float)$raw['timeunit'];
        return is_finite($seconds) ? $seconds : INF;
    }

    /**
     * A time limit in whole seconds from 0 to MAX_TIMELIMIT (0 for anything not a number).
     *
     * @param mixed $value submitted or stored value
     * @return int
     */
    private static function clean_timelimit($value): int {
        if (!is_numeric($value) || is_nan((float)$value)) {
            return 0;
        }
        // Clamped as a float first: casting a huge value to an integer would wrap around.
        return (int)min((float)self::MAX_TIMELIMIT, max(0.0, (float)$value));
    }

    /**
     * Plain text of a teacher-entered name, for use inside a lang string (gallery convention).
     *
     * @param string $text the name
     * @param \context_module $context module context
     * @return string
     */
    private static function plain(string $text, \context_module $context): string {
        return format_string($text, true, ['context' => $context, 'escape' => false]);
    }

    /**
     * Whether the current user's draft area holds at least one file.
     *
     * @param int $draftitemid draft item id
     * @return bool
     */
    private static function draft_has_files(int $draftitemid): bool {
        global $USER;
        if ($draftitemid <= 0 || empty($USER->id)) {
            return false;
        }
        $usercontext = \context_user::instance($USER->id);
        return !get_file_storage()->is_area_empty($usercontext->id, 'user', 'draft', $draftitemid);
    }

    /**
     * vrroom-scene/1 file reference to the first file in a module file area item.
     *
     * @param \file_storage $fs file storage
     * @param \context_module $context module context
     * @param string $area models or media
     * @param int $itemid item id
     * @return array|null ['area', 'itemid', 'filepath', 'filename'], or null when the item is empty
     */
    private static function file_ref(\file_storage $fs, \context_module $context, string $area, int $itemid): ?array {
        $files = $fs->get_area_files($context->id, 'mod_vrroom', $area, $itemid, 'filepath, filename', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return [
            'area' => $area,
            'itemid' => $itemid,
            'filepath' => $file->get_filepath(),
            'filename' => $file->get_filename(),
        ];
    }

    /**
     * Label for a question this activity uses that is not in the teacher's choice list.
     *
     * @param int $qbeid question bank entry id
     * @return string
     */
    private static function question_label(int $qbeid): string {
        global $DB;
        $sql = "SELECT q.id, q.name, q.contextid
                  FROM {question_versions} qv
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qv.questionbankentryid = :qbeid
              ORDER BY qv.version DESC";
        $records = $DB->get_records_sql($sql, ['qbeid' => $qbeid], 0, 1);
        $record = reset($records);
        if (!$record) {
            return get_string('questionmissing', 'mod_vrroom');
        }
        return format_string($record->name, true, ['context' => \context_system::instance()]);
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

    /**
     * New lowercase UUID.
     *
     * @return string
     */
    private static function new_uuid(): string {
        return strtolower(\core\uuid::generate());
    }
}
