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

/**
 * Data generator for mod_mavr.
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_mavr\local\attempt\attempt_manager;
use mod_mavr\local\attempt\event_store;

/**
 * Creates MAVR activities, attempts and statements for tests.
 *
 * The template is $record->template: 'gallery' (the default), 'escape' or 'blank'. Any other
 * template gets the record as given.
 *
 * Gallery: stations come from $record->stations (an array of station arrays) or, for Behat
 * tables, from pipe-separated strings: stationtitles ("Vase|Portrait"), stationquestions ("Q1|",
 * question names, empty = none), and optionally stationpoints ("1|2"), stationrequired ("1|0")
 * and stationtexts. Without either, two text-only stations are made.
 *
 * Escape chain: puzzles come from $record->puzzles (an array of puzzle arrays with the keys
 * title, clue, locktype, keyname, questionbankentryid, hints and points) or from pipe-separated
 * strings: puzzletitles ("Desk|Safe"), puzzlelocks ("key|question", also "none"),
 * puzzlequestions ("|Q1", question names), puzzlehints ("Look on the desk.|", one hint per
 * puzzle) and puzzlepoints ("1|1"). Without either, puzzle "Desk" (key) and "Safe" (no lock)
 * are made. timelimit (seconds), hintpenalty and story set the activity-wide fields.
 *
 * Empty room: blank_environment (default gallery-hall).
 *
 * Live mode: livemode (default 0) and livemaxusers (default 6).
 *
 * Everything is turned into the template's own form fields (gallery_*, escape_*, blank_*),
 * exactly as the activity form would submit them.
 */
class mod_mavr_generator extends testing_module_generator {
    /**
     * Create a MAVR activity.
     *
     * @param array|stdClass|null $record data for the module, see the class comment for stations
     * @param array|null $options general options for course module
     * @return stdClass the mavr record with cmid
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;

        $defaults = [
            'runtime' => 'builtin',
            'template' => 'gallery',
            'grade' => 100,
            'grademethod' => 1,
            'maxattempts' => 0,
            'completionobjectives' => 0,
            'livemode' => 0,
            'livemaxusers' => 6,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }

        switch ((string)$record->template) {
            case 'gallery':
                $this->prepare_gallery($record);
                break;
            case 'escape':
                $this->prepare_escape($record);
                break;
            case 'blank':
                if (!isset($record->blank_environment)) {
                    $record->blank_environment = 'gallery-hall';
                }
                break;
        }

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Turn the gallery parts of a record into the gallery template's form fields.
     *
     * @param stdClass $record the generator record, changed in place
     * @return void
     */
    private function prepare_gallery(stdClass $record): void {
        foreach (['gallery_environment' => 'gallery-hall', 'gallery_ordered' => 0] as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }

        $stations = $this->build_stations($record);
        unset(
            $record->stations,
            $record->stationtitles,
            $record->stationquestions,
            $record->stationpoints,
            $record->stationrequired,
            $record->stationtexts
        );

        $record->gallery_stationid = [];
        $record->gallery_title = [];
        $record->gallery_text = [];
        $record->gallery_mediatype = [];
        $record->gallery_question = [];
        $record->gallery_required = [];
        $record->gallery_points = [];
        foreach (array_values($stations) as $i => $station) {
            $record->gallery_stationid[$i] = '';
            $record->gallery_title[$i] = (string)$station['title'];
            $record->gallery_text[$i] = ['text' => (string)$station['text'], 'format' => FORMAT_HTML];
            $record->gallery_mediatype[$i] = (string)$station['mediatype'];
            $record->gallery_question[$i] = (int)$station['questionbankentryid'];
            $record->gallery_required[$i] = empty($station['required']) ? 0 : 1;
            $record->gallery_points[$i] = (float)$station['points'];
        }
        $record->gallery_stationcount = count($stations);
    }

