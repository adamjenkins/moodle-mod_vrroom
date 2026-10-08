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

namespace vrroomruntime_builtin\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

use vrroomruntime_builtin\local\live\config;

/**
 * A text area of STUN or TURN URLs, one per line, checked against the allowed schemes.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_iceurls extends \admin_setting_configtextarea {
    /** @var string[] URL schemes allowed in this setting. */
    protected array $schemes;

    /**
     * Constructor.
     *
     * @param string $name Setting name, 'vrroomruntime_builtin/<name>'.
     * @param \lang_string $visiblename Label.
     * @param \lang_string $description Description.
     * @param string[] $schemes Allowed schemes, ['stun', 'stuns'] or ['turn', 'turns'].
     */
    public function __construct(string $name, \lang_string $visiblename, \lang_string $description, array $schemes) {
        $this->schemes = array_values($schemes);
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW, '60', '4');
    }

    /**
     * Check every non-empty line.
     *
     * @param string $data The submitted text.
     * @return bool|string true, or an error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }
        return config::validate_urls((string)$data, $this->schemes);
    }
}
