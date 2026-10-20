<?php

/**
 * Writes each validated instrument CIEL can carry, in English and in each translation, as an
 * OpenMRS 3 form: <folder>/<id>.json, <id>-<language>.json. Names the instruments it can't.
 *
 *   php tools/export-o3.php [folder]     (default build/o3)
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

use OpenHealthcare\ClinicForms\OpenMrs\O3FormExport;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Words;

$folder = $argv[1] ?? dirname(__DIR__) . '/build/o3';
@mkdir($folder, 0777, true);
$english = DocumentLibrary::shipped();
$written = [];
$not = [];
foreach (array_keys(Words::LANGUAGES) as $language) {
    foreach (($language === 'en' ? $english : DocumentLibrary::shipped($language))->all() as $doc) {
        if ($doc->language !== $language || !$doc->validated) {
            continue;
        }
        $form = O3FormExport::form($doc, $language === 'en' ? null : $english->get($doc->id));
        $name = $doc->id . ($language === 'en' ? '' : "-$language");
        if ($form === null) {
            $not[] = $name;
            continue;
        }
        file_put_contents("$folder/$name.json", json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $written[] = $name;
    }
}
echo 'wrote ' . count($written) . " OpenMRS forms to $folder: " . implode(', ', $written) . "\n";
echo 'no CIEL concepts for: ' . implode(', ', $not) . "\n";
