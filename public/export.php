<?php

/**
 * Clinic Forms as files: the chosen documents as one Word (.docx), LibreOffice (.odt) or PDF file,
 * or one of the clinic's own Word or LibreOffice documents, filled with the same details the
 * printed packet would have; or as FHIR Questionnaires ("fhir"), blank forms with the clinic's
 * details and never a patient's. The file is built in memory and sent; nothing is stored, except with
 * "pdf-documents", which files the PDF in the patient's Documents instead of sending it, and with
 * "oe-questionnaires", which adds the Questionnaires to OpenEMR's own questionnaires instead.
 * A PDF is made by LibreOffice, tagged for PDF/UA, when it is installed on the server (LibreOfficePdf),
 * and by OpenEMR's mPDF otherwise. "pdf-blank" sends the blank documents' accessible PDFs made when
 * the module was released (BlankPdfs), for servers without LibreOffice: one PDF, or several in a .zip.
 * "accessible" sends the accessible copy this server can make (AccessibleCopy): LibreOffice's PDF/UA,
 * the release's PDF/UA copies for blank documents, or else the Word file; never mPDF's untagged PDF.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenHealthcare\ClinicForms\Office\AccessibleCopy;
use OpenHealthcare\ClinicForms\Office\BlankPdfs;
use OpenHealthcare\ClinicForms\Office\LibreOfficePdf;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\PdfWriter;
use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Compat\OpenEmr;
use OpenHealthcare\ClinicForms\Event\DocumentFillEvent;
use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Fhir\QuestionnaireRegistration;
use OpenHealthcare\ClinicForms\Fhir\VisitOffering;
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentFill;
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
// Added to OpenEMR's questionnaires, they are the same blank Questionnaires the FHIR file holds.
$toQuestionnaires = $format === 'oe-questionnaires';
$fhir = $format === 'fhir' || $toQuestionnaires;
// The release's blank PDFs never carry a patient.
$blankPdfs = $format === 'pdf-blank';
// The accessible copy: a patient who uses a screen reader gets one they can read.
$accessible = $format === 'accessible';
$patient = !$fhir && !$blankPdfs && ($toDocuments || !empty($_POST['fill_patient'])) && $pid > 0 ? $site->patient($pid) : [];
$recipientId = (int)($_POST['recipient'] ?? 0);
$today = function_exists('oeFormatShortDate') ? (string)oeFormatShortDate(date('Y-m-d')) : date('Y-m-d');
// What the chart already holds, carried into the questions that ask it, for the patient to check (Fill).
[$chart, $chartCodes] = $patient !== [] ? $site->chart($pid) : [[], []];
$context = new Context(
    $site->facility((int)($_POST['facility'] ?? 0)),
    $patient,
    $recipientId > 0 ? $site->recipient($recipientId) : [],
    $today,
    null,
    $site->user((int)(OpenEmr::session('authUserID') ?? 0)),
    $site->provider((int)($_POST['provider'] ?? 0)),
    $chart,
    Settings::forSite($siteDir)->site(),
    $chartCodes,
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

/**
 * Adds each document's Questionnaire to OpenEMR's questionnaires, or updates the one it added
 * before, and goes back to the page saying what was done with each.
 *
 * With visit types picked (OpenEMR 8.5 and later), each one added or updated also becomes an
 * encounter form of its own, listed in those visits' Questionnaires menu.
 *
 * @param list<\OpenHealthcare\ClinicForms\Paper\Document> $documents
 * @param array<string, \OpenHealthcare\ClinicForms\Paper\Document> $english
 * @param list<int> $visitTypes calendar category ids, as VisitOffering::chosen() returns them
 * @param list<string> $surfaces other surfaces to offer them on, as VisitOffering::surfaces() returns them
 */
