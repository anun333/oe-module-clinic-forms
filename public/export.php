<?php

/**
 * Clinic Forms for editing: the chosen documents as one Word (.docx) or LibreOffice (.odt) file,
 * or one of the clinic's own Word or LibreOffice documents, filled with the same details the
 * printed packet would have. The file is built in memory and sent; nothing is stored.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Words;
use OpenHealthcare\ClinicForms\Repository\SiteData;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: Clinic Forms', xl('Clinic Forms'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
    CsrfUtils::csrfNotVerified();
}

$siteDir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR');
$site = new SiteData();
$pid = (int)($session->get('pid') ?? 0);
$patient = !empty($_POST['fill_patient']) && $pid > 0 ? $site->patient($pid) : [];
$recipientId = (int)($_POST['recipient'] ?? 0);
$today = function_exists('oeFormatShortDate') ? (string)oeFormatShortDate(date('Y-m-d')) : date('Y-m-d');
$context = new Context(
    $site->facility((int)($_POST['facility'] ?? 0)),
    $patient,
    $recipientId > 0 ? $site->recipient($recipientId) : [],
    $today,
    null,
    $site->user((int)($session->get('authUserID') ?? 0)),
);
$paper = ($_POST['paper'] ?? '') === 'a4' ? 'A4' : 'letter';
$language = Words::language($_POST['language'] ?? 'en');
$taglines = !empty($_POST['taglines']) ? Taglines::forSite($siteDir)->forPage($language) : [];

/** Ends with a short plain message, for a request that can't produce a file. */
function clinicFormsRefuse(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$template = (string)($_POST['template'] ?? '');
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
    $writer = ($_POST['format'] ?? '') === 'odt' ? new OdtWriter(Words::t('Date', $language)) : new WordWriter(Words::t('Date', $language));
    $library = DocumentLibrary::forSite($siteDir, $language);
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
    $stem = count($documents) === 1 ? $documents[0]->id : 'clinic-forms';
}

if ($patient !== []) {
    EventAuditLogger::getInstance()->newEvent(
        'patient-record-select',
        (string)$session->get('authUser'),
        (string)$session->get('authProvider'),
        1,
        'Clinic Forms downloaded for editing (' . $ext . ') with patient details: ' . implode(', ', $titles),
        $pid,
    );
}

$suffix = $patient !== [] ? '_' . preg_replace('/[^A-Za-z0-9-]+/', '-', $patient['id'] !== '' ? $patient['id'] : (string)$pid) : '';
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $stem) . $suffix . '.' . $ext;
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
