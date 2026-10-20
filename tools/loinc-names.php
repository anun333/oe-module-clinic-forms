<?php

/**
 * Writes data/loinc-names.json: LOINC's name for every LOINC code the module's data holds (documents'
 * codes, and each instrument reference's panel, question, total and answer codes), as fhir.loinc.org's
 * $lookup gives it (needs a free LOINC account): a term's long common name, an answer's (LA...)
 * display text. The LOINC licence asks that any LOINC code a product passes on go with one of LOINC's
 * names for it; the FHIR export reads them from this file. Where LOINC has a Spanish long common name
 * (its es-MX linguistic variant), it's kept too, for Spanish questionnaires.
 *
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/loinc-names.php          write the file
 *   LOINC_USER=... LOINC_PASSWORD=... php tools/loinc-names.php --check  compare only; exit 1 if LOINC's differ
 *
 * A code LOINC doesn't have, or a term (not an answer, which has no status) it has as anything but
 * active, is an error.
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
$data = dirname(__DIR__) . '/data';

/** Every LOINC code, and answer code, under the keys that hold one, at any depth. */
function loincCodesIn(mixed $value, array &$codes): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $v) {
        if (in_array($key, ['code', 'loinc', 'total_code'], true) && is_string($v) && (Loinc::isValid($v) || Loinc::isAnswer($v))) {
            $codes[$v] = true;
        }
        loincCodesIn($v, $codes);
    }
}

$codes = [];
$files = array_merge(
    glob("$data/documents/*.json") ?: [],
    glob("$data/documents/*/*.json") ?: [],
    glob("$data/packs/*/documents/*.json") ?: [],
    glob("$data/packs/*/documents/*/*.json") ?: [],
    glob("$data/instruments/*.json") ?: [],
);
foreach ($files as $file) {
    loincCodesIn(json_decode((string)file_get_contents($file), true), $codes);
}
ksort($codes, SORT_NATURAL);
if ($codes === []) {
    fwrite(STDERR, "no LOINC codes found under data/; the scan is wrong\n");
    exit(1);
}

$auth = loincAuth();
$names = [];
$spanish = [];
$version = '';
$failed = 0;
foreach (array_keys($codes) as $code) {
    $code = (string)$code;
    try {
        $p = loincFetch('https://fhir.loinc.org/CodeSystem/$lookup?system=http://loinc.org&code=' . rawurlencode($code), [$auth, 'Accept: application/fhir+json']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "$code: {$e->getMessage()}\n");
        $failed++;
        continue;
    }
    $display = null;
    $status = null;
    $es = [];
    foreach ($p['parameter'] ?? [] as $parameter) {
        if (($parameter['name'] ?? '') === 'display') {
            $display = (string)($parameter['valueString'] ?? '');
        } elseif (($parameter['name'] ?? '') === 'version') {
            $version = (string)($parameter['valueString'] ?? $version);
        } elseif (($parameter['name'] ?? '') === 'property') {
            $parts = array_column($parameter['part'] ?? [], null, 'name');
            if (($parts['code']['valueCode'] ?? '') === 'STATUS') {
                $status = (string)($parts['value']['valueString'] ?? $parts['value']['valueCode'] ?? '');
            }
        } elseif (($parameter['name'] ?? '') === 'designation') {
            $parts = array_column($parameter['part'] ?? [], null, 'name');
            $language = (string)($parts['language']['valueCode'] ?? '');
            if (str_starts_with($language, 'es') && ($parts['use']['valueCoding']['code'] ?? '') === 'LONG_COMMON_NAME') {
                $es[$language] = (string)($parts['value']['valueString'] ?? '');
            }
        }
    }
    $answer = Loinc::isAnswer($code);
    if ($display === null || $display === '' || (!$answer && $status !== 'ACTIVE')) {
        fwrite(STDERR, "$code: LOINC gives " . ($display === null || $display === '' ? 'no name' : "status $status") . "\n");
        $failed++;
        continue;
    }
    $names[$code] = $display;
    // Mexican Spanish first, LOINC's variant for US Spanish speakers' most common background; else any Spanish one.
    $name = $es['es-MX'] ?? (array_values(array_filter($es))[0] ?? '');
    if ($name !== '') {
        $spanish[$code] = $name;
    }
}
if ($failed > 0) {
    exit(1);
}

$new = [
    'from' => 'fhir.loinc.org CodeSystem/$lookup (display: the LONG_COMMON_NAME)',
    'loinc_version' => $version,
    'notice' => LOINC_NOTICE,
    'names' => $names,
    'names_es' => $spanish,
];
$file = Loinc::namesFile();
$old = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
$same = is_array($old) && ($old['names'] ?? null) == $names && ($old['names_es'] ?? null) == $spanish;
if ($check) {
    echo $same ? count($names) . " names match LOINC $version\n" : "LOINC's names differ from data/loinc-names.json; run without --check and review the change\n";
    exit($same ? 0 : 1);
}
file_put_contents($file, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($names) . ' names, ' . count($spanish) . ' in Spanish' . ($same ? ', unchanged' : ', written') . " (LOINC $version)\n";
