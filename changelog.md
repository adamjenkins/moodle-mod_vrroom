# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

ALPHA. First release, Moodle 5.2 and 5.3. Not yet tagged.

### Added

- 360° photo rooms (server side): a new environment type `panorama` (`{type:'panorama', file,
  northoffset?, sky?}`; no bounds, lights or shape) and an optional `placement {yaw, pitch, distance?}`
  on every item of a photo room, from which the item's position is derived
  (`\mod_vrroom\local\panorama::placement_position()`). Uploads of kind `panorama` (jpg, png, webp) may
  be any shape (2:1 is best; a wider photo is shown as a band round the horizon, a narrower one
  stretched) and must be within new site settings `panomaxwidth` (16384 px), `panomaxsize` (50 MB); smaller copies
  are made beside the photo at `variants/desktop.*` and `variants/mobile.*` (`panodesktopwidth` 8192 px,
  `panomobilewidth` 4096 px) and sent to the viewer as `environment.variants`. Hotspot media may be
  audio and video as well as images (`content.media[].kind` in the launch config). The room editor's
  save checks every photo, its file list marks 360° photos and hides the copies.
- 360° photo rooms in the built-in viewer: the photo is drawn on a sphere around the viewer (the
  phone or desktop copy chosen by screen and texture limit), with look-around by dragging, keys, mouse
  wheel or pinch zoom, an optional "look by moving your phone" toggle, no walking or teleport, round
  arrow markers to other photos, and live-room avatars on a ring around the centre. Points placed by
  direction (`vrroomruntime_builtin/placement`). Hotspot panels play audio and video. New entry
  `vrroomruntime_builtin/standalone` shows rooms on another plugin's page.
- Room editor photo mode: "New photo room", 360° photos in the surroundings list, look-around in the
  photo, click the photo to move the selected point there, direction/height/distance fields. The editor
  takes an injectable backend (services, upload form, features), so other plugins can reuse it.
  `mod_vrroom/panorama_picker`: a flat-photo direction picker for forms.
- Virtual tour template (`vrroomtemplate_tour`): upload up to 20 360° photos, joined by arrows, and
  stops placed by direction (picked on the photo), with text, picture, audio or video; visit all or in
  order, with points, completion and a grade.
- `\mod_vrroom\local\launch\scene_formatter` (scene formatting moved out of `config_builder`, unchanged
  for VR room) with `standalone_config()`, and public `editor_manager::strip_runtime_keys()` and
  `scene_set_problem()`, so other plugins (the virtual tour assignment submission) can store, check and
  show rooms. `$plugin->version` 2026101100 (new settings, API addition).
- Webcams in built-in live rooms: a Camera button next to the microphone; a person's video shows on
  the face screen of their avatar and fades in and out without reconnecting (one video transceiver per
  connection, `replaceTrack`, about 320×240 at 15 fps). Per-person "Hide video for me". Site setting
  `vrroomruntime_builtin/livevideo` and activity setting `vrroom.livevideo` (both default on;
  `$plugin->version` 2026101000, schema change with upgrade step; built-in run mode 2026101000).
  While the video shows, the avatar's head grows to twice its size, upwards from the neck
  (`avatarmodel.videoHeadScale()`), so the face can be seen across the room.
- Hook `\mod_vrroom\hook\avatar_appearance`, dispatched when the built-in run mode lists the people in
  a live room: an optional plugin (such as local_vravatar) can describe how each avatar looks
  (`vrroom-avatar/1`) and give a profile picture. Without a listener every avatar looks as before.
- The avatar builder (`vrroomruntime_builtin/live/avatarmodel`) draws designed avatars (body, head,
  colours, hair, glasses, hats, a face screen with face, profile picture or webcam): 14 hairstyles and
  10 hair colours, round/square/oval/heart heads, skin tones, eyes, eyebrows, nose, ears and beards
  (all optional keys of `vrroom-avatar/1` whose defaults keep the earlier look); hats adapt to tall hair.
