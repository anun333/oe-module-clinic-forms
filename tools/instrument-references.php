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
    try {
        $fetched = loincInstrument($loinc, $auth);
        // Where the published instrument scores differently from LOINC's lists, its scoring is written by hand.
        $questions = loincApplyScoring($fetched['questions'], $old['scoring']['overrides'] ?? []);
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
        'total' => ['min' => $range['min'] + 0, 'max' => $range['max'] + 0],
        'cutoffs' => $old['cutoffs'] ?? [],
        'cutoffs_source' => $old['cutoffs_source'] ?? null,
        'accepted' => $old['accepted'] ?? ['questions' => new stdClass()],
    ] + array_intersect_key($old, ['scoring' => 1, 'licence' => 1, 'source' => 1]);
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
