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
 * English strings for the Gallery walk template.
 *
 * @package    vrroomtemplate_gallery
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addstations'] = 'Add {no} more stations';
$string['chooserhelp'] = 'Students walk from station to station, look at models, images and texts, and answer questions from the question bank.';
$string['environment'] = 'Environment';
$string['environment_help'] = 'The room the stations are placed in: a simple room, a room from an installed room pack, or your own model. Simple and room-pack rooms have fixed places for stations. An uploaded model gets its stations in a circle. The picture under the list shows the room from its starting point.';
$string['environmentfile'] = 'Environment model';
$string['environmentfile_help'] = 'A GLB file of the room. Its floor should be at height zero, measured in metres.';
$string['environmentupload'] = 'Upload my own model (GLB)';
$string['environmentuploadgroup'] = 'Your own model';
$string['errorenvironmentfile'] = 'Upload an environment model, or choose a bundled environment.';
$string['errorimagerequired'] = 'Upload an image, or choose a different exhibit.';
$string['errormodelrequired'] = 'Upload a model, or choose a different exhibit.';
$string['errornostations'] = 'Add at least one station.';
$string['errorpoints'] = 'Points must be zero or more.';
$string['errorpointsrange'] = 'Points must be 0, or a number from 0.0000001 to {$a}.';
$string['errortitlerequired'] = 'Give the station a title.';
$string['errortoomanystations'] = 'The chosen environment has room for {$a} stations.';
$string['galleryheading'] = 'Gallery walk';
$string['mediatype'] = 'Exhibit';
$string['mediatype_image'] = 'Image';
$string['mediatype_model'] = '3D model (GLB)';
$string['mediatype_none'] = 'Text only';
$string['noquestion'] = 'No question';
$string['objectiveordered'] = 'Visit the stations in order';
$string['objectivequestion'] = 'Answer the question at {$a}';
$string['objectivevisit'] = 'Visit {$a}';
$string['ordered'] = 'Stations must be visited in order';
$string['ordered_help'] = 'If ticked, each station opens only after the previous one has been visited.';
$string['pluginname'] = 'Gallery walk';
$string['previewplaces'] = 'Places for {$a} stations';
$string['privacy:metadata'] = 'The Gallery walk template stores no personal data.';
$string['station'] = 'Station {no}';
$string['stationimage'] = 'Image file';
$string['stationmodel'] = 'Model file';
$string['stationpoints'] = 'Points';
$string['stationpoints_help'] = 'Points for answering this station\'s question, or for visiting the station if it has no question.';
$string['stationquestion'] = 'Question';
$string['stationquestion_help'] = 'An optional question from the course question banks. It opens once the student has visited the station.';
$string['stationrequired'] = 'Required';
$string['stationtext'] = 'Text';
$string['stationtitle'] = 'Title';
