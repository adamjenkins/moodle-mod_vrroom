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
 * Behat data generator for the VR room built-in viewer run mode.
 *
 * @package    vrroomruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for live room presences.
 *
 * Usage:
 *   And the following "vrroomruntime_builtin > presences" exist:
 *     | activity | user     | room |
 *     | vrroom1  | student2 | 1    |
 *
 * A presence made here stays fresh for ten minutes, the length of a scenario, because nobody polls
 * for it.
 *
 * @package    vrroomruntime_builtin
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_vrroomruntime_builtin_generator extends behat_generator_base {
    /** @var int Seconds ahead of now a generated presence is last seen, so it stays fresh. */
    private const FRESH_FOR = 600;

    /**
     * The entities that can be created.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'presences' => [
                'singular' => 'presence',
                'datagenerator' => 'peer',
                'required' => ['activity', 'user'],
                'switchids' => ['user' => 'userid', 'group' => 'groupid'],
            ],
        ];
    }

    /**
     * Turn the activity idnumber into its vrroom id and fill the defaults of a presence.
     *
     * @param array $data Row of the step, with user and group already turned into ids.
     * @return array Values for vrroomruntime_builtin_generator::create_peer().
     */
    protected function preprocess_peer(array $data): array {
        $cmid = $this->get_activity_id((string)$data['activity']);
        $cm = get_coursemodule_from_id('vrroom', $cmid, 0, false, MUST_EXIST);
        unset($data['activity']);
        $data['vrroomid'] = (int)$cm->instance;
        $data['room'] = (int)($data['room'] ?? 1);
        $data['kind'] = (string)($data['kind'] ?? 'preview');
        $data['attemptid'] = (int)($data['attemptid'] ?? 0);
        $data['timeseen'] = (int)($data['timeseen'] ?? time() + self::FRESH_FOR);
        $data['timejoined'] = (int)($data['timejoined'] ?? time());
        return $data;
    }
}
