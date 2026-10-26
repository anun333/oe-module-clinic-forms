<?php

/**
 * Clinic Forms: choose the documents and layout forms to print, the clinic letterhead, the paper
 * size, and optionally a patient and an Address Book recipient to fill in.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenHealthcare\ClinicForms\Bootstrap;
use OpenHealthcare\ClinicForms\Compat\OpenEmr;
use OpenHealthcare\ClinicForms\Guide;
use OpenHealthcare\ClinicForms\Office\BlankPdfs;
use OpenHealthcare\ClinicForms\Office\LibreOfficePdf;
use OpenHealthcare\ClinicForms\Office\PdfWriter;
use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Instrument;
use OpenHealthcare\ClinicForms\Paper\Pack;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use OpenHealthcare\ClinicForms\Paper\Settings;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Words;
use OpenHealthcare\ClinicForms\Repository\SiteData;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('ACL check failed for patients/demo: Clinic Forms', xl('Clinic Forms'));
}

$siteDir = OpenEmr::siteDir();
$site = new SiteData();
$facilities = $site->facilities();
// The country pack (settings.json, else the first facility's country; blank is the US), and the
// region of the first facility. Printing uses the region of the facility chosen.
[$pack, $packNotes] = Pack::forSite($siteDir, $facilities[0]['country'] ?? '');
$packId = $pack?->id;
$region = $pack?->region($facilities[0]['state'] ?? '');
$library = DocumentLibrary::forSite($siteDir, 'en', $packId, $region);
$packets = Packets::forSite($library, $siteDir, $packId);
$spanish = DocumentLibrary::forSite($siteDir, 'es', $packId, $region);
$taglines = Taglines::forSite($siteDir, $packId);
$problems = array_merge($library->problems(), $packets->problems, $spanish->problems(), $taglines->problems);
// Which of each facility's state languages the taglines don't cover yet (Section 1557).
$taglineGaps = [];
foreach ($facilities as $f) {
    $code = $pack?->region($f['state']);
    if ($code !== null && $taglines->taglines !== [] && !isset($taglineGaps[$code])) {
        $taglineGaps[$code] = ['name' => $pack->regions[$code]['name']] + $pack->taglineGaps($code, array_keys($taglines->taglines));
    }
}
// A clinic's own version of a document the law says what goes in: say what its wording doesn't show.
$requirements = Requirements::shipped($packId);
foreach ($library->all() as $doc) {
    $missing = $doc->source === 'clinic' ? $requirements->missing($doc) : [];
    if ($missing !== []) {
        $problems[] = $doc->title . ' (' . xl('local version') . '): ' . xl('could not find') . ' ' . implode('; ', $missing)
            . '. ' . xl('Check that it has these') . ' (' . $requirements->source($doc->id) . ').';
    }
}
// A clinic's own version of a validated instrument (PHQ-9…): say where it departs from the published one,
// since a changed question, answer or score changes what the score means.
$shippedDocs = DocumentLibrary::shipped('en', $packId)->all();
foreach ($library->all() as $doc) {
    $differs = Instrument::localDifferences($doc, $shippedDocs[$doc->id] ?? null);
    if ($differs !== []) {
        $problems[] = $doc->title . ' (' . xl('local version') . '): ' . xl('differs from the published instrument, so its score may not mean what the published cut-offs say') . ': ' . implode('; ', $differs) . '.';
    }
}
foreach ($spanish->all() as $id => $es) {
    $en = $library->get($id);
    if ($es->source === 'clinic' && $es->language === 'es' && $en !== null && ($en->validated || ($shippedDocs[$id]->validated ?? false))) {
        $differs = Instrument::translationDifferences($en, $es);
        if ($differs !== []) {
            $problems[] = $es->title . ' (' . xl('local Spanish version') . '): ' . xl('differs from the English instrument') . ': ' . implode('; ', $differs) . '.';
        }
    }
}
$templates = TemplateLibrary::forSite($siteDir)->all();
$settings = Settings::forSite($siteDir);
// PDFs tagged for PDF/UA: every one by LibreOffice when the server has it; otherwise the blank ones made at release.
$libreOffice = $settings->pdfLibreOffice && LibreOfficePdf::find() !== null;
$pdfAvailable = $libreOffice || PdfWriter::available();
$blankPdfs = !$libreOffice && $packId === BlankPdfs::PACK;
$canSaveDocuments = OpenEmr::canWriteDocuments();
$canAddQuestionnaires = OpenEmr::canAddQuestionnaires();
// OpenEMR 8.5 and later offer a questionnaire by visit type (#14485); older ones don't, and show no choice.
$visitTypes = $canAddQuestionnaires && OpenEmr::canOfferByVisitType() ? OpenEmr::visitTypes() : [];
$documentsCategory = $settings->documentsCategory;
$isAdmin = AclMain::aclCheckCore('admin', 'super');
$csrf = OpenEmr::csrfToken();
$flash = is_string($_GET['cf_msg'] ?? null) ? $_GET['cf_msg'] : '';
$flashOk = ($_GET['cf_ok'] ?? '') === '1';
$recipients = $site->recipients();
// Letters are signed by the clinician printing them, or else the patient's own provider.
$providers = $site->providers();
$providerIds = array_column($providers, 'id');
$userId = (int)(OpenEmr::session('authUserID') ?? 0);
$layouts = $site->layoutForms($pack?->layouts ?? []);
$pid = (int)(OpenEmr::session('pid') ?? 0);
$patient = $pid > 0 ? $site->patient($pid) : [];
// Another module's link may tick documents and the patient fill ("?select=doc:a,doc:b&fill=1"); nothing prints until Print.
$preselect = array_values(array_filter(explode(',', is_string($_GET['select'] ?? null) ? $_GET['select'] : ''), static fn(string $i): bool => Packets::isItem($i)));
$prefill = ($_GET['fill'] ?? '') === '1';
$signer = in_array($userId, $providerIds, true) ? $userId : ($pid > 0 && in_array($site->patientProvider($pid), $providerIds, true) ? $site->patientProvider($pid) : 0);
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

$byCategory = [];
foreach ($library->all() as $doc) {
    $byCategory[$doc->category][] = [
        'value' => 'doc:' . $doc->id,
        'title' => $doc->title . ($doc->source === 'clinic' ? ' (' . xl('local version') . ')' : ''),
        'about' => $doc->about,
        'es' => $spanish->isTranslated($doc->id),
        'esOutOfDate' => isset($spanish->outOfDate()[$doc->id]),
        // Ours, made with machine help: 45 CFR 92.201(c)(3) wants a qualified human translator's review first.
        'esReview' => $spanish->isTranslated($doc->id) && ($spanish->get($doc->id)?->machineAssisted ?? false),
        'draft' => $doc->status === 'draft',
    ];
}
foreach ($layouts as $form) {
    $byCategory[xl('Blank layout forms') . ': ' . $form['category']][] = ['value' => 'layout:' . $form['id'], 'title' => $form['title'], 'about' => xl('The paper version of this form, in the order staff enter it')];
}
?>
<!DOCTYPE html>
<?php // English, as OpenEMR's own pages say: the module's text is English unless OpenEMR holds a translation of it, and
      // OpenEMR's language codes aren't language tags ("el" is its Latin American Spanish). ?>
<html lang="<?php echo attr(OpenEmr::pageLang()); ?>" dir="<?php echo attr(OpenEmr::pageDir()); ?>">
<head>
    <title><?php echo xlt('Clinic Forms'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\ClinicForms\Contrast::style(); ?>
    <style>
        .cf-cat { margin-top: 1rem; }
        .cf-about { color: #555; font-size: 0.85rem; }
        /* WCAG 2.1 AA contrast (4.5:1) on this page's background, where Bootstrap 4's own colours give 3.65 and 3.04 */
        code { color: #a61e5c; }
        .badge-info { background-color: #117a8b; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <h1 class="h4"><?php echo xlt('Clinic Forms'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p class="text-muted"><?php echo xlt('Print paperwork with the clinic details filled in, and blank paper versions of layout forms for intake by hand and entry later.'); ?>
        <?php echo xlt('Consents and releases are starting points: have them reviewed for your state before use.'); ?></p>
    <p class="cf-about" id="cf-pack">
        <?php if ($pack !== null) { ?>
            <?php echo xlt('Country pack'); ?>: <strong><?php echo text($pack->name); ?></strong><?php echo $region !== null ? ' (' . text($pack->regions[$region]['name'] . ', ' . $pack->isoRegion($region)) . ')' : ''; ?>.
            <?php echo xlt('Its documents follow the law and programmes of that country; the others apply anywhere.'); ?>
        <?php } else { ?>
            <?php echo xlt('No country pack: only the documents that apply anywhere are offered.'); ?>
        <?php } ?>
        <?php echo xlt('To choose another, put'); ?> <code><?php echo text('{"country": "us"}'); ?></code> <?php echo xlt('or'); ?> <code><?php echo text('{"country": "none"}'); ?></code>
        <?php echo xlt('in settings.json in your Clinic Forms folder.'); ?>
        <?php $hidden = array_keys(array_filter($library->withdrawn(), static fn(string $why): bool => $why === 'hidden')); ?>
        <?php if ($hidden !== []) { ?>
            <?php echo xlt('Hidden there'); ?>: <?php echo text(implode(', ', $hidden)); ?>.
        <?php } ?>
    </p>
    <?php if ($library->unstatedBasis() !== []) { ?>
        <div class="alert alert-light border cf-basis">
            <?php echo xlt('Your own versions of these documents do not say which version of the module they were copied from, so you will not be told when the module version changes. Add the based_on line shown to each'); ?>:
            <ul class="mb-0"><?php foreach ($library->unstatedBasis() as $u) { ?><li><?php echo text($u['title'] . ' (' . $u['id'] . ', ' . xl('the module has version') . ' ' . $u['version'] . '): '); ?><code><?php echo text('"based_on": "' . $u['fingerprint'] . '"'); ?></code></li><?php } ?></ul>
        </div>
    <?php } ?>
    <?php if ($packNotes !== []) { ?>
        <div class="alert alert-info"><?php foreach ($packNotes as $note) { ?><div><?php echo text($note); ?></div><?php } ?></div>
    <?php } ?>
    <?php if ($flash !== '') { ?>
        <div class="alert <?php echo $flashOk ? 'alert-success' : 'alert-warning'; ?>"><?php echo text($flash); ?></div>
    <?php } ?>
    <?php if ($problems !== []) { ?>
        <div class="alert alert-warning">
            <?php echo xlt('Some local documents or packets need attention'); ?>:
            <ul class="mb-0"><?php foreach ($problems as $problem) { ?><li><?php echo text($problem); ?></li><?php } ?></ul>
        </div>
    <?php } ?>
    <?php if (array_filter($byCategory, static fn(array $items): bool => array_filter($items, static fn(array $item): bool => !empty($item['esReview'])) !== []) !== []) { ?>
        <div class="alert alert-light border">
            <?php echo xlt('Spanish versions marked ES needs review were translated with machine help. Federal rules for these documents (45 CFR 92.201) ask that a qualified human translator review them before they go to patients. A reviewed copy can be saved as a local version'); ?>.
        </div>
    <?php } ?>
    <?php if ($spanish->outOfDate() !== []) { ?>
        <div class="alert alert-info">
            <?php echo xlt('These Spanish versions were made from older English wording, so the documents print in English until they are updated'); ?>:
            <?php echo text(implode(', ', array_map(static fn(string $id): string => $library->get($id)?->title ?? $id, array_keys($spanish->outOfDate())))); ?>
        </div>
    <?php } ?>

    <form method="post" action="<?php echo attr($moduleUrl . '/public/print.php'); ?>" target="_blank" id="cf-form">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="facility"><?php echo xlt('Letterhead'); ?></label>
                <select class="form-control" name="facility" id="facility">
                    <?php foreach ($facilities as $f) { ?>
                        <option value="<?php echo attr((string)$f['id']); ?>"><?php echo text($f['name']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-2">
                <label for="language"><?php echo xlt('Language'); ?></label>
                <select class="form-control" name="language" id="language">
                    <?php foreach (Words::LANGUAGES as $code => $name) { ?>
                        <option value="<?php echo attr($code); ?>"><?php echo text($name); ?></option>
                    <?php } ?>
                </select>
                <small class="form-text text-muted"><?php echo xlt('Documents marked ES print in Spanish; the rest stay in English.'); ?></small>
            </div>
            <div class="form-group col-md-2">
                <label for="paper"><?php echo xlt('Paper'); ?></label>
                <select class="form-control" name="paper" id="paper">
                    <option value="letter"<?php echo ($pack?->paper ?? 'a4') === 'letter' ? ' selected' : ''; ?>><?php echo xlt('Letter'); ?></option>
                    <option value="a4"<?php echo ($pack?->paper ?? 'a4') === 'a4' ? ' selected' : ''; ?>>A4</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label for="recipient"><?php echo xlt('Recipient (for referral letters and releases)'); ?></label>
                <select class="form-control" name="recipient" id="recipient">
                    <option value="0"><?php echo xlt('None: leave blank'); ?></option>
                    <?php foreach ($recipients as $r) { ?>
                        <option value="<?php echo attr((string)$r['id']); ?>"><?php echo text($r['label']); ?></option>
                    <?php } ?>
                </select>
                <small class="form-text text-muted"><?php echo xlt('From the Address Book (Administration > Address Book).'); ?></small>
            </div>
            <div class="form-group col-md-6">
                <label for="provider"><?php echo xlt('Signing provider (for letters and notes)'); ?></label>
                <select class="form-control" name="provider" id="provider">
                    <option value="0"><?php echo xlt('None: leave blank'); ?></option>
                    <?php foreach ($providers as $p) { ?>
                        <option value="<?php echo attr((string)$p['id']); ?>"<?php echo $p['id'] === $signer ? ' selected' : ''; ?>><?php echo text($p['name']); ?></option>
                    <?php } ?>
                </select>
                <small class="form-text text-muted"><?php echo xlt('Name, credentials (Valedictory) and NPI come from Administration > Users.'); ?></small>
            </div>
        </div>
        <?php if ($patient !== []) { ?>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="fill_patient" value="1" id="fill_patient"<?php echo $prefill ? ' checked' : ''; ?>>
                <label class="form-check-label" for="fill_patient">
                    <?php echo xlt('Fill in the current patient'); ?>: <?php echo text($patient['name'] . ($patient['dob'] !== '' ? ', ' . $patient['dob'] : '')); ?>
                </label>
                <small class="form-text text-muted"><?php echo xlt('Otherwise everything prints blank, to fill in by hand.'); ?></small>
            </div>
            <div class="form-check mb-2 ml-4">
                <input class="form-check-input" type="checkbox" name="fill_layouts" value="1" id="fill_layouts">
                <label class="form-check-label" for="fill_layouts"><?php echo xlt('Also fill Demographics, History and the UDS form with what is on file, for the patient to check and correct'); ?></label>
                <small class="form-text text-muted"><?php echo xlt('Social Security and driver license numbers print as their last four characters.'); ?></small>
            </div>
        <?php } ?>

        <?php if ($taglines->taglines !== []) { ?>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="taglines" value="1" id="taglines" checked>
            <label class="form-check-label" for="taglines"><?php echo xlt('Add the language assistance taglines to patient pages'); ?>
                (<?php echo text(implode(', ', array_keys($taglines->taglines))); ?>)</label>
            <?php if ($packId === 'us') { ?>
                <small class="form-text text-muted"><?php echo xlt('Section 1557 (45 CFR 92.11) asks for them on intake, consent and privacy forms, in English and the 15 languages most spoken in your state. Add yours in taglines.json in your Clinic Forms folder.'); ?></small>
            <?php } ?>
            <?php foreach ($taglineGaps as $gap) { ?>
                <small class="form-text text-muted cf-tagline-gap">
                    <?php echo text($gap['name']); ?>:
                    <?php if ($gap['missing'] !== []) { ?>
                        <?php echo xlt('no tagline yet for'); ?> <?php echo text(implode(', ', $gap['missing'])); ?>.
                    <?php } ?>
                    <?php if ($gap['groups'] !== []) { ?>
                        <?php echo xlt('The Census counts these as groups, so check the HHS list for which language'); ?>: <?php echo text(implode('; ', $gap['groups'])); ?>.
                    <?php } ?>
                    <?php echo xlt('From the Census Bureau (American Community Survey 2019-2023); HHS publishes the list the rule uses.'); ?>
                </small>
            <?php } ?>
        </div>
        <?php } ?>
        <div class="mb-2">
            <span class="mr-2"><?php echo xlt('Ready-made'); ?>:</span>
            <?php foreach ($packets->packets as $p) { ?>
                <button type="button" class="btn btn-sm btn-secondary mb-1 cf-packet-btn" data-items="<?php echo attr(implode(',', $p['items'])); ?>"<?php echo ($p['about'] ?? '') !== '' ? ' title="' . attr(xl($p['about'])) . '"' : ''; ?>><?php echo text(xl($p['title'])); ?></button>
            <?php } ?>
            <button type="button" class="btn btn-sm btn-link mb-1" id="cf-clear"><?php echo xlt('Clear'); ?></button>
        </div>

        <?php foreach ($byCategory as $category => $items) { ?>
            <div class="cf-cat"><h2 class="h6"><?php echo text($category); ?></h2>
                <?php foreach ($items as $item) { ?>
                    <div class="form-check">
                        <input class="form-check-input cf-item" type="checkbox" name="items[]" value="<?php echo attr($item['value']); ?>" id="<?php echo attr('i-' . $item['value']); ?>"<?php echo in_array($item['value'], $preselect, true) ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="<?php echo attr('i-' . $item['value']); ?>"><?php echo text($item['title']); ?><?php echo !empty($item['draft']) ? ' <span class="badge badge-warning">' . xlt('Draft') . '</span>' : ''; ?><?php echo !empty($item['es']) ? ' <span class="badge badge-info"><span aria-hidden="true">ES</span><span class="sr-only">' . xlt('Spanish') . '</span></span>' . (!empty($item['esReview']) ? ' <span class="badge badge-warning" title="' . xla('Spanish made with machine help: have a qualified translator review it before giving it to patients') . '">' . xlt('ES needs review') . '</span>' : '') : (!empty($item['esOutOfDate']) ? ' <span class="badge badge-light">' . xlt('ES out of date') . '</span>' : ''); ?>
                            <span class="cf-about">— <?php echo text($item['about']); ?></span></label>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
        <?php if ($templates !== []) { ?>
            <div class="cf-cat"><h2 class="h6"><?php echo xlt('Your Word and LibreOffice documents'); ?></h2>
                <p class="cf-about"><?php echo xlt('Downloaded filled with the letterhead, recipient and patient chosen above, to edit and print in Word or LibreOffice.'); ?></p>
                <?php foreach ($templates as $t) { ?>
                    <button type="submit" class="btn btn-sm btn-outline-secondary mb-1" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="template" value="<?php echo attr($t['file']); ?>">
                        <?php echo text($t['name']); ?> (.<?php echo text($t['format']); ?>)
                    </button>
                <?php } ?>
            </div>
        <?php } ?>
        <input type="hidden" name="order" id="cf-order" value="">
        <div class="mt-3">
            <button type="submit" class="btn btn-primary"><?php echo xlt('Open for printing'); ?></button>
            <div class="mt-2">
                <span class="mr-1"><?php echo xlt('Download'); ?>:</span>
                <?php
                // The accessible copy first: what a patient who uses a screen reader can read (AccessibleCopy).
                $accessibleKind = $libreOffice ? xl('PDF tagged for screen readers')
                    : ($blankPdfs ? xl('a tagged PDF when printed blank, otherwise Word') : xl('Word file'));
                ?>
                <button type="submit" class="btn btn-primary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="accessible"><?php echo xlt('Accessible copy'); ?> (<?php echo text($accessibleKind); ?>)</button>
                <button type="submit" class="btn btn-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="docx"><?php echo xlt('Word'); ?> (.docx)</button>
                <button type="submit" class="btn btn-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="odt"><?php echo xlt('LibreOffice'); ?> (.odt)</button>
                <?php if (!$libreOffice && PdfWriter::available()) { ?>
                    <button type="submit" class="btn btn-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="pdf"><?php echo xlt('Print copy'); ?> (<?php echo xlt('PDF, not tagged for screen readers'); ?>)</button>
                <?php } ?>
            </div>
            <small class="form-text text-muted"><?php echo xlt('Give a patient who uses a screen reader the accessible copy.'); ?>
                <?php if ($libreOffice) {
                    echo xlt('On this server it is a PDF made by LibreOffice and tagged for screen readers (PDF/UA), with your letterhead and the patient details; the Word file when a document printed without the patient name is ticked beside one that has it.');
                } elseif ($blankPdfs) {
                    echo xlt('On this server it is a PDF tagged for screen readers (PDF/UA) when the documents print blank, made when Clinic Forms was released: US Letter, with the language assistance taglines and no letterhead, several in a .zip. Filled for a patient, or with a document of your own, it is the Word file.');
                } else {
                    echo xlt('On this server it is the Word file, which a screen reader can read.');
                } ?>
                <?php echo xlt('The Word and LibreOffice files open in Word, LibreOffice or Google Docs to edit, and a screen reader can read them.'); ?>
                <?php echo $libreOffice ? '' : xlt('The print copy is for printing: its PDF has no tags for screen readers. With LibreOffice installed on the OpenEMR server, every PDF is tagged.'); ?>
                <?php echo xlt('Layout forms print from the browser only.'); ?></small>
            <?php if ($pdfAvailable && $patient !== [] && $canSaveDocuments) { ?>
                <button type="submit" class="btn btn-outline-primary mt-2" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="pdf-documents"><?php echo xlt('Save a PDF copy in the patient chart'); ?></button>
                <small class="form-text text-muted"><?php echo xlt('The ticked documents, filled for this patient, filed under Documents'); ?> (<?php echo text($documentsCategory); ?>).</small>
            <?php } ?>
            <div class="mt-2">
                <button type="submit" class="btn btn-outline-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="fhir"><?php echo xlt('FHIR Questionnaire'); ?> (.json)</button>
                <small class="form-text text-muted"><?php echo xlt('The ticked documents as blank forms to fill on screen, in OpenEMR questionnaires or another system. Never with patient details. Validated screening instruments carry their LOINC codes and points.'); ?></small>
            </div>
            <?php if ($canAddQuestionnaires) { ?>
                <div class="mt-2">
                    <button type="submit" class="btn btn-outline-primary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="oe-questionnaires"><?php echo xlt('Add to OpenEMR questionnaires'); ?></button>
                    <small class="form-text text-muted"><?php echo xlt('Staff can then enter the answers from a paper form in OpenEMR: in an encounter, Questionnaires, then New Questionnaire, or (8.3 and later) the patient menu, Assessments, then Questionnaires. OpenEMR adds up the score and keeps the answers in the chart. Adding a form again updates it; a questionnaire Clinic Forms did not add is left alone.'); ?></small>
                    <?php if ($visitTypes !== []) { ?>
                        <fieldset class="mt-2">
                            <legend class="h6 mb-1"><?php echo xlt('Also list them in visits of these types (optional)'); ?></legend>
                            <?php foreach ($visitTypes as $typeId => $typeName) { ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="visit_type[]" id="cf-visit-type-<?php echo attr((string)$typeId); ?>" value="<?php echo attr((string)$typeId); ?>">
                                    <label class="form-check-label" for="cf-visit-type-<?php echo attr((string)$typeId); ?>"><?php echo text($typeName); ?></label>
                                </div>
                            <?php } ?>
                            <small class="form-text text-muted"><?php echo xlt('Each ticked document becomes a form of its own in the Questionnaires menu of those visits, by OpenEMR questionnaire availability. With none ticked they are offered as before, through New Questionnaire.'); ?></small>
                        </fieldset>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </form>

    <?php if ($isAdmin) { ?>
        <details class="mt-4 mb-4">
            <summary><?php echo xlt('Add your own Word or LibreOffice documents'); ?></summary>
            <p class="mt-2"><?php echo xlt('Write a letter or form in Word or LibreOffice, type placeholders where details should go, and add it here. Staff then download it filled in.'); ?>
                <?php echo xlt('Placeholders are written with braces, for example'); ?> <code>{patient.name}</code> <?php echo xlt('or'); ?> <code>{recipient.address}</code>.</p>
            <form method="post" action="<?php echo attr($moduleUrl . '/public/upload.php'); ?>" enctype="multipart/form-data" class="form-inline mb-2">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                <input type="hidden" name="action" value="add">
                <input type="file" name="template" accept=".docx,.odt" class="form-control-file mr-2" required>
                <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Add'); ?></button>
            </form>
            <?php foreach ($templates as $t) { ?>
                <form method="post" action="<?php echo attr($moduleUrl . '/public/upload.php'); ?>" class="d-inline-block mr-2 mb-1">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="file" value="<?php echo attr($t['file']); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo xlt('Remove'); ?> <?php echo text($t['name']); ?></button>
                </form>
            <?php } ?>
            <table class="table table-sm mt-3 small">
                <tr><th><?php echo xlt('Placeholder'); ?></th><th><?php echo xlt('OpenEMR template tag that also works'); ?></th></tr>
                <?php
                $coreTags = array_flip(TemplateFiller::CORE_TAGS);
                foreach (Context::KEYS as $group => $keys) {
                    foreach ($keys as $key) { ?>
                        <tr><td><code>{<?php echo text($group . '.' . $key); ?>}</code></td><td><?php echo isset($coreTags[$group . '.' . $key]) ? '<code>{' . text($coreTags[$group . '.' . $key]) . '}</code>' : ''; ?></td></tr>
                    <?php }
                } ?>
            </table>
        </details>
    <?php } ?>
</div>
<script>
    // A ready-made packet keeps its own order; anything ticked by hand follows in page order.
    (function () {
        const order = document.getElementById('cf-order');
        const boxes = () => Array.from(document.querySelectorAll('.cf-item'));
        document.querySelectorAll('.cf-packet-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const items = btn.dataset.items.split(',');
                boxes().forEach(b => { b.checked = items.includes(b.value); });
                order.value = items.join(',');
            });
        });
        document.getElementById('cf-clear').addEventListener('click', function () {
            boxes().forEach(b => { b.checked = false; });
            order.value = '';
        });
        // Filling the forms from the chart needs the patient filled in too.
        const fillPatient = document.getElementById('fill_patient');
        const fillLayouts = document.getElementById('fill_layouts');
        if (fillPatient && fillLayouts) {
            fillLayouts.addEventListener('change', function () { if (fillLayouts.checked) { fillPatient.checked = true; } });
            fillPatient.addEventListener('change', function () { if (!fillPatient.checked) { fillLayouts.checked = false; } });
        }
        document.getElementById('cf-form').addEventListener('submit', function () {
            const ticked = boxes().filter(b => b.checked).map(b => b.value);
            const first = order.value === '' ? [] : order.value.split(',').filter(v => ticked.includes(v));
            order.value = first.concat(ticked.filter(v => !first.includes(v))).join(',');
        });
    })();
</script>
</body>
</html>
