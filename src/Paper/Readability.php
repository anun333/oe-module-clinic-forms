<?php

/**
 * The reading grade level of a document's patient-facing sentences, by the Flesch-Kincaid grade
 * formula: 0.39 × words per sentence + 11.8 × syllables per word − 15.59. Syllables are counted
 * by vowel groups, the usual estimate. Health-literacy guidance (AHRQ's Health Literacy
 * Universal Precautions Toolkit, CDC's Clear Communication Index) asks for patient materials
 * at about a 6th to 8th grade level.
 *
 * Paragraphs and checklist items are measured; labels, headings and blanks are not, since
 * they aren't sentences. Placeholders count as one ordinary word.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Readability
{
    /** @return array{grade: float, words: int, sentences: int} */
    public static function measure(Document $doc): array
    {
        $sentences = [];
        foreach ($doc->blocks as $b) {
            if ($b['type'] === 'paragraph') {
                foreach (preg_split('/(?<=[.!?])\s+/', trim((string)$b['text'])) ?: [] as $s) {
                    $sentences[] = $s;
                }
            } elseif ($b['type'] === 'checklist') {
                foreach ($b['items'] as $item) {
                    $sentences[] = (string)$item;
                }
            }
        }
        $words = 0;
        $syllables = 0;
        foreach ($sentences as $s) {
            $s = (string)preg_replace('/\{[a-z]+\.[a-z]+\}/', 'clinic', $s);
            foreach (preg_split('/[^A-Za-z\']+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $words++;
                $syllables += self::syllables($word);
            }
        }
        $n = count($sentences);
        if ($words === 0 || $n === 0) {
            return ['grade' => 0.0, 'words' => 0, 'sentences' => 0];
        }
        $grade = 0.39 * ($words / $n) + 11.8 * ($syllables / $words) - 15.59;
        return ['grade' => round($grade, 1), 'words' => $words, 'sentences' => $n];
    }

    public static function syllables(string $word): int
    {
        $w = strtolower(trim($word, "'"));
        if (strlen($w) <= 3) {
            return 1;
        }
        $w = (string)preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $w);
        $w = (string)preg_replace('/^y/', '', $w);
        return max(1, preg_match_all('/[aeiouy]{1,2}/', $w));
    }
}
