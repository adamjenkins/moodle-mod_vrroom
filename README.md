# VR room — virtual-reality rooms for Moodle

VR room is a Moodle activity that puts students in a 3D room they explore in the browser, on a
computer or a phone. Students walk between stations, look at models, images and texts, and answer
questions from the course question bank. Everything they do is recorded as xAPI statements, and
the grade and activity completion are calculated from those statements on the server, so the
activity runs without a teacher present.

Every activity also has a **list view** that shows the same stations, texts and questions as an
ordinary page section. It works with a keyboard and a screen reader, and it is used automatically
when a browser cannot show 3D.

## Requirements

- Moodle 5.2 or 5.3 (`$plugin->supported = [502, 503]`). Continuous integration currently tests Moodle 5.3 only.
- A browser with WebGL for the 3D room. The list view needs nothing special.
- No external services: the 3D viewer (three.js) is bundled in the plugin and makes no calls to
  other sites. Live mode (below) connects the participants' browsers directly to each other; STUN
  and TURN servers are used only if an administrator adds them.
- Voice in live mode needs HTTPS (browsers allow the microphone only on secure pages).

## Installation

1. Copy this repository to `public/mod/vrroom` in your Moodle code (Moodle 5.x keeps plugins under
   `public/`).
2. Visit *Site administration > Notifications* to install it.
3. Optionally review *Site administration > Plugins > Activity modules > VR room*. *General settings*:
   maximum model file size, statements per request, statements per attempt and the launch token
   lifetime. *Built-in viewer*: live mode on or off for the site (on by default), and optional
   STUN and TURN servers (none by default).

The plugin includes two sub-plugins that install with it:

| Sub-plugin | Path | Purpose |
|---|---|---|
| `vrroomruntime_builtin` | `runtime/builtin` | The built-in viewer: 3D room and list view, inside Moodle. |
| `vrroomtemplate_gallery` | `template/gallery` | The *Gallery walk* template. |

On installation the plugin registers the `.glb` file type (`model/gltf-binary`) if the site does
not have it yet, so teachers can upload 3D models. Uninstalling the plugin removes it again.

## Using it

1. In a course, add a **VR room** activity. The activity chooser lists one entry per template:
   **Gallery walk**, **Escape chain** and **Empty room**.
2. Fill in the template's form. For a gallery walk: an environment (*Gallery hall*, *Courtyard*, or
   your own GLB model) and stations with a title, a text, optionally a 3D model or an image, and
   optionally a question from the course question banks. An escape chain has puzzles with key or
   question locks, hints and an optional time limit.
3. Choose a grading method (highest, average or last attempt), the number of attempts, how
   questions are answered (*One try per question*, or *Try again until correct* with a share of the
   points lost per wrong try), and the completion rule *Complete all required objectives* if wanted.
4. Optionally open the **Room editor** tab to move things, add objects, zones and tasks, or build a
   room from scratch.

Students open the activity, enter the 3D room (or the list view), visit the stations and answer the
questions. A question opens once its station has been visited. When they finish the attempt the
grade goes to the gradebook and completion is updated. Teachers see each student's attempts,
objectives and recorded statements under *Reports* and can delete attempts there.

### Controls in the 3D room

- Computer: W and S or the up and down arrows to walk (Shift to go faster), A and D or the left
  and right arrows to turn, Q and E to step sideways, drag to look around, click
  the floor to walk there, click a station's marker to open it, Enter to open what is in the middle
  of the screen.
- Touch screen: the stick at the bottom left to move, drag to look around, tap to walk or open.
- Walls, furniture and 3D models stop you; you slide along them instead of walking through.
- The bar under the room has **Fullscreen** and three drag settings, saved as the user's Moodle
  preferences so they apply in every VR room and on every device: *Reverse left/right drag*,
  *Reverse up/down drag* and *Lock up/down drag* (the view stays level).
- On a VR headset (and, as extras, AR on Android phones and mixed reality on passthrough headsets)
  *Enter VR* / *Enter AR* / *Enter mixed reality* buttons appear. They need HTTPS.

## What is included

No release has been tagged yet (0.1.0, ALPHA). The code includes:

- The activity with scenes, task graph (objectives and rules), attempts, grading and completion.
- The built-in viewer for desktop and phone browsers, with WebXR for VR headsets (and AR and mixed
  reality as extras), and the accessible list view.
