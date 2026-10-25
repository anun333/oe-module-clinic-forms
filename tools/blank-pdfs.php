<?php

/**
 * Makes the blank documents' accessible PDFs (data/pdf; see BlankPdfs), with LibreOffice, before
 * a release. Each is the module's own .odt made a PDF with PDF/UA on; check them with veraPDF
 * afterwards (the workspace's release script does).
 *
 *   php tools/blank-pdfs.php            make the PDFs that are missing or out of date
 *   php tools/blank-pdfs.php --all      make every one again
 *   php tools/blank-pdfs.php --check    report only; exit 1 if any is missing or out of date
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

use OpenHealthcare\ClinicForms\Office\BlankPdfs;
use OpenHealthcare\ClinicForms\Office\LibreOfficePdf;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Words;

$site = sys_get_temp_dir() . '/clinic-forms-blank-site-' . bin2hex(random_bytes(4));
mkdir($site);
$pdfs = new BlankPdfs();
$documents = BlankPdfs::shipped($site);
$stale = $pdfs->stale($documents);
if (($argv[1] ?? '') === '--check') {
    rmdir($site);
    echo $stale === [] ? 'Every blank document has its accessible PDF (' . count($documents) . ").\n" : implode("\n", $stale) . "\n";
    exit($stale === [] ? 0 : 1);
}
$lo = LibreOfficePdf::find();
if ($lo === null) {
    rmdir($site);
    fwrite(STDERR, "LibreOffice isn't installed in any of the places LibreOfficePdf looks.\n");
    exit(1);
}
$all = ($argv[1] ?? '') === '--all';
$redo = array_map(static fn(string $line): string => explode(':', $line, 2)[0], $stale);
$manifestFile = $pdfs->dir() . '/manifest.json';
$manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : [];
$entries = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
$entries = array_intersect_key($entries, $documents);
$made = 0;
foreach ($documents as $key => [$doc, $taglines]) {
    if (!$all && !in_array($key, $redo, true)) {
        continue;
    }
    $odt = (new OfficeExport(new Context([]), $doc->language, $taglines))->write([$doc], new OdtWriter(Words::t('Date', $doc->language), $doc->language), BlankPdfs::PAPER);
    $pdf = $lo->convert($odt);
    if ($pdf === null) {
        fwrite(STDERR, "$key: LibreOffice made no PDF\n");
        exit(1);
    }
    $file = $pdfs->dir() . "/$key.pdf";
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, $pdf);
    $entries[$key] = ['fingerprint' => BlankPdfs::fingerprint($doc, $taglines), 'sha256' => hash('sha256', $pdf)];
    $made++;
    echo "$key\n";
}
foreach (glob($pdfs->dir() . '/*/*.pdf') ?: [] as $file) {
    // PDFs of documents no longer shipped
    $key = basename(dirname($file)) . '/' . basename($file, '.pdf');
    if (!isset($documents[$key])) {
        unlink($file);
    }
}
ksort($entries);
$version = trim((string)shell_exec(escapeshellarg($lo->program()) . ' --version 2>/dev/null'));
file_put_contents($manifestFile, json_encode([
    'about' => 'The blank documents\' accessible PDFs, each with what it was made from. Written by tools/blank-pdfs.php; do not edit.',
    'made_with' => $made > 0 ? $version : ($manifest['made_with'] ?? $version),
    'documents' => $entries,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
rmdir($site);
echo "Made $made of " . count($documents) . ".\n";
