<?php

/**
 * Clinic Forms as files: the chosen documents as one Word (.docx), LibreOffice (.odt) or PDF file,
 * or one of the clinic's own Word or LibreOffice documents, filled with the same details the
 * printed packet would have. The file is built in memory and sent; nothing is stored, except with
 * "pdf-documents", which files the PDF in the patient's Documents instead of sending it.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\PdfWriter;
use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Compat\OpenEmr;
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Pack;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Settings;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Words;
use OpenHealthcare\ClinicForms\Repository\SiteData;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('ACL check failed for patients/demo: Clinic Forms', xl('Clinic Forms'));
}
OpenEmr::requireCsrf($_POST['csrf_token_form'] ?? '');

$siteDir = OpenEmr::siteDir();
$site = new SiteData();
[$pack] = Pack::forSite($siteDir, $site->facilities()[0]['country'] ?? '');
$region = $pack?->region($site->facilityState((int)($_POST['facility'] ?? 0)));
$pid = (int)(OpenEmr::session('pid') ?? 0);
$format = (string)($_POST['format'] ?? '');
// A copy for the patient's Documents always names the patient whose chart it goes in.
$toDocuments = $format === 'pdf-documents';
$patient = ($toDocuments || !empty($_POST['fill_patient'])) && $pid > 0 ? $site->patient($pid) : [];
$recipientId = (int)($_POST['recipient'] ?? 0);
$today = function_exists('oeFormatShortDate') ? (string)oeFormatShortDate(date('Y-m-d')) : date('Y-m-d');
$context = new Context(
    $site->facility((int)($_POST['facility'] ?? 0)),
    $patient,
    $recipientId > 0 ? $site->recipient($recipientId) : [],
    $today,
    null,
    $site->user((int)(OpenEmr::session('authUserID') ?? 0)),
    $site->provider((int)($_POST['provider'] ?? 0)),
);
$paper = ($_POST['paper'] ?? '') === 'a4' ? 'A4' : 'letter';
$language = Words::language($_POST['language'] ?? 'en');
$taglines = !empty($_POST['taglines']) ? Taglines::forSite($siteDir, $pack?->id)->forPage($language) : [];

/** Ends with a short plain message, for a request that can't produce a file. */
function clinicFormsRefuse(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$template = (string)($_POST['template'] ?? '');
$versions = [];
if ($template !== '' && $toDocuments) {
    clinicFormsRefuse(400, xl('Only Clinic Forms documents are saved to Documents as PDF; download a Word or LibreOffice document to edit it.'));
}
if ($template !== '') {
    $library = TemplateLibrary::forSite($siteDir);
    $bytes = $library->bytes($template);
    if ($bytes === null) {
        clinicFormsRefuse(404, xl('That Word or LibreOffice document is no longer there.'));
    }
    try {
        $out = TemplateFiller::fill($bytes, $context);
    } catch (\InvalidArgumentException $e) {
        clinicFormsRefuse(422, xl('This document could not be filled') . ': ' . $e->getMessage());
    }
    $ext = strtolower(pathinfo($template, PATHINFO_EXTENSION));
    $mime = $ext === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $titles = [TemplateLibrary::displayName($template)];
    $stem = pathinfo($template, PATHINFO_FILENAME);
} else {
    if (str_starts_with($format, 'pdf') && !PdfWriter::available()) {
        clinicFormsRefuse(422, xl('This OpenEMR has no PDF writer (mPDF), so the PDF could not be made.'));
    }
    if ($toDocuments && ($patient === [] || !OpenEmr::canWriteDocuments())) {
        clinicFormsRefuse(403, $patient === [] ? xl('Choose a patient first: the PDF is saved in their Documents.') : xl('You are not allowed to add to patients Documents.'));
    }
    $writer = match (true) {
        $format === 'odt' => new OdtWriter(Words::t('Date', $language)),
        str_starts_with($format, 'pdf') => new PdfWriter(Words::t('Date', $language), $context->value('patient.id')),
        default => new WordWriter(Words::t('Date', $language)),
    };
    $library = DocumentLibrary::forSite($siteDir, $language, $pack?->id, $region);
    $documents = [];
    foreach (explode(',', (string)($_POST['order'] ?? '')) as $item) {
        if (Packets::isItem($item) && str_starts_with($item, 'doc:')) {
            $doc = $library->get(substr($item, 4));
            if ($doc !== null) {
                $documents[] = $doc;
            }
        }
    }
    if ($documents === []) {
        clinicFormsRefuse(400, xl('Tick at least one document. Layout forms (Demographics, History and the like) print from the browser only.'));
    }
    $out = (new OfficeExport($context, $language, $taglines))->write($documents, $writer, $paper);
    $ext = $writer->extension();
    $mime = $writer->mimeType();
    $titles = array_map(static fn($d): string => $d->title, $documents);
    $versions = array_map(static fn($d): string => $d->stamp(), $documents);
    $stem = count($documents) === 1 ? $documents[0]->id : 'clinic-forms';
}

$suffix = $patient !== [] ? '_' . preg_replace('/[^A-Za-z0-9-]+/', '-', $patient['id'] !== '' ? $patient['id'] : (string)$pid) : '';
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $stem) . $suffix . '.' . $ext;

if ($toDocuments) {
    $categoryName = Settings::forSite($siteDir)->documentsCategory;
    $categoryId = $site->documentCategory($categoryName);
    [$documentId, $error] = $categoryId === null ? [null, 'no Documents category'] : OpenEmr::saveDocument($pid, $categoryId, $filename, $mime, $out);
    if ($documentId === null) {
        header('Location: index.php?' . http_build_query(['cf_msg' => xl('The PDF was not saved to Documents') . ': ' . $error, 'cf_ok' => 0]));
        exit;
    }
    OpenEmr::audit('patient-record-insert', 'Clinic Forms saved to Documents as ' . $filename . ' (document ' . $documentId . ', category ' . $categoryName . '): ' . implode(', ', $versions), $pid);
    header('Location: index.php?' . http_build_query(['cf_msg' => xl('Saved in the patient chart, under Documents') . ': ' . $filename, 'cf_ok' => 1]));
    exit;
}

if ($patient !== []) {
    OpenEmr::audit('patient-record-select', 'Clinic Forms downloaded for editing (' . $ext . ') with patient details: ' . implode(', ', $titles), $pid);
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