function clinicFormsAddQuestionnaires(QuestionnaireExport $export, array $documents, array $english, array $visitTypes = [], array $surfaces = []): never
{
    $registration = new QuestionnaireRegistration(OpenEmr::questionnaire(...), OpenEmr::saveQuestionnaire(...));
    $offering = new VisitOffering(OpenEmr::registerEncounterForm(...), OpenEmr::offerInVisitType(...), OpenEmr::offeredInEveryVisit(...));
    $titles = [];
    $rows = [];
    foreach ($documents as $doc) {
        try {
            $q = $export->questionnaire($doc, $english[$doc->id] ?? null);
            [$what, $rowId] = $registration->register($q);
            $offered = ['offered' => false, 'everywhere' => false];
            if (($what === QuestionnaireRegistration::ADDED || $what === QuestionnaireRegistration::UPDATED) && $rowId !== null) {
                $offered = $offering->offer($rowId, trim(is_string($q['title'] ?? null) ? $q['title'] : $doc->title), $visitTypes);
                foreach ($surfaces as $surface) {
                    OpenEmr::offerOnSurface($rowId, $surface);
                    $titles['on ' . $surface][] = $doc->title;
                }
            }
        } catch (\Throwable $e) {
            error_log('Clinic Forms: ' . $doc->id . ' was not added to OpenEMR questionnaires: ' . $e->getMessage());
            [$what, $rowId] = ['failed', null];
            $offered = ['offered' => false, 'everywhere' => false];
        }
        $titles[$what][] = $doc->title;
        if ($offered['offered']) {
            $titles[$offered['everywhere'] ? 'offered everywhere' : 'offered'][] = $doc->title;
        }
        if ($what === QuestionnaireRegistration::ADDED || $what === QuestionnaireRegistration::UPDATED) {
            $rows[] = $what . ' ' . $doc->stamp() . ' as questionnaire ' . $rowId
                . ($offered['offered'] ? ', offered in visit types ' . implode(' ', $visitTypes) : '')
                . ($surfaces !== [] ? ', offered on ' . implode(' ', $surfaces) : '');
        }
    }
    if ($rows !== []) {
        OpenEmr::audit('other', 'Clinic Forms in OpenEMR questionnaires: ' . implode(', ', $rows));
    }
    $said = [
        QuestionnaireRegistration::ADDED => xl('Added to OpenEMR questionnaires'),
        QuestionnaireRegistration::UPDATED => xl('Updated in OpenEMR questionnaires'),
        QuestionnaireRegistration::NOTHING_TO_ANSWER => xl('Left out, since they have nothing to answer'),
        QuestionnaireRegistration::NOT_OURS => xl('Left as they are: OpenEMR already has a questionnaire with the same title that Clinic Forms did not add'),
        QuestionnaireRegistration::TURNED_OFF => xl('Left as they are: turned off in OpenEMR'),
        'failed' => xl('Could not be added (the PHP error log says why)'),
        'offered' => xl('Listed in the Questionnaires menu of the visit types ticked'),
        'offered everywhere' => xl('Already offered in every visit, so the visit types ticked add nothing; to narrow it, use Availability on the Questionnaires page of the patient menu (administrators)'),
        'on smart' => xl('Offered to SMART apps launched from the patient menu, Assessments, FHIR Assessments'),
        'on portal' => xl('Kept for the patient portal, for when the OpenEMR portal reads these settings (the portal in OpenEMR 8.5 does not yet)'),
    ];
    $lines = [];
    foreach ($said as $what => $words) {
        if (isset($titles[$what])) {
            $lines[] = $words . ': ' . implode(', ', $titles[$what]) . '.';
        }
    }
    if ($rows !== []) {
        $lines[] = xl('To fill one in for a patient: in an encounter, Questionnaires, then New Questionnaire; or, on OpenEMR 8.3 and later, the patient menu, Assessments, then Questionnaires.');
    }
    header('Location: index.php?' . http_build_query(['cf_msg' => implode(' ', $lines), 'cf_ok' => $rows !== [] && !isset($titles['failed']) ? 1 : 0]));
    exit;
}

