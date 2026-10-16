<?php

/**
 * Records the wording of each shipped document's version in data/document-versions.json.
 *
 *   php tools/document-versions.php                 record new versions (refuses if a recorded one changed)
 *   php tools/document-versions.php --check         report only; exit 1 on any problem
 *   php tools/document-versions.php --changes FILE  list documents changed since FILE (an earlier
 *                                                   release's record: git show v0.12.0:data/document-versions.json)
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OpenHealthcare\ClinicForms\Paper\DocumentVersions;

$file = dirname(__DIR__) . '/' . DocumentVersions::FILE;
$recorded = DocumentVersions::load();
$documents = DocumentVersions::shipped();

if (($argv[1] ?? '') === '--changes') {
    $before = DocumentVersions::load($argv[2] ?? '');
    $lines = DocumentVersions::changes($before, $recorded);
    echo $lines === [] ? "No document changed.\n" : implode("\n", $lines) . "\n";
    exit(0);
}
$changed = array_values(array_filter(DocumentVersions::problems($recorded, $documents), static fn(string $p): bool => str_contains($p, 'wording changed') || str_contains($p, 'no version')));
if (($argv[1] ?? '') === '--check' || $changed !== []) {
    $problems = DocumentVersions::problems($recorded, $documents);
    echo $problems === [] ? "Every document's version is recorded with its wording.\n" : implode("\n", $problems) . "\n";
    exit($problems === [] ? 0 : 1);
}
$new = DocumentVersions::record($recorded, $documents);
file_put_contents($file, json_encode(['about' => 'The wording (fingerprint) of each version of each shipped document. Written by tools/document-versions.php; do not edit.', 'documents' => $new], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Recorded ' . count($new) . " documents.\n";