    /**
     * Turn the escape chain parts of a record into the escape template's form fields.
     *
     * @param stdClass $record the generator record, changed in place
     * @return void
     */
    private function prepare_escape(stdClass $record): void {
        $puzzles = $this->build_puzzles($record);

        $record->escape_environment = (string)($record->escape_environment ?? 'escape-room');
        $record->escape_story = (string)($record->escape_story ?? ($record->story ?? ''));
        $record->escape_timelimit = (int)($record->escape_timelimit ?? ($record->timelimit ?? 0));
        $record->escape_hintpenalty = (float)($record->escape_hintpenalty ?? ($record->hintpenalty ?? 0));
        unset(
            $record->puzzles,
            $record->puzzletitles,
            $record->puzzlelocks,
            $record->puzzlequestions,
            $record->puzzlehints,
            $record->puzzlepoints,
            $record->timelimit,
            $record->hintpenalty,
            $record->story
        );

        $fields = ['puzzleid', 'title', 'clue', 'cluemedia', 'clueimage', 'cluemodel', 'locktype', 'keyname',
            'question', 'hint1', 'hint2', 'points'];
        foreach ($fields as $field) {
            $record->{'escape_' . $field} = [];
        }
        foreach (array_values($puzzles) as $i => $puzzle) {
            $hints = array_values($puzzle['hints']);
            $record->escape_puzzleid[$i] = '';
            $record->escape_title[$i] = (string)$puzzle['title'];
            $record->escape_clue[$i] = ['text' => (string)$puzzle['clue'], 'format' => FORMAT_HTML];
            $record->escape_cluemedia[$i] = 'none';
            $record->escape_clueimage[$i] = 0;
            $record->escape_cluemodel[$i] = 0;
            $record->escape_locktype[$i] = (string)$puzzle['locktype'];
            $record->escape_keyname[$i] = (string)$puzzle['keyname'];
            $record->escape_question[$i] = (int)$puzzle['questionbankentryid'];
            $record->escape_hint1[$i] = (string)($hints[0] ?? '');
            $record->escape_hint2[$i] = (string)($hints[1] ?? '');
            $record->escape_points[$i] = (float)$puzzle['points'];
        }
        $record->escape_puzzlecount = count($puzzles);
    }

    /**
     * The puzzles of an escape record as arrays with every key set.
     *
     * @param stdClass $record the generator record
     * @return array[]
     */
    private function build_puzzles(stdClass $record): array {
        $puzzles = [];
        if (!empty($record->puzzles) && is_array($record->puzzles)) {
            foreach ($record->puzzles as $i => $puzzle) {
                $puzzles[] = $this->complete_puzzle((array)$puzzle, (int)$i);
            }
            return $puzzles;
        }

        if (isset($record->puzzletitles) && trim((string)$record->puzzletitles) !== '') {
            $titles = self::split((string)$record->puzzletitles);
            $locks = self::split((string)($record->puzzlelocks ?? ''));
            $questions = self::split((string)($record->puzzlequestions ?? ''));
            $hints = self::split((string)($record->puzzlehints ?? ''));
            $points = self::split((string)($record->puzzlepoints ?? ''));
            foreach ($titles as $i => $title) {
                $puzzle = ['title' => $title];
                if (($locks[$i] ?? '') !== '') {
                    $puzzle['locktype'] = $locks[$i];
                }
                $questionname = $questions[$i] ?? '';
                if ($questionname !== '') {
                    $puzzle['questionbankentryid'] = $this->get_question_bank_entry_id($questionname);
                    if (!isset($puzzle['locktype'])) {
                        $puzzle['locktype'] = 'question';
                    }
                }
                if (($hints[$i] ?? '') !== '') {
                    $puzzle['hints'] = [$hints[$i]];
                }
                if (($points[$i] ?? '') !== '') {
                    $puzzle['points'] = (float)$points[$i];
                }
                $puzzles[] = $this->complete_puzzle($puzzle, $i);
            }
            return $puzzles;
        }

        return [
            $this->complete_puzzle(['title' => 'Desk', 'locktype' => 'key'], 0),
            $this->complete_puzzle(['title' => 'Safe', 'locktype' => 'none'], 1),
        ];
    }

    /**
     * Fill the missing keys of a puzzle with defaults.
     *
     * @param array $puzzle partial puzzle
     * @param int $index position (for the default title)
     * @return array
     */
    private function complete_puzzle(array $puzzle, int $index): array {
        $title = (string)($puzzle['title'] ?? ('Puzzle ' . ($index + 1)));
        $hints = [];
        foreach ((array)($puzzle['hints'] ?? []) as $hint) {
            if (trim((string)$hint) !== '') {
                $hints[] = (string)$hint;
            }
        }
        return [
            'title' => $title,
            'clue' => (string)($puzzle['clue'] ?? '<p>A clue at ' . s($title) . '.</p>'),
            'locktype' => (string)($puzzle['locktype'] ?? 'key'),
            'keyname' => (string)($puzzle['keyname'] ?? 'Key'),
            'questionbankentryid' => (int)($puzzle['questionbankentryid'] ?? 0),
            'hints' => array_slice($hints, 0, 2),
            'points' => (float)($puzzle['points'] ?? 1),
        ];
    }