$template = (string)($_POST['template'] ?? '');
$versions = [];
$viaLibreOffice = false;
if ($template !== '' && $toDocuments) {
    clinicFormsRefuse(400, xl('Only Clinic Forms documents are saved to Documents as PDF; download a Word or LibreOffice document to edit it.'));
}
if ($template !== '' && $toQuestionnaires) {
    clinicFormsRefuse(400, xl('Only Clinic Forms documents can be added to OpenEMR questionnaires.'));
}
if ($toQuestionnaires && !OpenEmr::canAddQuestionnaires()) {
    clinicFormsRefuse(403, xl('You are not allowed to add OpenEMR questionnaires.'));
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
    $libreOffice = (str_starts_with($format, 'pdf') || $accessible) && !$blankPdfs && Settings::forSite($siteDir)->pdfLibreOffice ? LibreOfficePdf::find() : null;
    if (str_starts_with($format, 'pdf') && !$blankPdfs && $libreOffice === null && !PdfWriter::available()) {
        clinicFormsRefuse(422, xl('This OpenEMR has no PDF writer (mPDF), so the PDF could not be made.'));
    }
    if ($toDocuments && ($patient === [] || !OpenEmr::canWriteDocuments())) {
        clinicFormsRefuse(403, $patient === [] ? xl('Choose a patient first: the PDF is saved in their Documents.') : xl('You are not allowed to add to patients Documents.'));
    }
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
    if ($fhir) {
        // A translation carries its instrument's codes only when it keeps the English document's instrument.
        $english = [];
        $englishLibrary = $language === 'en' ? null : DocumentLibrary::forSite($siteDir, 'en', $pack?->id, $region);
        foreach ($documents as $doc) {
            $en = $englishLibrary?->get($doc->id);
            if ($en !== null && $doc->language !== 'en') {
                $english[$doc->id] = $en;
            }
        }
        // The richer forms (answer grids, signatures, TAPS-1's skip by sex) only where this site's runtime runs them.
        $export = new QuestionnaireExport($context->withoutPatient(), $language, $taglines, QuestionnaireExport::rich());
        if ($toQuestionnaires) {
            $visitTypes = OpenEmr::canOfferByVisitType() ? VisitOffering::chosen($_POST['visit_type'] ?? [], OpenEmr::visitTypes()) : [];
            $surfaces = OpenEmr::canOfferByVisitType() ? VisitOffering::surfaces($_POST['surface'] ?? []) : [];
            clinicFormsAddQuestionnaires($export, $documents, $english, $visitTypes, $surfaces);
        }
        $out = json_encode($export->export($documents, $english), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $ext = 'json';
        $mime = 'application/fhir+json';
    } else {
        // Another module may fill a table a document names (Sliding Fee: the notice's discounts).
        $filled = DocumentFill::apply($documents, static fn(DocumentFillEvent $e) => OpenEmr::dispatch($e, DocumentFillEvent::EVENT_NAME), $patient !== [] ? $pid : null);
        // LibreOffice gives the file one page header, so a document filed without a name, beside one with
        // the patient's, goes to mPDF, which gives each document its own (or, for the accessible copy, to Word).
        $mixed = $patient !== [] && array_filter($filled, static fn($d): bool => $d->anonymous) !== [];
        // The release's copies were made before the clinic's own details were known, so a document another
        // module fills has none.
        $releaseFiles = $blankPdfs || ($accessible && $patient === [] && $libreOffice === null)
            ? ($pack?->id === BlankPdfs::PACK && array_map(static fn($d) => $d->blocks, $filled) === array_map(static fn($d) => $d->blocks, $documents)
                ? (new BlankPdfs())->files($documents, Taglines::forSite($siteDir, BlankPdfs::PACK)->forPage($language)) : null)
            : null;
        $route = $accessible ? AccessibleCopy::route($libreOffice !== null, $mixed, $patient !== [], $releaseFiles !== null) : null;
        if ($blankPdfs || $route === AccessibleCopy::BLANK_PDF) {
            $files = $releaseFiles;
            if ($files === null) {
                clinicFormsRefuse(422, xl('No accessible PDF was made for every ticked document as your clinic has it: a document of your own, your own taglines, one another module fills in, or one not written in this language. The LibreOffice and Word files are accessible; download one of those instead.'));
            }
            [$out, $ext, $mime] = count($files) === 1 ? [reset($files), 'pdf', 'application/pdf'] : [Zip::build($files), 'zip', 'application/zip'];
        } else {
            $viaLibreOffice = $libreOffice !== null && !$mixed && ($route === null || $route === AccessibleCopy::LIBREOFFICE_PDF);
            $writer = match (true) {
                $format === 'odt' => new OdtWriter(Words::t('Date', $language), $language),
                $viaLibreOffice => new OdtWriter(Words::t('Date', $language), $language, $context->value('patient.id'), xl('Chart number') . ' ' . $context->value('patient.id')),
                $accessible => new WordWriter(Words::t('Date', $language), $language),
                str_starts_with($format, 'pdf') => new PdfWriter(Words::t('Date', $language), $context->value('patient.id')),
                default => new WordWriter(Words::t('Date', $language), $language),
            };
            $out = (new OfficeExport($context, $language, $taglines))->write($filled, $writer, $paper);
            $ext = $writer->extension();
            $mime = $writer->mimeType();
            if ($viaLibreOffice) {
                $pdf = $libreOffice->convert($out);
                if ($pdf === null && $accessible) {
                    // The accessible copy is never mPDF's untagged PDF: the Word file instead.
                    $word = new WordWriter(Words::t('Date', $language), $language);
                    [$out, $ext, $mime] = [(new OfficeExport($context, $language, $taglines))->write($filled, $word, $paper), $word->extension(), $word->mimeType()];
                } else {
                    if ($pdf === null && !PdfWriter::available()) {
                        clinicFormsRefuse(500, xl('LibreOffice could not make the PDF, and this OpenEMR has no other PDF writer (mPDF).'));
                    }
                    // If LibreOffice fails, mPDF makes the PDF as before.
                    $out = $pdf ?? (new OfficeExport($context, $language, $taglines))->write($filled, new PdfWriter(Words::t('Date', $language), $context->value('patient.id')), $paper);
                    [$ext, $mime] = ['pdf', 'application/pdf'];
                }
            }
        }
        $documents = $filled;
    }
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
    OpenEmr::audit('patient-record-insert', 'Clinic Forms saved to Documents as ' . $filename . ' (document ' . $documentId . ', category ' . $categoryName
        . ($viaLibreOffice ? ', PDF/UA by LibreOffice' : '') . '): ' . implode(', ', $versions), $pid);
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
