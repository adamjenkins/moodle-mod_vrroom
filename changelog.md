# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Escape chain: the story panel follows the start point's yaw (unchanged for the built-in rooms).
- The plain-hall fallback of the viewer is centred on the stored bounds.
- Keyboard: A/D and ←/→ turn; Q/E step sideways (previously the reverse).
- Renamed the plugin from `mod_mavr` to `mod_vrroom` ("VR room"); sub-plugin types
  `vrroomruntime` and `vrroomtemplate`. Never released under the old name.
- `$plugin->supported` widened to `[502, 503]`.

### Added

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

### Changed

- The VR room site settings are a category with *General settings* and one page per run mode.

## [0.1.0] - 2026-10-08

ALPHA. First release, Moodle 5.2 only.

### Added

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