    /**
     * Start (or resume) an attempt of a user.
     *
     * @param int $mavrid mavr id
     * @param int $userid user id
     * @return stdClass the attempt row
     */
    public function create_attempt(int $mavrid, int $userid): stdClass {
        global $DB;
        $mavr = $DB->get_record('mavr', ['id' => $mavrid], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_instance($mavr->id, 'mavr', $mavr->course);
        [$attempt] = attempt_manager::start_or_resume($cm, $mavr, $userid);
        return $attempt;
    }

    /**
     * Store a statement for an attempt (as if accepted from the browser) and recompute it.
     *
     * @param stdClass $attempt attempt row (updated in place by the recompute)
     * @param string $verb short verb, e.g. 'entered'
     * @param string $type object type, e.g. 'zone'
     * @param string|null $sceneid scene id
     * @param string|null $id object id
     * @param bool $clientasserted whether the browser reported it
     * @param array|null $result server result (e.g. ['fraction' => 1.0] for 'answered')
     * @return int the new mavr_events id
     */
    public function record_event(
        stdClass $attempt,
        string $verb,
        string $type,
        ?string $sceneid,
        ?string $id,
        bool $clientasserted = true,
        ?array $result = null
    ): int {
        $eventid = event_store::insert(
            (int)$attempt->id,
            strtolower(\core\uuid::generate()),
            ['verb' => $verb, 'type' => $type, 'id' => $id, 'scene' => $sceneid, 'fraction' => null],
            $clientasserted,
            $result,
            time()
        );
        attempt_manager::recompute($attempt);
        return $eventid;
    }

    /**
     * The stations of a record as arrays with every key set.
     *
     * @param stdClass $record the generator record
     * @return array[]
     */
    private function build_stations(stdClass $record): array {
        $stations = [];
        if (!empty($record->stations) && is_array($record->stations)) {
            foreach ($record->stations as $i => $station) {
                $stations[] = $this->complete_station((array)$station, (int)$i);
            }
            return $stations;
        }

        if (isset($record->stationtitles) && trim((string)$record->stationtitles) !== '') {
            $titles = self::split((string)$record->stationtitles);
            $questions = self::split((string)($record->stationquestions ?? ''));
            $points = self::split((string)($record->stationpoints ?? ''));
            $required = self::split((string)($record->stationrequired ?? ''));
            $texts = self::split((string)($record->stationtexts ?? ''));
            foreach ($titles as $i => $title) {
                $station = ['title' => $title];
                $questionname = $questions[$i] ?? '';
                if ($questionname !== '') {
                    $station['questionbankentryid'] = $this->get_question_bank_entry_id($questionname);
                }
                if (($points[$i] ?? '') !== '') {
                    $station['points'] = (float)$points[$i];
                }
                if (($required[$i] ?? '') !== '') {
                    $station['required'] = (int)$required[$i];
                }
                if (($texts[$i] ?? '') !== '') {
                    $station['text'] = $texts[$i];
                }
                $stations[] = $this->complete_station($station, $i);
            }
            return $stations;
        }

        return [
            $this->complete_station(['title' => 'Station 1'], 0),
            $this->complete_station(['title' => 'Station 2'], 1),
        ];
    }

    /**
     * Fill the missing keys of a station with defaults.
     *
     * @param array $station partial station
     * @param int $index position (for the default title)
     * @return array
     */
    private function complete_station(array $station, int $index): array {
        $title = (string)($station['title'] ?? ('Station ' . ($index + 1)));
        return [
            'title' => $title,
            'text' => (string)($station['text'] ?? '<p>About ' . s($title) . '.</p>'),
            'questionbankentryid' => (int)($station['questionbankentryid'] ?? 0),
            'required' => array_key_exists('required', $station) ? (int)!empty($station['required']) : 1,
            'points' => (float)($station['points'] ?? 1),
            'mediatype' => (string)($station['mediatype'] ?? 'none'),
        ];
    }

    /**
     * The question bank entry of the latest version of a question with this name.
     *
     * @param string $name question name
     * @return int question_bank_entries id
     * @throws coding_exception when there is no such question
     */
    private function get_question_bank_entry_id(string $name): int {
        global $DB;
        $sql = "SELECT qv.questionbankentryid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                 WHERE q.name = :name
              ORDER BY qv.version DESC, qv.id DESC";
        $records = $DB->get_records_sql($sql, ['name' => $name], 0, 1);
        if (!$records) {
            throw new coding_exception('There is no question named "' . $name . '".');
        }
        return (int)reset($records)->questionbankentryid;
    }

    /**
     * Split a pipe-separated Behat value, trimming each part.
     *
     * @param string $value the value
     * @return string[]
     */
    private static function split(string $value): array {
        if ($value === '') {
            return [];
        }
        return array_map('trim', explode('|', $value));
    }
}
