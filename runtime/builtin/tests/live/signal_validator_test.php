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
 * Tests for the signal payload rules (PHASE3-CONTRACT §3.4).
 *
 * @package    mavrruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(signal_validator::class)]
final class signal_validator_test extends \basic_testcase {
    /**
     * Descriptions: accepted shapes are rebuilt in key order without unknown keys.
     */
    public function test_description_valid(): void {
        [$json, $error] = signal_validator::canonical('description',
            json_encode(['sdp' => "v=0\r\ns=-\r\n", 'zzz' => 1, 'type' => 'answer', 'gen' => 1000000]));
        $this->assertSame('', $error);
        $this->assertSame('{"gen":1000000,"type":"answer","sdp":"v=0\r\ns=-\r\n"}', $json);

        $sdp = 'v=0' . str_repeat('a', signal_validator::MAX_SDP - 3);
        [$json] = signal_validator::canonical('description', json_encode(['gen' => 0, 'type' => 'offer', 'sdp' => $sdp]));
        $this->assertNotNull($json);
        $this->assertSame([null, 'invalid'],
            signal_validator::canonical('description', json_encode(['gen' => 0, 'type' => 'offer', 'sdp' => $sdp . 'a'])));
    }

    /**
     * Descriptions: every rule refuses.
     */
    public function test_description_invalid(): void {
        $base = ['gen' => 0, 'type' => 'offer', 'sdp' => "v=0\r\n"];
        $cases = [
            'gen negative' => ['gen' => -1] + $base,
            'gen too high' => ['gen' => 1000001] + $base,
            'gen float' => ['gen' => 1.5] + $base,
            'gen string' => ['gen' => '1'] + $base,
            'gen missing' => ['type' => 'offer', 'sdp' => "v=0\r\n"],
            'type pranswer' => ['type' => 'pranswer'] + $base,
            'sdp empty' => ['sdp' => ''] + $base,
            'sdp not v=0' => ['sdp' => "o=- 1\r\n"] + $base,
            'sdp array' => ['sdp' => ['v=0']] + $base,
            'sdp control char' => ['sdp' => "v=0\x01"] + $base,
            'sdp non-ascii' => ['sdp' => "v=0\r\n\u{e9}"] + $base,
        ];
        foreach ($cases as $name => $payload) {
            $this->assertSame([null, 'invalid'], signal_validator::canonical('description', json_encode($payload)), $name);
        }
        $this->assertSame([null, 'invalid'], signal_validator::canonical('description', '{not json'));
        $this->assertSame([null, 'invalid'], signal_validator::canonical('description', '"v=0"'));
        $this->assertSame([null, 'invalid'], signal_validator::canonical('description', '[1,2]'));
        $this->assertSame([null, 'invalid'], signal_validator::canonical('description', '{"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}}'));
        $this->assertSame([null, 'invalid'], signal_validator::canonical('offer', json_encode($base)));
    }

    /**
     * Candidates: bounds of every field, nulls kept, end-of-candidates accepted.
     */
    public function test_candidates_valid(): void {
        $payload = ['gen' => 3, 'candidates' => [
            ['usernameFragment' => str_repeat('u', 64), 'sdpMLineIndex' => 15, 'sdpMid' => str_repeat('m', 32),
                'candidate' => str_repeat('c', 512), 'foo' => 'bar'],
            ['candidate' => ''],
        ]];
        [$json, $error] = signal_validator::canonical('candidates', json_encode($payload));
        $this->assertSame('', $error);
        $decoded = json_decode($json, true);
        $this->assertSame(['gen', 'candidates'], array_keys($decoded));
        $this->assertSame(['candidate', 'sdpMid', 'sdpMLineIndex', 'usernameFragment'], array_keys($decoded['candidates'][0]));
        $this->assertArrayNotHasKey('foo', $decoded['candidates'][0]);
        $this->assertSame(['candidate' => '', 'sdpMid' => null, 'sdpMLineIndex' => null, 'usernameFragment' => null],
            $decoded['candidates'][1]);

        $this->assertSame(['{"gen":0,"candidates":[]}', ''],
            signal_validator::canonical('candidates', '{"gen":0,"candidates":[]}'));
        $thirty = ['gen' => 0, 'candidates' => array_fill(0, 30, ['candidate' => 'x'])];
        $this->assertSame('', signal_validator::canonical('candidates', json_encode($thirty))[1]);
    }

    /**
     * Candidates: every rule refuses.
     */
    public function test_candidates_invalid(): void {
        $entry = ['candidate' => 'candidate:1 1 udp 1 192.0.2.1 1 typ host', 'sdpMid' => '0', 'sdpMLineIndex' => 0,
            'usernameFragment' => 'abcd'];
        $cases = [
            'too many' => array_fill(0, 31, $entry),
            'candidate too long' => [['candidate' => str_repeat('c', 513)] + $entry],
            'candidate missing' => [['sdpMid' => '0']],
            'candidate newline' => [['candidate' => "a\nb"] + $entry],
            'mid too long' => [['sdpMid' => str_repeat('m', 33)] + $entry],
            'mid bad char' => [['sdpMid' => 'a b'] + $entry],
            'mline too high' => [['sdpMLineIndex' => 16] + $entry],
            'mline negative' => [['sdpMLineIndex' => -1] + $entry],
            'mline string' => [['sdpMLineIndex' => '0'] + $entry],
            'ufrag too long' => [['usernameFragment' => str_repeat('u', 65)] + $entry],
            'entry not object' => ['candidate:1'],
            'entry list' => [['a', 'b']],
        ];
        foreach ($cases as $name => $list) {
            $this->assertSame([null, 'invalid'],
                signal_validator::canonical('candidates', json_encode(['gen' => 0, 'candidates' => $list])), $name);
        }
        $this->assertSame([null, 'invalid'], signal_validator::canonical('candidates', '{"gen":0,"candidates":{"a":1}}'));
        $this->assertSame([null, 'invalid'], signal_validator::canonical('candidates', '{"gen":0}'));
    }

    /**
     * Canonical JSON above the cap is too large, and so is a text far above it.
     */
    public function test_too_large(): void {
        $big = ['gen' => 1, 'candidates' => array_fill(0, 30, ['candidate' => str_repeat('a', 512), 'sdpMid' => '0',
            'sdpMLineIndex' => 0, 'usernameFragment' => 'abcd'])];
        $this->assertSame([null, 'toolarge'], signal_validator::canonical('candidates', json_encode($big)));

        // CR LF doubles in JSON: a 14999-character description is above the cap once encoded.
        $sdp = 'v=0' . str_repeat("\r\n", 7498);
        $this->assertSame([null, 'toolarge'],
            signal_validator::canonical('description', json_encode(['gen' => 0, 'type' => 'offer', 'sdp' => $sdp])));

        $huge = '{"gen":0,"candidates":[],"pad":"' . str_repeat('x', 2 * room_manager::MAX_PAYLOAD) . '"}';
        $this->assertSame([null, 'toolarge'], signal_validator::canonical('candidates', $huge));
    }
}
