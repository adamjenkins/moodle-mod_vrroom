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

/**
 * Tests for the "beam in" timeline (runtime/builtin/amd/src/live/spawnflash.js).
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {timeline, FLASH_MS} from '../../runtime/builtin/amd/src/live/spawnflash.js';

test('the light pulses once: it rises, holds and fades, never flickering', () => {
    let peak = 0;
    let falling = false;
    let previous = 0;
    for (let t = 0; t <= FLASH_MS; t += 10) {
        const opacity = timeline(t, false).column.opacity;
        assert.ok(opacity >= 0 && opacity <= 0.65 + 1e-9, `opacity ${opacity} at ${t} ms`);
        if (opacity < previous - 1e-9) {
            falling = true;
        } else if (falling) {
            assert.ok(opacity <= previous + 1e-9, `the light rose again at ${t} ms`);
        }
        peak = Math.max(peak, opacity);
        previous = opacity;
    }
    assert.ok(peak > 0.5);
    assert.equal(timeline(FLASH_MS, false).column.opacity, 0);
});

test('the avatar grows from a sliver to full size and the effect ends', () => {
    assert.ok(timeline(0, false).avatar.y < 0.1);
    assert.equal(timeline(FLASH_MS, false).avatar.y, 1);
    assert.equal(timeline(FLASH_MS, false).avatar.xz, 1);
    assert.equal(timeline(FLASH_MS - 1, false).done, false);
    assert.equal(timeline(FLASH_MS, false).done, true);
    assert.ok(timeline(1000, false).ring.scale > timeline(100, false).ring.scale);
});

test('with reduced motion there is no light and the avatar is there at once', () => {
    const state = timeline(0, true);
    assert.equal(state.done, true);
    assert.equal(state.column.opacity, 0);
    assert.equal(state.ring.opacity, 0);
    assert.deepEqual(state.avatar, {xz: 1, y: 1});
});
