<?php

/**
 * A validated instrument's reference (data/instruments/<loinc>.json) and the check of a document
 * against it. A score means what its validation study says only when the questions, their order,
 * the answers and the points for each are the instrument's, and the cut-offs printed beside it are
 * the published ones; so those are what is checked, not the wording alone.
 *
 * A reference holds, from LOINC (tools/instrument-references.php fetches them), or written by hand
 * from the owner's instrument where LOINC's is incomplete ("questions_from" says whose and why):
 *   "questions": [{"code": "44250-9", "text": "...", "answers": [{"code": "LA6568-5", "text": "Not at all", "score": 0}]}]
 *   "total": {"min": 0, "max": 27}
 * and, written by hand with their source:
 *   "cutoffs": [{"score": 10, "meaning": "moderate depression", "for": "men"}], "cutoffs_source": {"citation": ..., "pmid": ..., "stated": ...}
 *   "accepted": {"questions": {"2": "why our wording knowingly differs from LOINC's"},
 *                "extra": {"8": "why the document asks an unscored question LOINC's panel doesn't have"}}
 * Question numbers in "questions" count the instrument's questions; in "extra", the document's.
 *
 * A screen read by rule rather than by a total (the ASQ: "Yes" to any of questions 1 to 4 is a
 * positive screen) has its published rules in place of cut-offs, by LOINC question code:
 *   "rules": [{"if": "any", "questions": ["115566-2", ...], "answers": ["Yes"], "meaning": "positive screen"}],
 *   "rules_source": {"citation": ..., "url": ..., "stated": ...}
 * and the document must say each one: its meaning, in a paragraph that names its questions by the
 * numbers the document prints for them.
 *
 * An instrument scored in parts (TAPS-2 scores each substance on its own) has subscales, each with
 * its questions, any of them only one of which a person answers (alcohol: 4 or more drinks for
 * women, 5 or more for men), the line the document gives its score, and its published cut-offs:
 *   "subscales": [{"name": "Alcohol", "questions": [...], "one_of": [["96850-3", "96851-1"]],
 *                  "field": "Alcohol score", "cutoffs": [{"score": 1, "meaning": "problem use"}]}],
 *   "subscales_source": {"citation": ..., "pmid": ..., "stated": ...}
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Instrument
{
    private const STOP = ['a', 'an', 'and', 'or', 'the', 'of', 'to', 'in', 'on', 'for', 'you', 'your', 'have', 'been', 'by', 'with', 'do', 'did', 'is', 'are'];
    private const NUMBERS = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/data/instruments';
    }

    /** The reference for a LOINC code, or null when there is none. */
    public static function reference(string $loinc): ?array
    {
        $file = self::dir() . '/' . $loinc . '.json';
        if (!Loinc::isValid($loinc) || !is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * The document's questions in order: each scale row, and each checklist with a label. A
     * checklist answer's points are written after it, "Never (0)". Each has the number the page
     * prints for it: a scale numbers its rows from 1, a checklist its label ("5. Are you…"), or none.
     *
     * @return list<array{text: string, number: ?int, answers: list<array{text: string, score: ?float}>}>
     */
    public static function questions(Document $doc): array
    {
        $out = [];
        foreach ($doc->blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'scale') {
                foreach ($block['items'] ?? [] as $n => $item) {
                    $answers = [];
                    foreach ($block['columns'] ?? [] as $i => $column) {
                        // A scale scores its columns the same for every question, or each question its own (item_scores).
                        $score = $block['item_scores'][$n][$i] ?? $block['scores'][$i] ?? null;
                        $answers[] = ['text' => (string)$column, 'score' => is_numeric($score) ? (float)$score : null];
                    }
                    $out[] = ['text' => self::unnumbered((string)$item), 'number' => $n + 1, 'answers' => $answers];
                }
            } elseif ($type === 'checklist' && ($block['label'] ?? '') !== '') {
                $answers = [];
                foreach ($block['items'] ?? [] as $item) {
                    $answers[] = preg_match('/^(.*?)\s*\((\d+(?:\.\d+)?)\)\s*$/u', (string)$item, $m) === 1
                        ? ['text' => $m[1], 'score' => (float)$m[2]]
                        : ['text' => (string)$item, 'score' => null];
                }
                $label = (string)$block['label'];
                $out[] = ['text' => self::unnumbered($label), 'number' => preg_match('/^\s*(\d+)[.)]\s/', $label, $m) === 1 ? (int)$m[1] : null, 'answers' => $answers];
            }
        }
        return $out;
    }

    /** @return array{min: float, max: float} the lowest and highest total of the scored questions */
    public static function totalRange(array $questions): array
    {
        $min = $max = 0.0;
        foreach ($questions as $q) {
            $scores = array_values(array_filter(array_column($q['answers'], 'score'), static fn($s): bool => $s !== null));
            if ($scores !== [] && count($scores) === count($q['answers'])) {
                $min += min($scores);
                $max += max($scores);
            }
        }
        return ['min' => $min, 'max' => $max];
    }

    /**
     * Where the document departs from its instrument, each in words a reviewer can act on. Empty
     * when it matches.
     *
     * @return list<string>
     */
    public static function differences(Document $doc, array $reference): array
    {
        $problems = [];
        $ours = self::questions($doc);
        $theirs = $reference['questions'] ?? [];
        $accepted = $reference['accepted']['questions'] ?? [];
        // An answer the printed form words differently from LOINC's list (Pfizer's PHQ-15 prints "Not
        // bothered at all" where LOINC has "Not bothered") is allowed when listed with LOINC's text and why:
        // "answers": {"<printed>": {"instead_of": "<LOINC's>", "why": "..."}}.
        $acceptedAnswers = $reference['accepted']['answers'] ?? [];
        // A question the printed form asks that LOINC's panel doesn't code (GAD-7's closing "how
        // difficult…") is allowed when it is listed with its reason and carries no score.
        foreach ($reference['accepted']['extra'] ?? [] as $n => $why) {
            $q = $ours[(int)$n - 1] ?? null;
            if ($q === null || trim((string)$why) === '') {
                $problems[] = "the reference accepts an extra question $n that the document doesn't have";
            } elseif (array_filter(array_column($q['answers'], 'score'), static fn($s): bool => $s !== null) !== []) {
                $problems[] = "question $n, \"{$q['text']}\", is extra to the instrument and scored, which changes the total";
            }
        }
        $answerProblems = [];  // what differs => the question numbers it differs in
        $extra = array_map('intval', array_keys($reference['accepted']['extra'] ?? []));
        $ours = array_values(array_filter($ours, static fn(int $i): bool => !in_array($i + 1, $extra, true), ARRAY_FILTER_USE_KEY));
        if (count($ours) !== count($theirs)) {
            $problems[] = sprintf('%d questions where the instrument has %d', count($ours), count($theirs));
        }
        foreach ($theirs as $i => $ref) {
            $n = $i + 1;
            $q = $ours[$i] ?? null;
            if ($q === null) {
                $problems[] = "question $n is missing: \"{$ref['text']}\"";
                continue;
            }
            if (!self::same($q['text'], (string)$ref['text']) && trim((string)($accepted[(string)$n] ?? '')) === '') {
                $problems[] = "question $n is \"{$q['text']}\" where the instrument has \"{$ref['text']}\" ({$ref['code']})";
            }
            $refAnswers = $ref['answers'] ?? [];
            if (count($q['answers']) !== count($refAnswers)) {
                $problems[] = sprintf('question %d has %d answers where the instrument has %d (%s)', $n, count($q['answers']), count($refAnswers),
                    implode(', ', array_map(static fn(array $a): string => self::answerLabel($a), $refAnswers)));
                continue;
            }
            foreach ($refAnswers as $j => $refAnswer) {
                $a = $q['answers'][$j];
                $insteadOf = $acceptedAnswers[$a['text']]['instead_of'] ?? null;
                if (!self::same($a['text'], (string)$refAnswer['text']) && $insteadOf !== $refAnswer['text']) {
                    $answerProblems[sprintf('answer %d is "%s" where the instrument has "%s"', $j + 1, $a['text'], $refAnswer['text'])][] = $n;
                }
                $refScore = isset($refAnswer['score']) && is_numeric($refAnswer['score']) ? (float)$refAnswer['score'] : null;
                if ($refScore !== $a['score']) {
                    $answerProblems[sprintf('"%s" scores %s where the instrument scores %s', $a['text'], self::points($a['score']), self::points($refScore))][] = $n;
                }
            }
        }
        // One changed column of a scale differs in every row: say it once, for the questions it affects.
        foreach ($answerProblems as $what => $numbers) {
            $problems[] = self::questionNumbers($numbers) . ', ' . $what;
        }
        $total = self::totalRange($ours);
        $refTotal = $reference['total'] ?? null;
        if (is_array($refTotal) && ($total['min'] !== (float)$refTotal['min'] || $total['max'] !== (float)$refTotal['max'])) {
            $problems[] = sprintf('the total runs %s to %s where the instrument runs %s to %s', self::points($total['min']), self::points($total['max']),
                self::points((float)$refTotal['min']), self::points((float)$refTotal['max']));
        }
        if (preg_match('/scored (\d+) to (\d+)/', $doc->about, $m) === 1 && ((float)$m[1] !== $total['min'] || (float)$m[2] !== $total['max'])) {
            $problems[] = "the description says \"scored $m[1] to $m[2]\" but the questions total " . self::points($total['min']) . ' to ' . self::points($total['max']);
        }
        foreach ($reference['subscales'] ?? [] as $subscale) {
            $problem = self::subscaleProblem($subscale, $theirs, $ours);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        $cutoffs = array_merge($reference['cutoffs'] ?? [], ...array_map(static fn(array $s): array => $s['cutoffs'] ?? [], $reference['subscales'] ?? [[]]));
        $published = array_map(static fn(array $c): float => (float)$c['score'], $cutoffs);
        foreach (self::printedCutoffs($doc) as $cut) {
            if (!in_array($cut, $published, true)) {
                $problems[] = 'it prints a cut-off of ' . self::points($cut) . ' where the published ones are '
                    . ($published === [] ? 'not recorded in the reference' : implode(', ', array_map([self::class, 'points'], array_unique($published))));
            }
        }
        foreach ($reference['rules'] ?? [] as $rule) {
            $problem = self::ruleProblem($rule, $theirs, $ours, $doc);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        return $problems;
    }

    /**
     * A subscale's range on the page against the instrument's: its questions found by code in the
     * reference, and the document's question in the same place.
     */
    private static function subscaleProblem(array $subscale, array $theirs, array $ours): ?string
    {
        $codes = array_column($theirs, 'code');
        $mine = [];
        $refs = [];
        foreach ($subscale['questions'] ?? [] as $code) {
            $i = array_search((string)$code, $codes, true);
            if ($i === false) {
                return "the reference's subscale \"{$subscale['name']}\" names $code, which isn't one of the instrument's questions";
            }
            $refs[(string)$code] = $theirs[$i]['answers'];
            $mine[(string)$code] = $ours[$i]['answers'] ?? [];
        }
        $range = self::subscaleRange($mine, $subscale['one_of'] ?? []);
        $refRange = self::subscaleRange($refs, $subscale['one_of'] ?? []);
        if ($range !== $refRange) {
            return sprintf('the %s score runs %s to %s where the instrument\'s runs %s to %s', $subscale['name'], self::points($range['min']),
                self::points($range['max']), self::points($refRange['min']), self::points($refRange['max']));
        }
        return null;
    }

    /**
     * The lowest and highest score of a subscale: its questions' points added up, counting only the
     * higher of each group a person answers just one of.
     *
     * @param array<string, list<array{score: mixed}>> $answers question code => its answers
     * @param list<list<string>> $oneOf
     * @return array{min: float, max: float}
     */
    public static function subscaleRange(array $answers, array $oneOf = []): array
    {
        $range = static function (array $list): array {
            $scores = array_map('floatval', array_filter(array_column($list, 'score'), 'is_numeric'));
            return $scores === [] ? [0.0, 0.0] : [min($scores), max($scores)];
        };
        $grouped = array_merge(...array_map(static fn(array $g): array => array_map('strval', $g), $oneOf ?: [[]]));
        $min = $max = 0.0;
        foreach ($answers as $code => $list) {
            if (!in_array((string)$code, $grouped, true)) {
                [$lo, $hi] = $range($list);
                $min += $lo;
                $max += $hi;
            }
        }
        foreach ($oneOf as $group) {
            $ranges = array_map(static fn(string $c): array => $range($answers[$c] ?? []), array_map('strval', $group));
            $min += min(array_column($ranges, 0));
            $max += max(array_column($ranges, 1));
        }
        return ['min' => $min, 'max' => $max];
    }

    /**
     * Whether the document says a published rule: a paragraph with the rule's meaning ("positive
     * screen", but not as part of "non-acute positive screen") that names the rule's questions by
     * the document's numbers ("questions 1 to 4", "Question #5"). A rule over every question needs
     * only its meaning.
     */
    private static function ruleProblem(array $rule, array $theirs, array $ours, Document $doc): ?string
    {
        $meaning = trim((string)($rule['meaning'] ?? ''));
        $codes = array_column($theirs, 'code');
        $numbers = [];
        foreach ($rule['questions'] ?? [] as $code) {
            $i = array_search((string)$code, $codes, true);
            if ($i === false) {
                return "the reference's rule for \"$meaning\" names $code, which isn't one of the instrument's questions";
            }
            $numbers[] = $ours[$i]['number'] ?? null;
        }
        $every = count($numbers) === count($theirs);
        $said = '/(?<![\p{L}-])' . preg_quote(mb_strtolower($meaning), '/') . '(?!\p{L})/u';
        foreach (self::paragraphs($doc) as $p) {
            if ($meaning !== '' && preg_match($said, mb_strtolower($p)) === 1
                && ($every || in_array(null, $numbers, true) || self::namesQuestions($p, $numbers))) {
                return null;
            }
        }
        $which = $every ? 'any question' : (in_array(null, $numbers, true) ? 'its questions' : self::questionNumbers(array_values(array_unique($numbers))));
        return sprintf('it doesn\'t say the published rule "%s to %s%s: %s"', implode(' or ', $rule['answers'] ?? []),
            ($rule['if'] ?? 'any') === 'all' && count($numbers) > 1 ? 'all of ' : (count($numbers) > 1 ? 'any of ' : ''), $which, $meaning);
    }

    /** A paragraph names these question numbers: "questions 1 to 4", "#1–#4", "question 5", "#5", or each in a list. */
    private static function namesQuestions(string $p, array $numbers): bool
    {
        $n = array_values(array_unique($numbers));
        sort($n);
        if (count($n) === 1) {
            return preg_match('/(?:question|pregunta)s?\s*#?' . $n[0] . '(?!\d)|#' . $n[0] . '(?!\d)/iu', $p) === 1;
        }
        if ($n === range($n[0], $n[0] + count($n) - 1)
            && preg_match('/(?<!\d)#?' . $n[0] . '\s*(?:to|through|thru|a|al|-|–|—)\s*#?' . end($n) . '(?!\d)/iu', $p) === 1) {
            return true;
        }
        foreach ($n as $x) {
            if (preg_match('/(?<!\d)#?' . $x . '(?!\d)/u', $p) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> the document's description and its paragraphs */
    private static function paragraphs(Document $doc): array
    {
        $out = [$doc->about];
        foreach ($doc->blocks as $block) {
            if (($block['type'] ?? '') === 'paragraph') {
                $out[] = (string)($block['text'] ?? '');
            }
        }
        return $out;
    }

    /**
     * The instrument's question for each of the document's questions, by the document's question
     * number (1 for the first scale row or labelled checklist), when the document is the instrument:
     * a validated document whose check against its reference finds nothing, or a translation of one
     * that keeps it (checked through its English document). Questions the reference accepts as
     * extra have none. Null when the document isn't its instrument, so an export claims no codes.
     *
     * @return ?array{reference: array, map: array<int, array>}
     */
    public static function matched(Document $doc, ?Document $english = null): ?array
    {
        $checked = $doc->language !== 'en' ? $english : $doc;
        if ($checked === null || !$checked->validated || ($checked->code['system'] ?? '') !== Loinc::SYSTEM) {
            return null;
        }
        $reference = self::reference((string)$checked->code['code']);
        if ($reference === null || self::differences($checked, $reference) !== []
            || ($checked !== $doc && self::translationDifferences($checked, $doc) !== [])) {
            return null;
        }
        $extra = array_map('intval', array_keys($reference['accepted']['extra'] ?? []));
        $map = [];
        $next = 0;
        foreach (array_keys(self::questions($doc)) as $i) {
            if (!in_array($i + 1, $extra, true) && isset($reference['questions'][$next])) {
                $map[$i + 1] = $reference['questions'][$next++];
            }
        }
        return ['reference' => $reference, 'map' => $map];
    }

    /**
     * A clinic's own version of a validated instrument, checked like the shipped one: its own file
     * with a shipped instrument's id (a copy of the PHQ-9 is still the PHQ-9, whether or not it says
     * "validated"), or its own document marked validated with a LOINC code there is a reference for.
     * Empty for shipped documents and for anything there is no reference to check against.
     *
     * @return list<string>
     */
    public static function localDifferences(Document $doc, ?Document $shipped = null): array
    {
        if ($doc->source !== 'clinic') {
            return [];
        }
        $validated = $doc->validated || ($shipped?->validated ?? false);
        $code = ($shipped?->validated ?? false) ? $shipped->code : $doc->code;
        if (!$validated || ($code['system'] ?? '') !== Loinc::SYSTEM) {
            return [];
        }
        $reference = self::reference((string)($code['code'] ?? ''));
        return $reference === null ? [] : self::differences($doc, $reference);
    }

    /**
     * A translation must keep its English document's instrument: the same number of questions and
     * answers, the same points, and the same numbers in its text (ranges, cut-offs, question numbers).
     *
     * @return list<string>
     */
    public static function translationDifferences(Document $english, Document $translation): array
    {
        $problems = [];
        $en = self::questions($english);
        $tr = self::questions($translation);
        if (count($en) !== count($tr)) {
            $problems[] = sprintf('%d questions where the English has %d', count($tr), count($en));
        }
        foreach ($en as $i => $q) {
            $t = $tr[$i] ?? null;
            if ($t === null) {
                continue;
            }
            if (count($t['answers']) !== count($q['answers'])) {
                $problems[] = sprintf('question %d has %d answers where the English has %d', $i + 1, count($t['answers']), count($q['answers']));
                continue;
            }
            if (array_column($t['answers'], 'score') !== array_column($q['answers'], 'score')) {
                $problems[] = sprintf('question %d scores %s where the English scores %s', $i + 1,
                    implode('/', array_map([self::class, 'points'], array_column($t['answers'], 'score'))), implode('/', array_map([self::class, 'points'], array_column($q['answers'], 'score'))));
            }
        }
        if (self::numbers($translation) !== self::numbers($english)) {
            $problems[] = 'its text has the numbers ' . implode(', ', self::numbers($translation)) . ' where the English has ' . implode(', ', self::numbers($english));
        }
        return $problems;
    }

    /** @return list<float> the cut-offs a document prints: "3 or more", "3 o más", "≥ 3" */
    public static function printedCutoffs(Document $doc): array
    {
        $text = $doc->about;
        foreach ($doc->blocks as $block) {
            if (($block['type'] ?? '') === 'paragraph') {
                $text .= "\n" . ($block['text'] ?? '');
            }
        }
        preg_match_all('/(\d+)\s+(?:or more|or higher|or above|o más|ou plus)|≥\s*(\d+)/u', $text, $m, PREG_SET_ORDER);
        return array_values(array_unique(array_map(static fn(array $x): float => (float)($x[1] !== '' ? $x[1] : $x[2]), $m), SORT_REGULAR));
    }

    /** Same wording: word for word both ways (85%), or nearly letter for letter. */
    public static function same(string $a, string $b): bool
    {
        $x = self::words($a);
        $y = self::words($b);
        if ($x === [] || $y === []) {
            return $x === $y;
        }
        $shared = count(array_intersect($x, $y));
        if (min($shared / count($x), $shared / count($y)) >= 0.85) {
            return true;
        }
        similar_text(self::plain($a), self::plain($b), $percent);
        return $percent >= 92.0;
    }

    /** @return list<string> content words, cut to four letters so "drinks" meets "drink" */
    private static function words(string $s): array
    {
        $words = [];
        foreach (preg_split('/\s+/', self::plain($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (!in_array($w, self::STOP, true)) {
                $words[] = mb_substr($w, 0, 4);
            }
        }
        return array_values(array_unique($words));
    }

    private static function plain(string $s): string
    {
        $s = mb_strtolower(strip_tags($s));
        $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = implode(' ', array_map(static fn(string $w): string => self::NUMBERS[$w] ?? $w, explode(' ', trim($s))));
        return $s;
    }

    private static function unnumbered(string $s): string
    {
        return trim((string)preg_replace('/^\s*\d+[.)]\s+/', '', $s));
    }

    /** @return list<string> */
    private static function numbers(Document $doc): array
    {
        $text = $doc->about;
        foreach ($doc->blocks as $block) {
            if (($block['type'] ?? '') === 'paragraph') {
                $text .= ' ' . ($block['text'] ?? '');
            }
        }
        preg_match_all('/\d+/', $text, $m);
        $n = $m[0];
        sort($n);
        return $n;
    }

    /** "question 3", "questions 1 to 9", "questions 2, 5 and 7" */
    private static function questionNumbers(array $n): string
    {
        if (count($n) === 1) {
            return "question $n[0]";
        }
        if ($n === range($n[0], $n[0] + count($n) - 1)) {
            return "questions $n[0] to " . end($n);
        }
        $last = array_pop($n);
        return 'questions ' . implode(', ', $n) . " and $last";
    }

    private static function answerLabel(array $a): string
    {
        return $a['text'] . (isset($a['score']) && is_numeric($a['score']) ? ' ' . self::points((float)$a['score']) : '');
    }

    public static function points(?float $p): string
    {
        return $p === null ? 'nothing' : rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    }
}