- Costumes: the avatar_appearance hook's `set_appearance()` takes an optional map of asset URLs
  (`outfit`, `hat`; http(s) only), sent to the browser as the peers' `assets`; the avatar builder wears
  body costumes (the head on the costume's neck, accent-tinted materials, lights) and device costumes
  (a PC or TV whose screen shows the face, profile picture or webcam), and model hats. Costume files
  come from an optional plugin such as local_vravatar. `$plugin->version` 2026101001 (API addition).
- Live rooms pass every look field (appearance, picture, assets) through the roster, so costumes
  reach the other people in the room; black and white added to the avatar colours; costume lamps
  are larger with a soft glow.
- A "beam in" effect where an avatar appears in a live room: a soft column of light in the person's
  accent colour and a spreading ring, 1.5 s, no flicker; none with reduced motion
  (`vrroomruntime_builtin/live/spawnflash`).

### Changed

- Template choice moved from per-template activity-chooser entries into the settings form
  (Template select reloading the form; `instance_manager::template_lock_reason()` locks it once
  attempts exist or the room is customised, checked again on save). Chooser callbacks removed.
- Escape chain: the story panel follows the start point's yaw (unchanged for the built-in rooms).
- The plain-hall fallback of the viewer is centred on the stored bounds.
- Keyboard: A/D and ←/→ turn; Q/E step sideways (previously the reverse).
- Renamed the plugin from `mod_mavr` to `mod_vrroom` ("VR room"); sub-plugin types
  `vrroomruntime` and `vrroomtemplate`. Never released under the old name.
- `$plugin->supported` widened to `[502, 503]`.

### Added

- A preview figure under the environment selector of the Gallery walk, Escape chain and Empty room
  forms (`\mod_vrroom\form\environment_preview`, template `mod_vrroom/environment_preview`, AMD
  module `mod_vrroom/environment_preview`) and in the room editor's environment field. Simple-room
  previews are `pix/environments/<id>.jpg`; pack rooms use their manifest preview.
  `environment_registry::preview_url()`.
- Small rooms in `vrroom-envpack/1`: optional `zonesize` (1.5 to 3 m, default 3) and `small`
  (2 to 20 anchors instead of 4 to 20). Gallery and escape zones use the room's zone size;
  snapshots store both keys. `$plugin->version` 2026100804 (so a room pack can depend on small-room
  support; no schema or web-service change).
- Sub-plugin type `vrroomenv` (directory `environment/`, room packs) with
  `\mod_vrroom\local\scene\environment_registry` (presets and pack rooms in one key space,
  manifest schema `vrroom-envpack/1`), scene environment type `pack`, the file endpoint
  `envfile.php`, credits on the activity page and a missing-pack warning for teachers. Templates
  store an `envsnapshot` of a pack room's layout. `$plugin->version` 2026100802 (registers the new
  sub-plugin type; no schema or web-service change).
- Viewer and room editor: GLTFLoader plugin for `MOZ_lightmap` (`vrroomruntime_builtin/mozlightmap`,
  MPL-2.0, adapted from Hubs).
- Bar under the 3D room: Fullscreen toggle; user preferences `mod_vrroom_invertx`,
  `mod_vrroom_inverty`, `mod_vrroom_locky` (exported by the privacy provider).
- Collision for walking: 0.3 m body radius against the environment, props and 3D models, with
  wall sliding.
- Activity settings `questionmode` (`once` / `retry`) and `retrypenalty`; both task-graph
  evaluators take them as options; fixtures `question_once_mode`, `question_retry_mode`.
- GitHub Actions workflow (Moodle 5.3; PHP 8.3 + MariaDB 11.4, PHP 8.4 + PostgreSQL 17), with
  extra steps for the bundled sub-plugins' PHPUnit suites and the built-in run mode's AMD lint and
  build check, and the JavaScript fixture tests.
- CI checks for development leftovers (TODO, `@testme`, merge conflict markers), for the files every
  plugin and bundled sub-plugin must ship, and for pending or undefined Behat steps; a newer push
  cancels the run still going for the older one.
