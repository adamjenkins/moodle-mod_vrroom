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
 * The built-in viewer run mode.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mavrruntime_builtin;

use mod_mavr\local\launch\config_builder;
use mod_mavr\local\runtime\base;
use mod_mavr\local\runtime\cleanup_scope;
use mod_mavr\local\runtime\launch_descriptor;
use mod_mavr\local\runtime\readiness_result;
use mod_mavr\local\runtime\runtime_capabilities;

/**
 * Built-in run mode: a three.js viewer inside the Moodle page, single user, browser statements.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime extends base {
    /** @var string AMD module that view.php boots. */
    public const CLIENT_MODULE = 'mavrruntime_builtin/launch';

    /** @var string Function of the AMD module that view.php calls. */
    public const CLIENT_FUNCTION = 'init';

    /**
     * The built-in viewer needs no configuration, so it is always ready.
     *
     * @return readiness_result
     */
    public function readiness(): readiness_result {
        return new readiness_result(true);
    }

    /**
     * What the built-in viewer can do in Phase 1.
     *
     * @return runtime_capabilities
     */
    public function capabilities(): runtime_capabilities {
        return new runtime_capabilities(
            1,
            false,
            false,
            [],
            ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'],
        );
    }

    /**
     * Inline launch: the client receives the launch config built by the activity.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $attempt The attempt row (mavr_attempts).
     * @param \stdClass $user The user who launches.
     * @return launch_descriptor
     */
    public function launch(\cm_info $cm, \stdClass $attempt, \stdClass $user): launch_descriptor {
        return $this->build_descriptor($cm, $attempt, $user);
    }

    /**
     * Inline launch for a teacher preview (no attempt, nothing recorded).
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $user The user who previews.
     * @return launch_descriptor
     */
    public function preview(\cm_info $cm, \stdClass $user): launch_descriptor {
        return $this->build_descriptor($cm, null, $user);
    }

    /**
     * Statements reach Moodle from the browser through core_xapi_statement_post.
     *
     * @return string
     */
    public function statement_channel(): string {
        return 'browser';
    }

    /**
     * Nothing to clean up: the built-in viewer keeps nothing outside the activity's own tables.
     *
     * @param cleanup_scope $scope What is being cleaned up.
     * @return void
     */
    public function cleanup(cleanup_scope $scope): void {
        // The built-in viewer has no remote rooms, accounts or tables of its own.
        return;
    }

    /**
     * The AMD entry point that view.php boots.
     *
     * @return array ['module' => string, 'function' => string]
     */
    public function client_entry(): array {
        return ['module' => self::CLIENT_MODULE, 'function' => self::CLIENT_FUNCTION];
    }

    /**
     * Build an inline launch descriptor for an attempt or a preview.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass|null $attempt The attempt, or null for a preview.
     * @param \stdClass $user The user.
     * @return launch_descriptor
     */
    protected function build_descriptor(\cm_info $cm, ?\stdClass $attempt, \stdClass $user): launch_descriptor {
        global $DB;
        $mavr = $DB->get_record('mavr', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        return new launch_descriptor(
            launch_descriptor::TYPE_INLINE,
            config_builder::build($cm, $context, $mavr, $attempt, $user),
        );
    }
}
