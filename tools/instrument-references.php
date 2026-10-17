<?php

/**
 * Writes data/instruments/<loinc>.json for each validated document: the instrument's questions
 * and answers from LOINC's own questionnaire (fhir.loinc.org, needs a free LOINC account), each
 * answer's score from LOINC's answer lists as NLM's Clinical Tables serve them (no account), and the
 * total range. What is written by hand in a reference (LOINC_HAND_FIELDS: the published cut-offs or
 * rules and their source, any scoring where LOINC differs, any accepted wording difference) is kept.
 *
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/instrument-references.php          write the references
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/instrument-references.php --check  compare only; exit 1 if LOINC's differ
 *
 * A reference whose questions LOINC can't give whole is written by hand from the owner's
 * instrument, with LOINC's codes where it has them, and says so under "questions_from" (USDA's
 * six-item food security module: LOINC's questionnaire leaves out AD1a). This leaves it alone.
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

require __DIR__ . '/loinc-lib.php';

$check = ($argv[1] ?? '') === '--check';
$auth = loincAuth();
$failed = 0;
foreach (DocumentLibrary::shipped()->all() as $doc) {
    $loinc = $doc->code['code'] ?? '';
    if (!$doc->validated || ($doc->code['system'] ?? '') !== Loinc::SYSTEM || !Loinc::isValid($loinc)) {
        continue;
    }
    $old = Instrument::reference($loinc) ?? [];
    if (isset($old['questions_from'])) {
        echo "$loinc ($doc->id): written by hand from {$old['questions_from']['name']}; not fetched\n";
        continue;
    }
    try {
        $fetched = loincInstrument($loinc, $auth);
        // Where the published instrument scores differently from LOINC's lists, its scoring is written by hand.
        [$questions, $freeText] = loincFreeText(loincApplyScoring($fetched['questions'], $old['scoring']['overrides'] ?? []));
        if (($gaps = loincScoringGaps($questions)) !== []) {
            throw new RuntimeException('some answers have no score in LOINC (' . implode(', ', $gaps) . '); add the published scoring under "scoring"');
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "$doc->id: {$e->getMessage()}\n");
        $failed++;
        continue;
    }
    $file = Instrument::dir() . "/$loinc.json";
    $range = Instrument::totalRange(array_map(static fn(array $x): array => ['answers' => array_map(static fn(array $a): array => ['score' => $a['score'] === null ? null : (float)$a['score']], $x['answers'])], $questions));
    $new = [
        'instrument' => $old['instrument'] ?? $doc->title,
        'loinc' => $loinc,
        'loinc_version' => $fetched['version'],
        'from' => "fhir.loinc.org Questionnaire/$loinc (questions, answers); scores and answer order from LOINC's answer lists via clinicaltables.nlm.nih.gov loinc_items"
            . ($fetched['borrowed'] === [] ? '' : '; scored from the same answer elsewhere in the panel: ' . implode(', ', $fetched['borrowed']))
            . (empty($old['scoring']['overrides']) ? '' : '; scoring as published where LOINC differs: see "scoring"'),
        'notice' => LOINC_NOTICE,
        'questions' => $questions,
    ] + ($freeText === [] ? [] : ['free_text' => $freeText]) + [
        'total' => ['min' => $range['min'] + 0, 'max' => $range['max'] + 0],
    ] + array_intersect_key($old, array_flip(LOINC_HAND_FIELDS)) + ['accepted' => ['questions' => new stdClass()]];
    $fetchedKeys = ['questions' => 1, 'free_text' => 1, 'total' => 1];
    $same = array_intersect_key($old, $fetchedKeys) == array_intersect_key($new, $fetchedKeys);
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
