# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

ALPHA. First release, Moodle 5.2 and 5.3. Not yet tagged.

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
