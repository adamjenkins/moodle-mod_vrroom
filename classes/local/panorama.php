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
 * 360° photos (equirectangular panoramas): size limits, checks, smaller copies and placement maths.
 *
 * A panorama is a 2:1 photo. Beside the original, in the same component, area and itemid, the
 * smaller copies the viewer prefers live at `<original filepath>variants/` as `desktop.<ext>` and
 * `mobile.<ext>` (jpg for jpeg and png photos, webp for webp ones). They are found by that
 * convention, never stored in the scene document.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class panorama {
    /** @var string[] Mimetypes a panorama may have. */
    public const IMAGE_MIMETYPES = ['image/jpeg', 'image/png', 'image/webp'];
    /** @var string[] File extensions a panorama may have. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** @var string Folder name of the smaller copies, under the original's filepath. */
    public const VARIANTS_DIR = 'variants';
    /** @var string[] Names of the smaller copies, largest first. */
    public const VARIANT_NAMES = ['desktop', 'mobile'];
    /** @var int Narrowest photo accepted, in pixels. */
    public const MIN_WIDTH = 1024;
    /** @var float Allowed deviation of the height from width/2, as a fraction of width/2. */
    public const RATIO_TOLERANCE = 0.02;
    /** @var int Default largest original width, in pixels. */
    public const DEFAULT_MAX_WIDTH = 16384;
    /** @var int Default width of the desktop copy, in pixels. */
    public const DEFAULT_DESKTOP_WIDTH = 8192;
    /** @var int Default width of the mobile copy, in pixels. */
    public const DEFAULT_MOBILE_WIDTH = 4096;
    /** @var int Default largest photo file, in MB. */
    public const DEFAULT_MAX_SIZE_MB = 50;
    /** @var int JPEG quality of the copies. */
    public const JPEG_QUALITY = 85;
    /** @var int WebP quality of the copies. */
    public const WEBP_QUALITY = 80;
    /** @var float Eye height the placement directions start from, in metres. */
    public const EYE = 1.6;
    /** @var float Default placement distance, in metres. */
    public const DEFAULT_DISTANCE = 4.0;

    /**
     * Largest original width accepted, in pixels (setting mod_vrroom/panomaxwidth).
     *
     * @return int
     */
    public static function max_width(): int {
        return self::int_setting('panomaxwidth', self::DEFAULT_MAX_WIDTH);
    }

    /**
     * Width of the desktop copy, in pixels (setting mod_vrroom/panodesktopwidth).
     *
     * @return int
     */
    public static function desktop_width(): int {
        return self::int_setting('panodesktopwidth', self::DEFAULT_DESKTOP_WIDTH);
    }

    /**
     * Width of the mobile copy, in pixels (setting mod_vrroom/panomobilewidth).
     *
     * @return int
     */
    public static function mobile_width(): int {
        return self::int_setting('panomobilewidth', self::DEFAULT_MOBILE_WIDTH);
    }

    /**
     * Largest photo file accepted, in bytes (setting mod_vrroom/panomaxsize, in MB).
     *
     * @return int
     */
    public static function max_bytes(): int {
        return self::int_setting('panomaxsize', self::DEFAULT_MAX_SIZE_MB) * 1024 * 1024;
    }

    /**
     * All the limits, as the editors receive them.
     *
     * @return array ['maxwidth', 'desktopwidth', 'mobilewidth', 'maxbytes']
     */
    public static function limits(): array {
        return [
            'maxwidth' => self::max_width(),
            'desktopwidth' => self::desktop_width(),
            'mobilewidth' => self::mobile_width(),
            'maxbytes' => self::max_bytes(),
        ];
    }

    /**
     * The size of a photo, without core's notice for a file that only looks like an image.
     *
     * stored_file::get_imageinfo() raises a PHP notice on a file whose name says image but whose
     * content is not one (a renamed upload), so the first bytes are checked for a JPEG, PNG or WebP
     * signature first.
     *
     * @param \stored_file $file The file.
     * @return array|false get_imageinfo()'s result, or false when it is not a JPEG, PNG or WebP image.
     */
    public static function image_info(\stored_file $file) {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 12);
        fclose($handle);
        $isjpeg = strncmp($head, "\xFF\xD8\xFF", 3) === 0;
        $ispng = strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0;
        $iswebp = strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP';
        if (!$isjpeg && !$ispng && !$iswebp) {
            return false;
        }
        return $file->get_imageinfo();
    }

    /**
     * Check that a stored file can be a 360° photo.
     *
     * @param \stored_file $file
     * @return string|null a mod_vrroom lang string identifier saying what is wrong, or null when it is fine
     */
    public static function check(\stored_file $file): ?string {
        if (!in_array((string)$file->get_mimetype(), self::IMAGE_MIMETYPES, true)) {
            return 'panoramabadtype';
        }
        $info = self::image_info($file);
        if (!is_array($info) || !in_array($info['mimetype'] ?? '', self::IMAGE_MIMETYPES, true)) {
            return 'panoramabadtype';
        }
        return self::check_dimensions((int)$info['width'], (int)$info['height'], (int)$file->get_filesize());
    }

    /**
     * The teacher-facing message for a check() result, with the site's limits added.
     *
     * Each of check()'s string identifiers also reads well on its own (get_string($key, 'mod_vrroom')).
     *
     * @param string $key a string identifier returned by check()
     * @return string
     */
    public static function message(string $key): string {
        $a = (object)[
            'minwidth' => self::MIN_WIDTH,
            'maxwidth' => self::max_width(),
            'maxsize' => display_size(self::max_bytes()),
        ];
        return get_string($key, 'mod_vrroom') . ' ' . get_string('panoramalimits', 'mod_vrroom', $a);
    }

    /**
     * Check the dimensions and size of a photo (for callers that measured it themselves).
     *
     * @param int $width pixels
     * @param int $height pixels
     * @param int $bytes file size
     * @return string|null a mod_vrroom lang string identifier, or null when fine
     */
    public static function check_dimensions(int $width, int $height, int $bytes): ?string {
        if (!self::is_ratio($width, $height)) {
            return 'panoramabadratio';
        }
        if ($width < self::MIN_WIDTH) {
            return 'panoramatoosmall';
        }
        if ($width > self::max_width()) {
            return 'panoramatoolarge';
        }
        if ($bytes > self::max_bytes()) {
            return 'panoramatoobig';
        }
        return null;
    }

    /**
     * Whether width and height make a 2:1 photo (height = width/2 within RATIO_TOLERANCE).
     *
     * @param int $width
     * @param int $height
     * @return bool
     */
    public static function is_ratio(int $width, int $height): bool {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $half = $width / 2;
        return abs($height - $half) <= $half * self::RATIO_TOLERANCE;
    }

    /**
     * Whether a stored image file has the 2:1 shape of a 360° photo (no other checks).
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function has_ratio(\stored_file $file): bool {
        if (!in_array((string)$file->get_mimetype(), self::IMAGE_MIMETYPES, true)) {
            return false;
        }
        $info = self::image_info($file);
        return is_array($info) && self::is_ratio((int)($info['width'] ?? 0), (int)($info['height'] ?? 0));
    }

    /**
     * Whether a file is one of the smaller copies (it sits in a variants/ folder).
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_variant(\stored_file $file): bool {
        return self::is_variant_path((string)$file->get_filepath());
    }

    /**
     * Whether a filepath is a folder of smaller copies.
     *
     * @param string $filepath
     * @return bool
     */
    public static function is_variant_path(string $filepath): bool {
        return str_ends_with($filepath, '/' . self::VARIANTS_DIR . '/');
    }

    /**
     * The filepath of a file's smaller copies.
     *
     * @param \stored_file $file the original
     * @return string
     */
    public static function variants_path(\stored_file $file): string {
        return $file->get_filepath() . self::VARIANTS_DIR . '/';
    }

    /**
     * Make the smaller copies of a photo, replacing any old ones.
     *
     * A copy is made only when the original is wider than the copy's width. This never throws: on any
     * failure it returns the copies it managed to make.
     *
     * @param \stored_file $file the original photo
     * @return string[] names of the copies made ('desktop', 'mobile')
     */
    public static function make_variants(\stored_file $file): array {
        $made = [];
        $source = null;
        $image = null;
        try {
            self::delete_variants($file);
            if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
                return $made;
            }
            $mimetype = (string)$file->get_mimetype();
            if (!in_array($mimetype, self::IMAGE_MIMETYPES, true)) {
                return $made;
            }
            $info = self::image_info($file);
            if (!is_array($info)) {
                return $made;
            }
            $width = (int)$info['width'];
            $height = (int)$info['height'];
            $targets = [];
            foreach (['desktop' => self::desktop_width(), 'mobile' => self::mobile_width()] as $name => $target) {
                if ($target > 0 && $width > $target) {
                    $targets[$name] = $target;
                }
            }
            if (!$targets || $height <= 0) {
                return $made;
            }

            \core_php_time_limit::raise(300);
            raise_memory_limit(MEMORY_HUGE);
            if (!self::memory_allows($width, $height)) {
                return $made;
            }
            $source = @imagecreatefromstring($file->get_content());
            if (!$source) {
                return $made;
            }
            $webp = $mimetype === 'image/webp' && function_exists('imagewebp');
            $extension = $webp ? 'webp' : 'jpg';
            $fs = get_file_storage();
            $current = $source;
            $currentwidth = $width;
            // Largest first, each scaled from the previous copy when it is still bigger (cheaper, same result).
            arsort($targets);
            foreach ($targets as $name => $target) {
                $targetheight = max(1, (int)round($target * $height / $width));
                $image = @imagescale($current, $target, $targetheight, IMG_BICUBIC_FIXED);
                if (!$image) {
                    continue;
                }
                ob_start();
                $ok = $webp ? imagewebp($image, null, self::WEBP_QUALITY) : imagejpeg($image, null, self::JPEG_QUALITY);
                $data = ob_get_clean();
                if (!$ok || !is_string($data) || $data === '') {
                    imagedestroy($image);
                    $image = null;
                    continue;
                }
                $fs->create_file_from_string([
                    'contextid' => $file->get_contextid(),
                    'component' => $file->get_component(),
                    'filearea' => $file->get_filearea(),
                    'itemid' => $file->get_itemid(),
                    'filepath' => self::variants_path($file),
                    'filename' => $name . '.' . $extension,
                    'userid' => $file->get_userid(),
                    'license' => $file->get_license(),
                    'author' => $file->get_author(),
                    'source' => null,
                ], $data);
                $made[] = $name;
                if ($current !== $source) {
                    imagedestroy($current);
                }
                $current = $image;
                $currentwidth = $target;
                $image = null;
            }
            if ($current !== $source && $currentwidth !== $width) {
                imagedestroy($current);
            }
        } catch (\Throwable $e) {
            debugging('mod_vrroom: could not make the smaller copies of a 360° photo: ' . $e->getMessage(), DEBUG_DEVELOPER);
        } finally {
            if ($image) {
                imagedestroy($image);
            }
            if ($source) {
                imagedestroy($source);
            }
        }
        return $made;
    }

    /**
     * Find the smaller copies of a photo.
     *
     * @param \stored_file $file the original photo
     * @return array ['desktop' => ?\stored_file, 'mobile' => ?\stored_file]
     */
    public static function find_variants(\stored_file $file): array {
        $found = array_fill_keys(self::VARIANT_NAMES, null);
        if (self::is_variant($file)) {
            return $found;
        }
        $fs = get_file_storage();
        foreach (self::VARIANT_NAMES as $name) {
            foreach (['jpg', 'webp'] as $extension) {
                $variant = $fs->get_file(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    self::variants_path($file),
                    $name . '.' . $extension
                );
                if ($variant && !$variant->is_directory()) {
                    $found[$name] = $variant;
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Whether a photo has at least one smaller copy.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function has_variants(\stored_file $file): bool {
        return array_filter(self::find_variants($file)) !== [];
    }

    /**
     * Delete the smaller copies of a photo (and their folder).
     *
     * @param \stored_file $file the original photo
     */
    public static function delete_variants(\stored_file $file): void {
        if (self::is_variant($file)) {
            return;
        }
        $prefix = self::variants_path($file);
        $files = get_file_storage()->get_area_files(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            'filepath, filename',
            true
        );
        foreach ($files as $stored) {
            if (str_starts_with((string)$stored->get_filepath(), $prefix)) {
                $stored->delete();
            }
        }
    }

    /**
     * The position of an item placed by direction in a 360° photo room.
     *
     * Yaw follows the viewer: 0 is straight ahead (-z), +90 is to the left (-x). Pitch is up. The
     * directions start at eye height (EYE) above the centre.
     *
     * @param float $yaw degrees
     * @param float $pitch degrees
     * @param float $distance metres
     * @return float[] [x, y, z]
     */
    public static function placement_position(float $yaw, float $pitch, float $distance = self::DEFAULT_DISTANCE): array {
        $y = deg2rad($yaw);
        $p = deg2rad($pitch);
        return [
            -sin($y) * cos($p) * $distance + 0.0,
            self::EYE + sin($p) * $distance + 0.0,
            -cos($y) * cos($p) * $distance + 0.0,
        ];
    }

    /**
     * Whether PHP may hold a decoded photo of this size (twice: the original and its first copy).
     *
     * @param int $width
     * @param int $height
     * @return bool
     */
    private static function memory_allows(int $width, int $height): bool {
        $limit = get_real_size((string)ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }
        // GD true colour: 4 bytes a pixel, plus about 25% for the copy and overhead.
        $needed = (int)($width * $height * 5) + memory_get_usage();
        return $needed < $limit;
    }

    /**
     * A positive integer setting, or its default.
     *
     * @param string $name setting name in mod_vrroom
     * @param int $default
     * @return int
     */
    private static function int_setting(string $name, int $default): int {
        $value = (int)get_config('mod_vrroom', $name);
        return $value > 0 ? $value : $default;
    }
}
