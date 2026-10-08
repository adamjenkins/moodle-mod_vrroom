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

namespace mod_vrroom\local\xapi;

/**
 * The vrroom-vocab/1 statement vocabulary: verb IRIs, object IRIs and context extensions.
 *
 * Object IRIs are built from the site's wwwroot and the course module id, so the same object
 * yields the same IRI in every run mode. They are identifiers, not pages.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class iri {
    /** @var string Base of VR room's own context extension IRIs. */
    public const EXT_BASE = 'https://wisecat.net/xapi/vrroom/ext/';

    /** @var string Context extension holding the attempt id. */
    public const EXT_ATTEMPT = self::EXT_BASE . 'attempt-id';

    /** @var string Context extension holding the statement id (core drops the top-level id). */
    public const EXT_STATEMENT = self::EXT_BASE . 'statement-id';

    /** @var string Context extension holding the vocabulary version. */
    public const EXT_VOCAB = self::EXT_BASE . 'vocab';

    /** @var string Context extension holding the channel the statement came through. */
    public const EXT_CHANNEL = self::EXT_BASE . 'channel';

    /** @var string Vocabulary version. */
    public const VOCAB = 'vrroom-vocab/1';

    /** @var string[] Short verb => verb IRI, all eleven verbs of vrroom-vocab/1. */
    public const VERBS = [
        'launched' => 'http://adlnet.gov/expapi/verbs/launched',
        'initialized' => 'http://adlnet.gov/expapi/verbs/initialized',
        'terminated' => 'http://adlnet.gov/expapi/verbs/terminated',
        'entered' => 'https://wisecat.net/xapi/vrroom/verbs/entered',
        'exited' => 'http://adlnet.gov/expapi/verbs/exited',
        'interacted' => 'http://adlnet.gov/expapi/verbs/interacted',
        'answered' => 'http://adlnet.gov/expapi/verbs/answered',
        'completed' => 'http://adlnet.gov/expapi/verbs/completed',
        'passed' => 'http://adlnet.gov/expapi/verbs/passed',
        'failed' => 'http://adlnet.gov/expapi/verbs/failed',
        'requested' => 'https://wisecat.net/xapi/vrroom/verbs/requested',
    ];

    /** @var array Verbs a client may send => object types allowed for that verb (hints are server-asserted). */
    public const CLIENT_VERBS = [
        'initialized' => ['activity'],
        'terminated' => ['activity'],
        'entered' => ['scene', 'zone'],
        'exited' => ['scene', 'zone'],
        'interacted' => ['object', 'zone'],
    ];

    /** @var string[] All object types of vrroom-vocab/1. */
    public const OBJECT_TYPES = ['activity', 'scene', 'zone', 'object', 'question', 'objective', 'hint'];

    /** @var string Pattern every scene, zone, object, objective and hint id must match. */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * The activity IRI for a course module.
     *
     * @param int $cmid course module id
     * @return string
     */
    public static function activity(int $cmid): string {
        global $CFG;
        return $CFG->wwwroot . '/mod/vrroom/xapi/' . $cmid;
    }

    /**
     * The IRI of an object inside an activity.
     *
     * For type 'scene' the scene id is $sceneid (or $id when $sceneid is null).
     *
     * @param int $cmid course module id
     * @param string $type one of self::OBJECT_TYPES
     * @param string|null $sceneid scene id (scene, zone and object types)
     * @param string|null $id zone, object, objective or hint id, or the slot number for questions
     * @return string
     * @throws \coding_exception for an unknown type or a missing id
     */
    public static function for_object(int $cmid, string $type, ?string $sceneid, ?string $id): string {
        $act = self::activity($cmid);
        switch ($type) {
            case 'activity':
                return $act;
            case 'scene':
                $scene = $sceneid ?? $id;
                self::require_id($scene);
                return $act . '/scene/' . $scene;
            case 'zone':
            case 'object':
                self::require_id($sceneid);
                self::require_id($id);
                return $act . '/scene/' . $sceneid . '/' . $type . '/' . $id;
            case 'question':
                if ($id === null || !preg_match('/^\d+$/', $id)) {
                    throw new \coding_exception('A question IRI needs a numeric slot');
                }
                return $act . '/question/' . $id;
            case 'objective':
                self::require_id($id);
                return $act . '/objective/' . $id;
            case 'hint':
                self::require_id($id);
                return $act . '/hint/' . $id;
        }
        throw new \coding_exception('Unknown VR room object type: ' . $type);
    }

    /**
     * Parse a VR room object IRI.
     *
     * For a scene IRI both 'sceneid' and 'id' hold the scene id; for an activity both are null.
     *
     * @param string $iri the IRI
     * @return array|null ['cmid' => int, 'type' => string, 'sceneid' => ?string, 'id' => ?string], or null
     */
    public static function parse(string $iri): ?array {
        global $CFG;
        $prefix = $CFG->wwwroot . '/mod/vrroom/xapi/';
        if (strpos($iri, $prefix) !== 0) {
            return null;
        }
        $rest = substr($iri, strlen($prefix));
        $idp = '([A-Za-z0-9_-]{1,64})';
        $patterns = [
            'activity' => '~^(\d+)$~',
            'scene' => '~^(\d+)/scene/' . $idp . '$~',
            'zone' => '~^(\d+)/scene/' . $idp . '/zone/' . $idp . '$~',
            'object' => '~^(\d+)/scene/' . $idp . '/object/' . $idp . '$~',
            'question' => '~^(\d+)/question/(\d+)$~',
            'objective' => '~^(\d+)/objective/' . $idp . '$~',
            'hint' => '~^(\d+)/hint/' . $idp . '$~',
        ];
        foreach ($patterns as $type => $pattern) {
            if (!preg_match($pattern, $rest, $m)) {
                continue;
            }
            $cmid = (int)$m[1];
            if ($cmid <= 0 || (string)$cmid !== $m[1]) {
                return null;
            }
            switch ($type) {
                case 'activity':
                    return ['cmid' => $cmid, 'type' => $type, 'sceneid' => null, 'id' => null];
                case 'scene':
                    return ['cmid' => $cmid, 'type' => $type, 'sceneid' => $m[2], 'id' => $m[2]];
                case 'zone':
                case 'object':
                    return ['cmid' => $cmid, 'type' => $type, 'sceneid' => $m[2], 'id' => $m[3]];
                default:
                    return ['cmid' => $cmid, 'type' => $type, 'sceneid' => null, 'id' => $m[2]];
            }
        }
        return null;
    }

    /**
     * The IRI for a short verb.
     *
     * @param string $short short verb, a key of self::VERBS
     * @return string
     * @throws \coding_exception for an unknown verb
     */
    public static function verb_iri(string $short): string {
        if (!array_key_exists($short, self::VERBS)) {
            throw new \coding_exception('Unknown VR room verb: ' . $short);
        }
        return self::VERBS[$short];
    }

    /**
     * The short verb for a verb IRI.
     *
     * @param string $iri verb IRI
     * @return string|null short verb, or null when the IRI is not in the vocabulary
     */
    public static function verb_short(string $iri): ?string {
        $short = array_search($iri, self::VERBS, true);
        return $short === false ? null : $short;
    }

    /**
     * Throw unless the id is a valid VR room id.
     *
     * @param string|null $id
     * @return void
     * @throws \coding_exception
     */
    private static function require_id(?string $id): void {
        if ($id === null || !preg_match(self::ID_PATTERN, $id)) {
            throw new \coding_exception('Invalid VR room id in IRI');
        }
    }
}