- Live mode for the built-in run mode (activity setting *Live mode*, 2 to 6 people per room):
  avatars with head and hand poses, positional voice with mute, push to talk and per-person mute,
  and a participant list. Browser-to-browser WebRTC with signalling relayed by Moodle web services
  (`vrroomruntime_builtin_live_join`, `_live_poll`, `_live_send`, `_live_leave`), tables
  `vrroomruntime_builtin_peers` and `vrroomruntime_builtin_signals`, and the scheduled task
  *Remove expired live room data*.
- Site settings of the built-in run mode: live mode on/off, STUN servers, TURN servers, TURN shared
  secret and TURN credential lifetime (all servers empty by default).
- Group and grouping support: one live room per group; reports filtered by group.
- Several live rooms per activity (or group) for the built-in run mode: activity setting *Live
  mode* gains *On, students open rooms* (`vrroom.livemode` 2) next to *On, fixed rooms* (1), and
  the new setting *Number of rooms* (`vrroom.liverooms`, default 1: the number of fixed rooms, 1 to
  20, or the most rooms students may open, 0 for no limit), backed up and restored. New web
  service `vrroomruntime_builtin_live_rooms` (the rooms with their occupancy); `_live_join` takes
  a room number (0 starts a new room) and returns it. Presences record their room
  (`vrroomruntime_builtin_peers.room`). Run mode capability `multirooms`; run modes without it
  (the companion) keep one room and never store mode 2. `$plugin->version` 2026100803 for
  `mod_vrroom` and 2026100801 for `vrroomruntime_builtin` (schema changes, with upgrade steps).

### Fixed

- The progress panel's redraw is pending work (`core/pending`), so tests no longer click a button that
  is about to be replaced.

- Live mode: when a person cannot be connected to and the site has no STUN or TURN server, the
  participant list says why and to ask the administrator (`liveconnectfailednoservers`); the built-in
  run mode's settings page warns while live mode is on without a usable server
  (`\vrroomruntime_builtin\local\live\config::ice_warning()`).

- A failed activity save drops the course's cached module info without writing to the database
  (`instance_manager::drop_course_cache()`), so on PostgreSQL, where a failed query aborts the
  transaction, the rollback still runs.

- Room editor: a room's name is escaped in the Delete room confirmation (it was placed in the dialogue
  as HTML).
- Restore: a question link to a question bank entry that is not in the backup is kept only for a
  backup of this site whose question the restoring user may use (`bank::validate_entry()`), else
  dropped from the slot and the template settings; question references are forced to the slot area
  and not repeated; the editor's cross-checks and file checks run on the restored rooms (a room that
  fails is rebuilt from its template); attempt state, score, flag, slot map and times, statement verb,
  object type and result fraction are allowlisted; run mode and template must be plugin names.
- Privacy: the live room's rate-limit counters (`ratewindow`, `pollcount`, `signalcount`) are
  declared and exported.

- `lib.php` loads `questionlib.php`: core's `edit_module_post_actions()` calls
  `question_get_default_category()` for `FEATURE_USES_QUESTIONS` modules, which failed for the Blank
  template and customised rooms (their forms never loaded the question library), and a failed add left
  a course modinfo cache naming a deleted course module (invalidcoursemodule on the course page).
  `instance_manager::add_instance()`/`update_instance()` also clear the course cache when they fail.
- Spawning inside walls: per-attempt resume states (`config_builder::resume_stateid()`,
  `vrroom-resume-<attempt id in the letters a to j>` because core takes state ids as PARAM_ALPHAEXT, validated by the xAPI handler against the user's own open attempt,
  removed on finish and delete), resume ignored when the room layout changed, a free-spot search near
  the start point (`vrroomruntime_builtin/spawn`, `controls.findStart()`), a per-person spread in live
  rooms, and the room editor resets the start point to the new surroundings' own start.
