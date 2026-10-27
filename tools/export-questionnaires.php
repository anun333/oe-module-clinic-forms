<?php

/**
 * Writes every shipped document, in English and in each translation, as a FHIR R4 Questionnaire:
 * <folder>/<id>.json, and <id>-<language>.json for a translation. Blank forms with no clinic's
 * details (a placeholder for the clinic is a blank line), and the US pack's taglines on patient
 * documents. For checking with the HL7 FHIR validator, and for publishing.
 *
 *   php tools/export-questionnaires.php [folder] [--rich]     (default build/questionnaires)
 *
 * --rich writes the forms OpenEMR's questionnaire runtime 0.4.0 and later gets (QuestionnaireExport::rich()):
 * answer grids, gtables, the patient's signature, TAPS-1's skip by sex. Without it, the plain ones.
 *
 * A document that includes a shared block of questions standing on its own (QuestionnaireExport::forms())
 * is also written as its modular form, <id>-modular.json (<id>-<language>-modular.json), and each such
 * block as block-<block>.json (block-<block>-<language>.json): SDC 4.0.0 Modular Forms. <id>.json stays
 * the assembled form, which form fillers run. A block's items must be the same in every document that
 * includes it, or the export stops.
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

$args = array_slice($argv, 1);
$rich = in_array('--rich', $args, true);
$folder = array_values(array_filter($args, static fn(string $a): bool => $a !== '--rich'))[0] ?? dirname(__DIR__) . '/build/questionnaires';
@mkdir($folder, 0777, true);
$english = DocumentLibrary::shipped();
$written = 0;
foreach (array_keys(Words::LANGUAGES) as $language) {
    $library = $language === 'en' ? $english : DocumentLibrary::shipped($language);
    $export = new QuestionnaireExport(new Context([]), $language, Taglines::forSite(null)->forPage($language), $rich);
    $blocks = [];  // block id => [the document it came from first, its items, its Questionnaire]
    foreach ($library->all() as $doc) {
        if ($doc->language !== $language) {
            continue;  // no translation yet: the library offers the English one
        }
        $forms = $export->forms($doc, $language === 'en' ? null : $english->get($doc->id));
        foreach (array_filter([$forms['assembled'], $forms['modular']]) as $q) {
            file_put_contents("$folder/{$q['id']}.json", json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            $written++;
        }
        foreach ($forms['blocks'] as $block) {
            $id = $block['include']['block'];
            if (isset($blocks[$id]) && $blocks[$id][1] !== $block['items']) {
                fwrite(STDERR, "block $id ($language): its items in {$doc->id} differ from those in {$blocks[$id][0]}\n");
                exit(1);
            }
            $blocks[$id] ??= [$doc->id, $block['items'], $export->blockQuestionnaire($block['include'], $block['language'], $block['items'], $block['copyright'])];
        }
    }
    foreach ($blocks as [, , $q]) {
        file_put_contents("$folder/{$q['id']}.json", json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $written++;
    }
}
echo "wrote $written " . ($rich ? 'rich ' : '') . "questionnaires to $folder\n";
