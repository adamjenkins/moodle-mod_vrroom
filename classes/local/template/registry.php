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
 * Finds the installed templates (mavrtemplate sub-plugins).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registry {
    /** @var base[]|null Instances keyed by short name, once discovered. */
    private static ?array $instances = null;

    /**
     * Every installed template.
     *
     * @return base[] keyed by short name, sorted by name.
     */
    public static function get_all(): array {
        if (self::$instances !== null) {
            return self::$instances;
        }
        $instances = [];
        foreach (\core_component::get_plugin_list('mavrtemplate') as $name => $unuseddir) {
            $classname = "\\mavrtemplate_{$name}\\template";
            if (!class_exists($classname) || !is_subclass_of($classname, base::class)) {
                continue;
            }
            $instances[$name] = new $classname();
        }
        ksort($instances);
        self::$instances = $instances;
        return $instances;
    }

    /**
     * One template by short name.
     *
     * @param string $name Short name, e.g. 'gallery'.
     * @return base
     * @throws \moodle_exception errorunknowntemplate when it is not installed.
     */
    public static function get(string $name): base {
        $all = self::get_all();
        if (!isset($all[$name])) {
            throw new \moodle_exception('errorunknowntemplate', 'mod_mavr', '', $name);
        }
        return $all[$name];
    }

    /**
     * Choices for the activity form.
     *
     * @return array name => display name
     */
    public static function get_choices(): array {
        $choices = [];
        foreach (self::get_all() as $name => $template) {
            $choices[$name] = $template->get_display_name();
        }
        return $choices;
    }

    /**
     * Forget the discovered instances (unit tests).
     */
    public static function reset_caches(): void {
        self::$instances = null;
    }
}
