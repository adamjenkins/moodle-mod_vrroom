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
 * Version details for the Escape chain template.
 *
 * @package    mavrtemplate_escape
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mavrtemplate_escape';
$plugin->version = 2026100800;
$plugin->release = '0.1.0';
$plugin->maturity = MATURITY_ALPHA;
// Moodle 5.2.0 (the v5.2.0 tag's public/version.php: 2026042000.00).
$plugin->requires = 2026042000;
$plugin->supported = [502, 502];
$plugin->dependencies = ['mod_mavr' => 2026100800];
