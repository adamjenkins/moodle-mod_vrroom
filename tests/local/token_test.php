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
 * Tests of launch tokens.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(token::class)]
final class token_test extends \advanced_testcase {

    /**
     * The secret is created once and kept.
     *
     * @return void
     */
    public function test_ensure_secret(): void {
        $this->resetAfterTest();
        unset_config('tokensecret', 'mod_mavr');
        token::ensure_secret();
        $secret = get_config('mod_mavr', 'tokensecret');
        $this->assertSame(64, strlen($secret));
        token::ensure_secret();
        $this->assertSame($secret, get_config('mod_mavr', 'tokensecret'));
    }

    /**
     * A fresh token verifies and carries its binding.
     *
     * @return void
     */
    public function test_issue_and_verify(): void {
        $this->resetAfterTest();
        $token = token::issue(5, 12, 7, 600);
        $this->assertStringStartsWith('v1.', $token);
        $data = token::verify($token);
        $this->assertSame(5, $data['userid']);
        $this->assertSame(12, $data['cmid']);
        $this->assertSame(7, $data['attemptid']);
        $this->assertGreaterThan(time(), $data['exp']);
    }

    /**
     * The tokenttl setting is the default lifetime.
     *
     * @return void
     */
    public function test_default_ttl_from_setting(): void {
        $this->resetAfterTest();
        set_config('tokenttl', 120, 'mod_mavr');
        $data = token::verify(token::issue(5, 12, 7));
        $this->assertLessThanOrEqual(time() + 120, $data['exp']);
        $this->assertGreaterThan(time() + 100, $data['exp']);
    }

    /**
     * An expired token is refused.
     *
     * @return void
     */
    public function test_expired_token_is_refused(): void {
        $this->resetAfterTest();
        $token = token::issue(5, 12, 7, 60);
        $this->assertNotNull(token::verify($token, time() + 30));
        $exp = token::verify($token)['exp'];
        $this->assertNull(token::verify($token, $exp));
        $this->assertNull(token::verify($token, time() + 120));
    }

    /**
     * A token whose payload was changed (e.g. to another user) is refused.
     *
     * @return void
     */
    public function test_tampered_or_wrong_user_token_is_refused(): void {
        $this->resetAfterTest();
        $token = token::issue(5, 12, 7, 600);
        [$version, $payload, $signature] = explode('.', $token);

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $data['u'] = 6;
        $forged = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $this->assertNull(token::verify("$version.$forged.$signature"));

        $this->assertNull(token::verify("$version.$payload." . strrev($signature)));
        $this->assertNull(token::verify("v2.$payload.$signature"));
        $this->assertNull(token::verify("$version.$payload"));
        $this->assertNull(token::verify(''));
        $this->assertNull(token::verify('v1.!!!.???'));

        // A token signed with another site's secret is refused.
        set_config('tokensecret', random_string(64), 'mod_mavr');
        $this->assertNull(token::verify($token));
    }
}
