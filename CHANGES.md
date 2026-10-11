# Changes

## 0.1.1 (2026-10-11) — ALPHA

The first release published to camp. The plugin code is the same as 0.1.0.

- Screenshots and captions for the camp listing.
- Development files (tests, CI workflows, the camp listing) are left out of the release ZIP.

## 0.1.0 (2026-10-11) — ALPHA

First release.

- New VR room activity: 3D rooms explored in the browser, with stations, models, images, texts and
  question-bank questions.
- Built-in viewer (`vrroomruntime_builtin`) for desktop and phone browsers, with an accessible list
  view that works with a keyboard and a screen reader.
- Gallery walk template (`vrroomtemplate_gallery`) with two bundled environments or an uploaded GLB
  room.
- Actions recorded as xAPI statements; grades and completion calculated on the server.
- Teacher reports, privacy provider, backup and restore, course reset and uninstall clean-up.
- The template is now chosen in the activity settings (one "VR room" entry in the activity
  chooser). Choosing another template shows its settings straight away, and it can be changed
  until students have attempts or the room has been edited in the room editor.
- The activity settings and the room editor show a picture of the chosen room before saving.
- Room packs may include small rooms (smaller station areas, 2 or more stations).
- 360° photo rooms and virtual tours: upload 360° photos (size limits in the site settings), place
  points of interest on them, and build tours with the new Virtual tour template; the viewer shows them
  on desktop, phones and headsets, and the room editor has a photo mode.
- Room packs: a new sub-plugin type (`vrroomenv`) for packs of ready-made 3D rooms. The activity
  form and the room editor list them by category next to the simple rooms, the activity page shows
  their credits, and an activity whose room pack is missing shows a plain hall (with a warning for
  teachers) instead of failing. Rooms from Hubs keep their baked lighting.
- Escape chain: the story text now stands in front of the start point whichever way the room's
  start point faces.
- Renamed from MAVR (mod_mavr) to VR room (mod_vrroom); the sub-plugin types are now
  vrroomruntime and vrroomtemplate.
- A bar under the 3D room: Fullscreen, and three drag settings remembered per user (reverse
  left/right, reverse up/down, lock up/down so the view stays level).
- Walking stops at walls, furniture and 3D models instead of passing through them.
- A and D and the left and right arrow keys now turn instead of stepping sideways; Q and E step
  sideways.
- New activity settings for questions: one try per question (an answered question now says so),
  or try again until correct, with a chosen share of the points lost per wrong try.
- Supports Moodle 5.3 as well as 5.2; GitHub Actions CI on Moodle 5.3 (PHP 8.3 with MariaDB 11.4,
  PHP 8.4 with PostgreSQL 17), including the bundled sub-plugins' tests and builds.
- Live mode (Phase 3): people working on the same activity at the same time can join a live room
  of up to six, see each other as avatars (3D, VR, AR and MR) and talk. Everyone keeps their own
  attempt and grade. Browsers connect directly to each other (WebRTC); Moodle relays the connection
  messages, and no other service is contacted unless an administrator adds STUN or TURN servers.
  Participants can see each other's IP addresses; the activity page and the privacy metadata say
  so. Administrators can turn live mode off for the site.
- Group mode: the activity now supports groups and groupings. Live rooms are per group, and the
  reports show only the attempts of the teacher's groups in separate groups mode.
- Site settings are now a category: *General settings* and one page per run mode.
- Several live rooms with the built-in viewer: a teacher sets the number of rooms (1 to 20) and
  the people per room (2 to 6), and students choose a room with space from a list that shows how
  full each room is. Or students open rooms themselves: they join a room with space or start a new
  one, up to a maximum the teacher sets (or no limit), and a room closes when the last person
  leaves. In group mode every group has its own rooms. Activities that already had live mode on
  keep one room. The companion run mode keeps one room.
- Restoring a backup now checks the rooms, task graph and settings the way the activity form and
  room editor do; anything invalid is skipped or cleaned, and a broken room is rebuilt from its
  template (or a plain hall) instead of being stored as it is.
- Actions sent from the 3D room are refused once the student can no longer see the activity (it
  is hidden, closed by an access restriction, or the enrolment is suspended), and the limit on
  stored actions per attempt now holds when several requests arrive at once.
- The privacy export now includes the live-room messages addressed to a user (without their
  content) and a few more stored fields, all now described in the privacy metadata.
- Fixed: saving an Empty room activity, or the settings of a room changed in the room editor,
  failed with an error, and a failed new activity could break the whole course page.
- Fixed: rooms could start inside a wall. Changing a room's surroundings in the room editor now
  moves the start point and stations into the new room; a new attempt starts at the start point
  instead of where the last one ended; a saved position is not used after the room changes; the
  viewer finds a free spot near the start point; people joining a live room no longer start on
  top of each other.
- The question mode and penalty can no longer be changed once students have attempts, and a try
  that can be repeated no longer shows the right answer.
- An attempt with everything required done is finished and graded after a day without activity.
- The room editor keeps things inside the room, moves a station's zone and information point
  together, asks before deleting a room, refuses to delete what tasks or open attempts still need,
  refuses files that are not real 3D models, and explains problems in plain words.
- Clearer messages when an attempt was finished elsewhere, deleted or closed; live controls stay
  available in fullscreen; question and story panels fit on phones.
- Restoring a backup checks question links, attempts and statements the way the activity itself
  does, and a room whose tasks no longer fit it is rebuilt from its template.
- Live mode explains when people cannot connect because no STUN or TURN server is set up, and the
  built-in viewer's settings page warns about it.
- Webcams in live rooms: turn your camera on and others see you on the face of your avatar; turn it
  off and your avatar's face comes back, without leaving the room. Teachers and administrators can
  turn webcams off.
- Avatars can be designed by each user with the optional VR avatar plugin (local_vravatar).
- People appearing in a live room "beam in" with a short, soft flash of light.
