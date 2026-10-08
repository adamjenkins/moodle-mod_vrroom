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

namespace mod_vrroom\local\runtime;

/**
 * Base class every vrroomruntime sub-plugin extends as \vrroomruntime_<name>\runtime.
 *
 * A run mode delivers the room to the learner. It never stores grades, never evaluates the task
 * graph authoritatively and never defines statements outside the vrroom-vocab/1 vocabulary.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var string Frankenstyle prefix of the sub-plugin type. */
    public const PREFIX = 'vrroomruntime_';

    /**
     * Frankenstyle component of the run mode, taken from the class's namespace.
     *
     * @return string e.g. 'vrroomruntime_builtin'
     */
    final public function get_component(): string {
        $component = strstr(static::class, '\\', true);
        if ($component === false || !str_starts_with($component, self::PREFIX) || $component === self::PREFIX) {
            throw new \coding_exception('A run mode class must live in a vrroomruntime_<name> namespace: ' . static::class);
        }
        return $component;
    }

    /**
     * Short name of the run mode.
     *
     * @return string e.g. 'builtin'
     */
    final public function get_name(): string {
        return substr($this->get_component(), strlen(self::PREFIX));
    }

    /**
     * Name shown to teachers and admins.
     *
     * @return string
     */
    public function get_display_name(): string {
        return get_string('pluginname', $this->get_component());
    }

    /**
     * Whether the run mode is configured and reachable. Checked before every launch.
     *
     * @return readiness_result
     */
    abstract public function readiness(): readiness_result;

    /**
     * What the run mode can do (users per room, voice, XR modes, objective types).
     *
     * @return runtime_capabilities
     */
    abstract public function capabilities(): runtime_capabilities;

    /**
     * Launch an attempt.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $attempt The vrroom_attempts row.
     * @param \stdClass $user The learner.
     * @return launch_descriptor
     */
    abstract public function launch(\cm_info $cm, \stdClass $attempt, \stdClass $user): launch_descriptor;

    /**
     * How statements reach Moodle.
     *
     * @return string 'browser' (core xAPI web service) or 'server' (VR room web service with a launch token).
     */
    abstract public function statement_channel(): string;

    /**
     * Remove remote rooms or accounts for a course reset, an activity deletion or a user deletion.
     *
     * @param cleanup_scope $scope What is being cleaned up.
     */
    abstract public function cleanup(cleanup_scope $scope): void;

    /**
     * The AMD module and function view.php boots with the boot data.
     *
     * @return array ['module' => string, 'function' => string]
     */
    abstract public function client_entry(): array;

    /**
     * Launch for a teacher preview, without an attempt.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $user The teacher.
     * @return launch_descriptor
     */
    public function preview(\cm_info $cm, \stdClass $user): launch_descriptor {
        throw new \coding_exception('The run mode ' . $this->get_name() . ' does not support preview.');
    }
}
