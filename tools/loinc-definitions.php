<?php

/**
 * Writes data/loinc-definitions.json: for each LOINC question the instrument references hold, the
 * question in LOINC's own FHIR questionnaire that defines it, as an SDC Questionnaire library reference
 * ("http://loinc.org/q/55757-9#32742": LOINC's questionnaire's canonical url, then the item's linkId).
 * The FHIR export puts it in the item's "definition", so a question writes the same field in any form:
 * the PHQ-2's first question has the same definition in the PHQ-2, the PHQ-4 and the PHQ-9.
 *
 * Which questionnaire, when LOINC asks a question in several (44250-9 is in the PHQ-2, PHQ-4 and PHQ-9):
 * the one with the fewest items, then the lowest code, so the choice doesn't depend on which forms
 * the module has. Only LOINC's choice questions are taken (a question LOINC asks as free text, where
 * the module offers answers, isn't the same item), and each instrument's total ("total_code", checked
 * against LOINC's total item by tools/instrument-references.php) maps to its questionnaire's one
 * decimal item, which LOINC leaves uncoded.
 *
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/loinc-definitions.php          write the file
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/loinc-definitions.php --check  compare only; exit 1 if LOINC's differ
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Composer installs the package under the web root; the tools are for the command line only.
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/loinc-lib.php';

use OpenHealthcare\ClinicForms\Paper\Loinc;

$check = ($argv[1] ?? '') === '--check';
$auth = loincAuth();

/** @return list<array<string, mixed>> every item, at any depth */
function loincItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $out[] = $item;
        $out = array_merge($out, loincItems($item['item'] ?? []));
    }
    return $out;
}

$candidates = [];  // code => list of [item count, panel, url#linkId]
$version = '';
foreach (glob(dirname(__DIR__) . '/data/instruments/*.json') ?: [] as $file) {
    $reference = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    $panel = (string)$reference['loinc'];
    try {
        $q = loincFetch("https://fhir.loinc.org/Questionnaire/$panel", [$auth, 'Accept: application/fhir+json']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "$panel: {$e->getMessage()}\n");
        exit(1);
    }
    $version = (string)($q['version'] ?? $version);
    $items = array_values(array_filter(loincItems($q['item'] ?? []), static fn(array $i): bool => !in_array($i['type'] ?? '', ['group', 'display'], true)));
    $url = (string)$q['url'];
    foreach ($items as $item) {
        if (($item['type'] ?? '') !== 'choice') {
            continue;
        }
        foreach ($item['code'] ?? [] as $coding) {
            if (($coding['system'] ?? '') === Loinc::SYSTEM) {
                $candidates[$coding['code']][] = [count($items), $panel, "$url#{$item['linkId']}"];
            }
        }
    }
    $totals = array_values(array_filter($items, static fn(array $i): bool => ($i['type'] ?? '') === 'decimal' && empty($i['code'])));
    if (isset($reference['total_code']) && count($totals) === 1) {
        $candidates[$reference['total_code']][] = [count($items), $panel, "$url#{$totals[0]['linkId']}"];
    }
}
$definitions = [];
foreach ($candidates as $code => $found) {
    usort($found, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $definitions[(string)$code] = $found[0][2];
}
ksort($definitions, SORT_NATURAL);

$file = Loinc::definitionsFile();
$old = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
$same = is_array($old) && ($old['definitions'] ?? null) == $definitions;
if ($check) {
    echo $same ? count($definitions) . " definitions match LOINC $version\n" : "LOINC's questionnaires differ from data/loinc-definitions.json; run without --check and review the change\n";
    exit($same ? 0 : 1);
}
file_put_contents($file, json_encode([
    'about' => "Each LOINC question's item in LOINC's own FHIR questionnaire (url#linkId, SDC's Questionnaire library referencing), for the FHIR export's item.definition. Written by tools/loinc-definitions.php; do not edit.",
    'from' => 'fhir.loinc.org Questionnaire/<panel> for each instrument in data/instruments: the questionnaire with the fewest items that asks the question as a choice; a total is its questionnaire\'s one uncoded decimal item',
    'loinc_version' => $version,
    'notice' => LOINC_NOTICE,
    'definitions' => $definitions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($definitions) . ' definitions' . ($same ? ', unchanged' : ', written') . " (LOINC $version)\n";
