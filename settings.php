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
 * Site settings for mod_vrroom.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// A category with the general settings and one page per run mode (pattern of mod/assign/settings.php).
$ADMIN->add('modsettings', new admin_category(
    'modvrroomfolder',
    new lang_string('pluginname', 'mod_vrroom'),
    $module->is_enabled() === false
));

$settings = new admin_settingpage(
    $section,
    get_string('generalsettings', 'admin'),
    'moodle/site:config',
    $module->is_enabled() === false
);
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'mod_vrroom/maxmodelsize',
        new lang_string('maxmodelsize', 'mod_vrroom'),
        new lang_string('maxmodelsize_desc', 'mod_vrroom'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_vrroom/maxbatch',
        new lang_string('maxbatch', 'mod_vrroom'),
        new lang_string('maxbatch_desc', 'mod_vrroom'),
        50,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_vrroom/maxattemptevents',
        new lang_string('maxattemptevents', 'mod_vrroom'),
        new lang_string('maxattemptevents_desc', 'mod_vrroom'),
        5000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configduration(
        'mod_vrroom/tokenttl',
        new lang_string('tokenttl', 'mod_vrroom'),
        new lang_string('tokenttl_desc', 'mod_vrroom'),
        3600
    ));

    // 360° photos (photo rooms): the largest original accepted and the smaller copies made for viewing.
    $settings->add(new admin_setting_heading(
        'mod_vrroom/panoramaheading',
        new lang_string('panoramasettings', 'mod_vrroom'),
        new lang_string('panoramasettings_desc', 'mod_vrroom')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_vrroom/panomaxsize',
        new lang_string('panomaxsize', 'mod_vrroom'),
        new lang_string('panomaxsize_desc', 'mod_vrroom'),
        \mod_vrroom\local\panorama::DEFAULT_MAX_SIZE_MB,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'mod_vrroom/panomaxwidth',
        new lang_string('panomaxwidth', 'mod_vrroom'),
        new lang_string('panomaxwidth_desc', 'mod_vrroom'),
        \mod_vrroom\local\panorama::DEFAULT_MAX_WIDTH,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'mod_vrroom/panodesktopwidth',
        new lang_string('panodesktopwidth', 'mod_vrroom'),
        new lang_string('panodesktopwidth_desc', 'mod_vrroom'),
        \mod_vrroom\local\panorama::DEFAULT_DESKTOP_WIDTH,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'mod_vrroom/panomobilewidth',
        new lang_string('panomobilewidth', 'mod_vrroom'),
        new lang_string('panomobilewidth_desc', 'mod_vrroom'),
        \mod_vrroom\local\panorama::DEFAULT_MOBILE_WIDTH,
        PARAM_INT
    ));
}
$ADMIN->add('modvrroomfolder', $settings);
// Tell core the settings structure is already added.
$settings = null;

foreach (core_plugin_manager::instance()->get_plugins_of_type('vrroomruntime') as $plugin) {
    /** @var \mod_vrroom\plugininfo\vrroomruntime $plugin */
    $plugin->load_settings($ADMIN, 'modvrroomfolder', $hassiteconfig);
}
