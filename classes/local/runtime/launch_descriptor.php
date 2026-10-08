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
 * How a run mode launches: inline on the page with a config, or at an external URL.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class launch_descriptor {
    /** @var string Render the viewer on the activity page with a launch config. */
    public const TYPE_INLINE = 'inline';
    /** @var string Embed or redirect to a URL carrying a launch token. */
    public const TYPE_EXTERNAL = 'external';

    /**
     * Constructor.
     *
     * @param string $type One of the TYPE_ constants.
     * @param array $config Inline launch config (vrroom-launch/1).
     * @param \moodle_url|null $url External launch URL.
     */
    public function __construct(
        /** @var string One of the TYPE_ constants. */
        public readonly string $type,
        /** @var array Inline launch config (vrroom-launch/1). */
        public readonly array $config = [],
        /** @var \moodle_url|null External launch URL. */
        public readonly ?\moodle_url $url = null,
    ) {
        if ($type !== self::TYPE_INLINE && $type !== self::TYPE_EXTERNAL) {
            throw new \coding_exception('Unknown launch type: ' . $type);
        }
        if ($type === self::TYPE_EXTERNAL && $url === null) {
            throw new \coding_exception('An external launch needs a URL.');
        }
    }

    /**
     * Plain array for JSON encoding.
     *
     * @return array ['type' => string, 'config' => array, 'url' => string|null]
     */
    public function to_array(): array {
        return [
            'type' => $this->type,
            'config' => $this->config,
            'url' => $this->url ? $this->url->out(false) : null,
        ];
    }
}
