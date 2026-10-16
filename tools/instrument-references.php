<?php

/**
 * Writes data/instruments/<loinc>.json for each validated document: the instrument's questions
 * and answers from LOINC's own questionnaire (fhir.loinc.org, needs a free LOINC account), each
 * answer's score from LOINC's answer lists as NLM's Clinical Tables serve them (no account), and the
 * total range. What is written by hand in a reference (the published cut-offs and their source, and
 * any accepted wording difference) is kept.
 *
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/instrument-references.php          write the references
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/instrument-references.php --check  compare only; exit 1 if LOINC's differ
 *
 * The tests read these files; they don't fetch anything.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Instrument;
use OpenHealthcare\ClinicForms\Paper\Loinc;

const NOTICE = 'This material contains content from LOINC (http://loinc.org). LOINC is copyright Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.';

$check = ($argv[1] ?? '') === '--check';
$user = getenv('LOINC_USER') ?: '';
$password = getenv('LOINC_PASSWORD') ?: '';
if ($user === '' || $password === '') {
    fwrite(STDERR, "LOINC_USER and LOINC_PASSWORD are needed (a free account at loinc.org)\n");
    exit(2);
}

function fetch(string $url, array $headers = []): array
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
function scoredAnswers(string $question): array
{
    $r = fetch('https://clinicaltables.nlm.nih.gov/api/loinc_items/v3/search?type=question&df=LOINC_NUM&ef=answers&maxList=50&terms=' . rawurlencode($question));
    $i = array_search($question, $r[1] ?? [], true);
    if ($i === false) {
        throw new RuntimeException("$question: not found at NLM");
    }
    $list = $r[2]['answers'][$i] ?? [];
    usort($list, static fn(array $a, array $b): int => ($a['SequenceNo'] ?? 0) <=> ($b['SequenceNo'] ?? 0));
    return array_map(static fn(array $a): array => [(string)$a['AnswerStringID'], (string)$a['DisplayText'], isset($a['Score']) && is_numeric($a['Score']) ? $a['Score'] + 0 : null], $list ?: []);
}

$auth = 'Authorization: Basic ' . base64_encode("$user:$password");
$failed = 0;
foreach (DocumentLibrary::shipped()->all() as $doc) {
    $loinc = $doc->code['code'] ?? '';
    if (!$doc->validated || ($doc->code['system'] ?? '') !== Loinc::SYSTEM || !Loinc::isValid($loinc)) {
        continue;
    }
    try {
        $q = fetch("https://fhir.loinc.org/Questionnaire/$loinc", [$auth, 'Accept: application/fhir+json']);
        $lqs = loincQuestions($q['item'] ?? []);
        // The panel's answers are the instrument's. A question's own answer list (its default) can
        // differ: GAD-7's 68509-9 defaults to "More days than not" where the panel asks "More than half
        // the days". Such an answer takes its score and place from the same answer elsewhere in the
        // panel, when every question there agrees on it.
        $lists = [];
        $panel = [];  // answer code -> [text, score, sequence] or false when the panel's questions disagree
        foreach ($lqs as $lq) {
            $lists[$lq['code']] = $lq['answers'] === [] ? [] : scoredAnswers($lq['code']);
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
                        throw new RuntimeException("{$lq['code']}: answer $code has no score in its own list or, consistently, in the panel's");
                    }
                    $a = $panel[$code];
                    $borrowed[] = "{$lq['code']} $code";
                }
                $answers[] = ['code' => $code, 'text' => $a[0], 'score' => $a[1], 'seq' => $a[2]];
            }
            usort($answers, static fn(array $x, array $y): int => $x['seq'] <=> $y['seq']);
            $questions[] = ['code' => $lq['code'], 'text' => $lq['text'], 'answers' => array_map(static fn(array $a): array => array_diff_key($a, ['seq' => 1]), $answers)];
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "$doc->id: {$e->getMessage()}\n");
        $failed++;
        continue;
    }
    $file = Instrument::dir() . "/$loinc.json";
    $old = Instrument::reference($loinc) ?? [];
    $range = Instrument::totalRange(array_map(static fn(array $x): array => ['answers' => array_map(static fn(array $a): array => ['score' => $a['score'] === null ? null : (float)$a['score']], $x['answers'])], $questions));
    $new = [
        'instrument' => $old['instrument'] ?? $doc->title,
        'loinc' => $loinc,
        'loinc_version' => (string)($q['version'] ?? ''),
        'from' => "fhir.loinc.org Questionnaire/$loinc (questions, answers); scores and answer order from LOINC's answer lists via clinicaltables.nlm.nih.gov loinc_items"
            . ($borrowed === [] ? '' : '; scored from the same answer elsewhere in the panel: ' . implode(', ', $borrowed)),
        'notice' => NOTICE,
        'questions' => $questions,
        'total' => ['min' => $range['min'] + 0, 'max' => $range['max'] + 0],
        'cutoffs' => $old['cutoffs'] ?? [],
        'cutoffs_source' => $old['cutoffs_source'] ?? null,
        'accepted' => $old['accepted'] ?? ['questions' => new stdClass()],
    ];
    $same = array_intersect_key($old, ['questions' => 1, 'total' => 1]) == array_intersect_key($new, ['questions' => 1, 'total' => 1]);
    if ($check) {
        echo $same ? "$loinc ($doc->id): matches LOINC $new[loinc_version]\n" : "$loinc ($doc->id): LOINC's questionnaire differs from the reference; run without --check and review the change\n";
        $failed += $same ? 0 : 1;
        continue;
    }
    @mkdir(Instrument::dir());
    file_put_contents($file, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "$loinc ($doc->id): " . count($questions) . ' questions' . ($same ? ', unchanged' : ', written') . "\n";
}
exit($failed === 0 ? 0 : 1);
