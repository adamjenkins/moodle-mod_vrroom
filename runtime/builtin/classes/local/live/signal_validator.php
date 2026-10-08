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

namespace mavrruntime_builtin\local\live;

/**
 * Checks a connection message and rebuilds it from known keys only (PHASE3-CONTRACT §3.4).
 *
 * Nothing a client sends is stored as it came: the payload is decoded, every value is checked
 * and a canonical JSON text is built from the allowed keys in a fixed order.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class signal_validator {
    /** @var string[] Signal types. */
    public const TYPES = ['description', 'candidates'];

    /** @var int Highest generation number. */
    public const MAX_GEN = 1000000;

    /** @var int Longest session description. */
    public const MAX_SDP = 15000;

    /** @var int Most candidates in one signal. */
    public const MAX_CANDIDATES = 30;

    /** @var int Longest candidate line. */
    public const MAX_CANDIDATE = 512;

    /** @var int Longest media id. */
    public const MAX_MID = 32;

    /** @var int Highest media line index. */
    public const MAX_MLINE = 15;

    /** @var int Longest username fragment. */
    public const MAX_UFRAG = 64;

    /** @var int JSON nesting depth accepted when decoding. */
    private const DEPTH = 6;

    /**
     * Check a payload and return its canonical JSON.
     *
     * @param string $type 'description' or 'candidates'.
     * @param string $payload JSON text from the client.
     * @return array{0: ?string, 1: string} [canonical JSON or null, error code '' | 'invalid' | 'toolarge']
     */
    public static function canonical(string $type, string $payload): array {
        if (!in_array($type, self::TYPES, true)) {
            return [null, 'invalid'];
        }
        // A text far above the cap cannot shrink below it; refuse it before decoding.
        if (strlen($payload) > 2 * room_manager::MAX_PAYLOAD) {
            return [null, 'toolarge'];
        }
        try {
            $data = json_decode($payload, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, 'invalid'];
        }
        if (!is_array($data) || ($data && array_is_list($data))) {
            return [null, 'invalid'];
        }

        $clean = $type === 'description' ? self::description($data) : self::candidates($data);
        if ($clean === null) {
            return [null, 'invalid'];
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [null, 'invalid'];
        }
        if (strlen($json) > room_manager::MAX_PAYLOAD) {
            return [null, 'toolarge'];
        }
        return [$json, ''];
    }

    /**
     * A session description: {gen, type, sdp}.
     *
     * @param array $data Decoded payload.
     * @return array|null
     */
    private static function description(array $data): ?array {
        $gen = self::gen($data);
        $kind = $data['type'] ?? null;
        $sdp = $data['sdp'] ?? null;
        if ($gen === null || !in_array($kind, ['offer', 'answer'], true) || !is_string($sdp)) {
            return null;
        }
        $length = strlen($sdp);
        if (
            $length < 1 || $length > self::MAX_SDP || strpos($sdp, 'v=0') !== 0
                || !preg_match('/^[\t\r\n\x20-\x7E]*$/', $sdp)
        ) {
            return null;
        }
        return ['gen' => $gen, 'type' => $kind, 'sdp' => $sdp];
    }

    /**
     * A batch of ICE candidates: {gen, candidates: [...]}.
     *
     * @param array $data Decoded payload.
     * @return array|null
     */
    private static function candidates(array $data): ?array {
        $gen = self::gen($data);
        $list = $data['candidates'] ?? null;
        if ($gen === null || !is_array($list) || !array_is_list($list) || count($list) > self::MAX_CANDIDATES) {
            return null;
        }
        $clean = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || ($entry && array_is_list($entry))) {
                return null;
            }
            $candidate = $entry['candidate'] ?? null;
            if (
                !is_string($candidate) || strlen($candidate) > self::MAX_CANDIDATE
                    || !preg_match('/^[\x20-\x7E]*$/', $candidate)
            ) {
                return null;
            }
            $mid = $entry['sdpMid'] ?? null;
            if (
                $mid !== null && (!is_string($mid) || strlen($mid) > self::MAX_MID
                    || !preg_match('/^[A-Za-z0-9_-]*$/', $mid))
            ) {
                return null;
            }
            $mline = $entry['sdpMLineIndex'] ?? null;
            if ($mline !== null && (!is_int($mline) || $mline < 0 || $mline > self::MAX_MLINE)) {
                return null;
            }
            $ufrag = $entry['usernameFragment'] ?? null;
            if (
                $ufrag !== null && (!is_string($ufrag) || strlen($ufrag) > self::MAX_UFRAG
                    || !preg_match('/^[\x20-\x7E]*$/', $ufrag))
            ) {
                return null;
            }
            $clean[] = [
                'candidate' => $candidate,
                'sdpMid' => $mid,
                'sdpMLineIndex' => $mline,
                'usernameFragment' => $ufrag,
            ];
        }
        return ['gen' => $gen, 'candidates' => $clean];
    }

    /**
     * The generation number of a payload.
     *
     * @param array $data Decoded payload.
     * @return int|null
     */
    private static function gen(array $data): ?int {
        $gen = $data['gen'] ?? null;
        if (!is_int($gen) || $gen < 0 || $gen > self::MAX_GEN) {
            return null;
        }
        return $gen;
    }
}
