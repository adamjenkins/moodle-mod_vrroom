# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Live mode for the built-in run mode (activity setting *Live mode*, 2 to 6 people per room):
  avatars with head and hand poses, positional voice with mute, push to talk and per-person mute,
  and a participant list. Browser-to-browser WebRTC with signalling relayed by Moodle web services
  (`vrroomruntime_builtin_live_join`, `_live_poll`, `_live_send`, `_live_leave`), tables
  `vrroomruntime_builtin_peers` and `vrroomruntime_builtin_signals`, and the scheduled task
  *Remove expired live room data*.
- Site settings of the built-in run mode: live mode on/off, STUN servers, TURN servers, TURN shared
  secret and TURN credential lifetime (all servers empty by default).
- Group and grouping support: one live room per group; reports filtered by group.

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
