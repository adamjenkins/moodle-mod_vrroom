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

namespace vrroomtemplate_tour;

use mod_vrroom\local\panorama;
use mod_vrroom\local\scene\scene_validator;
use mod_vrroom\local\template\compiled_activity;

/**
 * Virtual tour template: 360° photos joined by arrows, with points of interest (stops) on them.
 *
 * The form fields are stored as a vrroomtemplate_tour/1 config in vrroom.templateconfig and compiled
 * into one panorama vrroom-scene/1 room per photo plus a vrroom-graph/1 task graph: one "interact"
 * objective per stop (a photo room has no walking, so zones are never entered), an optional unlock
 * chain and strict sequence over the stops, and a rule that finishes the attempt when every required
 * stop has been opened. The structure follows the Gallery walk template.
 *
 * @package    vrroomtemplate_tour
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template extends \mod_vrroom\local\template\base {
    /** @var string Frankenstyle component of this template (string and file lookups). */
    public const COMPONENT = 'vrroomtemplate_tour';

    /** @var string Schema identifier written into the stored config. */
    public const SCHEMA = 'vrroomtemplate_tour/1';

    /** @var string Stop media: none (text only). */
    public const MEDIA_NONE = 'none';

    /** @var string Stop media: an image. */
    public const MEDIA_IMAGE = 'image';

    /** @var string Stop media: an audio clip. */
    public const MEDIA_AUDIO = 'audio';

    /** @var string Stop media: a video clip. */
    public const MEDIA_VIDEO = 'video';

    /** @var array<string, string[]> File extensions each stop media type accepts (as mod_vrroom's editor). */
    public const MEDIA_EXTENSIONS = [
        self::MEDIA_IMAGE => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
        self::MEDIA_AUDIO => ['mp3', 'ogg', 'oga', 'm4a'],
        self::MEDIA_VIDEO => ['mp4', 'webm'],
    ];

    /** @var string[] Photo file types (as panorama::IMAGE_MIMETYPES). */
    public const PHOTO_TYPES = ['.jpg', '.jpeg', '.png', '.webp'];

    /** @var int Most photos (rooms) in one tour: mod_vrroom's scene limit. */
    public const MAX_PHOTOS = scene_validator::MAX_SCENES;

    /** @var int Photo groups shown on a new form. */
    public const DEFAULT_PHOTO_REPEATS = 2;

    /** @var int Photo groups added by the "Add more photos" button. */
    public const ADD_PHOTO_REPEATS = 1;

    /** @var int Stop groups shown on a new form. */
    public const DEFAULT_STOP_REPEATS = 3;

    /** @var int Stop groups added by the "Add more stops" button. */
    public const ADD_STOP_REPEATS = 2;

    /** @var int Item id of the first stop's media file (stop k uses MEDIA_ITEMID_BASE + k). */
    public const MEDIA_ITEMID_BASE = 1000;

    /** @var int Longest photo or stop title, in characters. */
    public const MAX_TITLE_LENGTH = 255;

    /** @var int Most points a stop can be worth. */
    public const MAX_POINTS = 99999;

    /** @var float Largest yaw and north offset magnitude, in degrees. */
    public const MAX_YAW = 180.0;

    /** @var float Largest pitch magnitude, in degrees. */
    public const MAX_PITCH = 85.0;

    /** @var float Distance of every marker from the viewer, in metres (the placement default). */
    public const DISTANCE = 4.0;

    /** @var float Pitch of the arrows to the next and previous photo, in degrees: low, so a stop ahead keeps its label clear. */
    public const PORTAL_PITCH = -25.0;

    /** @var string Colour shown while a photo loads. */
    public const SKY = '#1c2026';

    /** @var int|null Instance id of the activity being edited, captured from the form in define_form(). */
    private ?int $editinginstance = null;

    /** @var int|null Course id that $editinginstance was captured for. */
    private ?int $editingcourse = null;

    // Form.

    /**
     * Add the tour fields to the activity form.
     *
     * @param \moodleform_mod $form the activity form (owner of repeat_elements())
     * @param \MoodleQuickForm $mform its QuickForm
     * @param int $courseid course the activity is in
     * @return string[] names of the non-repeated elements added (headers excluded)
     */
    public function define_form(\moodleform_mod $form, \MoodleQuickForm $mform, int $courseid): array {
        global $PAGE;

        $instance = (int)$form->get_instance();
        $this->editinginstance = $instance > 0 ? $instance : null;
        $this->editingcourse = $courseid;
        $config = $this->editinginstance ? self::load_stored_config($this->editinginstance, $courseid) : null;
        $photos = $config ? self::config_photos($config) : [];
        $stops = $config ? self::config_stops($config) : [];

        $mform->addElement('header', 'tour_heading', get_string('tourheading', self::COMPONENT));

        $mform->addElement('advcheckbox', 'tour_ordered', get_string('ordered', self::COMPONENT));
        $mform->setDefault('tour_ordered', 0);
        $mform->addHelpButton('tour_ordered', 'ordered', self::COMPONENT);

        // Photos.
        $photorepeated = [
            $mform->createElement('header', 'tour_photoheader', get_string('photo', self::COMPONENT)),
            $mform->createElement('hidden', 'tour_photoid', ''),
            $mform->createElement(
                'text',
                'tour_phototitle',
                get_string('phototitle', self::COMPONENT),
                ['size' => 50, 'maxlength' => self::MAX_TITLE_LENGTH]
            ),
            $mform->createElement(
                'filemanager',
                'tour_photofile',
                get_string('photofile', self::COMPONENT),
                null,
                self::photo_file_options()
            ),
            $mform->createElement('float', 'tour_northoffset', get_string('northoffset', self::COMPONENT)),
        ];
        $photooptions = [
            'tour_photoheader' => ['expanded' => true],
            'tour_photoid' => ['type' => PARAM_ALPHANUMEXT],
            'tour_phototitle' => ['type' => PARAM_TEXT],
            'tour_photofile' => ['helpbutton' => ['photofile', self::COMPONENT]],
            'tour_northoffset' => ['default' => 0, 'helpbutton' => ['northoffset', self::COMPONENT]],
        ];
        $photorows = $form->repeat_elements(
            $photorepeated,
            min(self::MAX_PHOTOS, max(count($photos), self::DEFAULT_PHOTO_REPEATS)),
            $photooptions,
            'tour_photorepeats',
            'tour_addphotos',
            self::ADD_PHOTO_REPEATS,
            get_string('addphotos', self::COMPONENT)
        );

        // The photo a stop is on, by form row: rows can be blanked but never reordered, so a row
        // number keeps pointing at the same photo while the form is edited.
        $photochoices = [];
        for ($k = 0; $k < $photorows; $k++) {
            $label = get_string('photono', self::COMPONENT, $k + 1);
            if (isset($photos[$k]) && (string)($photos[$k]->title ?? '') !== '') {
                $label = get_string('photochoice', self::COMPONENT, (object)[
                    'no' => $k + 1,
                    'title' => format_string((string)$photos[$k]->title, true, [
                        'context' => \context_course::instance($courseid),
                        'escape' => false,
                    ]),
                ]);
            }
            $photochoices[$k] = $label;
        }

        // Stops.
        $stoprepeated = [
            $mform->createElement('header', 'tour_stopheader', get_string('stop', self::COMPONENT)),
            $mform->createElement('hidden', 'tour_stopid', ''),
            $mform->createElement('select', 'tour_stopphoto', get_string('stopphoto', self::COMPONENT), $photochoices),
            $mform->createElement(
                'text',
                'tour_stoptitle',
                get_string('stoptitle', self::COMPONENT),
                ['size' => 50, 'maxlength' => self::MAX_TITLE_LENGTH]
            ),
            $mform->createElement(
                'editor',
                'tour_stoptext',
                get_string('stoptext', self::COMPONENT),
                ['rows' => 6],
                ['maxfiles' => 0, 'noclean' => false]
            ),
            $mform->createElement('float', 'tour_yaw', get_string('yaw', self::COMPONENT)),
            $mform->createElement('float', 'tour_pitch', get_string('pitch', self::COMPONENT)),
            $mform->createElement('button', 'tour_pick', get_string('pickonphoto', self::COMPONENT)),
            $mform->createElement('select', 'tour_mediatype', get_string('mediatype', self::COMPONENT), [
                self::MEDIA_NONE => get_string('mediatype_none', self::COMPONENT),
                self::MEDIA_IMAGE => get_string('mediatype_image', self::COMPONENT),
                self::MEDIA_AUDIO => get_string('mediatype_audio', self::COMPONENT),
                self::MEDIA_VIDEO => get_string('mediatype_video', self::COMPONENT),
            ]),
            $mform->createElement(
                'filemanager',
                'tour_media',
                get_string('stopmedia', self::COMPONENT),
                null,
                self::media_file_options()
            ),
            $mform->createElement('advcheckbox', 'tour_required', get_string('stoprequired', self::COMPONENT)),
            $mform->createElement('text', 'tour_points', get_string('stoppoints', self::COMPONENT), ['size' => 6]),
        ];
        $stopoptions = [
            'tour_stopheader' => ['expanded' => true],
            'tour_stopid' => ['type' => PARAM_ALPHANUMEXT],
            'tour_stopphoto' => ['type' => PARAM_INT, 'default' => 0],
            'tour_stoptitle' => ['type' => PARAM_TEXT],
            'tour_stoptext' => ['type' => PARAM_RAW],
            'tour_yaw' => ['default' => 0, 'helpbutton' => ['yaw', self::COMPONENT]],
            'tour_pitch' => ['default' => 0, 'helpbutton' => ['pitch', self::COMPONENT]],
            'tour_mediatype' => ['type' => PARAM_ALPHA, 'default' => self::MEDIA_NONE],
            'tour_media' => [
                'hideif' => ['tour_mediatype', 'eq', self::MEDIA_NONE],
                'helpbutton' => ['stopmedia', self::COMPONENT],
            ],
            'tour_required' => ['default' => 1],
            'tour_points' => ['type' => PARAM_INT, 'default' => 1, 'helpbutton' => ['stoppoints', self::COMPONENT]],
        ];
        $form->repeat_elements(
            $stoprepeated,
            max(count($stops), self::DEFAULT_STOP_REPEATS),
            $stopoptions,
            'tour_stoprepeats',
            'tour_addstops',
            self::ADD_STOP_REPEATS,
            get_string('addstops', self::COMPONENT)
        );

        // The "Pick on the photo" buttons open mod_vrroom's direction picker on the chosen photo.
        $PAGE->requires->js_call_amd('vrroomtemplate_tour/form', 'init');

        return ['tour_ordered', 'tour_addphotos', 'tour_addstops'];
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
            return;
        }

        $defaultvalues['tour_ordered'] = !empty($config->ordered) ? 1 : 0;

        $photos = self::config_photos($config);
        $photoindex = [];
        foreach ($photos as $k => $photo) {
            $photoindex[(string)$photo->id] = $k;
            $defaultvalues['tour_photoid'][$k] = (string)$photo->id;
            $defaultvalues['tour_phototitle'][$k] = (string)($photo->title ?? '');
            $defaultvalues['tour_northoffset'][$k] = self::clean_angle($photo->northoffset ?? 0, self::MAX_YAW);
            // The repeat_elements() 'default' options are stored under flat keys such as
            // 'tour_northoffset[0]', and QuickForm looks a flat key up before the nested array,
            // so the stored values must be given under the same flat keys to win (as mod_quiz does).
            $defaultvalues["tour_northoffset[{$k}]"] = $defaultvalues['tour_northoffset'][$k];

            if ($context !== null) {
                $draftitemid = 0;
                file_prepare_draft_area(
                    $draftitemid,
                    $context->id,
                    'mod_vrroom',
                    'environment',
                    (int)($photo->fileitemid ?? ($k + 1)),
                    self::photo_file_options()
                );
                self::drop_draft_variants($draftitemid);
                $defaultvalues['tour_photofile'][$k] = $draftitemid;
            }
        }
        $defaultvalues['tour_photorepeats'] = min(self::MAX_PHOTOS, max(count($photos), self::DEFAULT_PHOTO_REPEATS));

        $stops = self::config_stops($config);
        foreach ($stops as $i => $stop) {
            $defaultvalues['tour_stopid'][$i] = (string)$stop->id;
            $defaultvalues['tour_stopphoto'][$i] = $photoindex[(string)($stop->photo ?? '')] ?? 0;
            $defaultvalues['tour_stoptitle'][$i] = (string)($stop->title ?? '');
            $defaultvalues['tour_stoptext'][$i] = [
                'text' => (string)($stop->text ?? ''),
                'format' => (string)($stop->textformat ?? FORMAT_HTML),
            ];
            $defaultvalues['tour_yaw'][$i] = self::clean_angle($stop->yaw ?? 0, self::MAX_YAW);
            $defaultvalues['tour_pitch'][$i] = self::clean_angle($stop->pitch ?? 0, self::MAX_PITCH);
            $defaultvalues['tour_mediatype'][$i] = self::clean_mediatype($stop->mediatype ?? null);
            $defaultvalues['tour_required'][$i] = !empty($stop->required) ? 1 : 0;
            $defaultvalues['tour_points'][$i] = self::clean_points($stop->points ?? 0);
            foreach (['tour_stopphoto', 'tour_yaw', 'tour_pitch', 'tour_mediatype', 'tour_required', 'tour_points'] as $field) {
                $defaultvalues["{$field}[{$i}]"] = $defaultvalues[$field][$i];
            }

            if ($context !== null) {
                $mediadraft = 0;
                file_prepare_draft_area(
                    $mediadraft,
                    $context->id,
                    'mod_vrroom',
                    'media',
                    (int)($stop->fileitemid ?? (self::MEDIA_ITEMID_BASE + $i)),
                    self::media_file_options()
                );
                $defaultvalues['tour_media'][$i] = $mediadraft;
            }
        }
        $defaultvalues['tour_stoprepeats'] = max(count($stops), self::DEFAULT_STOP_REPEATS);
    }

    /**
     * Validate the tour fields.
     *
     * @param array $data submitted data
     * @param array $files submitted files (unused: draft areas are read from $data)
     * @param int $courseid course the activity is in
     * @return array element name => error message
     */
    public function validation(array $data, array $files, int $courseid): array {
        $errors = [];

        // Photos: every used row needs a title and exactly one good 360° photo.
        foreach (self::all_photo_indexes($data) as $k) {
            $title = self::photo_title($data, $k);
            $hasfile = self::draft_has_files((int)($data['tour_photofile'][$k] ?? 0));
            if ($title === '' && $hasfile) {
                $errors["tour_phototitle[{$k}]"] = get_string('errorphototitle', self::COMPONENT);
            } else if (\core_text::strlen($title) > self::MAX_TITLE_LENGTH) {
                $errors["tour_phototitle[{$k}]"] = get_string('maximumchars', '', self::MAX_TITLE_LENGTH);
            }
            if ($title === '') {
                continue;
            }
            if (!$hasfile) {
                $errors["tour_photofile[{$k}]"] = get_string('errorphotofile', self::COMPONENT);
            } else {
                $file = self::draft_file((int)$data['tour_photofile'][$k]);
                $problem = $file ? panorama::check($file) : 'panoramabadtype';
                if ($problem !== null) {
                    $errors["tour_photofile[{$k}]"] = get_string($problem, 'mod_vrroom', self::limits_for_strings());
                }
            }
            if (!self::angle_in_range($data['tour_northoffset'][$k] ?? 0, self::MAX_YAW)) {
                $errors["tour_northoffset[{$k}]"] = get_string('errornorthoffset', self::COMPONENT);
            }
        }

        $photorows = self::photo_rows($data);
        if (!$photorows) {
            $errors['tour_phototitle[0]'] = get_string('errornophotos', self::COMPONENT);
            return $errors;
        }
        if (count($photorows) > self::MAX_PHOTOS) {
            $errors['tour_phototitle[0]'] = get_string('errortoomanyphotos', self::COMPONENT, self::MAX_PHOTOS);
        }

        // Rows without a title but with other content are a mistake, not a blank row.
        foreach (self::all_stop_indexes($data) as $i) {
            if (self::stop_title($data, $i) === '' && self::stop_has_content($data, $i)) {
                $errors["tour_stoptitle[{$i}]"] = get_string('errorstoptitle', self::COMPONENT);
            }
        }

        foreach (self::stop_rows($data) as $i) {
            if (\core_text::strlen(self::stop_title($data, $i)) > self::MAX_TITLE_LENGTH) {
                $errors["tour_stoptitle[{$i}]"] = get_string('maximumchars', '', self::MAX_TITLE_LENGTH);
            }

            $photorow = $data['tour_stopphoto'][$i] ?? 0;
            if (!is_numeric($photorow) || !in_array((int)$photorow, $photorows, true)) {
                $errors["tour_stopphoto[{$i}]"] = get_string('errorstopphoto', self::COMPONENT);
            }

            if (!self::angle_in_range($data['tour_yaw'][$i] ?? 0, self::MAX_YAW)) {
                $errors["tour_yaw[{$i}]"] = get_string('erroryaw', self::COMPONENT);
            }
            if (!self::angle_in_range($data['tour_pitch'][$i] ?? 0, self::MAX_PITCH)) {
                $errors["tour_pitch[{$i}]"] = get_string('errorpitch', self::COMPONENT);
            }

            $mediatype = self::clean_mediatype($data['tour_mediatype'][$i] ?? null);
            if ($mediatype !== self::MEDIA_NONE) {
                $file = self::draft_file((int)($data['tour_media'][$i] ?? 0));
                if ($file === null) {
                    $errors["tour_media[{$i}]"] = get_string('errormediarequired', self::COMPONENT);
                } else if (!self::media_matches($file->get_filename(), $mediatype)) {
                    $errors["tour_media[{$i}]"] = get_string(
                        'errormediatype',
                        self::COMPONENT,
                        implode(', ', self::MEDIA_EXTENSIONS[$mediatype])
                    );
                }
            }

            $points = $data['tour_points'][$i] ?? 1;
            if (!is_numeric($points) || (int)$points != $points || (int)$points < 0 || (int)$points > self::MAX_POINTS) {
                $errors["tour_points[{$i}]"] = get_string('errorpoints', self::COMPONENT, self::MAX_POINTS);
            }
        }

        return $errors;
    }

    // Config and files.

    /**
     * Turn submitted form data into the stored vrroomtemplate_tour/1 config.
     *
     * Photo rows without a title (and without a file) and stop rows without a title are dropped;
     * the rest are numbered in form order. Photo k keeps its file at itemid k + 1 of area
     * environment, stop k at itemid 1000 + k of area media. Ids posted back in hidden fields are
     * kept only when the stored config of this activity already has them, so the scene ids (the
     * photo ids) stay stable across edits.
     *
     * @param \stdClass $formdata data from the activity form (or the generator)
     * @return \stdClass config
     */
    public function extract_config(\stdClass $formdata): \stdClass {
        $data = (array)$formdata;
        $existing = !empty($data['instance']) ? self::load_stored_config((int)$data['instance']) : null;

        $knownphotos = [];
        $knownstops = [];
        if ($existing) {
            foreach (self::config_photos($existing) as $photo) {
                $knownphotos[(string)$photo->id] = true;
            }
            foreach (self::config_stops($existing) as $stop) {
                $knownstops[(string)$stop->id] = true;
            }
        }

        $photos = [];
        $photobyrow = [];
        $usedids = [];
        foreach (self::photo_rows($data) as $index => $k) {
            if ($index >= self::MAX_PHOTOS) {
                break;
            }
            $id = clean_param((string)($data['tour_photoid'][$k] ?? ''), PARAM_ALPHANUMEXT);
            if ($id === '' || !isset($knownphotos[$id]) || isset($usedids[$id]) || !self::is_uuid($id)) {
                $id = self::new_uuid();
            }
            $usedids[$id] = true;
            $title = self::photo_title($data, $k);
            if ($title === '') {
                $title = get_string('photono', self::COMPONENT, $index + 1);
            }
            $photos[] = (object)[
                'id' => $id,
                'title' => $title,
                'fileitemid' => $index + 1,
                'northoffset' => self::clean_angle($data['tour_northoffset'][$k] ?? 0, self::MAX_YAW),
            ];
            $photobyrow[$k] = $id;
        }

        $stops = [];
        if ($photos) {
            $firstphoto = $photos[0]->id;
            foreach (self::stop_rows($data) as $index => $i) {
                $id = clean_param((string)($data['tour_stopid'][$i] ?? ''), PARAM_ALPHANUMEXT);
                if ($id === '' || !isset($knownstops[$id]) || isset($usedids[$id])) {
                    $id = self::new_uuid();
                }
                $usedids[$id] = true;

                $text = $data['tour_stoptext'][$i] ?? '';
                if (is_array($text)) {
                    $format = $text['format'] ?? FORMAT_HTML;
                    $text = $text['text'] ?? '';
                } else {
                    $format = FORMAT_HTML;
                }

                $photorow = $data['tour_stopphoto'][$i] ?? 0;
                $photo = is_numeric($photorow) ? ($photobyrow[(int)$photorow] ?? $firstphoto) : $firstphoto;

                $stops[] = (object)[
                    'id' => $id,
                    'photo' => $photo,
                    'title' => self::stop_title($data, $i),
                    'text' => (string)$text,
                    'textformat' => (string)clean_param($format, PARAM_INT),
                    'yaw' => self::clean_angle($data['tour_yaw'][$i] ?? 0, self::MAX_YAW),
                    'pitch' => self::clean_angle($data['tour_pitch'][$i] ?? 0, self::MAX_PITCH),
                    'mediatype' => self::clean_mediatype($data['tour_mediatype'][$i] ?? null),
                    'fileitemid' => self::MEDIA_ITEMID_BASE + $index,
                    'required' => !empty($data['tour_required'][$i] ?? 1),
                    'points' => self::clean_points($data['tour_points'][$i] ?? 1),
                ];
            }
        }

        return (object)[
            'schema' => self::SCHEMA,
            'ordered' => !empty($data['tour_ordered']),
            'photos' => $photos,
            'stops' => $stops,
        ];
    }

    /**
     * Save the form's draft file areas into the module's file areas.
     *
     * Photo k (in config order) goes to area environment at itemid k + 1, and its desktop and mobile
     * copies are made beside it; stop k's media goes to area media at itemid 1000 + k. Files at any
     * other itemid of those areas (and the unused models area) are removed. A draft field that is
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

        $photorows = array_slice(self::photo_rows($data), 0, self::MAX_PHOTOS);
        foreach ($photorows as $index => $k) {
            if (!empty($data['tour_photofile'][$k])) {
                self::save_photo((int)$data['tour_photofile'][$k], $context, $index + 1);
            }
        }
        $photocount = count($photorows);
        $stored = $fs->get_area_files($context->id, 'mod_vrroom', 'environment', false, 'itemid, filepath, filename', true);
        foreach ($stored as $file) {
            $itemid = (int)$file->get_itemid();
            if ($itemid < 1 || $itemid > $photocount) {
                $file->delete();
            }
        }

        $stoprows = $photorows ? self::stop_rows($data) : [];
        foreach ($stoprows as $index => $i) {
            $itemid = self::MEDIA_ITEMID_BASE + $index;
            if (self::clean_mediatype($data['tour_mediatype'][$i] ?? null) === self::MEDIA_NONE) {
                $fs->delete_area_files($context->id, 'mod_vrroom', 'media', $itemid);
            } else if (!empty($data['tour_media'][$i])) {
                file_save_draft_area_files(
                    (int)$data['tour_media'][$i],
                    $context->id,
                    'mod_vrroom',
                    'media',
                    $itemid,
                    self::media_file_options()
                );
            }
        }
        $stopcount = count($stoprows);
        $stored = $fs->get_area_files($context->id, 'mod_vrroom', 'media', false, 'itemid, filepath, filename', true);
        foreach ($stored as $file) {
            $itemid = (int)$file->get_itemid();
            if ($itemid < self::MEDIA_ITEMID_BASE || $itemid >= self::MEDIA_ITEMID_BASE + $stopcount) {
                $file->delete();
            }
        }

        // A tour has no models; whatever another template left there is no longer referenced.
        $fs->delete_area_files($context->id, 'mod_vrroom', 'models');
    }

    // Compile.

    /**
     * Compile a stored config into one panorama scene per photo and the task graph.
     *
     * @param \stdClass $config vrroomtemplate_tour/1 config
     * @param \context_module $context module context (file areas are read from it)
     * @return compiled_activity
     */
    public function compile(\stdClass $config, \context_module $context): compiled_activity {
        $fs = get_file_storage();
        $photos = self::config_photos($config);
        $stops = self::config_stops($config);
        $ordered = !empty($config->ordered);

        if (!$photos) {
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'A virtual tour needs at least one photo.');
        }
        if (count($photos) > self::MAX_PHOTOS) {
            throw new \moodle_exception('invalidscene', 'mod_vrroom', '', 'A virtual tour takes at most ' .
                self::MAX_PHOTOS . ' photos.');
        }

        $photoids = array_map(fn ($photo) => (string)$photo->id, $photos);
        $stopsbyphoto = array_fill_keys($photoids, []);
        foreach ($stops as $i => $stop) {
            $pid = (string)($stop->photo ?? '');
            $stopsbyphoto[isset($stopsbyphoto[$pid]) ? $pid : $photoids[0]][] = $i;
        }

        $scenes = [];
        foreach ($photos as $k => $photo) {
            $pid = (string)$photo->id;
            $title = (string)($photo->title ?? '');
            $file = self::photo_ref($fs, $context, (int)($photo->fileitemid ?? ($k + 1)));
            if ($file === null) {
                throw new \moodle_exception('invalidscene', 'mod_vrroom', '', "The photo of \"{$title}\" is missing.");
            }

            $interactables = [];
            if (isset($photos[$k + 1])) {
                $interactables[] = self::portal("pn-{$pid}", $photos[$k + 1], 0.0);
            }
            if ($k > 0) {
                $interactables[] = self::portal("pp-{$pid}", $photos[$k - 1], 180.0);
            }

            $northoffset = self::clean_angle($photo->northoffset ?? 0, self::MAX_YAW);
            foreach ($stopsbyphoto[$pid] as $i) {
                $stop = $stops[$i];
                $sid = (string)$stop->id;
                $stoptitle = (string)($stop->title ?? '');
                // The form's yaw is measured on the flat photo (the picker); the viewer turns the
                // photo by its north offset, so the point turns with it.
                $yaw = self::wrap_angle(self::clean_angle($stop->yaw ?? 0, self::MAX_YAW) + $northoffset);
                $pitch = self::clean_angle($stop->pitch ?? 0, self::MAX_PITCH);
                $media = [];
                if (self::clean_mediatype($stop->mediatype ?? null) !== self::MEDIA_NONE) {
                    $ref = self::file_ref($fs, $context, 'media', (int)($stop->fileitemid ?? (self::MEDIA_ITEMID_BASE + $i)));
                    if ($ref !== null) {
                        $media[] = $ref;
                    }
                }
                $interactables[] = [
                    'id' => "h-{$sid}",
                    'kind' => 'hotspot',
                    'name' => $stoptitle,
                    'position' => panorama::placement_position($yaw, $pitch, self::DISTANCE),
                    'rotation' => [0.0, $yaw, 0.0],
                    'placement' => ['yaw' => $yaw, 'pitch' => $pitch, 'distance' => self::DISTANCE],
                    'content' => [
                        'title' => $stoptitle,
                        'text' => (string)($stop->text ?? ''),
                        'textformat' => (string)($stop->textformat ?? FORMAT_HTML),
                        'media' => $media,
                        'question' => null,
                    ],
                ];
            }

            $scenes[] = [
                'schema' => 'vrroom-scene/1',
                'id' => $pid,
                'name' => $title,
                'environment' => [
                    'type' => 'panorama',
                    'file' => $file,
                    'northoffset' => self::clean_angle($photo->northoffset ?? 0, self::MAX_YAW),
                    'sky' => self::SKY,
                ],
                'spawn' => [['id' => "sp-{$pid}", 'position' => [0.0, 0.0, 0.0], 'yaw' => 0.0]],
                'zones' => [],
                'props' => [],
                'interactables' => $interactables,
            ];
        }

        // Objectives in form order (the order "Visit in order" asks for).
        $objectives = [];
        $visitids = [];
        foreach ($stops as $stop) {
            $sid = (string)$stop->id;
            $plaintitle = format_string((string)($stop->title ?? ''), true, ['context' => $context, 'escape' => false]);
            $visitid = "v-{$sid}";
            $visit = [
                'id' => $visitid,
                'type' => 'interact',
                'title' => get_string('objectivevisit', self::COMPONENT, $plaintitle),
                'target' => "h-{$sid}",
                'points' => self::clean_points($stop->points ?? 0),
                'required' => !empty($stop->required),
            ];
            if ($ordered && $visitids) {
                $visit['unlock'] = ['after' => [end($visitids)]];
            }
            $objectives[] = $visit;
            $visitids[] = $visitid;
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

        $rules = [];
        if ($visitids) {
            // A tour without stops is just a walk-through: nothing to finish on.
            $rules[] = [
                'id' => 'r-finish',
                'on' => ['on' => 'all_required_completed'],
                'once' => true,
                'actions' => [['do' => 'finish']],
            ];
        }

        $graph = [
            'schema' => 'vrroom-graph/1',
            'hidden' => [],
            'objectives' => $objectives,
            'rules' => $rules,
        ];

        return new compiled_activity($scenes, $graph, []);
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
     * File options for the photo file managers (one 360° photo, size from mod_vrroom's settings).
     *
     * @return array
     */
    public static function photo_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => panorama::max_bytes(),
            'accepted_types' => self::PHOTO_TYPES,
        ];
    }

    /**
     * File options for the stop media file managers (one image, audio or video file).
     *
     * @return array
     */
    public static function media_file_options(): array {
        $types = [];
        foreach (self::MEDIA_EXTENSIONS as $extensions) {
            foreach ($extensions as $extension) {
                $types[] = '.' . $extension;
            }
        }
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => self::max_media_bytes(),
            'accepted_types' => $types,
        ];
    }

    /**
     * Largest stop media upload in bytes, from the mod_vrroom maxmodelsize setting (MB, default 20).
     *
     * @return int
     */
    public static function max_media_bytes(): int {
        $mb = (int)get_config('mod_vrroom', 'maxmodelsize');
        if ($mb <= 0) {
            $mb = 20;
        }
        return $mb * 1048576;
    }

    /**
     * An arrow to another photo, placed by direction.
     *
     * @param string $id portal id
     * @param \stdClass $target the photo it leads to
     * @param float $yaw direction of the arrow, in degrees
     * @return array
     */
    private static function portal(string $id, \stdClass $target, float $yaw): array {
        $targetid = (string)$target->id;
        return [
            'id' => $id,
            'kind' => 'portal',
            'name' => (string)($target->title ?? ''),
            'position' => panorama::placement_position($yaw, self::PORTAL_PITCH, self::DISTANCE),
            'rotation' => [0.0, $yaw, 0.0],
            'placement' => ['yaw' => $yaw, 'pitch' => self::PORTAL_PITCH, 'distance' => self::DISTANCE],
            'target' => ['scene' => $targetid, 'spawn' => "sp-{$targetid}"],
        ];
    }

    /**
     * Save one photo's draft area and make its desktop and mobile copies.
     *
     * When the draft holds the very file already stored (the form was saved without touching the
     * photo), nothing is written, so the copies made earlier are kept instead of being made again.
     *
     * @param int $draftitemid draft item id of the photo's file manager
     * @param \context_module $context module context
     * @param int $itemid item id in area environment
     */
    private static function save_photo(int $draftitemid, \context_module $context, int $itemid): void {
        global $USER;
        $fs = get_file_storage();

        $stored = self::photo_file($fs, $context, $itemid);
        $drafts = [];
        if (!empty($USER->id)) {
            $usercontext = \context_user::instance($USER->id);
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filepath, filename', false);
            foreach ($files as $file) {
                if (!self::is_variant_path($file->get_filepath())) {
                    $drafts[] = $file;
                }
            }
        }
        if (
            $stored !== null && count($drafts) === 1
                && $drafts[0]->get_contenthash() === $stored->get_contenthash()
                && $drafts[0]->get_filename() === $stored->get_filename()
                && $drafts[0]->get_filepath() === $stored->get_filepath()
        ) {
            $variants = panorama::find_variants($stored);
            if (empty(array_filter($variants))) {
                panorama::make_variants($stored);
            }
            return;
        }

        file_save_draft_area_files($draftitemid, $context->id, 'mod_vrroom', 'environment', $itemid, self::photo_file_options());
        $photo = self::photo_file($fs, $context, $itemid);
        if ($photo !== null) {
            panorama::make_variants($photo);
        }
    }

    /**
     * Remove the desktop and mobile copies from a draft area prepared from a stored photo.
     *
     * The file manager shows (and saves back) only the photo itself; the copies are made again
     * from it when it changes.
     *
     * @param int $draftitemid draft item id
     */
    private static function drop_draft_variants(int $draftitemid): void {
        global $USER;
        if ($draftitemid <= 0 || empty($USER->id)) {
            return;
        }
        $usercontext = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filepath, filename');
        foreach ($files as $file) {
            if (self::is_variant_path($file->get_filepath())) {
                $file->delete();
            }
        }
    }

    /**
     * Whether a file path is a folder of made copies (".../variants/").
     *
     * @param string $filepath
     * @return bool
     */
    private static function is_variant_path(string $filepath): bool {
        return str_ends_with($filepath, '/variants/');
    }

    /**
     * The stored original photo at an item of area environment (never one of its copies).
     *
     * @param \file_storage $fs file storage
     * @param \context_module $context module context
     * @param int $itemid item id
     * @return \stored_file|null
     */
    private static function photo_file(\file_storage $fs, \context_module $context, int $itemid): ?\stored_file {
        $files = $fs->get_area_files($context->id, 'mod_vrroom', 'environment', $itemid, 'filepath, filename', false);
        foreach ($files as $file) {
            if (!self::is_variant_path($file->get_filepath())) {
                return $file;
            }
        }
        return null;
    }

    /**
     * vrroom-scene/1 file reference to a stored original photo.
     *
     * @param \file_storage $fs file storage
     * @param \context_module $context module context
     * @param int $itemid item id in area environment
     * @return array|null
     */
    private static function photo_ref(\file_storage $fs, \context_module $context, int $itemid): ?array {
        $file = self::photo_file($fs, $context, $itemid);
        if ($file === null) {
            return null;
        }
        return [
            'area' => 'environment',
            'itemid' => $itemid,
            'filepath' => $file->get_filepath(),
            'filename' => $file->get_filename(),
        ];
    }

    /**
     * vrroom-scene/1 file reference to the first file in a module file area item.
     *
     * @param \file_storage $fs file storage
     * @param \context_module $context module context
     * @param string $area media
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
     * Photos of a config as a list of objects (each with a valid id).
     *
     * @param \stdClass $config stored or freshly extracted config
     * @return \stdClass[]
     */
    private static function config_photos(\stdClass $config): array {
        $photos = [];
        foreach (array_values((array)($config->photos ?? [])) as $photo) {
            if (is_array($photo) || is_object($photo)) {
                $photo = (object)$photo;
                if (
                    isset($photo->id) && is_string($photo->id) && preg_match('/^[A-Za-z0-9_-]{1,36}$/', $photo->id)
                ) {
                    $photos[] = $photo;
                }
            }
        }
        return $photos;
    }

    /**
     * Stops of a config as a list of objects (each with a valid id).
     *
     * @param \stdClass $config stored or freshly extracted config
     * @return \stdClass[]
     */
    private static function config_stops(\stdClass $config): array {
        $stops = [];
        foreach (array_values((array)($config->stops ?? [])) as $stop) {
            if (is_array($stop) || is_object($stop)) {
                $stop = (object)$stop;
                if (isset($stop->id) && is_string($stop->id) && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $stop->id)) {
                    $stops[] = $stop;
                }
            }
        }
        return $stops;
    }

    /**
     * Load and decode the stored config of an activity.
     *
     * @param int $vrroomid activity instance id
     * @param int|null $courseid when given, the activity must belong to this course
     * @return \stdClass|null null when absent or not a tour config
     */
    private static function load_stored_config(int $vrroomid, ?int $courseid = null): ?\stdClass {
        global $DB;
        $conditions = ['id' => $vrroomid, 'template' => 'tour'];
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
     * Indexes of every submitted photo row.
     *
     * @param array $data form data
     * @return int[]
     */
    private static function all_photo_indexes(array $data): array {
        $indexes = [];
        foreach (['tour_phototitle', 'tour_photofile'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                foreach (array_keys($data[$field]) as $k) {
                    $indexes[(int)$k] = true;
                }
            }
        }
        $indexes = array_keys($indexes);
        sort($indexes);
        return $indexes;
    }

    /**
     * Indexes of the rows that make a photo (a non-empty title or a file), in form order.
     *
     * @param array $data form data
     * @return int[] list of row indexes; list position = photo index
     */
    private static function photo_rows(array $data): array {
        $rows = [];
        foreach (self::all_photo_indexes($data) as $k) {
            if (self::photo_title($data, $k) !== '' || self::draft_has_files((int)($data['tour_photofile'][$k] ?? 0))) {
                $rows[] = $k;
            }
        }
        return $rows;
    }

    /**
     * Cleaned, trimmed title of a photo row.
     *
     * @param array $data form data
     * @param int $k row index
     * @return string
     */
    private static function photo_title(array $data, int $k): string {
        return trim(clean_param((string)($data['tour_phototitle'][$k] ?? ''), PARAM_TEXT));
    }

    /**
     * Indexes of every submitted stop row.
     *
     * @param array $data form data
     * @return int[]
     */
    private static function all_stop_indexes(array $data): array {
        $titles = $data['tour_stoptitle'] ?? [];
        $indexes = is_array($titles) ? array_map('intval', array_keys($titles)) : [];
        sort($indexes);
        return $indexes;
    }

    /**
     * Indexes of the rows that make a stop (a non-empty title), in form order.
     *
     * @param array $data form data
     * @return int[] list of row indexes; list position = stop index
     */
    private static function stop_rows(array $data): array {
        $rows = [];
        foreach (self::all_stop_indexes($data) as $i) {
            if (self::stop_title($data, $i) !== '') {
                $rows[] = $i;
            }
        }
        return $rows;
    }

    /**
     * Cleaned, trimmed title of a stop row.
     *
     * @param array $data form data
     * @param int $i row index
     * @return string
     */
    private static function stop_title(array $data, int $i): string {
        return trim(clean_param((string)($data['tour_stoptitle'][$i] ?? ''), PARAM_TEXT));
    }

    /**
     * Whether a stop row has content besides its title (text or media).
     *
     * @param array $data form data
     * @param int $i row index
     * @return bool
     */
    private static function stop_has_content(array $data, int $i): bool {
        $text = $data['tour_stoptext'][$i] ?? '';
        if (is_array($text)) {
            $text = $text['text'] ?? '';
        }
        if (trim(strip_tags((string)$text)) !== '') {
            return true;
        }
        return self::clean_mediatype($data['tour_mediatype'][$i] ?? null) !== self::MEDIA_NONE;
    }

    /**
     * Normalise a stop media type to one of the MEDIA_* constants.
     *
     * @param mixed $value submitted or stored value
     * @return string
     */
    private static function clean_mediatype($value): string {
        $value = is_string($value) ? $value : '';
        return array_key_exists($value, self::MEDIA_EXTENSIONS) ? $value : self::MEDIA_NONE;
    }

    /**
     * Whether a file name's extension suits a stop media type.
     *
     * @param string $filename
     * @param string $mediatype one of the MEDIA_* constants other than none
     * @return bool
     */
    private static function media_matches(string $filename, string $mediatype): bool {
        $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::MEDIA_EXTENSIONS[$mediatype] ?? [], true);
    }

    /**
     * Bring an angle into -180..180 degrees.
     *
     * @param float $angle Degrees.
     * @return float
     */
    private static function wrap_angle(float $angle): float {
        $wrapped = fmod($angle + 180.0, 360.0);
        if ($wrapped < 0) {
            $wrapped += 360.0;
        }
        return round($wrapped - 180.0, 1);
    }

    /**
     * An angle rounded to 0.1 degree and clamped to ±$max (0 for anything that is not a finite number).
     *
     * @param mixed $value submitted or stored value
     * @param float $max largest magnitude
     * @return float
     */
    private static function clean_angle($value, float $max): float {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            return 0.0;
        }
        return min($max, max(-$max, round((float)$value, 1)));
    }

    /**
     * Whether a submitted angle is a finite number within ±$max.
     *
     * @param mixed $value submitted value
     * @param float $max largest magnitude
     * @return bool
     */
    private static function angle_in_range($value, float $max): bool {
        return is_numeric($value) && is_finite((float)$value) && abs((float)$value) <= $max;
    }

    /**
     * A whole number of points from 0 to MAX_POINTS (0 for anything else).
     *
     * @param mixed $value submitted or stored value
     * @return int
     */
    private static function clean_points($value): int {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            return 0;
        }
        return (int)min(self::MAX_POINTS, max(0, (int)round((float)$value)));
    }

    /**
     * The photo limits, for the placeholders of mod_vrroom's panorama error strings.
     *
     * @return \stdClass maxwidth, desktopwidth, mobilewidth, maxbytes and maxsize (human-readable)
     */
    private static function limits_for_strings(): \stdClass {
        $limits = (object)panorama::limits();
        $limits->maxsize = display_size((int)($limits->maxbytes ?? panorama::max_bytes()));
        return $limits;
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
     * The first file (not a made copy) in the current user's draft area.
     *
     * @param int $draftitemid draft item id
     * @return \stored_file|null
     */
    private static function draft_file(int $draftitemid): ?\stored_file {
        global $USER;
        if ($draftitemid <= 0 || empty($USER->id)) {
            return null;
        }
        $usercontext = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filepath, filename', false);
        foreach ($files as $file) {
            if (!self::is_variant_path($file->get_filepath())) {
                return $file;
            }
        }
        return null;
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
