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

namespace mod_vrroom\local\questions;

/**
 * A plain-text view of a question for the in-world panels of immersive VR.
 *
 * In an immersive session the page DOM is not visible, so the question engine's HTML cannot be
 * shown. For true/false and multiple-choice questions this class builds a small structured view
 * instead: the question text and the choice labels as plain text, and for each choice the field
 * name and value the question engine expects (truefalse and single-answer multichoice post
 * "answer", the index into the attempt's choice order for multichoice; multiple-answer
 * multichoice posts "choice<key>" = 1). The response then goes through the normal submit
 * service, so the question engine grades exactly as it does for the HTML panel.
 *
 * The view never carries the right answer, the fractions of the choices or any feedback text.
 * Questions whose text or choices hold images, media, embedded content or TeX are reported as
 * unsupported, because the plain-text view would lose part of the question.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bridge {
    /** @var string[] Question types the in-world panel can show. */
    public const QTYPES = ['truefalse', 'multichoice'];

    /** @var int Maximum length of the question text in characters. */
    public const MAX_TEXT = 2000;

    /** @var int Maximum length of a choice label in characters. */
    public const MAX_LABEL = 300;

    /** @var string HTML elements whose content a plain-text view cannot show. */
    private const MEDIA_PATTERN = '~<\s*(img|video|audio|iframe|object|embed|svg|math|canvas)\b~i';

    /** @var string TeX delimiters ($$, \(, \[ and the [tex] filter syntax). */
    private const TEX_PATTERN = '~\$\$|\\\\\(|\\\\\[|\[tex\]~i';

    /**
     * Build the in-world view of one question of an attempt.
     *
     * The caller has checked that the attempt is the user's own open attempt and that the slot's
     * objective is not locked.
     *
     * @param \stdClass $attempt The vrroom_attempts record (uniqueid, slotmap).
     * @param int $graphslot The graph slot number.
     * @param \context_module $context The activity's module context.
     * @param bool $answered Whether the slot's objective is completed.
     * @return array The view: slot, supported, qtype, multiple, questiontext, choices, answered, state, fraction.
     */
    public static function get_view(\stdClass $attempt, int $graphslot, \context_module $context, bool $answered): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $qa = usage::get_question_attempt($attempt, $graphslot);
        $question = $qa->get_question();
        $qtype = (string)$question->get_type_name();
        $fraction = $answered ? $qa->get_fraction() : null;

        $view = [
            'slot' => $graphslot,
            'supported' => false,
            'qtype' => $qtype,
            'multiple' => false,
            'questiontext' => '',
            'choices' => [],
            'answered' => $answered,
            'state' => (string)$qa->get_state(),
            'fraction' => $fraction === null ? null : (float)$fraction,
        ];

        if (!in_array($qtype, self::QTYPES, true)) {
            return $view;
        }

        $text = self::to_plain($question->format_questiontext($qa), self::MAX_TEXT);
        if ($text === null) {
            return $view;
        }

        $prefix = $qa->get_field_prefix();
        if ($qtype === 'truefalse') {
            $multiple = false;
            $choices = [
                ['name' => $prefix . 'answer', 'value' => '1', 'label' => get_string('true', 'qtype_truefalse')],
                ['name' => $prefix . 'answer', 'value' => '0', 'label' => get_string('false', 'qtype_truefalse')],
            ];
        } else {
            $choices = self::multichoice_choices($qa, $question, $prefix);
            if ($choices === null) {
                return $view;
            }
            $multiple = $question instanceof \qtype_multichoice_multi_question;
        }

        $view['supported'] = true;
        $view['multiple'] = $multiple;
        $view['questiontext'] = $text;
        $view['choices'] = $choices;
        return $view;
    }

    /**
     * Reduce formatted HTML to one line of plain text.
     *
     * @param string|null $html HTML as formatted for output (filters applied).
     * @param int $max Maximum length in characters; longer text is cut and ends with an ellipsis.
     * @return string|null The plain text, or null when the HTML holds content that plain text cannot show.
     */
    public static function to_plain(?string $html, int $max): ?string {
        if ($html === null || $html === '') {
            return '';
        }
        if (preg_match(self::MEDIA_PATTERN, $html) || preg_match(self::TEX_PATTERN, $html)) {
            return null;
        }
        $text = html_to_text($html, 0, false);
        $text = trim((string)preg_replace('~[\s\x{00A0}]+~u', ' ', $text));
        if ($max > 0 && \core_text::strlen($text) > $max) {
            $text = rtrim(\core_text::substr($text, 0, max(0, $max - 1))) . '…';
        }
        return $text;
    }

    /**
     * The choices of a multiple-choice question in the attempt's own order.
     *
     * @param \question_attempt $qa The question attempt.
     * @param \question_definition $question Its question (single or multiple answer).
     * @param string $prefix The attempt's field prefix.
     * @return array[]|null The choices, or null when one of them cannot be shown as plain text.
     */
    private static function multichoice_choices(
        \question_attempt $qa,
        \question_definition $question,
        string $prefix
    ): ?array {
        $single = $question instanceof \qtype_multichoice_single_question;
        $multi = $question instanceof \qtype_multichoice_multi_question;
        if (!$single && !$multi) {
            return null;
        }
        $choices = [];
        foreach ($question->get_order($qa) as $key => $ansid) {
            if (!isset($question->answers[$ansid])) {
                return null;
            }
            $answer = $question->answers[$ansid];
            $label = self::to_plain($question->format_text(
                $answer->answer,
                $answer->answerformat,
                $qa,
                'question',
                'answer',
                $ansid
            ), self::MAX_LABEL);
            if ($label === null) {
                return null;
            }
            $choices[] = $single
                ? ['name' => $prefix . 'answer', 'value' => (string)$key, 'label' => $label]
                : ['name' => $prefix . 'choice' . $key, 'value' => '1', 'label' => $label];
        }
        return $choices ?: null;
    }
}
