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

namespace mod_mavr\local\xapi;

/**
 * Tests of the IRI helper.
 *
 * @package    mod_mavr
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(iri::class)]
final class iri_test extends \advanced_testcase {

    /**
     * Objects for the round trip.
     *
     * @return array
     */
    public static function objects_provider(): array {
        return [
            'activity' => ['activity', null, null, null, null],
            'scene' => ['scene', 'scene-1', null, 'scene-1', 'scene-1'],
            'zone' => ['zone', 'scene-1', 'z-1', 'scene-1', 'z-1'],
            'object' => ['object', '5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11', 'h_2', '5d1c2c6e-6c2f-4d0e-9a51-7b1d0e9c2a11', 'h_2'],
            'question' => ['question', null, '3', null, '3'],
            'objective' => ['objective', null, 'q-1', null, 'q-1'],
            'hint' => ['hint', null, 'hn-desk-1', null, 'hn-desk-1'],
        ];
    }

    /**
     * for_object() and parse() agree.
     *
     * @param string $type
     * @param string|null $sceneid
     * @param string|null $id
     * @param string|null $expectscene
     * @param string|null $expectid
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('objects_provider')]
    public function test_round_trip(string $type, ?string $sceneid, ?string $id, ?string $expectscene,
            ?string $expectid): void {
        global $CFG;
        $this->resetAfterTest();
        $built = iri::for_object(12, $type, $sceneid, $id);
        $this->assertStringStartsWith($CFG->wwwroot . '/mod/mavr/xapi/12', $built);
        $this->assertSame(['cmid' => 12, 'type' => $type, 'sceneid' => $expectscene, 'id' => $expectid], iri::parse($built));
    }

    /**
     * The documented IRI shapes.
     *
     * @return void
     */
    public function test_shapes(): void {
        global $CFG;
        $act = $CFG->wwwroot . '/mod/mavr/xapi/7';
        $this->assertSame($act, iri::activity(7));
        $this->assertSame($act . '/scene/s/zone/z', iri::for_object(7, 'zone', 's', 'z'));
        $this->assertSame($act . '/scene/s/object/o', iri::for_object(7, 'object', 's', 'o'));
        $this->assertSame($act . '/question/2', iri::for_object(7, 'question', null, '2'));
        $this->assertSame($act . '/objective/v-1', iri::for_object(7, 'objective', null, 'v-1'));
        $this->assertSame($act . '/hint/hn-1', iri::for_object(7, 'hint', null, 'hn-1'));
        $this->assertContains('hint', iri::OBJECT_TYPES);
    }

    /**
     * Anything outside the grammar is refused.
     *
     * @return void
     */
    public function test_parse_rejects(): void {
        global $CFG;
        $act = $CFG->wwwroot . '/mod/mavr/xapi/';
        $bad = [
            'http://elsewhere.example/mod/mavr/xapi/12',
            $act,
            $act . 'abc',
            $act . '012',
            $act . '0',
            $act . '12/',
            $act . '12/scene/',
            $act . '12/scene/a b',
            $act . '12/scene/s/zone/' . str_repeat('x', 65),
            $act . '12/scene/s/thing/z',
            $act . '12/question/q1',
            $act . '12/objective/a/b',
            $act . '12/hint/',
            $act . '12/hint/a b',
            $act . '12/scene/s/hint/h',
            $act . '12/scene/s/zone/z?x=1',
        ];
        foreach ($bad as $value) {
            $this->assertNull(iri::parse($value), $value);
        }
    }

    /**
     * Invalid ids cannot be built.
     *
     * @return void
     */
    public function test_for_object_rejects_bad_ids(): void {
        $this->expectException(\coding_exception::class);
        iri::for_object(1, 'zone', 'scene', 'bad/id');
    }

    /**
     * A hint IRI needs a valid hint id.
     *
     * @return void
     */
    public function test_hint_requires_id(): void {
        $this->expectException(\coding_exception::class);
        iri::for_object(1, 'hint', null, null);
    }

    /**
     * Verb lookups.
     *
     * @return void
     */
    public function test_verbs(): void {
        $this->assertCount(11, iri::VERBS);
        $this->assertSame('https://wisecat.net/xapi/mavr/verbs/requested', iri::verb_iri('requested'));
        $this->assertSame('requested', iri::verb_short('https://wisecat.net/xapi/mavr/verbs/requested'));
        foreach (iri::VERBS as $short => $verbiri) {
            $this->assertSame($verbiri, iri::verb_iri($short));
            $this->assertSame($short, iri::verb_short($verbiri));
        }
        $this->assertNull(iri::verb_short('http://adlnet.gov/expapi/verbs/experienced'));
        foreach (array_keys(iri::CLIENT_VERBS) as $verb) {
            $this->assertArrayHasKey($verb, iri::VERBS);
        }
        $this->assertArrayNotHasKey('answered', iri::CLIENT_VERBS);
        $this->assertArrayNotHasKey('completed', iri::CLIENT_VERBS);
        // Hint requests are asserted by the server only.
        $this->assertArrayNotHasKey('requested', iri::CLIENT_VERBS);
        $this->expectException(\coding_exception::class);
        iri::verb_iri('jumped');
    }
}
