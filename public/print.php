<?php

/**
 * Clinic Forms: the chosen documents and layout forms as one printable packet. Printing goes
 * through OpenEMR's own print logging (Administration > Config > Logging > Printing Log Option).
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
use OpenEMR\Services\LogoService;
use OpenHealthcare\ClinicForms\Bootstrap;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Repository\SiteData;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: Clinic Forms', xl('Clinic Forms'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
    CsrfUtils::csrfNotVerified();
}

$library = DocumentLibrary::forSite(OEGlobalsBag::getInstance()->getString('OE_SITE_DIR'));
$site = new SiteData();
$items = array_values(array_filter(
    explode(',', (string)($_POST['order'] ?? '')),
    static fn(string $item): bool => Packets::isItem($item),
));
$facility = $site->facility((int)($_POST['facility'] ?? 0));
$recipientId = (int)($_POST['recipient'] ?? 0);
$recipient = $recipientId > 0 ? $site->recipient($recipientId) : [];
$pid = (int)($session->get('pid') ?? 0);
$patient = !empty($_POST['fill_patient']) && $pid > 0 ? $site->patient($pid) : [];
$paper = ($_POST['paper'] ?? '') === 'a4' ? 'A4' : 'letter';

$logo = (new LogoService())->getLogo('core/menu/primary');
$today = function_exists('oeFormatShortDate') ? (string)oeFormatShortDate(date('Y-m-d')) : date('Y-m-d');
$renderer = new Renderer(new Context($facility, $patient, $recipient, $today, $logo !== '' ? $logo : null));

$pages = [];
$titles = [];
foreach ($items as $item) {
    if (str_starts_with($item, 'doc:')) {
        $doc = $library->get(substr($item, 4));
        if ($doc !== null) {
            $pages[] = $renderer->document($doc);
            $titles[] = $doc->title;
        }
    } else {
        $form = $site->layoutForm(substr($item, 7));
        if ($form !== null) {
            $pages[] = $renderer->layoutForm($form);
            $titles[] = $form->title;
        }
    }
}

if ($patient !== []) {
    // The event the Staff Worklist and core patient pages use, so the log viewer's Patient Record
    // filter finds it.
    EventAuditLogger::getInstance()->newEvent(
        'patient-record-select',
        (string)$session->get('authUser'),
        (string)$session->get('authProvider'),
        1,
        'Clinic Forms printed with patient details: ' . implode(', ', $titles),
        $pid,
    );
}
$printLog = xl('Clinic Forms') . ': ' . implode(', ', $titles) . ($patient !== [] ? ' (' . xl('patient') . ' ' . ($patient['id'] ?? '') . ')' : ' (' . xl('blank') . ')');
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo text(xl('Clinic Forms') . ': ' . implode(', ', $titles)); ?></title>
    <link rel="stylesheet" href="<?php echo attr($moduleUrl . '/public/assets/print.css'); ?>">
    <style>@page { size: <?php echo $paper === 'A4' ? 'A4' : 'letter'; ?>; margin: 0.6in; }</style>
</head>
<body>
<div class="cf-toolbar">
    <button type="button" id="cf-print"><?php echo xlt('Print'); ?></button>
    <span><?php echo text(count($pages) . ' ' . xl('documents')); ?></span>
</div>
<main class="cf-packet">
    <?php
    if ($pages === []) {
        echo '<p>' . xlt('Nothing was chosen to print.') . '</p>';
    }
    // Each page is built by Renderer, which escapes every value it prints.
    echo implode("\n", $pages);
    ?>
</main>
<script>
    window.printlogdata = <?php echo json_encode($printLog); ?>;
    document.getElementById('cf-print').addEventListener('click', function () {
        // OpenEMR's print logging, when this page runs inside OpenEMR; plain printing otherwise.
        if (window.top && typeof window.top.printLogPrint === 'function' && window.top !== window) {
            window.top.printLogPrint(window);
        } else if (window.opener && typeof window.opener.top.printLogPrint === 'function') {
            window.opener.top.printLogPrint(window);
        } else {
            window.print();
        }
    });
</script>
</body>
</html>
