<?php

/**
 * LOINC's questionnaires and answer scores, for the tools that write data/instruments and the draft
 * documents (tools/instrument-references.php, tools/import-instrument.php). Not part of the module
 * at runtime: the tests read the files these tools write and fetch nothing.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

const LOINC_NOTICE = 'This material contains content from LOINC (http://loinc.org). LOINC is copyright Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.';

/**
 * What a reference holds that LOINC can't give, written by hand with its source and kept when the
 * reference is fetched again.
 */
const LOINC_HAND_FIELDS = ['instrument', 'title', 'about', 'instructions', 'licence', 'source', 'total_code', 'total_code_source', 'cutoffs', 'cutoffs_source', 'rules', 'rules_source', 'subscales', 'subscales_source', 'scoring', 'accepted'];

/** The Authorization header for fhir.loinc.org, from LOINC_USER and LOINC_PASSWORD; exits without them. */
function loincAuth(): string
{
    $user = getenv('LOINC_USER') ?: '';
    $password = getenv('LOINC_PASSWORD') ?: '';
    if ($user === '' || $password === '') {
        fwrite(STDERR, "LOINC_USER and LOINC_PASSWORD are needed (a free account at loinc.org)\n");
        exit(2);
    }
    return 'Authorization: Basic ' . base64_encode("$user:$password");
}

function loincFetch(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 60, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m) === 1 ? (int)$m[1] : 0;
    if ($body === false || $status !== 200) {
        throw new RuntimeException("$url: HTTP $status");
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("$url: not JSON");
    }
    return $data;
}

/** LOINC's questionnaire: each question with its code, wording and answer codes, in order (totals left out). */
function loincQuestions(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!empty($item['item'])) {
            $out = array_merge($out, loincQuestions($item['item']));
            continue;
        }
        $code = $item['code'][0]['code'] ?? '';
        if ($code === '' || in_array($item['type'] ?? '', ['display', 'group'], true)) {
            continue;
        }
        $out[] = ['code' => $code, 'text' => (string)($item['text'] ?? ''), 'answers' => array_values(array_filter(array_map(
            static fn(array $o): string => (string)($o['valueCoding']['code'] ?? ''), $item['answerOption'] ?? []), 'strlen'))];
    }
    return $out;
}

/** LOINC's answer list for a question, with scores, as NLM serves it: [[code, text, score|null]] in LOINC's order. */
function loincScoredAnswers(string $question): array
{
    $r = loincFetch('https://clinicaltables.nlm.nih.gov/api/loinc_items/v3/search?type=question&df=LOINC_NUM&ef=answers&maxList=50&terms=' . rawurlencode($question));
    $i = array_search($question, $r[1] ?? [], true);
    if ($i === false) {
        throw new RuntimeException("$question: not found at NLM");
    }
    $list = $r[2]['answers'][$i] ?? [];
    usort($list, static fn(array $a, array $b): int => ($a['SequenceNo'] ?? 0) <=> ($b['SequenceNo'] ?? 0));
    return array_map(static fn(array $a): array => [(string)$a['AnswerStringID'], (string)$a['DisplayText'], isset($a['Score']) && is_numeric($a['Score']) ? $a['Score'] + 0 : null], $list ?: []);
}

/**
 * An instrument's questions as LOINC has them: LOINC's own questionnaire for the questions and their
 * answers, LOINC's answer lists (through NLM) for each answer's score and order.
 *
 * The panel's answers are the instrument's. A question's own answer list (its default) can differ:
 * GAD-7's 68509-9 defaults to "More days than not" where the panel asks "More than half the days".
 * Such an answer takes its score and place from the same answer elsewhere in the panel, when every
 * question there agrees on it.
 *
 * @return array{version: string, title: string, questions: list<array>, borrowed: list<string>}
 */
