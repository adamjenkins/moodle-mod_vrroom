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

namespace mod_vrroom\tests\hook;

use mod_vrroom\hook\avatar_appearance;

/**
 * Test listener of the avatar_appearance hook: calls the closure a test sets, and counts dispatches.
 *
 * Registered by tests/fixtures/hook/avatar_appearance_hooks.php through \core\hook\manager::phpunit_get_instance().
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class avatar_appearance_callbacks {
    /** @var callable|null What the listener does, set by the test. */
    public static $callback = null;

    /** @var int How often the hook was dispatched. */
    public static int $calls = 0;

    /** @var array The peer lists the hook was dispatched with, in order. */
    public static array $peerlists = [];

    /**
     * Forget the closure and the counts.
     *
     * @return void
     */
    public static function reset(): void {
        self::$callback = null;
        self::$calls = 0;
        self::$peerlists = [];
    }

    /**
     * The listener.
     *
     * @param avatar_appearance $hook The hook.
     * @return void
     */
    public static function avatar_appearance(avatar_appearance $hook): void {
        self::$calls++;
        self::$peerlists[] = $hook->get_peers();
        if (self::$callback !== null) {
            call_user_func(self::$callback, $hook);
        }
    }

    /**
     * Use a hook manager that knows only this listener (or none), for the rest of the test.
     *
     * @param bool $listen Whether this listener is registered.
     * @return void
     */
    public static function install(bool $listen = true): void {
        self::reset();
        $files = [];
        if ($listen) {
            $files['mod_vrroom'] = \core_component::get_component_directory('mod_vrroom')
                . '/tests/fixtures/hook/avatar_appearance_hooks.php';
        }
        \core\di::set(\core\hook\manager::class, \core\hook\manager::phpunit_get_instance($files));
    }
}
