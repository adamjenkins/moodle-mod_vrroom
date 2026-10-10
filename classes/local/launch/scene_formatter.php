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

namespace mod_vrroom\local\launch;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\graph\evaluator;
use mod_vrroom\local\panorama;
use mod_vrroom\local\scene\environment_registry;
use mod_vrroom\local\xapi\iri;

/**
 * Formats stored scene documents for a client, for VR room itself and for other plugins.
 *
 * Teacher (or student) content leaves the server formatted: hotspot text as format_text() HTML,
 * names and titles through format_string() (tags stripped, entities decoded, because the client
 * escapes them in its templates), and every file reference with a URL. Where the URL comes from is
 * up to the caller: by default it is a mod_vrroom pluginfile URL in the given context; another plugin
 * (the virtual tour assignment submission) passes a callback that maps a reference to its own files.
 *
 * Runtime-only keys added here (removed again by editor_manager::strip_runtime_keys):
 * file references get `url`; a 360° photo environment gets `variants` {desktop?, mobile?} (URLs of
 * the smaller copies beside the photo); hotspot media entries get `kind` (image, audio or video).
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scene_formatter {
    /** @var string[] File areas a scene may reference (mod_vrroom's own areas). */
    public const FILE_AREAS = ['models', 'media', 'environment', 'assets'];

    /**
     * Format a stored scene for the client.
     *
     * @param array $scene decoded vrroom-scene/1 document
     * @param \context $context context the content belongs to (filters and the default file URLs)
     * @param callable|null $fileurl function(array $ref): ?array returning ['url' => string, 'file' => ?\stored_file]
     *     for a reference, or null when the reference is unknown; null means mod_vrroom's areas in $context
     * @return array
     */
    public static function prepare_scene(array $scene, \context $context, ?callable $fileurl = null): array {
        $fileurl = $fileurl ?? self::default_fileurl($context);
        if (isset($scene['name'])) {
            $scene['name'] = self::plain((string)$scene['name'], $context);
        }
        if (isset($scene['environment']) && is_array($scene['environment'])) {
            $environment = $scene['environment'];
            unset($environment['variants']);
            if (isset($environment['file']) && is_array($environment['file'])) {
                $resolved = self::resolve($environment['file'], $fileurl);
                $environment['file'] = $resolved['ref'];
                if (($environment['type'] ?? '') === 'panorama' && $resolved['file'] instanceof \stored_file) {
                    $variants = [];
                    foreach (panorama::find_variants($resolved['file']) as $name => $variant) {
                        if ($variant instanceof \stored_file) {
                            $variants[$name] = self::stored_file_url($variant);
                        }
                    }
                    $environment['variants'] = (object)$variants;
                }
            }
            if (($environment['type'] ?? '') === 'pack') {
                $environment = self::prepare_pack_environment($environment);
            }
            $scene['environment'] = $environment;
        }
        foreach (['spawn', 'zones', 'interactables'] as $list) {
            if (empty($scene[$list]) || !is_array($scene[$list])) {
                continue;
            }
            foreach ($scene[$list] as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['name'])) {
                    $item['name'] = self::plain((string)$item['name'], $context);
                }
                if (isset($item['file']) && is_array($item['file'])) {
                    $item['file'] = self::resolve($item['file'], $fileurl)['ref'];
                }
                if (($item['kind'] ?? '') === 'text' && isset($item['text'])) {
                    $item['text'] = self::plain((string)$item['text'], $context);
                }
                if (isset($item['content']) && is_array($item['content'])) {
                    $item['content'] = self::prepare_content($item['content'], $context, $fileurl);
                }
                $scene[$list][$i] = $item;
            }
        }
        return $scene;
    }

    /**
     * A vrroom-launch/1 preview config for a viewer embedded by another plugin (no attempt, no grade).
     *
     * Nothing is recorded: the session runs as a preview, with an empty graph, no questions, no
     * resume position and live sessions off.
     *
     * @param array $scenes decoded vrroom-scene/1 documents in room order (the first is the start room)
     * @param \context $context context the content belongs to
     * @param \stdClass $user the user viewing
     * @param callable|null $fileurl reference to URL callback, see prepare_scene()
     * @param array $extra optional 'uid' (int, sent as cmid: a stable id for the viewer root) and 'finishurl'
     * @return array
     */
    public static function standalone_config(
        array $scenes,
        \context $context,
        \stdClass $user,
        ?callable $fileurl,
        array $extra = []
    ): array {
        global $CFG;

        $prepared = [];
        foreach ($scenes as $scene) {
            if (is_array($scene)) {
                $prepared[] = self::prepare_scene($scene, $context, $fileurl);
            }
        }
        $graph = ['schema' => 'vrroom-graph/1', 'hidden' => [], 'objectives' => [], 'rules' => []];
        $result = (new evaluator($graph, []))->evaluate([]);
        $coursecontext = $context->get_course_context(false);
        $uid = (int)($extra['uid'] ?? 0);

        return [
            'schema' => config_builder::SCHEMA,
            'preview' => true,
            'standalone' => true,
            'cmid' => $uid,
            'contextid' => (int)$context->id,
            'courseid' => $coursecontext ? (int)$coursecontext->instanceid : (int)SITEID,
            'userid' => (int)$user->id,
            'attemptid' => 0,
            'attemptnumber' => 0,
            'activityiri' => '',
            'actor' => [
                'objectType' => 'Agent',
                'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$user->id],
            ],
            'extbase' => iri::EXT_BASE,
            'verbs' => iri::VERBS,
            'maxbatch' => config_builder::get_maxbatch(),
            'startscene' => $prepared ? (string)($prepared[0]['id'] ?? '') : '',
            'scenes' => $prepared,
            'graph' => $graph,
            'hints' => (object)[],
            'state' => attempt_manager::build_state(0, 'preview', 0, $result, 0, 0),
            'events' => [],
            'questions' => [],
            'resume' => null,
            'finishurl' => (string)($extra['finishurl'] ?? ''),
            'questionmode' => ['mode' => 'once', 'retrypenalty' => 0],
            'look' => self::look($user),
            'live' => ['available' => false],
        ];
    }

    /**
     * The user's look preferences (mouse inversion, vertical lock), as the launch config carries them.
     *
     * @param \stdClass $user
     * @return array
     */
    public static function look(\stdClass $user): array {
        return [
            'invertx' => (bool)get_user_preferences('mod_vrroom_invertx', 0, $user),
            'inverty' => (bool)get_user_preferences('mod_vrroom_inverty', 0, $user),
            'locky' => (bool)get_user_preferences('mod_vrroom_locky', 0, $user),
        ];
    }

    /**
     * The default reference to URL callback: mod_vrroom's own areas in a context.
     *
     * @param \context $context
     * @return callable function(array $ref): ?array
     */
    public static function default_fileurl(\context $context): callable {
        return function (array $ref) use ($context): ?array {
            $area = (string)($ref['area'] ?? '');
            if (!in_array($area, self::FILE_AREAS, true) || !isset($ref['filename'])) {
                return null;
            }
            $filepath = (string)($ref['filepath'] ?? '/');
            $filepath = $filepath === '' ? '/' : $filepath;
            $itemid = (int)($ref['itemid'] ?? 0);
            $filename = (string)$ref['filename'];
            $url = \moodle_url::make_pluginfile_url($context->id, 'mod_vrroom', $area, $itemid, $filepath, $filename)
                ->out(false);
            $file = get_file_storage()->get_file($context->id, 'mod_vrroom', $area, $itemid, $filepath, $filename);
            return ['url' => $url, 'file' => $file ?: null];
        };
    }

    /**
     * The pluginfile URL of a stored file.
     *
     * @param \stored_file $file
     * @return string
     */
    public static function stored_file_url(\stored_file $file): string {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * The media kind of a hotspot media file: image, audio or video.
     *
     * @param string $mimetype the file's mimetype ('' when not known)
     * @param string $filename the file name, used when there is no mimetype
     * @return string
     */
    public static function media_kind(string $mimetype, string $filename = ''): string {
        if ($mimetype === '' && $filename !== '') {
            $mimetype = (string)mimeinfo('type', $filename);
        }
        if (str_starts_with($mimetype, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimetype, 'video/')) {
            return 'video';
        }
        return 'image';
    }

    /**
     * Add the URL to a reference through the callback.
     *
     * @param array $ref file reference
     * @param callable $fileurl see prepare_scene()
     * @return array ['ref' => the reference with url (or without, when unknown), 'file' => ?\stored_file]
     */
    private static function resolve(array $ref, callable $fileurl): array {
        unset($ref['url']);
        $found = $fileurl($ref);
        if (!is_array($found) || !isset($found['url'])) {
            return ['ref' => $ref, 'file' => null];
        }
        $ref['url'] = (string)$found['url'];
        $file = $found['file'] ?? null;
        return ['ref' => $ref, 'file' => $file instanceof \stored_file ? $file : null];
    }

    /**
     * Add the model URL, room name and credit of a room from a room pack, or mark it missing.
     *
     * The viewer falls back to a plain hall sized from the stored bounds when there is no URL.
     *
     * @param array $environment environment block of type 'pack'
     * @return array
     */
    private static function prepare_pack_environment(array $environment): array {
        unset($environment['url'], $environment['roomname'], $environment['credit'], $environment['missing']);
        $def = null;
        if (is_string($environment['pack'] ?? null) && is_string($environment['room'] ?? null)) {
            $def = environment_registry::get_pack_room($environment['pack'], $environment['room']);
        }
        if ($def === null) {
            $environment['missing'] = true;
            return $environment;
        }
        $environment['url'] = $def['url'];
        $environment['roomname'] = $def['name'];
        $environment['credit'] = $def['credit'];
        return $environment;
    }

    /**
     * Format hotspot content: html from text/textformat, plain title, media file URLs and kinds.
     *
     * @param array $content hotspot content
     * @param \context $context context the content belongs to
     * @param callable|null $fileurl see prepare_scene(); null for mod_vrroom's areas in $context
     * @return array
     */
    public static function prepare_content(array $content, \context $context, ?callable $fileurl = null): array {
        $fileurl = $fileurl ?? self::default_fileurl($context);
        $format = isset($content['textformat']) ? (string)$content['textformat'] : (string)FORMAT_HTML;
        $content['html'] = format_text((string)($content['text'] ?? ''), $format, ['context' => $context]);
        unset($content['text']);
        if (isset($content['title'])) {
            $content['title'] = self::plain((string)$content['title'], $context);
        }
        if (!empty($content['media']) && is_array($content['media'])) {
            foreach ($content['media'] as $i => $media) {
                if (is_array($media) && isset($media['area'])) {
                    unset($media['kind']);
                    $resolved = self::resolve($media, $fileurl);
                    $media = $resolved['ref'];
                    $media['kind'] = self::kind_of($resolved['file'], (string)($media['filename'] ?? ''));
                    $content['media'][$i] = $media;
                } else if (is_array($media) && isset($media['file']) && is_array($media['file'])) {
                    $resolved = self::resolve($media['file'], $fileurl);
                    $media['file'] = $resolved['ref'];
                    $media['kind'] = self::kind_of($resolved['file'], (string)($media['file']['filename'] ?? ''));
                    $content['media'][$i] = $media;
                }
            }
        }
        return $content;
    }

    /**
     * Add a URL to a file reference through the callback (the reference is returned without one when unknown).
     *
     * @param array $ref ['area', 'itemid', 'filepath', 'filename']
     * @param \context $context context of the default callback
     * @param callable|null $fileurl see prepare_scene()
     * @return array
     */
    public static function file_ref(array $ref, \context $context, ?callable $fileurl = null): array {
        return self::resolve($ref, $fileurl ?? self::default_fileurl($context))['ref'];
    }

    /**
     * A plain-text label: filtered, tags stripped, entities decoded (client templates escape it).
     *
     * @param string $text
     * @param \context $context
     * @return string
     */
    public static function plain(string $text, \context $context): string {
        return html_entity_decode(
            strip_tags(format_string($text, true, ['context' => $context, 'escape' => false])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * The media kind of a resolved file.
     *
     * @param \stored_file|null $file the stored file, when the callback found one
     * @param string $filename the reference's file name
     * @return string image, audio or video
     */
    private static function kind_of(?\stored_file $file, string $filename): string {
        return self::media_kind($file ? (string)$file->get_mimetype() : '', $filename);
    }
}
