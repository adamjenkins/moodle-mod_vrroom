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
 * Site settings of the VR room built-in viewer run mode (live mode).
 *
 * Included by \mod_vrroom\plugininfo\vrroomruntime::load_settings(), which provides $settings.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'vrroomruntime_builtin/liveheading',
        new lang_string('livesettings', 'vrroomruntime_builtin'),
        new lang_string('livesettings_desc', 'vrroomruntime_builtin')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'vrroomruntime_builtin/liveenabled',
        new lang_string('liveenabled', 'vrroomruntime_builtin'),
        new lang_string('liveenabled_desc', 'vrroomruntime_builtin'),
        1
    ));

    $settings->add(new \vrroomruntime_builtin\admin\setting_iceurls(
        'vrroomruntime_builtin/livestunurls',
        new lang_string('livestunurls', 'vrroomruntime_builtin'),
        new lang_string('livestunurls_desc', 'vrroomruntime_builtin'),
        ['stun', 'stuns']
    ));

    $settings->add(new \vrroomruntime_builtin\admin\setting_iceurls(
        'vrroomruntime_builtin/liveturnurls',
        new lang_string('liveturnurls', 'vrroomruntime_builtin'),
        new lang_string('liveturnurls_desc', 'vrroomruntime_builtin'),
        ['turn', 'turns']
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'vrroomruntime_builtin/liveturnsecret',
        new lang_string('liveturnsecret', 'vrroomruntime_builtin'),
        new lang_string('liveturnsecret_desc', 'vrroomruntime_builtin'),
        ''
    ));

    $settings->add(new admin_setting_configduration(
        'vrroomruntime_builtin/liveturnttl',
        new lang_string('liveturnttl', 'vrroomruntime_builtin'),
        new lang_string('liveturnttl_desc', 'vrroomruntime_builtin'),
        \vrroomruntime_builtin\local\live\config::DEFAULT_TURN_TTL
    ));
}
