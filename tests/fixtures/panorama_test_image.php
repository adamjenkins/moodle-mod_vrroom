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
 * Test photos drawn with GD, so the tests need no binary fixtures.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class panorama_test_image {
    /**
     * An image of a size drawn with GD (a gradient, so the encoders have something to keep).
     *
     * @param int $width
     * @param int $height
     * @param string $type jpg, png or webp
     * @return string encoded image
     */
    public static function make(int $width, int $height, string $type = 'jpg'): string {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 64) {
            $colour = imagecolorallocate($image, intdiv($x * 255, $width) % 256, 120, 200);
            imagefilledrectangle($image, $x, 0, min($width - 1, $x + 63), $height - 1, $colour);
        }
        ob_start();
        if ($type === 'png') {
            imagepng($image);
        } else if ($type === 'webp') {
            imagewebp($image);
        } else {
            imagejpeg($image, null, 70);
        }
        $data = ob_get_clean();
        imagedestroy($image);
        return $data;
    }
}
