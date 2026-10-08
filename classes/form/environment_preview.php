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

use mod_vrroom\local\scene\environment_registry;

/**
 * A picture of the chosen room under an environment selector of the activity settings.
 *
 * The figure holds a map of every choice (name, preview URL, credit, places, placeholder) in a
 * data attribute; the AMD module mod_vrroom/environment_preview swaps the picture and caption as
 * soon as the teacher picks another room, before the form is saved. Choices without a picture (an
 * uploaded model, a room this site lacks, a preset without one) show a placeholder text instead.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class environment_preview {
    /**
     * Add a preview figure under an environment selectgroups element and start the AMD module.
     *
     * @param \MoodleQuickForm $mform the form
     * @param string $selectname name of the selectgroups element (e.g. 'gallery_environment')
     * @param array $choices the same group => [key => label] array given to the select
     * @param string $initial the key shown first (stored key, else the template's default)
     * @param array|null $places [identifier, component] of a "Places for {$a} …" string, or null (no places line)
     * @param array $extra key => ['name' => plain, 'placeholder' => plain, 'places' => ?int] for
     *        non-registry choices (the gallery's 'upload')
     */
    public static function add(
        \MoodleQuickForm $mform,
        string $selectname,
        array $choices,
        string $initial,
        ?array $places = null,
        array $extra = []
    ): void {
        global $OUTPUT, $PAGE;

        $map = self::build_map($choices, $places, $extra);
        if (!array_key_exists($initial, $map)) {
            $initial = $map ? (string)array_key_first($map) : '';
        }
        $entry = $map[$initial] ?? [
            'name' => '',
            'url' => null,
            'credit' => '',
            'places' => '',
            'placeholder' => get_string('envpreviewnone', 'mod_vrroom'),
        ];
        $uniqid = \html_writer::random_id('vrroom-envpreview');
        $context = [
            'uniqid' => $uniqid,
            'previewsjson' => json_encode(
                (object)$map,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            ),
            'none' => get_string('envpreviewnone', 'mod_vrroom'),
            'name' => $entry['name'],
            'url' => $entry['url'],
            'credit' => $entry['credit'],
            'places' => $entry['places'],
            'placeholder' => $entry['placeholder'],
        ];
        $html = $OUTPUT->render_from_template('mod_vrroom/environment_preview', $context);
        $mform->addElement('static', $selectname . '_preview', '', $html);
        $PAGE->requires->js_call_amd('mod_vrroom/environment_preview', 'init', ['id_' . $selectname, $uniqid]);
    }

    /**
     * The preview map of every choice.
     *
     * @param array $choices group => [key => label], as given to the select
     * @param array|null $places [identifier, component] of a "Places for {$a} …" string, or null
     * @param array $extra key => ['name' => plain, 'placeholder' => plain, 'places' => ?int] for non-registry choices
     * @return array key => ['name' => string, 'url' => ?string, 'credit' => string, 'places' => string,
     *         'placeholder' => string], all plain text (escaped by the template)
     */
    public static function build_map(array $choices, ?array $places = null, array $extra = []): array {
        $map = [];
        foreach ($choices as $options) {
            foreach ((array)$options as $key => $label) {
                $key = (string)$key;
                if (array_key_exists($key, $extra)) {
                    $item = $extra[$key];
                    $count = $item['places'] ?? null;
                    $map[$key] = [
                        'name' => (string)($item['name'] ?? $label),
                        'url' => null,
                        'credit' => '',
                        'places' => ($places && is_int($count)) ? get_string($places[0], $places[1], $count) : '',
                        'placeholder' => (string)($item['placeholder'] ?? get_string('envpreviewnone', 'mod_vrroom')),
                    ];
                    continue;
                }
                $def = environment_registry::get($key);
                if ($def === null) {
                    $map[$key] = [
                        'name' => (string)$label,
                        'url' => null,
                        'credit' => '',
                        'places' => '',
                        'placeholder' => get_string('envpreviewmissing', 'mod_vrroom'),
                    ];
                    continue;
                }
                $url = environment_registry::preview_url($def);
                $credit = (string)($def['credit'] ?? '');
                $map[$key] = [
                    'name' => (string)$def['name'],
                    'url' => $url,
                    'credit' => $credit !== '' ? get_string('envpreviewcredit', 'mod_vrroom', $credit) : '',
                    'places' => $places ? get_string($places[0], $places[1], count($def['anchors'] ?? [])) : '',
                    'placeholder' => $url === null ? get_string('envpreviewnone', 'mod_vrroom') : '',
                ];
            }
        }
        return $map;
    }
}
