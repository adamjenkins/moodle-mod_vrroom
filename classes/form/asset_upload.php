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

namespace mod_vrroom\form;

use mod_vrroom\local\editor\editor_manager;

/**
 * Upload one file for the room editor (a model, an image, audio or video) into the assets area.
 *
 * Shown by core_form/modalform from mod_vrroom/editor_assets. Each upload gets its own item id, so
 * the editor can refer to it and the save can delete it again when no room uses it.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class asset_upload extends \core_form\dynamic_form {
    /** @var string[] Kinds of file this form takes. */
    public const KINDS = ['model', 'image', 'audio', 'video'];

    /**
     * The form fields.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'kind');
        $mform->setType('kind', PARAM_ALPHA);

        $mform->addElement(
            'filemanager',
            'assetfile',
            get_string('editorassetfile', 'mod_vrroom'),
            null,
            self::file_options($this->get_kind())
        );
        $mform->addRule('assetfile', null, 'required');
    }

    /**
     * The module context of the activity named by cmid.
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        $cmid = (int)$this->optional_param('cmid', 0, PARAM_INT);
        // The id comes from the browser: it must be a VR room course module.
        $cm = get_coursemodule_from_id('vrroom', $cmid, 0, false, MUST_EXIST);
        return \context_module::instance($cm->id);
    }

    /**
     * Only teachers who manage the activity may upload.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/vrroom:manage', $this->get_context_for_dynamic_submission());
    }

    /**
     * Fill the hidden fields.
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data([
            'cmid' => (int)$this->optional_param('cmid', 0, PARAM_INT),
            'kind' => $this->get_kind(),
        ]);
    }

    /**
     * The room editor of the activity.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/mod/vrroom/edit.php', ['id' => (int)$this->optional_param('cmid', 0, PARAM_INT)]);
    }

    /**
     * Store the uploaded file under a new item id of the assets area.
     *
     * @return array The file reference with url, mimetype, kind and filesize.
     */
    public function process_dynamic_submission() {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $kind = $this->get_kind();
        if (!in_array($kind, self::KINDS, true)) {
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', 'file kind');
        }
        $data = $this->get_data();
        $context = $this->get_context_for_dynamic_submission();

        $itemid = editor_manager::next_asset_itemid($context);
        file_save_draft_area_files(
            (int)$data->assetfile,
            $context->id,
            'mod_vrroom',
            editor_manager::ASSET_AREA,
            $itemid,
            self::file_options($kind)
        );

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_vrroom', editor_manager::ASSET_AREA, $itemid, 'id', false);
        $file = count($files) === 1 ? reset($files) : null;
        if ($file === null || editor_manager::asset_kind($file->get_filename()) !== $kind) {
            $name = $file ? $file->get_filename() : '';
            $fs->delete_area_files($context->id, 'mod_vrroom', editor_manager::ASSET_AREA, $itemid);
            throw new \moodle_exception('erroreditorinvalid', 'mod_vrroom', '', s($name !== '' ? $name : 'file'));
        }
        return editor_manager::asset_entry($file);
    }

    /**
     * The kind of file asked for.
     *
     * @return string One of KINDS, or '' when the request names none of them.
     */
    private function get_kind(): string {
        $kind = (string)$this->optional_param('kind', '', PARAM_ALPHA);
        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    /**
     * File manager options for a kind.
     *
     * @param string $kind One of KINDS.
     * @return array
     */
    private static function file_options(string $kind): array {
        $types = [];
        foreach (editor_manager::KIND_EXTENSIONS[$kind] ?? [] as $extension) {
            $types[] = '.' . $extension;
        }
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => editor_manager::get_max_bytes(),
            'accepted_types' => $types ?: ['.glb'],
        ];
    }
}
