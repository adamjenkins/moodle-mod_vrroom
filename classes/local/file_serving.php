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

namespace mod_vrroom\local;

/**
 * The decisions behind vrroom_pluginfile(): which file areas are served and which files open inline.
 *
 * Only raster images are shown inline. Everything else (GLB models, audio, video, and above all SVG
 * or HTML, which a browser would run script from) is sent as an attachment.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_serving {
    /** @var string[] The activity file areas vrroom_pluginfile() serves. */
    public const FILEAREAS = ['models', 'media', 'environment', 'assets'];

    /** @var string[] The MIME types that may be shown inline; all others are forced to download. */
    public const INLINE_MIMETYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Whether a file area is one vrroom_pluginfile() serves.
     *
     * @param string $filearea The requested file area.
     * @return bool
     */
    public static function is_served_filearea(string $filearea): bool {
        return in_array($filearea, self::FILEAREAS, true);
    }

    /**
     * Whether a file must be sent as a download rather than shown inline.
     *
     * @param string $mimetype The stored file's MIME type.
     * @param bool $forcedownload Whether the request already asked for a download.
     * @return bool
     */
    public static function force_download(string $mimetype, bool $forcedownload): bool {
        return !in_array($mimetype, self::INLINE_MIMETYPES, true) || $forcedownload;
    }
}
