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
 * What a template compiles to: scenes, a task graph and question bindings.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class compiled_activity {
    /**
     * Constructor.
     *
     * @param array[] $scenes mavr-scene/1 documents (decoded arrays); the first is the start scene.
     * @param array $graph mavr-graph/1 document (decoded array).
     * @param array[] $slots Each ['slot' => int, 'questionbankentryid' => int, 'maxmark' => float,
     *     'objectiveid' => string].
     */
    public function __construct(
        /** @var array[] mavr-scene/1 documents; the first is the start scene. */
        public readonly array $scenes,
        /** @var array mavr-graph/1 document. */
        public readonly array $graph,
        /** @var array[] Question bindings. */
        public readonly array $slots,
    ) {
    }
}
