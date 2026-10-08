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
 * Site settings of live mode: whether it is on, the STUN and TURN servers, TURN credentials.
 *
 * TURN credentials follow the TURN REST API scheme: the username is "<expiry>:<peer id>" and the
 * credential is base64(HMAC-SHA1(secret, username)). The shared secret never leaves the server.
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** @var string Component that owns the settings. */
    public const COMPONENT = 'mavrruntime_builtin';

    /** @var int Most URLs accepted in one STUN or TURN list. */
    public const MAX_ICE_URLS = 10;

    /** @var int Default lifetime of TURN credentials in seconds (6 hours). */
    public const DEFAULT_TURN_TTL = 21600;

    /** @var int Shortest lifetime of TURN credentials in seconds. */
    public const MIN_TURN_TTL = 60;

    /** @var string[] Schemes that may carry a ?transport= query. */
    private const TURN_SCHEMES = ['turn', 'turns'];

    /**
     * Whether live mode is on for the site (on when the setting was never saved).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config(self::COMPONENT, 'liveenabled');
        if ($value === false || $value === null) {
            return true;
        }
        return (bool)(int)$value;
    }

    /**
     * Check a list of ICE URLs, one per line.
     *
     * @param string $text The setting text.
     * @param string[] $schemes Allowed schemes.
     * @return bool|string true, or the error message.
     */
    public static function validate_urls(string $text, array $schemes): bool|string {
        $isturn = (bool)array_intersect($schemes, self::TURN_SCHEMES);
        $count = 0;
        foreach (self::lines($text) as $number => $line) {
            if ($line === '') {
                continue;
            }
            if (!self::is_valid_url($line, $schemes)) {
                return get_string($isturn ? 'errorinvalidturnurl' : 'errorinvalidstunurl', self::COMPONENT, $number);
            }
            $count++;
        }
        if ($count > self::MAX_ICE_URLS) {
            return get_string('errortoomanyiceurls', self::COMPONENT);
        }
        return true;
    }

    /**
     * The valid URLs of a list, trimmed, at most MAX_ICE_URLS.
     *
     * @param string $text The setting text.
     * @param string[] $schemes Allowed schemes.
     * @return string[]
     */
    public static function parse_urls(string $text, array $schemes): array {
        $urls = [];
        foreach (self::lines($text) as $line) {
            if ($line === '' || !self::is_valid_url($line, $schemes)) {
                continue;
            }
            $urls[] = $line;
            if (count($urls) >= self::MAX_ICE_URLS) {
                break;
            }
        }
        return $urls;
    }

    /**
     * The ICE servers a peer may use.
     *
     * STUN servers are listed when set. TURN servers are listed only when both URLs and a shared
     * secret are set, with credentials made for this peer.
     *
     * @param string $peerid The peer the credentials are made for.
     * @param int|null $now Current time.
     * @return array ['servers' => list of ['urls' => string[], 'username'?, 'credential'?], 'expires' => int]
     */
    public static function ice_servers(string $peerid, ?int $now = null): array {
        $now = $now ?? time();
        $servers = [];
        $expires = 0;

        $stun = self::parse_urls((string)get_config(self::COMPONENT, 'livestunurls'), ['stun', 'stuns']);
        if ($stun) {
            $servers[] = ['urls' => $stun];
        }

        $turn = self::parse_urls((string)get_config(self::COMPONENT, 'liveturnurls'), self::TURN_SCHEMES);
        $secret = (string)get_config(self::COMPONENT, 'liveturnsecret');
        if ($turn && $secret !== '') {
            $ttl = get_config(self::COMPONENT, 'liveturnttl');
            $ttl = ($ttl === false || $ttl === null || $ttl === '') ? self::DEFAULT_TURN_TTL : (int)$ttl;
            $ttl = max(self::MIN_TURN_TTL, $ttl);
            $expires = $now + $ttl;
            $username = self::turn_username($peerid, $expires);
            $servers[] = [
                'urls' => $turn,
                'username' => $username,
                'credential' => self::turn_credential($username, $secret),
            ];
        }

        return ['servers' => $servers, 'expires' => $expires];
    }

    /**
     * TURN REST API username.
     *
     * @param string $peerid Peer id.
     * @param int $expires Expiry time.
     * @return string
     */
    public static function turn_username(string $peerid, int $expires): string {
        return "{$expires}:{$peerid}";
    }

    /**
     * TURN REST API credential: base64 of the HMAC-SHA1 of the username with the shared secret.
     *
     * @param string $username The username.
     * @param string $secret The shared secret.
     * @return string
     */
    public static function turn_credential(string $username, string $secret): string {
        return base64_encode(hash_hmac('sha1', $username, $secret, true));
    }

    /**
     * Lines of a text, trimmed, keyed by line number (1 upwards).
     *
     * @param string $text
     * @return array<int, string>
     */
    private static function lines(string $text): array {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
            $lines[$index + 1] = trim($line);
        }
        return $lines;
    }

    /**
     * Whether one URL is a valid STUN or TURN URL of the allowed schemes.
     *
     * @param string $url Trimmed URL.
     * @param string[] $schemes Allowed schemes.
     * @return bool
     */
    private static function is_valid_url(string $url, array $schemes): bool {
        if ($url === '' || strlen($url) > 300 || !$schemes) {
            return false;
        }
        $quoted = implode('|', array_map(fn($s) => preg_quote((string)$s, '~'), $schemes));
        if (!preg_match('~^(' . $quoted . '):(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?(\?transport=(?:udp|tcp))?$~',
                $url, $m)) {
            return false;
        }
        $scheme = $m[1];
        $host = $m[2];
        $port = $m[3] ?? '';
        $query = $m[4] ?? '';

        if ($query !== '' && !in_array($scheme, self::TURN_SCHEMES, true)) {
            return false;
        }
        if ($port !== '' && ((int)$port < 1 || (int)$port > 65535)) {
            return false;
        }
        if ($host[0] === '[') {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (preg_match('/^[0-9.]+$/', $host)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        return (bool)preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host)
            && strpos($host, '..') === false;
    }
}
