# Changes

## Unreleased

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

## 0.1.0 (2026-10-08) — ALPHA

First release (Phase 1).

- New VR room activity: 3D rooms explored in the browser, with stations, models, images, texts and
  question-bank questions.
- Built-in viewer (`vrroomruntime_builtin`) for desktop and phone browsers, with an accessible list
  view that works with a keyboard and a screen reader.
- Gallery walk template (`vrroomtemplate_gallery`) with two bundled environments or an uploaded GLB
  room.
- Actions recorded as xAPI statements; grades and completion calculated on the server.
- Teacher reports, privacy provider, backup and restore, course reset and uninstall clean-up.
