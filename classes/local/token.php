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

namespace mod_mavr\local;

/**
 * Launch tokens for run modes that post statements through the server channel.
 *
 * Format: "v1." + base64url(JSON {"u","c","a","e"}) + "." + base64url(HMAC-SHA256 of
 * "v1." + payload with the site secret). A token is bound to one user, course module and
 * attempt, and expires after the configured lifetime.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token {

    /** @var string Token format version prefix. */
    private const VERSION = 'v1';

    /** @var int Lifetime used when the setting is missing or invalid. */
    private const DEFAULT_TTL = 3600;

    /**
     * Create the site secret if it does not exist yet.
     *
     * @return void
     */
    public static function ensure_secret(): void {
        $secret = get_config('mod_mavr', 'tokensecret');
        if (empty($secret) || strlen((string)$secret) < 32) {
            set_config('tokensecret', random_string(64), 'mod_mavr');
        }
    }

    /**
     * Issue a token.
     *
     * @param int $userid user the token is for
     * @param int $cmid course module id
     * @param int $attemptid attempt id
     * @param int|null $ttl lifetime in seconds; null = the tokenttl setting
     * @return string
     */
    public static function issue(int $userid, int $cmid, int $attemptid, ?int $ttl = null): string {
        self::ensure_secret();
        if ($ttl === null) {
            $ttl = (int)get_config('mod_mavr', 'tokenttl');
            if ($ttl <= 0) {
                $ttl = self::DEFAULT_TTL;
            }
        }
        $payload = self::base64url_encode(json_encode([
            'u' => $userid,
            'c' => $cmid,
            'a' => $attemptid,
            'e' => time() + $ttl,
        ]));
        $signed = self::VERSION . '.' . $payload;
        return $signed . '.' . self::base64url_encode(self::sign($signed, self::secret()));
    }

    /**
     * Verify a token.
     *
     * @param string $token the token
     * @param int|null $timenow current time (tests)
     * @return array|null ['userid' => int, 'cmid' => int, 'attemptid' => int, 'exp' => int] when the
     *         signature is valid and the token has not expired; otherwise null
     */
    public static function verify(string $token, ?int $timenow = null): ?array {
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION || $parts[1] === '' || $parts[2] === '') {
            return null;
        }
        $expected = self::sign(self::VERSION . '.' . $parts[1], $secret);
        $given = self::base64url_decode($parts[2]);
        if ($given === null || !hash_equals($expected, $given)) {
            return null;
        }
        $json = self::base64url_decode($parts[1]);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['u', 'c', 'a', 'e'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] <= 0) {
                return null;
            }
        }
        $timenow = $timenow ?? time();
        if ($data['e'] <= $timenow) {
            return null;
        }
        return [
            'userid' => $data['u'],
            'cmid' => $data['c'],
            'attemptid' => $data['a'],
            'exp' => $data['e'],
        ];
    }

    /**
     * The site secret, or '' when none is set.
     *
     * @return string
     */
    private static function secret(): string {
        return (string)get_config('mod_mavr', 'tokensecret');
    }

    /**
     * Raw HMAC-SHA256.
     *
     * @param string $data signed data
     * @param string $secret secret
     * @return string raw bytes
     */
    private static function sign(string $data, string $secret): string {
        return hash_hmac('sha256', $data, $secret, true);
    }

    /**
     * Base64url without padding.
     *
     * @param string $data
     * @return string
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode base64url (padding optional).
     *
     * @param string $data
     * @return string|null null when the input is not base64url
     */
    private static function base64url_decode(string $data): ?string {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $data)) {
            return null;
        }
        $b64 = strtr($data, '-_', '+/');
        $remainder = strlen($b64) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }
}
