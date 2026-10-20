<?php

/**
 * Writes every shipped document, in English and in each translation, as a FHIR R4 Questionnaire:
 * <folder>/<id>.json, and <id>-<language>.json for a translation. Blank forms with no clinic's
 * details (a placeholder for the clinic is a blank line), and the US pack's taglines on patient
 * documents. For checking with the HL7 FHIR validator, and for publishing.
 *
 *   php tools/export-questionnaires.php [folder]     (default build/questionnaires)
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

use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Words;

if (!function_exists('xl')) {
    // OpenEMR's translation; outside OpenEMR, English stays English.
    function xl(string $s): string
    {
        return $s;
    }
}

$folder = $argv[1] ?? dirname(__DIR__) . '/build/questionnaires';
@mkdir($folder, 0777, true);
$english = DocumentLibrary::shipped();
$written = 0;
foreach (array_keys(Words::LANGUAGES) as $language) {
    $library = $language === 'en' ? $english : DocumentLibrary::shipped($language);
    $export = new QuestionnaireExport(new Context([]), $language, Taglines::forSite(null)->forPage($language));
    foreach ($library->all() as $doc) {
        if ($doc->language !== $language) {
            continue;  // no translation yet: the library offers the English one
        }
        $q = $export->questionnaire($doc, $language === 'en' ? null : $english->get($doc->id));
        file_put_contents("$folder/{$q['id']}.json", json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $written++;
    }
}
echo "wrote $written questionnaires to $folder\n";