- Three templates: Gallery walk, Escape chain and Empty room; a room and task editor.
- Question-bank questions graded by Moodle's question engine, in either question mode.
- Live mode for small groups (below), built in.
- Room packs (below): ready-made 3D rooms that templates and the room editor offer next to the
  simple built-in rooms.
- xAPI statements through Moodle's xAPI web service, validated and stored by the activity.
- Teacher reports, privacy provider, backup and restore, course reset and clean uninstall.

Separate, optional: the **companion** run mode (`vrroomruntime_companion` plus the
`vrroom-companion` container stack) for larger live rooms. Not yet built: Hubs integration,
spatial analytics and Moodle app support.

## Room packs

A room pack is a sub-plugin of type `vrroomenv`, installed at `mod/vrroom/environment/<name>`. It
holds 3D rooms (a GLB model and a preview image each) and an `environments.json` manifest
(schema `vrroom-envpack/1`) giving each room's start point, walkable area, places for stations and
credit line. The bundled pack `vrroomenv_rooms` ("Bundled rooms", 18 rooms) is a separate
repository; VR room works without it.

Teachers pick a room in the activity form (Gallery walk, Escape chain, Empty room) or in the room
editor; rooms are grouped by category. Room files are served to logged-in users by
`envfile.php`. If a room's pack is not installed (for example after a restore onto another site),
students see a plain hall of the same size and teachers see a warning on the activity page. The
credits of the rooms an activity uses are shown on its activity page.

## Live mode

A teacher can turn on **Live mode** in the activity settings (*Live mode* section). Then up to six
people (the teacher chooses 2 to 6) who are working on the activity at the same time can join a
live room: they see each other as simple avatars in the 3D room or in VR, AR and MR, and can talk
to each other. Each person still makes their own attempt and gets their own grade; tasks and
objects are not shared. In group mode each group has its own room, and students join only rooms
of their own groups.

Live mode is part of this plugin and **needs no companion service or other server**. The
companion run mode is only for what live mode does not do well on its own: rooms of up to about 50
people, server-based voice, and keeping participants' IP addresses from each other.

How it works and what it discloses:

- The browsers connect **directly to each other** (WebRTC). Moodle only passes the connection
  messages between them, through web-service polling; no other service is contacted by default.
  Because the connection is direct, **each participant can see the IP addresses of the others**.
  The activity page says so next to the *Join the live room* button, and the privacy metadata
  lists it.
- Without STUN or TURN servers, live mode usually works only when everyone is on the same
  network. An administrator can add STUN and TURN servers (with a TURN REST shared secret, for
  example coturn's `static-auth-secret`) under *Site administration > Plugins > Activity modules
  > VR room > Built-in viewer*, or turn live mode off for the whole site there.
- Nothing is recorded: presence rows go when a person leaves or stops responding for 20 seconds,
  connection messages when delivered or after 60 seconds.
- Voice needs HTTPS. On a plain-HTTP site avatars and the participant list work, but browsers
  refuse the microphone.

Tested so far: two students joining the same room and seeing each other (in two browsers on one
machine), and the server rules in PHPUnit (rooms and groups kept apart, the size limit, rate and
size limits, expiry). Not yet tested: real voice between people, participants on different
networks (where TURN may be needed), and avatars on VR headsets.

## Trust model

The browser only reports what the student did (entered a station, opened an object). It never sends
scores. The server replays the attempt's recorded actions through the activity's task graph to
compute objectives and the grade, rejects actions on objectives that are still locked, and grades
questions only with the question engine. Actions reported by the browser are labelled as such in the
reports.

## Licence

GNU GPL v3 or later; see `LICENSE`. Bundled third-party code is listed in
`runtime/builtin/thirdpartylibs.xml`. Some grading and reporting code was adapted from
[mod_gear](https://moodle.org/plugins/mod_gear) by Boban Blagojevic (GPL v3); the files concerned
carry his copyright line. The viewer's `MOZ_lightmap` loader plugin
(`runtime/builtin/amd/src/mozlightmap.js`, baked lighting of Hubs rooms) is adapted from
[Hubs](https://github.com/Hubs-Foundation/hubs) (`src/components/gltf-model-plus.js`) and is under
the Mozilla Public License 2.0, as its header says.
