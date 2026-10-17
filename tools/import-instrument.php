<?php

/**
 * Brings a validated instrument in from LOINC: its reference (data/instruments/<loinc>.json) and a
 * draft paper document (data/documents/<id>.json, or a pack's).
 *
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/import-instrument.php 102010-6 pc-ptsd-5 [--pack us] [--audience staff] [--force]
 *
 * First run: writes a reference seed and stops, for the parts LOINC can't give, written by hand with
 * their sources: the document's title and about, the holder's terms, the published cut-offs (or, for
 * a screen read by rule, the published rules), and any scoring where the published instrument
 * differs from LOINC's answer lists ("scoring"). Run it again
 * and it fetches LOINC's questions, applies that scoring, writes the reference, and writes the
 * document as a draft (status "draft"): its wording is LOINC's, to be compared with the instrument's
 * own printed form before the draft is made active. An existing document is kept unless --force.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/loinc-lib.php';

use OpenHealthcare\ClinicForms\Paper\Instrument;
use OpenHealthcare\ClinicForms\Paper\Loinc;

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$pack = null;
$audience = 'patient';  // a clinician asks the ASQ: --audience staff
foreach ($argv as $i => $a) {
    if ($a === '--pack' || $a === '--audience') {
        $value = $argv[$i + 1] ?? '';
        $args = array_values(array_diff($args, [$value]));
        $a === '--pack' ? $pack = $value : $audience = $value;
    }
}
[$loinc, $id] = $args + ['', ''];
if (!Loinc::isValid($loinc) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1 || !in_array($audience, \OpenHealthcare\ClinicForms\Paper\Document::AUDIENCES, true)) {
    fwrite(STDERR, "usage: php tools/import-instrument.php <loinc code> <document id> [--pack us] [--audience staff] [--force]\n");
    exit(2);
}
$refFile = Instrument::dir() . "/$loinc.json";
$old = Instrument::reference($loinc);
if ($old === null || trim((string)($old['title'] ?? '')) === '' || (($old['cutoffs'] ?? []) === [] && ($old['rules'] ?? []) === [])) {
    if ($old === null) {
        @mkdir(Instrument::dir());
        file_put_contents($refFile, json_encode([
            'instrument' => '', 'loinc' => $loinc, 'title' => '', 'about' => '', 'instructions' => '',
            'licence' => ['holder' => '', 'terms' => '', 'permission' => ''],
            'source' => ['name' => '', 'url' => ''],
            'cutoffs' => [], 'cutoffs_source' => ['citation' => '', 'pmid' => '', 'url' => '', 'stated' => ''],
            'rules' => [], 'rules_source' => ['citation' => '', 'pmid' => '', 'url' => '', 'stated' => ''],
            'scoring' => ['overrides' => new stdClass(), 'source' => ['citation' => '', 'url' => '', 'stated' => '']],
            'accepted' => ['questions' => new stdClass()],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    fwrite(STDERR, "$refFile: fill in the title, about, licence, source, the published cut-offs or rules and their source (and any scoring that differs from LOINC), then run this again; leave out whichever of cutoffs and rules the instrument doesn't have\n");
    exit(1);
}

if (isset($old['questions_from'])) {
    fwrite(STDERR, "$refFile is written by hand from {$old['questions_from']['name']}: edit it and the document directly\n");
    exit(1);
}
$fetched = loincInstrument($loinc, loincAuth());
// Every item in LOINC's order, for the document; the reference keeps the questions and lists the free text apart.
$items = loincApplyScoring($fetched['questions'], $old['scoring']['overrides'] ?? []);
[$questions, $freeText] = loincFreeText($items);
if (($gaps = loincScoringGaps($questions)) !== []) {
    fwrite(STDERR, 'Some answers have no score in LOINC (' . implode(', ', $gaps) . "): add the published scoring under \"scoring\" in $refFile\n");
    exit(1);
}
$range = Instrument::totalRange(array_map(static fn(array $x): array => ['answers' => array_map(static fn(array $a): array => ['score' => $a['score'] === null ? null : (float)$a['score']], $x['answers'])], $questions));
$reference = [
    'instrument' => $old['instrument'],
    'loinc' => $loinc,
    'loinc_version' => $fetched['version'],
    'from' => "fhir.loinc.org Questionnaire/$loinc (questions, answers); scores and answer order from LOINC's answer lists via clinicaltables.nlm.nih.gov loinc_items"
        . ($fetched['borrowed'] === [] ? '' : '; scored from the same answer elsewhere in the panel: ' . implode(', ', $fetched['borrowed']))
        . (empty((array)($old['scoring']['overrides'] ?? [])) ? '' : '; scoring as published where LOINC differs: see "scoring"'),
    'notice' => LOINC_NOTICE,
    'questions' => $questions,
] + ($freeText === [] ? [] : ['free_text' => $freeText]) + [
    'total' => ['min' => $range['min'] + 0, 'max' => $range['max'] + 0],
] + array_intersect_key($old, array_flip(LOINC_HAND_FIELDS));
file_put_contents($refFile, json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "$refFile: " . count($questions) . " questions, total {$reference['total']['min']} to {$reference['total']['max']}\n";

$dir = dirname(__DIR__) . '/data/' . ($pack === null ? 'documents' : "packs/$pack/documents");
$docFile = "$dir/$id.json";
if (is_file($docFile) && !in_array('--force', $argv, true)) {
    echo "$docFile: kept (exists; --force to write it again)\n";
    exit(0);
}

/** Answers as a checklist's items: "Yes (1)", or the bare text when unscored. */
function choiceItems(array $answers): array
{
    return array_map(static fn(array $a): string => $a['text'] . ($a['score'] === null ? '' : ' (' . $a['score'] . ')'), $answers);
}

