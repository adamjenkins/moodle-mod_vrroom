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

namespace vrroomruntime_builtin\local\live;

/**
 * Tests for the live mode site settings, ICE servers and TURN credentials.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\vrroomruntime_builtin\admin\setting_iceurls::class)]
final class config_test extends \advanced_testcase {
    /** @var string A peer id for the tests. */
    private const PEERID = '0123456789abcdef0123456789abcdef';

    /**
     * Live mode is on when never saved, and follows the setting otherwise.
     */
    public function test_is_enabled(): void {
        $this->resetAfterTest();
        unset_config('liveenabled', 'vrroomruntime_builtin');
        $this->assertTrue(config::is_enabled());
        set_config('liveenabled', 0, 'vrroomruntime_builtin');
        $this->assertFalse(config::is_enabled());
        set_config('liveenabled', 1, 'vrroomruntime_builtin');
        $this->assertTrue(config::is_enabled());
    }

    /**
     * Valid STUN and TURN lists.
     */
    public function test_validate_urls_valid(): void {
        $this->resetAfterTest();
        $stun = "stun:stun.example.org\nstun:stun.example.org:3478\n\n  stuns:stun.example.org:5349  \n"
            . "stun:192.0.2.10:3478\nstun:[2001:db8::1]:3478\nstun:[2001:db8::1]";
        $this->assertTrue(config::validate_urls($stun, ['stun', 'stuns']));
        $this->assertTrue(config::validate_urls('', ['stun', 'stuns']));

        $turn = "turn:turn.example.org:3478?transport=udp\nturns:turn.example.org:5349?transport=tcp\n"
            . "turn:turn.example.org\nturn:[2001:db8::2]:3478";
        $this->assertTrue(config::validate_urls($turn, ['turn', 'turns']));
    }

    /**
     * Invalid lines name their line number with the error of the setting's scheme.
     */
    public function test_validate_urls_invalid(): void {
        $this->resetAfterTest();
        $stunerror = fn(int $line) => get_string('errorinvalidstunurl', 'vrroomruntime_builtin', $line);
        $turnerror = fn(int $line) => get_string('errorinvalidturnurl', 'vrroomruntime_builtin', $line);

        $this->assertSame($stunerror(2), config::validate_urls("stun:ok.example.org\nturn:turn.example.org", ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:stun.example.org?transport=udp', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:stun.example.org:0', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:stun.example.org:65536', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:999.1.1.1', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:2001:db8::1', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:[not:an:ip]', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('https://stun.example.org', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:-bad.example.org', ['stun', 'stuns']));
        $this->assertSame($stunerror(1), config::validate_urls('stun:user@stun.example.org', ['stun', 'stuns']));

        $this->assertSame($turnerror(1), config::validate_urls('stun:stun.example.org', ['turn', 'turns']));
        $this->assertSame($turnerror(1), config::validate_urls('turn:turn.example.org?transport=sctp', ['turn', 'turns']));
        $this->assertSame($turnerror(3), config::validate_urls("turn:a.example.org\n\nturn:b.example.org:x", ['turn', 'turns']));
    }

    /**
     * More than ten URLs are refused; parse_urls keeps at most ten valid lines.
     */
    public function test_too_many_urls(): void {
        $this->resetAfterTest();
        $lines = [];
        for ($i = 1; $i <= 11; $i++) {
            $lines[] = "stun:stun{$i}.example.org";
        }
        $text = implode("\n", $lines);
        $this->assertSame(
            get_string('errortoomanyiceurls', 'vrroomruntime_builtin'),
            config::validate_urls($text, ['stun', 'stuns'])
        );
        $this->assertTrue(config::validate_urls(implode("\n", array_slice($lines, 0, 10)), ['stun', 'stuns']));

        $parsed = config::parse_urls("  stun:a.example.org \nbad line\n\n" . $text, ['stun', 'stuns']);
        $this->assertCount(10, $parsed);
        $this->assertSame('stun:a.example.org', $parsed[0]);
        $this->assertNotContains('bad line', $parsed);
    }

    /**
     * Nothing set: no servers and no expiry.
     */
    public function test_ice_servers_none(): void {
        $this->resetAfterTest();
        $ice = config::ice_servers(self::PEERID, 1000);
        $this->assertSame(['servers' => [], 'expires' => 0], $ice);
    }

    /**
     * STUN only.
     */
    public function test_ice_servers_stun_only(): void {
        $this->resetAfterTest();
        set_config('livestunurls', "stun:stun.example.org:3478\nstuns:stun.example.org:5349", 'vrroomruntime_builtin');
        $ice = config::ice_servers(self::PEERID, 1000);
        $this->assertSame(0, $ice['expires']);
        $this->assertSame([['urls' => ['stun:stun.example.org:3478', 'stuns:stun.example.org:5349']]], $ice['servers']);
    }

    /**
     * TURN with a secret: REST API credentials; the secret is in no part of the result.
     */
    public function test_ice_servers_turn_with_secret(): void {
        $this->resetAfterTest();
        $secret = 'very-secret-value-123';
        set_config('livestunurls', 'stun:stun.example.org', 'vrroomruntime_builtin');
        set_config('liveturnurls', 'turn:turn.example.org:3478?transport=udp', 'vrroomruntime_builtin');
        set_config('liveturnsecret', $secret, 'vrroomruntime_builtin');
        set_config('liveturnttl', 3600, 'vrroomruntime_builtin');

        $now = 1700000000;
        $ice = config::ice_servers(self::PEERID, $now);
        $this->assertSame($now + 3600, $ice['expires']);
        $this->assertCount(2, $ice['servers']);
        $turn = $ice['servers'][1];
        $this->assertSame(['urls', 'username', 'credential'], array_keys($turn));
        $this->assertSame(['turn:turn.example.org:3478?transport=udp'], $turn['urls']);
        $this->assertSame(($now + 3600) . ':' . self::PEERID, $turn['username']);
        $this->assertSame(
            base64_encode(hash_hmac('sha1', ($now + 3600) . ':' . self::PEERID, $secret, true)),
            $turn['credential']
        );
        $this->assertStringNotContainsString($secret, json_encode($ice));

        // A lifetime below a minute is raised to a minute.
        set_config('liveturnttl', 5, 'vrroomruntime_builtin');
        $this->assertSame($now + 60, config::ice_servers(self::PEERID, $now)['expires']);
    }

    /**
     * TURN URLs without a secret are not offered.
     */
    public function test_ice_servers_turn_without_secret(): void {
        $this->resetAfterTest();
        set_config('liveturnurls', 'turn:turn.example.org:3478', 'vrroomruntime_builtin');
        set_config('liveturnsecret', '', 'vrroomruntime_builtin');
        $this->assertSame(['servers' => [], 'expires' => 0], config::ice_servers(self::PEERID, 1000));
    }

    /**
     * The TURN helpers.
     */
    public function test_turn_helpers(): void {
        $this->assertSame('99:' . self::PEERID, config::turn_username(self::PEERID, 99));
        $this->assertSame(base64_encode(hash_hmac('sha1', 'u', 's', true)), config::turn_credential('u', 's'));
    }

    /**
     * The settings page exists in the VR room category and renders; the URL setting validates.
     */
    public function test_settings_page(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = admin_get_root(true, true);
        $this->assertNotNull($root->locate('modvrroomfolder'));
        $this->assertNotNull($root->locate('modsettingvrroom'));
        $page = $root->locate('vrroomruntimesettingbuiltin');
        $this->assertNotNull($page);
        $html = $page->output_html();
        $this->assertStringContainsString('livestunurls', $html);
        $this->assertStringContainsString('liveturnsecret', $html);

        $setting = new \vrroomruntime_builtin\admin\setting_iceurls(
            'vrroomruntime_builtin/livestunurls',
            new \lang_string('livestunurls', 'vrroomruntime_builtin'),
            new \lang_string('livestunurls_desc', 'vrroomruntime_builtin'),
            ['stun', 'stuns']
        );
        $this->assertTrue($setting->validate('stun:stun.example.org'));
        $this->assertSame(get_string('errorinvalidstunurl', 'vrroomruntime_builtin', 1), $setting->validate('nonsense'));
    }
}
