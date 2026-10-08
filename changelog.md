# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - 2026-10-08

ALPHA. First release, Moodle 5.2 only.

### Added

- Activity module `mod_mavr` with two sub-plugin types: run modes (`mavrruntime`) and templates
  (`mavrtemplate`).
- Scene documents (`mavr-scene/1`), task graphs (`mavr-graph/1`) with objectives (visit a zone,
  interact, answer a question, collect, sequence) and rules, and graph versions so old attempts
  replay under the graph they were played with.
- Built-in run mode `mavrruntime_builtin`: a three.js viewer bundled in the plugin (desktop and
  touch controls, procedural environments, GLB models and images) and an accessible list view.
- Gallery walk template `mavrtemplate_gallery`: environment choice (*Gallery hall*, *Courtyard* or
  an uploaded GLB), repeated stations with text, model or image, optional question, required flag
  and points, and an optional visiting order.
- xAPI statements (`mavr-vocab/1`) through core `core_xapi_statement_post`, validated by the
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
