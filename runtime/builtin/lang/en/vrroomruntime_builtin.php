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
$string['answeredonce'] = 'You have answered this question in this attempt. To answer it again, start a new attempt.';
$string['attemptnotopen'] = 'This room is no longer open for this attempt: the attempt was finished or deleted, or the activity is no longer available to you. Reload the page to continue.';
$string['backtoactivity'] = 'Go to the activity page';
$string['controlshelp'] = 'Walk forwards and backwards with W and S or the up and down arrows, turn with A and D or the left and right arrows, step sideways with Q and E, and look around by dragging. Click the floor to walk there and press Enter to open what is in the middle of the screen. On a touch screen, use the stick to move and drag to look around.';
$string['controlshelppanorama'] = 'This room is a 360° photo seen from its centre. Look around by dragging, turn with A and D or the left and right arrows, and look up and down with W and S or the up and down arrows. Zoom with the mouse wheel or by pinching. Click a marker, or press Enter to open the one in the middle of the screen. On a phone you can also look around by moving the phone.';
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
$string['errorliveroomgone'] = 'This room has closed because everyone left it. Choose another room or start a new one.';
$string['errorliveroomlimit'] = 'No more rooms can be opened: the most allowed at once is {$a}.';
$string['errorliveroomnotfound'] = 'There is no such live room.';
$string['errortoomanyiceurls'] = 'Enter at most 10 URLs.';
$string['exitfullscreen'] = 'Exit fullscreen';
$string['exitxr'] = 'Exit';
$string['finishattempt'] = 'Finish attempt';
$string['finishconfirm'] = 'Finish this attempt? You will not be able to change it afterwards.';
$string['finishconfirmtitle'] = 'Finish attempt';
$string['fullscreen'] = 'Fullscreen';
$string['gethint'] = 'Get a hint';
$string['hidecontrols'] = 'Hide controls';
$string['hintcost'] = 'Each hint takes {$a} points off.';
$string['hintsused'] = 'Hints used: {$a->used} of {$a->total}';
$string['invertlookx'] = 'Reverse left/right drag';
$string['invertlooky'] = 'Reverse up/down drag';
$string['leadsto'] = 'Leads to {$a}';
$string['listviewheading'] = 'Stations';
$string['liveallfull'] = 'All rooms are full. Try again in a moment.';
$string['livealone'] = 'Only you are here so far.';
$string['liveattemptended'] = 'Your attempt has ended, so you can no longer join the live room.';
$string['livecambusy'] = 'The camera is in use by another program or could not be started. Close the other program and try again.';
$string['livecamdenied'] = 'The camera could not be used. Check your browser\'s permission for this site.';
$string['livecamerror'] = 'The camera could not be started.';
$string['livecaminsecure'] = 'Webcams need a secure (HTTPS) connection, so your camera cannot be used on this site.';
$string['livecamnone'] = 'No camera was found on this device.';
$string['livecamoff'] = 'Camera off';
$string['livecamon'] = 'Camera on';
$string['livecamstate_on'] = 'Camera on';
$string['liveclosed'] = 'The live room has been closed.';
$string['liveconnectfailed'] = 'Could not connect to {$a}.';
$string['liveconnectfailednoservers'] = 'Could not connect to {$a}. This site has no STUN or TURN server for live rooms, so only people on the same network can connect. Ask your administrator to set one up.';
$string['liveconnecting'] = 'Connecting…';
$string['livecurrentroom'] = 'You are in {$a}.';
$string['liveenabled'] = 'Live mode';
$string['liveenabled_desc'] = 'Let teachers turn on live mode in VR room activities that use the built-in viewer: several live rooms of up to six people each, where people see each other as avatars and can talk. The browsers connect directly to each other, so each participant can see the IP addresses of the others.';
$string['livefull'] = 'The live room is full ({$a} people). Try again later.';
$string['livegroupselect'] = 'Room of group';
$string['livehidevideopeer'] = 'Hide video of {$a} for me';
$string['liveholdtotalk'] = 'Hold to talk';
$string['liveicewarningnone'] = 'No STUN or TURN server is set, so people in a live room can connect only when they are on the same network (people on different networks see each other in the list but cannot connect). Set at least one STUN server; add a TURN server for networks with strict firewalls.';
$string['liveicewarningnosecret'] = 'TURN servers are set but there is no TURN shared secret, so they are not used, and no STUN server is set: people in a live room can connect only when they are on the same network. Set the shared secret, or a STUN server.';
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
$string['livenorooms'] = 'No room is open yet. Start a new room.';
$string['livenotice'] = 'When you join the live room, your browser connects directly to the browsers of the other people in it. They can see your IP address, your name and where you are in the room, and they hear you while your microphone is on. Nothing of this is recorded.';
$string['livenoticevideo'] = 'When you join the live room, your browser connects directly to the browsers of the other people in it. They can see your IP address, your name and where you are in the room, they hear you while your microphone is on and see you while your camera is on. Nothing of this is recorded.';
$string['liveoff'] = 'Live mode is off for this activity.';
$string['liveparticipants'] = 'People in the room ({$a})';
$string['livepeerjoined'] = '{$a} joined the live room.';
$string['livepeerleft'] = '{$a} left the live room.';
$string['livepushtotalk'] = 'Push to talk (hold T)';
$string['livereconnecting'] = 'The connection to the live room was lost. Reconnecting…';
$string['livereplaced'] = 'You joined the live room in another window, so you have left it here.';
$string['liveroom'] = 'Live room';
$string['liveroomfull'] = '(full)';
$string['liveroomjoin'] = 'Join';
$string['liveroomname'] = 'Room {$a}';
$string['liveroomnamegroup'] = '{$a->group}: Room {$a->number}';
$string['liveroomoccupancy'] = '{$a->count} of {$a->capacity}';
$string['liveroomremoved'] = 'Your room was removed when the teacher changed the number of rooms. Choose another room.';
$string['liverooms'] = 'Rooms';
$string['liveroomslistfailed'] = 'The list of rooms could not be loaded. It will be tried again.';
$string['liveselfview'] = 'Your camera';
$string['livesettings'] = 'Live mode';
$string['livesettings_desc'] = 'The browsers in a live room find each other through this Moodle site; no other service is contacted unless you add STUN or TURN servers below. Without them, live mode usually works only when everyone is on the same network.';
$string['liveshowvideopeer'] = 'Show video of {$a} for me';
$string['livestartlimit'] = 'No more rooms can be opened (the most allowed at once: {$a}). Join a room with space, or wait until a room closes.';
$string['livestartroom'] = 'Start a new room';
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
$string['livevideo'] = 'Allow webcams in live rooms';
$string['livevideo_desc'] = 'Let people in live rooms turn on their webcam, so the others see their camera picture on the face of their avatar. Each person decides with the Camera button, off at first, and teachers can turn webcams off for an activity. The video goes directly from browser to browser, like the voice; it is not sent to or stored on this site.';
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
$string['motionlook'] = 'Look around by moving your phone';
$string['nolaunch'] = 'You cannot open this room.';
$string['nomorehints'] = 'There are no more hints.';
$string['objectivestatus_available'] = 'To do';
$string['objectivestatus_completed'] = 'Done';
$string['objectivestatus_locked'] = 'Locked';
$string['objectivestatuschanged'] = '{$a->title}: {$a->status}';
$string['openstation'] = 'Open station';
$string['otheritems'] = 'Other items';
$string['panoramaloadfailed'] = 'The 360° photo of this room could not be loaded.';
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
$string['privacy:metadata:livepeers:appearance'] = 'How your avatar looks, if you designed one, and your profile picture if you chose to show it.';
$string['privacy:metadata:livepeers:fullname'] = 'Your name, shown above your avatar and in the list of participants.';
$string['privacy:metadata:livepeers:ipaddress'] = 'The IP addresses of your device.';
$string['privacy:metadata:livepeers:pose'] = 'Where you are and where you look in the room, and your hands in VR.';
$string['privacy:metadata:livepeers:video'] = 'Your camera picture, while your camera is on.';
$string['privacy:metadata:livepeers:voice'] = 'Your voice, while your microphone is on.';
$string['privacy:metadata:peers'] = 'Who is in a live room of a VR room activity right now. The record is removed when the person leaves or stops responding for 20 seconds.';
$string['privacy:metadata:peers:attemptid'] = 'The attempt the user is making in the live room; none when a teacher previews the room.';
$string['privacy:metadata:peers:groupid'] = 'The group whose live room it is.';
$string['privacy:metadata:peers:kind'] = 'Whether the user is in the live room making an attempt or previewing the room.';
$string['privacy:metadata:peers:lastsignal'] = 'When the user\'s browser last sent or received a connection message.';
$string['privacy:metadata:peers:peerid'] = 'The random id of the user\'s presence in the live room, shown to the other participants.';
$string['privacy:metadata:peers:pollcount'] = 'How often the user\'s browser checked in during the current rate limit period (to limit the load one user can cause).';
$string['privacy:metadata:peers:ratewindow'] = 'When the current rate limit period of the user\'s presence started.';
$string['privacy:metadata:peers:room'] = 'The number of the live room the user is in.';
$string['privacy:metadata:peers:signalcount'] = 'How many connection messages the user\'s browser sent during the current rate limit period.';
$string['privacy:metadata:peers:timejoined'] = 'When the user joined the live room.';
$string['privacy:metadata:peers:timeseen'] = 'When the user\'s browser last checked in.';
$string['privacy:metadata:peers:userid'] = 'The user in the live room.';
$string['privacy:metadata:signals'] = 'Connection messages passed between the browsers in a live room. They are deleted when delivered, and after 60 seconds at the latest.';
$string['privacy:metadata:signals:frompeer'] = 'The live room presence that sent the message.';
$string['privacy:metadata:signals:payload'] = 'Technical connection details, including the network (IP) addresses of the sender\'s device.';
$string['privacy:metadata:signals:timecreated'] = 'When the message was sent.';
$string['privacy:metadata:signals:topeer'] = 'The live room presence the message is for.';
$string['privacy:metadata:signals:type'] = 'The kind of connection message.';
$string['progress'] = 'Progress';
$string['questionlocked'] = 'Visit this station first to unlock its question.';
$string['questionpreview'] = 'Questions are not available in preview.';
$string['retrynotice'] = 'That is not right yet. You can try again.';
$string['roomlabel'] = 'Room: {$a}';
$string['scenechanged'] = 'You are now in {$a}.';
$string['scoreprogress'] = 'Points: {$a->earned} of {$a->max}';
$string['showcontrols'] = 'Show controls';
$string['standalonenoscenes'] = 'There is nothing to show yet.';
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
$string['xrplaypause'] = 'Play / pause';
$string['xrprogress'] = '{$a->done} of {$a->total} required tasks done';
$string['xrsubmit'] = 'Submit';
