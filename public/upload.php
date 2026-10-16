<?php

/**
 * Adds or removes one of the clinic's own Word or LibreOffice documents. Administrators only.
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
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;

if (!AclMain::aclCheckCore('admin', 'super')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for admin/super: Clinic Forms templates', xl('Clinic Forms'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
    CsrfUtils::csrfNotVerified();
}

$library = TemplateLibrary::forSite(OEGlobalsBag::getInstance()->getString('OE_SITE_DIR'));
$ok = false;
if (($_POST['action'] ?? '') === 'remove') {
    $file = (string)($_POST['file'] ?? '');
    $ok = $library->remove($file);
    $message = $ok ? xl('Removed') . ': ' . TemplateLibrary::displayName($file) : xl('There is no such document to remove.');
} else {
    $upload = $_FILES['template'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        $message = xl('Choose a Word (.docx) or LibreOffice (.odt) document to add.');
    } else {
        try {
            $added = $library->add((string)$upload['tmp_name'], (string)$upload['name']);
            $ok = true;
            $message = xl('Added') . ': ' . TemplateLibrary::displayName($added['file']) . '. '
                . ($added['known'] !== [] ? xl('Placeholders it fills') . ': ' . implode(' ', $added['known']) . '. ' : xl('It has no placeholders, so it downloads as written.') . ' ')
                . ($added['unknown'] !== [] ? xl('Not filled by Clinic Forms (left as typed)') . ': ' . implode(' ', $added['unknown']) . '.' : '');
        } catch (\InvalidArgumentException $e) {
            $message = xl('Not added') . ': ' . $e->getMessage();
        }
    }
}
if ($ok) {
    EventAuditLogger::getInstance()->newEvent('other', (string)$session->get('authUser'), (string)$session->get('authProvider'), 1, 'Clinic Forms template ' . (($_POST['action'] ?? '') === 'remove' ? 'removed' : 'added') . ': ' . $message);
}
header('Location: index.php?' . http_build_query(['cf_msg' => $message, 'cf_ok' => $ok ? 1 : 0]));
