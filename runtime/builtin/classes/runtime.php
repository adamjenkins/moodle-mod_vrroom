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
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace vrroomruntime_builtin;

use vrroomruntime_builtin\local\live\access;
use vrroomruntime_builtin\local\live\config;
use vrroomruntime_builtin\local\live\room_manager;
use mod_vrroom\local\launch\config_builder;
use mod_vrroom\local\runtime\base;
use mod_vrroom\local\runtime\cleanup_scope;
use mod_vrroom\local\runtime\launch_descriptor;
use mod_vrroom\local\runtime\readiness_result;
use mod_vrroom\local\runtime\runtime_capabilities;

/**
 * Built-in run mode: a three.js viewer inside the Moodle page, small live rooms, browser statements.
 *
 * Every person keeps their own attempt. With live mode on, up to six people can share a live
 * room where their browsers connect directly to each other for avatars and voice; Moodle only
 * relays the connection messages (\vrroomruntime_builtin\local\live\room_manager).
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime extends base {
    /** @var string AMD module that view.php boots. */
    public const CLIENT_MODULE = 'vrroomruntime_builtin/launch';

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
     * What the built-in viewer can do: small live rooms with voice and avatars while live mode is on
     * for the site (else one user), immersive VR, AR and mixed reality where the device supports
     * them (the browser decides at run time), all objective types.
     *
     * @return runtime_capabilities
     */
    public function capabilities(): runtime_capabilities {
        $live = config::is_enabled();
        return new runtime_capabilities(
            $live ? room_manager::MAX_PEERS : 1,
            $live,
            $live,
            ['vr', 'ar', 'mr'],
            ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'],
        );
    }

    /**
     * Inline launch: the client receives the launch config built by the activity.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $attempt The attempt row (vrroom_attempts).
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
     * Remove the live room presences and connection messages of the scope.
     *
     * @param cleanup_scope $scope What is being cleaned up.
     * @return void
     */
    public function cleanup(cleanup_scope $scope): void {
        switch ($scope->type) {
            case cleanup_scope::INSTANCE_DELETE:
                if ($scope->vrroomid > 0) {
                    room_manager::purge_activity($scope->vrroomid);
                }
                break;
            case cleanup_scope::COURSE_RESET:
                if ($scope->vrroomid > 0) {
                    room_manager::purge_activity($scope->vrroomid);
                } else if ($scope->courseid > 0) {
                    room_manager::purge_course($scope->courseid);
                }
                break;
            case cleanup_scope::USER_DELETE:
                if ($scope->userid > 0) {
                    room_manager::purge_user($scope->userid, $scope->vrroomid);
                }
                break;
        }
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
        $vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $config = config_builder::build($cm, $context, $vrroom, $attempt, $user);
        $config['live'] = access::launch_block($cm, $context, $vrroom, $attempt, $user);
        return new launch_descriptor(launch_descriptor::TYPE_INLINE, $config);
    }
}
