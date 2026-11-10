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

namespace mod_vrroom\local\template;

/**
 * Keeps what a teacher typed in one template's fields while the activity form switches templates.
 *
 * Changing the template reloads the form with the other template's fields (a no-submit button),
 * so the fields of the template switched away from are no longer posted. Their submitted values
 * are kept in the session, per form, and posted again when the teacher switches back to that
 * template (Gallery, then Escape, then Gallery keeps the gallery's stations). Every template's
 * fields are named "<template>_...", which is what tells them apart.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_carry {
    /** @var string $SESSION property holding the kept values: [formkey => [template => [field => value]]]. */
    public const SESSION_KEY = 'mod_vrroom_templatefields';

    /**
     * The submitted fields of one template.
     *
     * @param array $post Submitted data.
     * @param string $template Template name.
     * @return array field => value
     */
    public static function fields_of(array $post, string $template): array {
        $prefix = $template . '_';
        $fields = [];
        foreach ($post as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                $fields[$key] = $value;
            }
        }
        return $fields;
    }

    /**
     * Keep the fields of the template switched away from and bring back those of the template shown now.
     *
     * The fields of the template shown now are brought back only when none of them was posted
     * (the reload that switches to it), never over values the teacher has just submitted.
     *
     * @param array $post Submitted data of a template-switch reload.
     * @param array $kept Values kept so far for this form: template => [field => value].
     * @param string $shown Template whose fields the submitted page showed.
     * @param string $name Template whose fields this page shows.
     * @return array{0: array, 1: array} [submitted data to use, values to keep]
     */
    public static function carry(array $post, array $kept, string $shown, string $name): array {
        if ($shown !== '' && $shown !== $name) {
            $kept[$shown] = self::fields_of($post, $shown);
        }
        if (!empty($kept[$name]) && !self::fields_of($post, $name)) {
            $post = $kept[$name] + $post;
        }
        return [$post, $kept];
    }

    /**
     * Apply carry() to the current request and the session.
     *
     * A form shown afresh (nothing posted) forgets what was kept for it. Only the template-switch
     * reload (the no-submit button) keeps or brings back values.
     *
     * @param string $formkey Which form: course and activity (or course and section for a new one).
     * @param string $name Template whose fields this page shows.
     * @param bool $switching Whether this request is the template-switch reload.
     * @param string $shown Template whose fields the submitted page showed ('' for none).
     * @return void
     */
    public static function apply(string $formkey, string $name, bool $switching, string $shown): void {
        global $SESSION;
        $all = isset($SESSION->{self::SESSION_KEY}) && is_array($SESSION->{self::SESSION_KEY})
            ? $SESSION->{self::SESSION_KEY} : [];
        if (empty($_POST)) {
            if (isset($all[$formkey])) {
                unset($all[$formkey]);
                $SESSION->{self::SESSION_KEY} = $all;
            }
            return;
        }
        if (!$switching) {
            return;
        }
        [$post, $kept] = self::carry($_POST, $all[$formkey] ?? [], $shown, $name);
        $_POST = $post;
        $all[$formkey] = $kept;
        $SESSION->{self::SESSION_KEY} = $all;
    }
}