- Question mode and retry penalty locked once attempts exist; the right answer and general feedback
  are hidden on a try that can be repeated.
- Attempt start under a per-user lock (concurrent starts resume one attempt); `errorattemptmissing`
  and `errorattemptbusy`; deleting an already deleted attempt redirects with a notice.
- Gallery and Escape numbers validated as finite and storable (points and hint penalty up to
  99999), the time limit capped, over-long hints refused.
- Room editor: unchanged saves no longer mark the room customised; saves under a per-activity lock;
  start points and zone centres must lie inside the room; saves that would strand open attempts are
  refused; uploaded and referenced models must be real glTF 2.0 binaries; teacher-facing messages.
- Live mode: controls inside fullscreen, live-off and finished-attempt states, focus kept when muting,
  counts that leave out the user's own presence.
- Shared materials no longer stay double-sided after a collision check; an ordered gallery no longer
  credits a locked station when teleporting; panels fit phone screens.
- `$plugin->version` 2026100900 (no schema change; new strings, scripts and the scheduled task's
  extra pass).

### Added

- The scheduled task finishes attempts that have every required objective done and no activity for
  24 hours (`attempt_manager::close_if_abandoned()`).

- Restore validates scenes (`scene_validator::validate_set()`), graph versions
  (`graph_validator::validate()`) and settings like the form; invalid or duplicate scenes, graphs,
  slots and attempts are skipped with a restore-log warning, non-UUID statement ids are replaced,
  and a room left invalid is rebuilt from its template or stored as a plain room.
- xAPI handler: statements and states are refused unless the activity is visible to the user and
  the user is actively enrolled (or viewing) the course; the per-attempt statement limit is
  re-counted under a per-attempt lock before each insert.
- Privacy: `vrroom_attempts.requireddone`, `vrroom_events.sceneid` and `statementid` declared (and
  `requireddone` exported); `vrroomruntime_builtin` declares `peerid`, `kind`, `attemptid`,
  `lastsignal` and `type`, and exports received live messages without their payload.
- File serving decisions moved to `\mod_vrroom\local\file_serving` and
  `\mod_vrroom\local\questions\files::get_access()`, with tests (no behaviour change).

### Changed

- The VR room site settings are a category with *General settings* and one page per run mode.

### Added (Phase 1)

- Activity module `mod_vrroom` with two sub-plugin types: run modes (`vrroomruntime`) and templates
  (`vrroomtemplate`).
- Scene documents (`vrroom-scene/1`), task graphs (`vrroom-graph/1`) with objectives (visit a zone,
  interact, answer a question, collect, sequence) and rules, and graph versions so old attempts
  replay under the graph they were played with.
- Built-in run mode `vrroomruntime_builtin`: a three.js viewer bundled in the plugin (desktop and
  touch controls, procedural environments, GLB models and images) and an accessible list view.
- Gallery walk template `vrroomtemplate_gallery`: environment choice (*Gallery hall*, *Courtyard* or
  an uploaded GLB), repeated stations with text, model or image, optional question, required flag
  and points, and an optional visiting order.
- xAPI statements (`vrroom-vocab/1`) through core `core_xapi_statement_post`, validated by the
  activity's xAPI handler (owner, activity, open attempt, locked objectives, batch and attempt
  limits) and stored once per statement id.
- Server-side task-graph evaluator that computes objectives, score and the finished state from the
  stored statements; a JavaScript evaluator with the same behaviour for instant feedback, both
  tested against shared fixtures.
- Question-bank questions in the room, rendered and graded by the question engine.
- Attempts with a maximum number and a grading method (highest, average, last); gradebook
  integration; completion rule *Complete all required objectives*.
- Teacher reports (attempts, objectives, statement timeline) with attempt deletion.
- Privacy provider, backup and restore (including question references and usages), course reset
  and uninstall clean-up.
- Site settings: maximum model file size, statements per request, statements per attempt, launch
  token lifetime.
- Registers the `.glb` file type on installation when the site does not have it.
