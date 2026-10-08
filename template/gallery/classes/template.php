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

namespace vrroomtemplate_gallery;

use vrroomtemplate_gallery\local\layout;
use mod_vrroom\local\questions\bank;
use mod_vrroom\local\scene\presets;
use mod_vrroom\local\template\compiled_activity;

/**
 * Gallery walk template: an environment with stations (text, a model or an image, an optional question).
 *
 * The form fields are stored as a vrroomtemplate_gallery/1 config in vrroom.templateconfig and compiled
 * into one vrroom-scene/1 room plus a vrroom-graph/1 task graph: one visit objective per station, one
 * question objective per station question (unlocked by the visit), an optional strict sequence
 * over the visits, and a rule that finishes the attempt when every required objective is done.
 *
 * @package    vrroomtemplate_gallery
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template extends \mod_vrroom\local\template\base {
    /** @var string Frankenstyle component of this template (string and file lookups). */
    public const COMPONENT = 'vrroomtemplate_gallery';

    /** @var string Schema identifier written into the stored config. */
    public const SCHEMA = 'vrroomtemplate_gallery/1';

    /** @var string Environment choice for a teacher-uploaded GLB. */
    public const ENVIRONMENT_UPLOAD = 'upload';

    /** @var string Environment preset chosen by default. */
    public const DEFAULT_ENVIRONMENT = 'gallery-hall';

    /** @var string Station exhibit: text only. */
    public const MEDIA_NONE = 'none';

    /** @var string Station exhibit: a GLB model. */
    public const MEDIA_MODEL = 'model';

    /** @var string Station exhibit: an image. */
    public const MEDIA_IMAGE = 'image';

    /** @var int Station groups shown on a new form. */
    public const DEFAULT_REPEATS = 3;

    /** @var int Station groups added by the "Add more stations" button. */
    public const ADD_REPEATS = 2;

    /** @var int Longest station title, in characters. */
    public const MAX_TITLE_LENGTH = 255;

    /** @var string[] Image types a station accepts (all are served inline by vrroom_pluginfile). */
    public const IMAGE_TYPES = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    /** @var string Scene id of the single spawn point. */
    public const SPAWN_ID = 'spawn-main';

    /** @var string Sky colour of an uploaded environment. */
    public const UPLOAD_SKY = '#9cc9ef';

    /** @var string Colour of the plinth under a model. */
    public const PLINTH_COLOUR = '#d9d4cc';

    /** @var int|null Instance id of the activity being edited, captured from the form in define_form(). */
    private ?int $editinginstance = null;

    /** @var int|null Course id that $editinginstance was captured for. */
    private ?int $editingcourse = null;

    // Form.

    /**
     * Add the gallery fields to the activity form.
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
        $stations = $config ? self::config_stations($config) : [];

        $mform->addElement('header', 'gallery_heading', get_string('galleryheading', self::COMPONENT));

        $environments = presets::get_choices();
        $environments[self::ENVIRONMENT_UPLOAD] = get_string('environmentupload', self::COMPONENT);
        $mform->addElement('select', 'gallery_environment', get_string('environment', self::COMPONENT), $environments);
        $mform->setDefault('gallery_environment', self::DEFAULT_ENVIRONMENT);
        $mform->addHelpButton('gallery_environment', 'environment', self::COMPONENT);

        $mform->addElement(
            'filemanager',
            'gallery_environmentfile',
            get_string('environmentfile', self::COMPONENT),
            null,
            self::model_file_options()
        );
        $mform->addHelpButton('gallery_environmentfile', 'environmentfile', self::COMPONENT);
        $mform->hideIf('gallery_environmentfile', 'gallery_environment', 'neq', self::ENVIRONMENT_UPLOAD);

        $mform->addElement('advcheckbox', 'gallery_ordered', get_string('ordered', self::COMPONENT));
        $mform->setDefault('gallery_ordered', 0);
        $mform->addHelpButton('gallery_ordered', 'ordered', self::COMPONENT);

        // Question choices: the course's banks, plus questions this activity already uses that the
        // current teacher cannot list (so editing never silently drops them).
        $questions = [0 => get_string('noquestion', self::COMPONENT)] + bank::get_choices($courseid);
        foreach ($stations as $station) {
            $qbeid = (int)($station->questionbankentryid ?? 0);
            if ($qbeid > 0 && !array_key_exists($qbeid, $questions)) {
                $questions[$qbeid] = self::question_label($qbeid);
            }
        }

        $repeated = [
            $mform->createElement('header', 'gallery_stationheader', get_string('station', self::COMPONENT)),
            $mform->createElement('hidden', 'gallery_stationid', ''),
            $mform->createElement(
                'text',
                'gallery_title',
                get_string('stationtitle', self::COMPONENT),
                ['size' => 50, 'maxlength' => self::MAX_TITLE_LENGTH]
            ),
            $mform->createElement(
                'editor',
                'gallery_text',
                get_string('stationtext', self::COMPONENT),
                ['rows' => 6],
                ['maxfiles' => 0, 'noclean' => false]
            ),
            $mform->createElement('select', 'gallery_mediatype', get_string('mediatype', self::COMPONENT), [
                self::MEDIA_NONE => get_string('mediatype_none', self::COMPONENT),
                self::MEDIA_MODEL => get_string('mediatype_model', self::COMPONENT),
                self::MEDIA_IMAGE => get_string('mediatype_image', self::COMPONENT),
            ]),
            $mform->createElement(
                'filemanager',
                'gallery_model',
                get_string('stationmodel', self::COMPONENT),
                null,
                self::model_file_options()
            ),
            $mform->createElement(
                'filemanager',
                'gallery_image',
                get_string('stationimage', self::COMPONENT),
                null,
                self::image_file_options()
            ),
            $mform->createElement(
                'autocomplete',
                'gallery_question',
                get_string('stationquestion', self::COMPONENT),
                $questions,
                ['multiple' => false]
            ),
            $mform->createElement('advcheckbox', 'gallery_required', get_string('stationrequired', self::COMPONENT)),
            $mform->createElement('float', 'gallery_points', get_string('stationpoints', self::COMPONENT)),
        ];
        $options = [
            'gallery_stationheader' => ['expanded' => true],
            'gallery_stationid' => ['type' => PARAM_ALPHANUMEXT],
            'gallery_title' => ['type' => PARAM_TEXT],
            'gallery_text' => ['type' => PARAM_RAW],
            'gallery_mediatype' => ['type' => PARAM_ALPHA, 'default' => self::MEDIA_NONE],
            'gallery_model' => ['hideif' => ['gallery_mediatype', 'neq', self::MEDIA_MODEL]],
            'gallery_image' => ['hideif' => ['gallery_mediatype', 'neq', self::MEDIA_IMAGE]],
            'gallery_question' => [
                'type' => PARAM_INT,
                'default' => 0,
                'helpbutton' => ['stationquestion', self::COMPONENT],
            ],
            'gallery_required' => ['default' => 1],
            'gallery_points' => ['default' => 1, 'helpbutton' => ['stationpoints', self::COMPONENT]],
        ];
        $form->repeat_elements(
            $repeated,
            max(count($stations), self::DEFAULT_REPEATS),
            $options,
            'gallery_stationcount',
            'gallery_addstations',
            self::ADD_REPEATS,
            get_string('addstations', self::COMPONENT)
        );

        return ['gallery_environment', 'gallery_environmentfile', 'gallery_ordered', 'gallery_addstations'];
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
            if (!isset($defaultvalues['gallery_environment'])) {
                $defaultvalues['gallery_environment'] = self::DEFAULT_ENVIRONMENT;
            }
            return;
        }

        $defaultvalues['gallery_environment'] = (string)($config->environment ?? self::DEFAULT_ENVIRONMENT);
        $defaultvalues['gallery_ordered'] = !empty($config->ordered) ? 1 : 0;

        if ($context !== null) {
            $draftitemid = file_get_submitted_draft_itemid('gallery_environmentfile');
            file_prepare_draft_area(
                $draftitemid,
                $context->id,
                'mod_vrroom',
                'environment',
                0,
                self::model_file_options()
            );
            $defaultvalues['gallery_environmentfile'] = $draftitemid;
        }

        $stations = self::config_stations($config);
        foreach ($stations as $i => $station) {
            $defaultvalues['gallery_stationid'][$i] = (string)($station->id ?? '');
            $defaultvalues['gallery_title'][$i] = (string)($station->title ?? '');
            $defaultvalues['gallery_text'][$i] = [
                'text' => (string)($station->text ?? ''),
                'format' => (string)($station->textformat ?? FORMAT_HTML),
            ];
            $defaultvalues['gallery_mediatype'][$i] = self::clean_mediatype($station->mediatype ?? null);
            $defaultvalues['gallery_question'][$i] = (int)($station->questionbankentryid ?? 0);
            $defaultvalues['gallery_required'][$i] = !empty($station->required) ? 1 : 0;
            $defaultvalues['gallery_points'][$i] = (float)($station->points ?? 0);

            // The repeat_elements() 'default' options are stored under flat keys such as
            // 'gallery_question[0]', and QuickForm looks a flat key up before the nested array,
            // so the stored values must be given under the same flat keys to win (as mod_quiz does).
            foreach (['gallery_mediatype', 'gallery_question', 'gallery_required', 'gallery_points'] as $field) {
                $defaultvalues["{$field}[{$i}]"] = $defaultvalues[$field][$i];
            }

            if ($context !== null) {
                $itemid = (int)($station->fileitemid ?? ($i + 1));
                $modeldraft = 0;
                file_prepare_draft_area(
                    $modeldraft,
                    $context->id,
                    'mod_vrroom',
                    'models',
                    $itemid,
                    self::model_file_options()
                );
                $defaultvalues['gallery_model'][$i] = $modeldraft;
                $imagedraft = 0;
                file_prepare_draft_area(
                    $imagedraft,
                    $context->id,
                    'mod_vrroom',
                    'media',
                    $itemid,
                    self::image_file_options()
                );
                $defaultvalues['gallery_image'][$i] = $imagedraft;
            }
        }
        $defaultvalues['gallery_stationcount'] = max(count($stations), self::DEFAULT_REPEATS);
    }

    /**
     * Validate the gallery fields.
     *
     * @param array $data submitted data
     * @param array $files submitted files (unused: draft areas are read from $data)
     * @param int $courseid course the activity is in
     * @return array element name => error message
     */
    public function validation(array $data, array $files, int $courseid): array {
        $errors = [];

        $environment = (string)($data['gallery_environment'] ?? self::DEFAULT_ENVIRONMENT);
        if ($environment === self::ENVIRONMENT_UPLOAD) {
            $limit = layout::MAX_UPLOAD_STATIONS;
            if (!self::draft_has_files((int)($data['gallery_environmentfile'] ?? 0))) {
                $errors['gallery_environmentfile'] = get_string('errorenvironmentfile', self::COMPONENT);
            }
        } else {
            $preset = presets::get($environment);
            if ($preset === null) {
                $errors['gallery_environment'] = get_string('required');
                return $errors;
            }
            $limit = count($preset['anchors'] ?? []);
        }

        // Rows without a title but with other content are a mistake, not a blank row.
        foreach (self::all_row_indexes($data) as $i) {
            if (self::row_title($data, $i) === '' && self::row_has_content($data, $i)) {
                $errors["gallery_title[{$i}]"] = get_string('errortitlerequired', self::COMPONENT);
            }
        }

        $rows = self::station_rows($data);
        if (!$rows) {
            $errors['gallery_title[0]'] = get_string('errornostations', self::COMPONENT);
            return $errors;
        }
        if (count($rows) > $limit) {
            $errors['gallery_environment'] = get_string('errortoomanystations', self::COMPONENT, $limit);
        }

        $keep = [];
        if ($this->editinginstance !== null && $this->editingcourse === $courseid) {
            $stored = self::load_stored_config($this->editinginstance, $courseid);
            foreach ($stored ? self::config_stations($stored) : [] as $station) {
                if (!empty($station->questionbankentryid)) {
                    $keep[] = (int)$station->questionbankentryid;
                }
            }
        }

        $usedquestions = [];
        foreach ($rows as $i) {
            if (\core_text::strlen(self::row_title($data, $i)) > self::MAX_TITLE_LENGTH) {
                $errors["gallery_title[{$i}]"] = get_string('maximumchars', '', self::MAX_TITLE_LENGTH);
            }

            $mediatype = self::clean_mediatype($data['gallery_mediatype'][$i] ?? null);
            if ($mediatype === self::MEDIA_MODEL && !self::draft_has_files((int)($data['gallery_model'][$i] ?? 0))) {
                $errors["gallery_model[{$i}]"] = get_string('errormodelrequired', self::COMPONENT);
            }
            if ($mediatype === self::MEDIA_IMAGE && !self::draft_has_files((int)($data['gallery_image'][$i] ?? 0))) {
                $errors["gallery_image[{$i}]"] = get_string('errorimagerequired', self::COMPONENT);
            }

            $points = $data['gallery_points'][$i] ?? 1;
            if (!is_numeric($points) || (float)$points < 0) {
                $errors["gallery_points[{$i}]"] = get_string('errorpoints', self::COMPONENT);
            }

            $qbeid = (int)($data['gallery_question'][$i] ?? 0);
            if ($qbeid > 0) {
                if (isset($usedquestions[$qbeid])) {
                    $errors["gallery_question[{$i}]"] = get_string('errorquestionduplicate', 'mod_vrroom');
                } else {
                    $usedquestions[$qbeid] = true;
                    $error = bank::validate_entry($qbeid, $courseid, $keep);
                    if ($error !== null) {
                        $errors["gallery_question[{$i}]"] = get_string($error, 'mod_vrroom');
                    }
                }
            }
        }

        return $errors;
    }

    // Config and files.

    /**
     * Turn submitted form data into the stored vrroomtemplate_gallery/1 config.
     *
     * Rows without a title are dropped; the remaining stations are numbered in form order and
     * their files live at itemid index + 1. Station ids posted back in hidden fields are kept
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
        foreach ($existing ? self::config_stations($existing) : [] as $station) {
            if (isset($station->id) && is_string($station->id)) {
                $knownids[$station->id] = true;
            }
        }

        $environment = (string)($data['gallery_environment'] ?? self::DEFAULT_ENVIRONMENT);
        if ($environment !== self::ENVIRONMENT_UPLOAD && presets::get($environment) === null) {
            $environment = self::DEFAULT_ENVIRONMENT;
        }

        $stations = [];
        $usedids = [];
        foreach (self::station_rows($data) as $index => $i) {
            $id = clean_param((string)($data['gallery_stationid'][$i] ?? ''), PARAM_ALPHANUMEXT);
            if ($id === '' || !isset($knownids[$id]) || isset($usedids[$id])) {
                $id = self::new_uuid();
            }
            $usedids[$id] = true;

            $text = $data['gallery_text'][$i] ?? '';
            if (is_array($text)) {
                $format = $text['format'] ?? FORMAT_HTML;
                $text = $text['text'] ?? '';
            } else {
                $format = FORMAT_HTML;
            }

            $points = $data['gallery_points'][$i] ?? 1;
            $points = is_numeric($points) ? max(0.0, round((float)$points, 7)) : 0.0;

            $stations[] = (object)[
                'id' => $id,
                'title' => self::row_title($data, $i),
                'text' => (string)$text,
                'textformat' => (string)clean_param($format, PARAM_INT),
                'mediatype' => self::clean_mediatype($data['gallery_mediatype'][$i] ?? null),
                'fileitemid' => $index + 1,
                'questionbankentryid' => max(0, (int)($data['gallery_question'][$i] ?? 0)),
                'required' => !empty($data['gallery_required'][$i] ?? 1),
                'points' => $points,
            ];
        }

        return (object)[
            'schema' => self::SCHEMA,
            'sceneid' => $sceneid,
            'environment' => $environment,
            'ordered' => !empty($data['gallery_ordered']),
            'stations' => $stations,
        ];
    }

    /**
     * Save the form's draft file areas into the module's file areas.
     *
     * The environment model goes to area environment, itemid 0. Station k (in config order) keeps
     * its model in area models or its image in area media at itemid k + 1; the other area at that
     * itemid is emptied, and itemids beyond the station count are removed. A draft field that is
     * absent (e.g. generator data) leaves the stored files untouched.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @param \context_module $context module context
     */
    public function save_files(\stdClass $formdata, \context_module $context): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $data = (array)$formdata;
        $fs = get_file_storage();

        $environment = (string)($data['gallery_environment'] ?? self::DEFAULT_ENVIRONMENT);
        if ($environment === self::ENVIRONMENT_UPLOAD) {
            if (!empty($data['gallery_environmentfile'])) {
                file_save_draft_area_files(
                    (int)$data['gallery_environmentfile'],
                    $context->id,
                    'mod_vrroom',
                    'environment',
                    0,
                    self::model_file_options()
                );
            }
        } else {
            $fs->delete_area_files($context->id, 'mod_vrroom', 'environment');
        }

        $rows = self::station_rows($data);
        foreach ($rows as $index => $i) {
            $itemid = $index + 1;
            $mediatype = self::clean_mediatype($data['gallery_mediatype'][$i] ?? null);

            if ($mediatype === self::MEDIA_MODEL) {
                if (!empty($data['gallery_model'][$i])) {
                    file_save_draft_area_files(
                        (int)$data['gallery_model'][$i],
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

            if ($mediatype === self::MEDIA_IMAGE) {
                if (!empty($data['gallery_image'][$i])) {
                    file_save_draft_area_files(
                        (int)$data['gallery_image'][$i],
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
     * @param \stdClass $config vrroomtemplate_gallery/1 config
     * @param \context_module $context module context (file areas are read from it)
     * @return compiled_activity
     */
    public function compile(\stdClass $config, \context_module $context): compiled_activity {
        $fs = get_file_storage();
        $stations = self::config_stations($config);
        $count = count($stations);
        $environmentid = (string)($config->environment ?? self::DEFAULT_ENVIRONMENT);
        $ordered = !empty($config->ordered);
        $sceneid = (is_string($config->sceneid ?? null) && self::is_uuid($config->sceneid))
            ? $config->sceneid : self::new_uuid();

        if ($environmentid === self::ENVIRONMENT_UPLOAD) {
            if ($count > layout::MAX_UPLOAD_STATIONS) {
                throw new \moodle_exception(
                    'invalidscene',
                    'mod_vrroom',
                    '',
                    "An uploaded environment takes at most " . layout::MAX_UPLOAD_STATIONS . " stations."
                );
            }
            $envfile = self::file_ref($fs, $context, 'environment', 0);
            if ($envfile === null) {
                throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'The environment model file is missing.');
            }
            $preset = null;
            $environment = [
                'type' => 'model',
                'file' => $envfile,
                'sky' => self::UPLOAD_SKY,
                'lights' => [
                    ['type' => 'ambient', 'intensity' => 0.8],
                    ['type' => 'directional', 'intensity' => 0.9, 'position' => [4, 10, 6]],
                ],
                'bounds' => layout::circle_bounds($count),
            ];
            $spawn = ['id' => self::SPAWN_ID, 'position' => [0.0, 0.0, 0.0], 'yaw' => 0.0];
        } else {
            $preset = presets::get($environmentid);
            if ($preset === null) {
                throw new \moodle_exception('invalidscene', 'mod_vrroom', '', "Unknown environment preset.");
            }
            $available = count($preset['anchors'] ?? []);
            if ($count > $available) {
                throw new \moodle_exception(
                    'invalidscene',
                    'mod_vrroom',
                    '',
                    "The environment has room for {$available} stations."
                );
            }
            $environment = [
                'type' => 'preset',
                'preset' => $environmentid,
                'shape' => $preset['shape'],
                'sky' => $preset['sky'],
                'lights' => $preset['lights'],
                'bounds' => $preset['bounds'],
            ];
            $spawnposition = [];
            foreach (array_values($preset['spawn']['position'] ?? [0, 0, 0]) as $coordinate) {
                $spawnposition[] = layout::round3((float)$coordinate);
            }
            $spawn = [
                'id' => self::SPAWN_ID,
                'position' => $spawnposition,
                'yaw' => layout::normalise_yaw((float)($preset['spawn']['yaw'] ?? 0)),
            ];
        }

        $anchors = layout::anchors($preset, $count);

        $zones = [];
        $props = [];
        $interactables = [];
        $objectives = [];
        $rules = [];
        $slots = [];
        $visitids = [];
        $slot = 0;

        foreach ($stations as $i => $station) {
            $sid = (string)$station->id;
            $title = (string)($station->title ?? '');
            $plaintitle = format_string($title, true, ['context' => $context, 'escape' => false]);
            $anchor = $anchors[$i];
            $a = $anchor['position'];
            $yaw = $anchor['yaw'];
            $front = layout::front($yaw);
            $rotation = [0.0, $yaw, 0.0];

            $zoneid = "z-{$sid}";
            $hotspotid = "h-{$sid}";
            $zones[] = [
                'id' => $zoneid,
                'name' => $title,
                'center' => self::vec($a[0] + 1.5 * $front[0], 1.5, $a[2] + 1.5 * $front[2]),
                'size' => [3.0, 3.0, 3.0],
            ];

            $itemid = (int)($station->fileitemid ?? ($i + 1));
            $mediatype = self::clean_mediatype($station->mediatype ?? null);
            if ($mediatype === self::MEDIA_MODEL && ($file = self::file_ref($fs, $context, 'models', $itemid))) {
                $props[] = [
                    'id' => "p-{$sid}",
                    'shape' => 'box',
                    'position' => self::vec($a[0], $a[1] + 0.5, $a[2]),
                    'size' => [0.9, 1.0, 0.9],
                    'color' => self::PLINTH_COLOUR,
                ];
                $interactables[] = [
                    'id' => "m-{$sid}",
                    'kind' => 'model',
                    'name' => $title,
                    'zone' => $zoneid,
                    'position' => self::vec($a[0], $a[1] + 1.0, $a[2]),
                    'rotation' => $rotation,
                    'scale' => [1.0, 1.0, 1.0],
                    'fit' => 1.0,
                    'file' => $file,
                    'opens' => $hotspotid,
                ];
            } else if ($mediatype === self::MEDIA_IMAGE && ($file = self::file_ref($fs, $context, 'media', $itemid))) {
                $interactables[] = [
                    'id' => "i-{$sid}",
                    'kind' => 'image',
                    'name' => $title,
                    'zone' => $zoneid,
                    'position' => self::vec($a[0], $a[1] + 1.6, $a[2]),
                    'rotation' => $rotation,
                    'width' => 1.6,
                    'file' => $file,
                    'opens' => $hotspotid,
                ];
            }

            $qbeid = (int)($station->questionbankentryid ?? 0);
            $points = max(0.0, (float)($station->points ?? 0));
            $required = !empty($station->required);
            $questionslot = null;
            if ($qbeid > 0) {
                $slot++;
                $questionslot = $slot;
            }

            $interactables[] = [
                'id' => $hotspotid,
                'kind' => 'hotspot',
                'name' => $title,
                'zone' => $zoneid,
                'position' => self::vec($a[0] + 0.6 * $front[0], $a[1] + 1.4, $a[2] + 0.6 * $front[2]),
                'rotation' => $rotation,
                'content' => [
                    'title' => $title,
                    'text' => (string)($station->text ?? ''),
                    'textformat' => (string)($station->textformat ?? FORMAT_HTML),
                    'media' => [],
                    'question' => $questionslot,
                ],
            ];

            $visitid = "v-{$sid}";
            $visit = [
                'id' => $visitid,
                'type' => 'visit_zone',
                'title' => get_string('objectivevisit', self::COMPONENT, $plaintitle),
                'zone' => $zoneid,
                'scene' => $sceneid,
                'points' => $questionslot !== null ? 0 : $points,
                'required' => $required,
            ];
            if ($ordered && $visitids) {
                $visit['unlock'] = ['after' => [end($visitids)]];
            }
            $objectives[] = $visit;
            $visitids[] = $visitid;

            if ($questionslot !== null) {
                $questionid = "q-{$sid}";
                $objectives[] = [
                    'id' => $questionid,
                    'type' => 'answer_question',
                    'title' => get_string('objectivequestion', self::COMPONENT, $plaintitle),
                    'slot' => $questionslot,
                    'points' => $points,
                    'required' => $required,
                    'unlock' => ['after' => [$visitid]],
                ];
                $slots[] = [
                    'slot' => $questionslot,
                    'questionbankentryid' => $qbeid,
                    'maxmark' => $points > 0 ? $points : 1.0,
                    'objectiveid' => $questionid,
                ];
                $rules[] = [
                    'id' => "r-open-{$sid}",
                    'on' => ['on' => 'objective_completed', 'objective' => $visitid],
                    'once' => true,
                    'actions' => [['do' => 'open_question', 'slot' => $questionslot]],
                ];
            }
        }

        if ($ordered && $visitids) {
            $objectives[] = [
                'id' => 's-order',
                'type' => 'sequence',
                'title' => get_string('objectiveordered', self::COMPONENT),
                'steps' => $visitids,
                'points' => 0,
                'required' => false,
            ];
        }

        $rules[] = [
            'id' => 'r-finish',
            'on' => ['on' => 'all_required_completed'],
            'once' => true,
            'actions' => [['do' => 'finish']],
        ];

        $scene = [
            'schema' => 'vrroom-scene/1',
            'id' => $sceneid,
            'name' => get_string('pluginname', self::COMPONENT),
            'environment' => $environment,
            'spawn' => [$spawn],
            'zones' => $zones,
            'props' => $props,
            'interactables' => $interactables,
        ];
        $graph = [
            'schema' => 'vrroom-graph/1',
            'hidden' => [],
            'objectives' => $objectives,
            'rules' => $rules,
        ];

        return new compiled_activity([$scene], $graph, $slots);
    }

    /**
     * Replace the stations' question bank entry ids after a restore.
     *
     * @param \stdClass $config decoded vrroomtemplate_gallery/1 config, changed in place
     * @param callable $map fn(int $old): int, returns $old when there is no mapping
     * @return bool whether any id changed
     */
    public function remap_question_entries(\stdClass $config, callable $map): bool {
        if (empty($config->stations) || !is_array($config->stations)) {
            return false;
        }
        $changed = false;
        foreach ($config->stations as $station) {
            if (!is_object($station) || empty($station->questionbankentryid)) {
                continue;
            }
            $old = (int)$station->questionbankentryid;
            $new = (int)$map($old);
            if ($new > 0 && $new !== $old) {
                $station->questionbankentryid = $new;
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
     * File options for model and environment file managers (GLB only, one file, size from settings).
     *
     * @return array
     */
    public static function model_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => self::max_model_bytes(),
            'accepted_types' => ['.glb'],
        ];
    }

    /**
     * File options for station image file managers.
     *
     * @return array
     */
    public static function image_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => self::max_model_bytes(),
            'accepted_types' => self::IMAGE_TYPES,
        ];
    }

    /**
     * Largest upload in bytes, from the mod_vrroom maxmodelsize setting (MB, default 20).
     *
     * @return int
     */
    public static function max_model_bytes(): int {
        $mb = (int)get_config('mod_vrroom', 'maxmodelsize');
        if ($mb <= 0) {
            $mb = 20;
        }
        return $mb * 1048576;
    }

    /**
     * Stations of a config as a list of objects.
     *
     * @param \stdClass $config stored or freshly extracted config
     * @return \stdClass[]
     */
    private static function config_stations(\stdClass $config): array {
        $stations = [];
        foreach (array_values((array)($config->stations ?? [])) as $station) {
            if (is_array($station) || is_object($station)) {
                $station = (object)$station;
                if (isset($station->id) && is_string($station->id) && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $station->id)) {
                    $stations[] = $station;
                }
            }
        }
        return $stations;
    }

    /**
     * Load and decode the stored config of an activity.
     *
     * @param int $vrroomid activity instance id
     * @param int|null $courseid when given, the activity must belong to this course
     * @return \stdClass|null null when absent or not a gallery config
     */
    private static function load_stored_config(int $vrroomid, ?int $courseid = null): ?\stdClass {
        global $DB;
        $conditions = ['id' => $vrroomid, 'template' => 'gallery'];
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
     * Indexes of every submitted station row.
     *
     * @param array $data form data
     * @return int[]
     */
    private static function all_row_indexes(array $data): array {
        $titles = $data['gallery_title'] ?? [];
        return is_array($titles) ? array_map('intval', array_keys($titles)) : [];
    }

    /**
     * Indexes of the rows that make a station (a non-empty title), in form order.
     *
     * @param array $data form data
     * @return int[] list of row indexes; list position = station index
     */
    private static function station_rows(array $data): array {
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
        return trim(clean_param((string)($data['gallery_title'][$i] ?? ''), PARAM_TEXT));
    }

    /**
     * Whether a row has content besides its title (text, an exhibit or a question).
     *
     * @param array $data form data
     * @param int $i row index
     * @return bool
     */
    private static function row_has_content(array $data, int $i): bool {
        $text = $data['gallery_text'][$i] ?? '';
        if (is_array($text)) {
            $text = $text['text'] ?? '';
        }
        if (trim(strip_tags((string)$text)) !== '') {
            return true;
        }
        if (self::clean_mediatype($data['gallery_mediatype'][$i] ?? null) !== self::MEDIA_NONE) {
            return true;
        }
        return (int)($data['gallery_question'][$i] ?? 0) > 0;
    }

    /**
     * Normalise an exhibit type to one of the MEDIA_* constants.
     *
     * @param mixed $value submitted or stored value
     * @return string
     */
    private static function clean_mediatype($value): string {
        $value = is_string($value) ? $value : '';
        return in_array($value, [self::MEDIA_MODEL, self::MEDIA_IMAGE], true) ? $value : self::MEDIA_NONE;
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
     * @param string $area models, media or environment
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
     * Rounded position vector.
     *
     * @param float $x
     * @param float $y
     * @param float $z
     * @return float[]
     */
    private static function vec(float $x, float $y, float $z): array {
        return [layout::round3($x), layout::round3($y), layout::round3($z)];
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