$blocks = [['type' => 'fields', 'fields' => [['label' => 'Name', 'value' => '{patient.name}'], ['label' => 'Date of birth', 'value' => '{patient.dob}'], ['label' => 'Date']]]];
// Questions with the same answers, one after another, share a scale; the rest are checklists, and
// free text has lines. The page's numbers: a scale numbers its rows from 1; a checklist carries its
// number in its label; a follow-up ("If yes, when…") has none.
$n = 0;
$printed = 0;
$numbers = [];  // question code => the number the page prints
$label = trim((string)($old['instructions'] ?? '')) !== '' ? (string)$old['instructions'] : (string)$old['title'];
while ($n < count($items)) {
    if ($items[$n]['answers'] === []) {
        $blocks[] = ['type' => 'lines', 'label' => $items[$n]['text'], 'count' => 2];
        $n++;
        continue;
    }
    // A run shares its answers, and whether it is scored: an unscored question (a gate, such as
    // PC-PTSD-5's trauma question) stands alone.
    $key = static fn(array $q): string => json_encode([array_column($q['answers'], 'text'), in_array(null, array_column($q['answers'], 'score'), true)]);
    $texts = array_column($items[$n]['answers'], 'text');
    $run = [$items[$n]];
    while (isset($items[$n + count($run)]) && $items[$n + count($run)]['answers'] !== [] && $key($items[$n + count($run)]) === $key($items[$n])) {
        $run[] = $items[$n + count($run)];
    }
    if (count($run) > 1) {
        $rows = array_map(static fn(array $q): array => array_column($q['answers'], 'score'), $run);
        $scored = !in_array(null, array_merge(...$rows), true);
        $scale = ['type' => 'scale', 'label' => $label, 'columns' => $texts, 'items' => array_column($run, 'text')];
        if ($scored) {
            count(array_unique(array_map('json_encode', $rows))) === 1 ? $scale['scores'] = $rows[0] : $scale['item_scores'] = $rows;
        }
        $blocks[] = $scale;
        foreach ($run as $r => $q) {
            $numbers[$q['code']] = $r + 1;
        }
        $printed = count($run);
    } elseif (preg_match('/^If (yes|no)\b/i', $run[0]['text']) === 1) {
        $blocks[] = ['type' => 'checklist', 'label' => $run[0]['text'], 'items' => choiceItems($run[0]['answers'])];
    } else {
        $numbers[$run[0]['code']] = ++$printed;
        $blocks[] = ['type' => 'checklist', 'label' => $printed . '. ' . $run[0]['text'], 'items' => choiceItems($run[0]['answers'])];
    }
    $n += count($run);
}
if ($reference['total']['max'] > $reference['total']['min']) {
    $blocks[] = ['type' => 'fields', 'fields' => [['label' => 'Total score (for office use)']]];
}
$blocks[] = ['type' => 'heading', 'text' => 'For office use'];
if (($old['cutoffs'] ?? []) !== []) {
    $cuts = array_map(static fn(array $c): string => $c['score'] . ' or more' . (isset($c['for']) ? ' for ' . $c['for'] : '') . ', ' . $c['meaning'], $old['cutoffs']);
    $blocks[] = ['type' => 'paragraph', 'text' => 'Published cut-offs (' . preg_replace('/\.$/', '', (string)($old['cutoffs_source']['short'] ?? $old['cutoffs_source']['citation'])) . '): ' . implode('; ', $cuts) . '.'];
}
// Each published rule as a sentence naming its questions by the page's numbers, for the reviewer to
// put in the published form's own words (the check holds the meaning and the numbers to it).
foreach ($old['rules'] ?? [] as $rule) {
    $ns = array_map(static fn(string $c): ?int => $numbers[$c] ?? null, $rule['questions']);
    sort($ns);
    $which = count($ns) === count($questions) ? 'any question' : (count($ns) === 1 ? "question $ns[0]"
        : (($rule['if'] ?? 'any') === 'all' ? 'all of' : 'any of') . " questions $ns[0] to " . end($ns));
    $blocks[] = ['type' => 'paragraph', 'text' => implode(' or ', $rule['answers']) . " to $which: {$rule['meaning']}."];
}
$blocks[] = ['type' => 'fields', 'fields' => [['label' => 'Reviewed by'], ['label' => 'Entered into OpenEMR by']]];
$blocks[] = ['type' => 'paragraph', 'text' => trim($old['source']['name'] . '. ' . $old['licence']['terms'])];

$document = [
    'id' => $id,
    'title' => $old['title'],
    'category' => 'Screening',
    'about' => $old['about'],
    'audience' => $audience,
    'code' => ['system' => Loinc::SYSTEM, 'code' => $loinc, 'display' => $fetched['title']],
    'validated' => true,
    'version' => 1,
    'status' => 'draft',
    'source' => ['kind' => 'instrument', 'name' => $old['source']['name'], 'url' => $old['source']['url']],
    'licence' => $old['licence'],
    'blocks' => $blocks,
];
@mkdir($dir, 0777, true);
file_put_contents($docFile, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "$docFile: written as a draft; compare its wording with the instrument's printed form, then make it active\n";
