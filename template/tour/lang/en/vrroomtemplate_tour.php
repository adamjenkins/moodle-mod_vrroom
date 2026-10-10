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
 * English strings for the Virtual tour template.
 *
 * @package    vrroomtemplate_tour
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addphotos'] = 'Add {no} more photo';
$string['addstops'] = 'Add {no} more stops';
$string['chooserhelp'] = 'Upload one or more 360° photos of a place. Students look around each photo, follow arrows from photo to photo, and open the points of interest you mark on them: text, pictures, audio or video.';
$string['errormediarequired'] = 'Upload a file, or choose "Text only".';
$string['errormediatype'] = 'This file does not match the chosen media. Accepted file types: {$a}.';
$string['errornophotos'] = 'Add at least one 360° photo.';
$string['errornorthoffset'] = 'The north offset must be a number from -180 to 180.';
$string['errorphotofile'] = 'Upload a 360° photo for this row, or clear its title.';
$string['errorphototitle'] = 'Give the photo a title.';
$string['errorpitch'] = 'The up/down angle must be a number from -85 to 85.';
$string['errorpoints'] = 'Points must be a whole number from 0 to {$a}.';
$string['errorstopphoto'] = 'Choose a photo that has a title and a file.';
$string['errorstoptitle'] = 'Give the stop a title.';
$string['errortoomanyphotos'] = 'A tour takes at most {$a} photos.';
$string['erroryaw'] = 'The left/right angle must be a number from -180 to 180.';
$string['mediatype'] = 'Media';
$string['mediatype_audio'] = 'Audio';
$string['mediatype_image'] = 'Picture';
$string['mediatype_none'] = 'Text only';
$string['mediatype_video'] = 'Video';
$string['northoffset'] = 'North offset (degrees)';
$string['northoffset_help'] = 'Turns the photo around you, from -180 to 180 degrees, so that the view students first see is the one you want. The arrow to the next photo always starts straight ahead.';
$string['objectiveordered'] = 'Visit the stops in order';
$string['objectivevisit'] = 'Visit {$a}';
$string['ordered'] = 'Visit in order';
$string['ordered_help'] = 'If ticked, each stop opens only after the previous one (in the order of this form) has been visited.';
$string['photo'] = 'Photo {no}';
$string['photochoice'] = 'Photo {$a->no}: {$a->title}';
$string['photofile'] = '360° photo';
$string['photofile_help'] = 'One 360° photo (JPG, PNG or WebP). For best results, use an equirectangular photo twice as wide as it is high, such as a phone camera\'s photo sphere; a wider photo is shown as a band around the horizon. Smaller copies for computers and phones are made automatically. The largest size allowed is set by the site administrator in the VR room settings.';
$string['photono'] = 'Photo {$a}';
$string['phototitle'] = 'Title';
$string['pickonphoto'] = 'Pick on the photo';
$string['pitch'] = 'Up/down angle (degrees)';
$string['pitch_help'] = 'How far above (positive) or below (negative) eye level the stop is shown, from -85 to 85 degrees. Use "Pick on the photo" to click the place instead.';
$string['pluginname'] = 'Virtual tour';
$string['privacy:metadata'] = 'The Virtual tour template stores no personal data.';
$string['stop'] = 'Stop {no}';
$string['stopmedia'] = 'Media file';
$string['stopmedia_help'] = 'A picture, an audio clip or a video shown when the stop is opened.';
$string['stopphoto'] = 'On photo';
$string['stoppoints'] = 'Points';
$string['stoppoints_help'] = 'Points for opening this stop.';
$string['stoprequired'] = 'Required';
$string['stoptext'] = 'Text';
$string['stoptitle'] = 'Title';
$string['tourheading'] = 'Virtual tour';
$string['yaw'] = 'Left/right angle (degrees)';
$string['yaw_help'] = 'Which way the stop is from the starting view, from -180 to 180 degrees: 0 is straight ahead, 90 is to the left and -90 to the right. Use "Pick on the photo" to click the place instead.';
