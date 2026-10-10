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
 * Lets a plugin (such as local_avatar) say how the people in a live room look.
 *
 * Dispatched once per peer list that a live room sends to the browser (join and every poll), for all
 * listed people at once. A listener calls set_appearance() for the peers whose look it knows; the
 * others keep today's avatar. The appearance document ("vrroom-avatar/1", AVATAR-DESIGN.md) is
 * validated by the listener; here it is only checked to be an array of at most MAX_BYTES of JSON,
 * and the picture to be a URL. Peers that are not in the list are ignored.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to set how the avatars of the people in a VR room live room look.')]
#[\core\attribute\tags('mod_vrroom')]
final class avatar_appearance {
    /** @var int Largest appearance document accepted, in bytes of JSON. */
    public const MAX_BYTES = 2048;

    /** @var array<string, int> peerid => userid of the listed peers. */
    private array $peers = [];

    /** @var array<string, array{appearance: array, picture: string}> What listeners set, by peer id. */
    private array $appearances = [];

    /**
     * Create the hook.
     *
     * @param array $peers List of ['peerid' => string, 'userid' => int]; entries without a peer id are skipped.
     * @param \context $context The activity's context.
     */
    public function __construct(
        array $peers,
        /** @var \context The activity's context */
        private readonly \context $context,
    ) {
        foreach ($peers as $peer) {
            $peerid = (string)($peer['peerid'] ?? '');
            if ($peerid === '') {
                continue;
            }
            $this->peers[$peerid] = (int)($peer['userid'] ?? 0);
        }
    }

    /**
     * The people whose look is asked for.
     *
     * @return array list of ['peerid' => string, 'userid' => int]
     */
    public function get_peers(): array {
        $list = [];
        foreach ($this->peers as $peerid => $userid) {
            $list[] = ['peerid' => (string)$peerid, 'userid' => $userid];
        }
        return $list;
    }

    /**
     * The activity's context.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->context;
    }

    /**
     * Set the look of one listed person.
     *
     * A later call for the same peer replaces the earlier one. An unknown peer, an appearance whose
     * JSON is longer than MAX_BYTES (or cannot be encoded) and a picture that is not a URL are ignored
     * (the picture alone is dropped).
     *
     * @param string $peerid A peer id of get_peers().
     * @param array $appearance The appearance document, already validated by the listener.
     * @param string $pictureurl The profile picture to show on the badge, '' for none.
     * @return void
     */
    public function set_appearance(string $peerid, array $appearance, string $pictureurl = ''): void {
        if (!array_key_exists($peerid, $this->peers)) {
            return;
        }
        $json = json_encode($appearance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return;
        }
        $picture = '';
        if ($pictureurl !== '') {
            $cleaned = clean_param($pictureurl, PARAM_URL);
            if ($cleaned === $pictureurl && preg_match('~^https?://~i', $cleaned)) {
                $picture = $cleaned;
            }
        }
        $this->appearances[$peerid] = ['appearance' => $appearance, 'picture' => $picture];
    }

    /**
     * What the listeners set.
     *
     * @return array<string, array{appearance: array, picture: string}> peerid => look
     */
    public function get_appearances(): array {
        return $this->appearances;
    }
}
