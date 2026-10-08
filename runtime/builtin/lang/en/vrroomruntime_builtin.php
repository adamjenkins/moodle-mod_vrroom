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
 * English strings for the VR room built-in viewer run mode.
 *
 * @package    vrroomruntime_builtin
 * @category   string
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allrequireddone'] = 'You have completed everything that is required. You can finish your attempt now or keep exploring.';
$string['controlshelp'] = 'Move with W, A, S and D or the arrow keys, and look around by dragging. Click the floor to walk there and press Enter to open what is in the middle of the screen. On a touch screen, use the stick to move and drag to look around.';
$string['enter3d'] = 'Enter the 3D room';
$string['enterar'] = 'Enter AR';
$string['enterlist'] = 'Open the list view';
$string['entermr'] = 'Enter mixed reality';
$string['entervr'] = 'Enter VR';
$string['errorinvalidstunurl'] = 'Line {$a} is not a valid STUN URL. It must look like stun:host or stun:host:port.';
$string['errorinvalidturnurl'] = 'Line {$a} is not a valid TURN URL. It must look like turn:host:port or turns:host:port, optionally followed by ?transport=udp or ?transport=tcp.';
$string['errorlivebusy'] = 'The live room is busy. Try again in a moment.';
$string['errorlivedisabled'] = 'Live mode is not available for this activity.';
$string['errorlivenoroom'] = 'You cannot join this live room.';
$string['errorlivenotallowed'] = 'You can join the live room only while you have an attempt in progress.';
$string['errorliveroomfull'] = 'The live room is full ({$a} people).';
$string['errortoomanyiceurls'] = 'Enter at most 10 URLs.';
$string['exitfullscreen'] = 'Exit fullscreen';
$string['exitxr'] = 'Exit';
$string['finishattempt'] = 'Finish attempt';
$string['finishconfirm'] = 'Finish this attempt? You will not be able to change it afterwards.';
$string['finishconfirmtitle'] = 'Finish attempt';
$string['fullscreen'] = 'Fullscreen';
$string['gethint'] = 'Get a hint';
$string['hintcost'] = 'Each hint takes {$a} points off.';
$string['hintsused'] = 'Hints used: {$a->used} of {$a->total}';
$string['invertlookx'] = 'Reverse left/right drag';
$string['invertlooky'] = 'Reverse up/down drag';
$string['leadsto'] = 'Leads to {$a}';
$string['listviewheading'] = 'Stations';
$string['livealone'] = 'Only you are here so far.';
$string['liveclosed'] = 'The live room has been closed.';
$string['liveconnectfailed'] = 'Could not connect to {$a}.';
$string['liveconnecting'] = 'Connecting…';
$string['liveenabled'] = 'Live mode';
$string['liveenabled_desc'] = 'Let teachers turn on live mode in VR room activities that use the built-in viewer: up to six people share a room, see each other as avatars and can talk. The browsers connect directly to each other, so each participant can see the IP addresses of the others.';
$string['livefull'] = 'The live room is full ({$a} people). Try again later.';
$string['livegroupselect'] = 'Room of group';
$string['liveholdtotalk'] = 'Hold to talk';
$string['livejoin'] = 'Join the live room';
$string['livejoined'] = 'You are in the live room.';
$string['livejoinfailed'] = 'You could not join the live room.';
$string['livejoining'] = 'Joining the live room…';
$string['livekindpreview'] = 'Teacher';
$string['liveleave'] = 'Leave the live room';
$string['liveleft'] = 'You have left the live room.';
$string['livemicdenied'] = 'The microphone could not be used. Check your browser\'s permission for this site.';
$string['livemicinsecure'] = 'Voice needs a secure (HTTPS) connection, so your microphone cannot be used on this site. You can still hear the others and see their avatars.';
$string['livemicoff'] = 'Turn off microphone';
$string['livemicon'] = 'Turn on microphone';
$string['livemicstate_muted'] = 'Muted';
$string['livemicstate_off'] = 'Microphone off';
$string['livemicstate_on'] = 'Microphone on';
$string['livemute'] = 'Mute';
$string['livemutepeer'] = 'Mute {$a} for me';
$string['livenogroup'] = 'You are not in a group of this activity, so there is no live room for you.';
$string['livenotice'] = 'When you join the live room, your browser connects directly to the browsers of the other people in it. They can see your IP address, your name and where you are in the room, and they hear you while your microphone is on. Nothing of this is recorded.';
$string['liveparticipants'] = 'People in the room ({$a})';
$string['livepeerjoined'] = '{$a} joined the live room.';
$string['livepeerleft'] = '{$a} left the live room.';
$string['livepushtotalk'] = 'Push to talk (hold T)';
$string['livereconnecting'] = 'The connection to the live room was lost. Reconnecting…';
$string['livereplaced'] = 'You joined the live room in another window, so you have left it here.';
$string['liveroom'] = 'Live room';
$string['livesettings'] = 'Live mode';
$string['livesettings_desc'] = 'The browsers in a live room find each other through this Moodle site; no other service is contacted unless you add STUN or TURN servers below. Without them, live mode usually works only when everyone is on the same network.';
$string['livestunurls'] = 'STUN servers';
$string['livestunurls_desc'] = 'One URL per line, for example stun:stun.example.org:3478. A STUN server learns the public IP address of every participant. Leave empty to use none.';
$string['liveturnsecret'] = 'TURN shared secret';
$string['liveturnsecret_desc'] = 'The secret shared with the TURN server (the TURN REST API, for example coturn\'s static-auth-secret). Moodle uses it to make short-lived credentials for each participant; the secret itself is never sent to browsers.';
$string['liveturnttl'] = 'TURN credential lifetime';
$string['liveturnttl_desc'] = 'How long the TURN credentials given to a participant stay valid.';
$string['liveturnurls'] = 'TURN servers';
$string['liveturnurls_desc'] = 'One URL per line, for example turn:turn.example.org:3478?transport=udp or turns:turn.example.org:5349. A TURN server relays the voice and positions of participants who cannot connect directly. TURN servers are used only when a shared secret is set below.';
$string['liveunmute'] = 'Unmute';
$string['liveunmutepeer'] = 'Unmute {$a} for me';
$string['livewhere3d'] = 'In the 3D room';
$string['livewherear'] = 'In AR (not shown as an avatar)';
$string['livewherelist'] = 'In the list view';
$string['livewheremr'] = 'In mixed reality';
$string['livewhereroom'] = 'In {$a}';
$string['livewherevr'] = 'In VR';
$string['liveyou'] = '{$a} (you)';
$string['loadfailed'] = 'The room could not be loaded. You can continue in the list view.';
$string['loading'] = 'Loading the room…';
$string['locklooky'] = 'Lock up/down drag';
$string['mediapause'] = 'Pause {$a}';
$string['mediaplay'] = 'Play {$a}';
$string['modelinlist'] = 'This station has a 3D model, which can be seen in the 3D room.';
$string['modelloadfailed'] = 'A model could not be loaded.';
$string['nolaunch'] = 'You cannot open this room.';
$string['nomorehints'] = 'There are no more hints.';
$string['objectivestatus_available'] = 'To do';
$string['objectivestatus_completed'] = 'Done';
$string['objectivestatus_locked'] = 'Locked';
$string['objectivestatuschanged'] = '{$a->title}: {$a->status}';
$string['openstation'] = 'Open station';
$string['otheritems'] = 'Other items';
$string['pickup'] = 'Take {$a}';
$string['pluginname'] = 'Built-in viewer';
$string['portalto'] = 'To {$a}';
$string['preview3d'] = 'Preview the 3D room';
$string['previewlist'] = 'Preview the list view';
$string['previewnotice'] = 'Preview: nothing you do here is recorded.';
$string['privacy:livepath'] = 'Live room';
$string['privacy:metadata:iceservers'] = 'If the site administrator has set up STUN or TURN servers, your browser contacts them to make the connection. They learn your IP address, and a TURN server relays your voice and position.';
$string['privacy:metadata:iceservers:ipaddress'] = 'The IP addresses of your device.';
$string['privacy:metadata:livepeers'] = 'In a live room the browsers of the participants connect directly to each other, so the other participants receive this data.';
$string['privacy:metadata:livepeers:fullname'] = 'Your name, shown above your avatar and in the list of participants.';
$string['privacy:metadata:livepeers:ipaddress'] = 'The IP addresses of your device.';
$string['privacy:metadata:livepeers:pose'] = 'Where you are and where you look in the room, and your hands in VR.';
$string['privacy:metadata:livepeers:voice'] = 'Your voice, while your microphone is on.';
$string['privacy:metadata:peers'] = 'Who is in a live room of a VR room activity right now. The record is removed when the person leaves or stops responding for 20 seconds.';
$string['privacy:metadata:peers:groupid'] = 'The group whose live room it is.';
$string['privacy:metadata:peers:timejoined'] = 'When the user joined the live room.';
$string['privacy:metadata:peers:timeseen'] = 'When the user\'s browser last checked in.';
$string['privacy:metadata:peers:userid'] = 'The user in the live room.';
$string['privacy:metadata:signals'] = 'Connection messages passed between the browsers in a live room. They are deleted when delivered, and after 60 seconds at the latest.';
$string['privacy:metadata:signals:frompeer'] = 'The live room presence that sent the message.';
$string['privacy:metadata:signals:payload'] = 'Technical connection details, including the network (IP) addresses of the sender\'s device.';
$string['privacy:metadata:signals:timecreated'] = 'When the message was sent.';
$string['privacy:metadata:signals:topeer'] = 'The live room presence the message is for.';
$string['progress'] = 'Progress';
$string['questionlocked'] = 'Visit this station first to unlock its question.';
$string['questionpreview'] = 'Questions are not available in preview.';
$string['retrynotice'] = 'That is not right yet. You can try again.';
$string['roomlabel'] = 'Room: {$a}';
$string['scenechanged'] = 'You are now in {$a}.';
$string['scoreprogress'] = 'Points: {$a->earned} of {$a->max}';
$string['statementsfailed'] = 'Some of your progress could not be saved yet. It will be sent again.';
$string['stationlocked'] = 'Visit the previous station first.';
$string['submitanswer'] = 'Submit answer';
$string['switchto3d'] = 'Switch to the 3D room';
$string['switchtolist'] = 'Switch to the list view';
$string['taskpurgelive'] = 'Remove expired live room data';
$string['timeleft'] = 'Time left: {$a}';
$string['timeup'] = 'The time is up. Your attempt is being finished.';
$string['timewarning'] = '{$a} left.';
$string['tryagain'] = 'Try again';
$string['useitem'] = 'Use {$a}';
$string['viewerlabel'] = 'Interactive 3D room';
$string['webglunavailable'] = 'Your browser cannot show 3D content. Use the list view instead.';
$string['xranswered'] = 'Answered';
$string['xrchoosemany'] = 'Choose one or more answers.';
$string['xrchooseone'] = 'Choose one answer.';
$string['xrclose'] = 'Close';
$string['xrcorrect'] = 'Correct';
$string['xrexittoanswer'] = 'This question cannot be shown here. Exit VR to answer it.';
$string['xrfailed'] = 'The VR or AR session could not be started.';
$string['xrincorrect'] = 'Not correct';
$string['xrinsecure'] = 'Virtual and augmented reality need a secure (HTTPS) connection, so they are not available on this site.';
$string['xrloading'] = 'Loading…';
$string['xropenquestion'] = 'Answer the question';
$string['xrpartiallycorrect'] = 'Partly correct';
$string['xrplace'] = 'Point at a table or the floor, then tap to place the room.';
$string['xrprogress'] = '{$a->done} of {$a->total} required tasks done';
$string['xrsubmit'] = 'Submit';