function loincInstrument(string $loinc, string $auth): array
{
    $q = loincFetch("https://fhir.loinc.org/Questionnaire/$loinc", [$auth, 'Accept: application/fhir+json']);
    $lqs = loincQuestions($q['item'] ?? []);
    $lists = [];
    $panel = [];  // answer code -> [text, score, sequence] or false when the panel's questions disagree
    foreach ($lqs as $lq) {
        $lists[$lq['code']] = $lq['answers'] === [] ? [] : loincScoredAnswers($lq['code']);
        foreach ($lists[$lq['code']] as $seq => [$code, $text, $score]) {
            $seen = $panel[$code] ?? null;
            $panel[$code] = $seen === null || $seen === [$text, $score, $seq] ? [$text, $score, $seq] : ($seen === false ? false : ($seen[1] === $score ? $seen : false));
        }
    }
    $questions = [];
    $borrowed = [];
    foreach ($lqs as $lq) {
        $own = [];
        foreach ($lists[$lq['code']] as $seq => [$code, $text, $score]) {
            $own[$code] = [$text, $score, $seq];
        }
        $answers = [];
        foreach ($lq['answers'] as $code) {
            $a = $own[$code] ?? null;
            if ($a === null) {
                if (empty($panel[$code])) {
                    throw new RuntimeException("{$lq['code']}: answer $code is in neither its own list nor, consistently, the panel's");
                }
                $a = $panel[$code];
                $borrowed[] = "{$lq['code']} $code";
            }
            $answers[] = ['code' => $code, 'text' => $a[0], 'score' => $a[1], 'seq' => $a[2]];
        }
        usort($answers, static fn(array $x, array $y): int => $x['seq'] <=> $y['seq']);
        $questions[] = ['code' => $lq['code'], 'text' => $lq['text'], 'answers' => array_map(static fn(array $a): array => array_diff_key($a, ['seq' => 1]), $answers)];
    }
    return ['version' => (string)($q['version'] ?? ''), 'title' => (string)($q['title'] ?? ''), 'questions' => $questions, 'borrowed' => $borrowed];
}

/**
 * The published instrument's scoring where LOINC's differs or is missing, written by hand in a
 * reference's "scoring": {"overrides": {"<question code>": {"unscored": true} | {"scores": {"Yes": 2, "No": 0}}
 * | {"order": ["Never", ...]} | {"leave_out": ["DK or Refused"]} | {"answers_from": "<question code>"}}, "source": {...}}.
 * "leave_out" drops answers LOINC lists that the published form doesn't print. "scores" and
 * "order" put the answers in the order they list, as the published form prints them. "answers_from"
 * gives a question LOINC has as free text the answers of another, as they stand after that one's own
 * override (TAPS's prescription question has none in LOINC, and the published tool asks it with the
 * same five as the others). A question or answer an override names that LOINC doesn't have is an
 * error, not ignored.
 */
function loincApplyScoring(array $questions, array $overrides): array
{
    $codes = array_column($questions, 'code');
    foreach ($overrides as $code => $o) {
        foreach (array_filter([(string)$code, (string)($o['answers_from'] ?? '')]) as $named) {
            if (!in_array($named, $codes, true)) {
                throw new RuntimeException("scoring override names $named, which isn't one of the instrument's questions");
            }
        }
    }
    foreach ($questions as &$q) {
        $o = $overrides[$q['code']] ?? null;
        if ($o === null || isset($o['answers_from'])) {
            continue;
        }
        foreach ($o['leave_out'] ?? [] as $text) {
            if (!in_array((string)$text, array_column($q['answers'], 'text'), true)) {
                throw new RuntimeException("scoring override for {$q['code']} leaves out \"$text\", which LOINC's question doesn't have");
            }
        }
        $q['answers'] = array_values(array_filter($q['answers'], static fn(array $a): bool => !in_array($a['text'], array_map('strval', $o['leave_out'] ?? []), true)));
        $texts = array_column($q['answers'], 'text');
        if (!empty($o['unscored'])) {
            foreach ($q['answers'] as &$a) {
                $a['score'] = null;
            }
            unset($a);
        }
        foreach (array_keys($o['scores'] ?? []) as $text) {
            if (!in_array((string)$text, $texts, true)) {
                throw new RuntimeException("scoring override for {$q['code']} names the answer \"$text\", which LOINC's question doesn't have");
            }
        }
        foreach ($q['answers'] as &$a) {
            if (array_key_exists($a['text'], $o['scores'] ?? [])) {
                $a['score'] = $o['scores'][$a['text']];
            }
        }
        unset($a);
        // The answers in the order the override lists them, as the published form prints them
        // (LOINC's list for STEADI's "I have fallen in the past year" runs No, Yes; the form, Yes, No).
        $listed = isset($o['order']) ? array_map('strval', $o['order']) : array_map('strval', array_keys($o['scores'] ?? []));
        if (isset($o['order']) && (count($listed) !== count($texts) || array_diff($texts, $listed) !== [])) {
            throw new RuntimeException("scoring override for {$q['code']} orders the answers " . implode(', ', $listed) . ' where LOINC has ' . implode(', ', $texts));
        }
        $order = array_flip($listed);
        usort($q['answers'], static fn(array $x, array $y): int => ($order[$x['text']] ?? PHP_INT_MAX) <=> ($order[$y['text']] ?? PHP_INT_MAX));
    }
    unset($q);
    $byCode = array_combine($codes, $questions);
    foreach ($questions as &$q) {
        $from = $overrides[$q['code']]['answers_from'] ?? null;
        if ($from === null) {
            continue;
        }
        if ($q['answers'] !== []) {
            throw new RuntimeException("{$q['code']} has answers in LOINC; \"answers_from\" is for a question that has none");
        }
        $q['answers'] = $byCode[(string)$from]['answers'];
    }
    unset($q);
    return $questions;
}

