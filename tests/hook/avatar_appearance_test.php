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

namespace mod_vrroom\hook;

/**
 * Tests of the avatar_appearance hook object.
 *
 * @package    mod_vrroom
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(avatar_appearance::class)]
final class avatar_appearance_test extends \advanced_testcase {
    /** @var string A peer id. */
    private const PEERA = '0123456789abcdef0123456789abcdef';

    /** @var string Another peer id. */
    private const PEERB = 'fedcba9876543210fedcba9876543210';

    /**
     * Peers and context come back as given; entries without a peer id are skipped.
     */
    public function test_peers_and_context(): void {
        $context = \context_system::instance();
        $hook = new avatar_appearance([
            ['peerid' => self::PEERA, 'userid' => '5'],
            ['userid' => 7],
            ['peerid' => self::PEERB, 'userid' => 6],
        ], $context);
        $this->assertSame(
            [['peerid' => self::PEERA, 'userid' => 5], ['peerid' => self::PEERB, 'userid' => 6]],
            $hook->get_peers()
        );
        $this->assertSame($context, $hook->get_context());
        $this->assertSame([], $hook->get_appearances());
    }

    /**
     * Set appearances: listed peers only, the last call wins, oversized documents and bad pictures are dropped.
     */
    public function test_set_appearance(): void {
        $hook = new avatar_appearance(
            [['peerid' => self::PEERA, 'userid' => 5], ['peerid' => self::PEERB, 'userid' => 6]],
            \context_system::instance()
        );
        $doc = ['schema' => 'vrroom-avatar/1', 'body' => 'slim', 'color' => 'teal', 'picture' => true];

        $hook->set_appearance('ffffffffffffffffffffffffffffffff', $doc);
        $this->assertSame([], $hook->get_appearances());

        $hook->set_appearance(self::PEERA, ['schema' => 'vrroom-avatar/1']);
        $hook->set_appearance(self::PEERA, $doc, 'https://example.org/pluginfile.php/5/user/icon/boost/f1');
        $this->assertSame(
            [self::PEERA => ['appearance' => $doc, 'picture' => 'https://example.org/pluginfile.php/5/user/icon/boost/f1']],
            $hook->get_appearances()
        );

        // A picture that is not an http(s) URL is dropped; the look stays.
        $hook->set_appearance(self::PEERB, $doc, 'javascript:alert(1)');
        $this->assertSame(['appearance' => $doc, 'picture' => ''], $hook->get_appearances()[self::PEERB]);

        // More than MAX_BYTES of JSON is ignored: the earlier look of the peer stays.
        $big = $doc + ['padding' => str_repeat('x', avatar_appearance::MAX_BYTES)];
        $hook->set_appearance(self::PEERB, $big);
        $this->assertSame($doc, $hook->get_appearances()[self::PEERB]['appearance']);
    }
}