/**
 * LOINC's questionnaire items with no answers: free text ("If yes, describe briefly"), not questions
 * a check can compare. Each is kept with the question it follows, for the document to give it lines.
 *
 * @return array{0: list<array>, 1: list<array{code: string, text: string, after: ?string}>} the questions, and the free text
 */
function loincFreeText(array $questions): array
{
    $choice = [];
    $free = [];
    foreach ($questions as $q) {
        if ($q['answers'] === []) {
            $free[] = ['code' => $q['code'], 'text' => $q['text'], 'after' => $choice === [] ? null : end($choice)['code']];
        } else {
            $choice[] = $q;
        }
    }
    return [$choice, $free];
}

/** Questions some of whose answers have a score and others none: a gap in the scoring to fill by hand. */
function loincScoringGaps(array $questions): array
{
    $gaps = [];
    foreach ($questions as $q) {
        $scores = array_column($q['answers'], 'score');
        $none = count(array_filter($scores, static fn($s): bool => $s === null));
        if ($none > 0 && $none < count($scores)) {
            $gaps[] = $q['code'];
        }
    }
    return $gaps;
}

/** A LOINC term's properties by name (STATUS, COMPONENT, METHOD_TYP...), as fhir.loinc.org gives them. @return array<string, string> */
function loincTermProperties(string $code, string $auth): array
{
    $term = loincFetch('https://fhir.loinc.org/CodeSystem/$lookup?system=http://loinc.org&code=' . rawurlencode($code), [$auth, 'Accept: application/fhir+json']);
    $props = [];
    foreach ($term['parameter'] ?? [] as $p) {
        if (($p['name'] ?? '') === 'property') {
            $value = $p['part'][1] ?? [];
            $props[(string)($p['part'][0]['valueCode'] ?? '')] = (string)($value['valueCoding']['display'] ?? $value['valueString'] ?? $value['valueCode'] ?? '');
        }
    }
    return $props;
}

/**
 * What's wrong with a reference's total_code, or null. LOINC's questionnaires leave their total
 * item uncoded, so the code is written by hand; this holds it to LOINC. The term must be active, a
 * quantitative score in the panel's own method (METHOD_TYP), and name the total: its component is
 * the text of the questionnaire's one total item (the PHQ-9's 44261-6 is "Patient health
 * questionnaire 9 item total score", word for word), or holds both that text and the method's name
 * (Stay Independent's total item says only "Total"; 97885-8 is "Stay Independent total score").
 * The method alone isn't enough: the PHQ-9 and PHQ-2 totals share "Reported.PHQ".
 */
function loincTotalCodeProblem(string $loinc, string $totalCode, string $auth): ?string
{
    $q = loincFetch("https://fhir.loinc.org/Questionnaire/$loinc", [$auth, 'Accept: application/fhir+json']);
    $totals = [];
    $walk = static function (array $items) use (&$walk, &$totals): void {
        foreach ($items as $item) {
            if (in_array($item['type'] ?? '', ['decimal', 'integer'], true) && empty($item['code'])) {
                $totals[] = trim((string)($item['text'] ?? ''));
            }
            $walk($item['item'] ?? []);
        }
    };
    $walk($q['item'] ?? []);
    if (count($totals) !== 1) {
        return "LOINC's questionnaire $loinc has " . count($totals) . ' uncoded total items, not one';
    }
    $text = $totals[0];
    $panel = loincTermProperties($loinc, $auth);
    $term = loincTermProperties($totalCode, $auth);
    $method = $panel['METHOD_TYP'] ?? '';
    $component = $term['COMPONENT'] ?? '';
    if (($term['STATUS'] ?? '') !== 'ACTIVE') {
        return "total_code $totalCode is " . ($term['STATUS'] ?? 'not found') . ', not ACTIVE';
    }
    if (($term['PROPERTY'] ?? '') !== 'Score' || ($term['SCALE_TYP'] ?? '') !== 'Qn') {
        return "total_code $totalCode is not a quantitative score (" . ($term['PROPERTY'] ?? '') . ', ' . ($term['SCALE_TYP'] ?? '') . ')';
    }
    if ($method !== '' && ($term['METHOD_TYP'] ?? '') !== $method) {
        return "total_code $totalCode has method \"" . ($term['METHOD_TYP'] ?? '') . "\", where $loinc's is \"$method\"";
    }
    $named = strcasecmp($component, $text) === 0
        || ($method !== '' && stripos($component, $method) !== false && stripos($component, $text) !== false);
    if (!$named) {
        return "total_code $totalCode has component \"$component\", where $loinc's total item says \"$text\"";
    }
    return null;
}
