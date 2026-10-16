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
use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Office\TemplateLibrary;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Words;
use OpenHealthcare\ClinicForms\Repository\SiteData;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('ACL check failed for patients/demo: Clinic Forms', xl('Clinic Forms'));
}

$siteDir = OpenEmr::siteDir();
$library = DocumentLibrary::forSite($siteDir);
$packets = Packets::forSite($library, $siteDir);
$spanish = DocumentLibrary::forSite($siteDir, 'es');
$taglines = Taglines::forSite($siteDir);
$problems = array_merge($library->problems(), $packets->problems, $spanish->problems(), $taglines->problems);
// A clinic's own version of a document the law says what goes in: say what its wording doesn't show.
$requirements = Requirements::shipped();
foreach ($library->all() as $doc) {
    $missing = $doc->source === 'clinic' ? $requirements->missing($doc) : [];
    if ($missing !== []) {
        $problems[] = $doc->title . ' (' . xl('local version') . '): ' . xl('could not find') . ' ' . implode('; ', $missing)
            . '. ' . xl('Check that it has these') . ' (' . $requirements->source($doc->id) . ').';
    }
}
$templates = TemplateLibrary::forSite($siteDir)->all();
$isAdmin = AclMain::aclCheckCore('admin', 'super');
$csrf = OpenEmr::csrfToken();
$flash = is_string($_GET['cf_msg'] ?? null) ? $_GET['cf_msg'] : '';
$flashOk = ($_GET['cf_ok'] ?? '') === '1';
$site = new SiteData();
$facilities = $site->facilities();
$recipients = $site->recipients();
$layouts = $site->layoutForms();
$pid = (int)(OpenEmr::session('pid') ?? 0);
$patient = $pid > 0 ? $site->patient($pid) : [];
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

$byCategory = [];
foreach ($library->all() as $doc) {
    $byCategory[$doc->category][] = [
        'value' => 'doc:' . $doc->id,
        'title' => $doc->title . ($doc->source === 'clinic' ? ' (' . xl('local version') . ')' : ''),
        'about' => $doc->about,
        'es' => $spanish->isTranslated($doc->id),
    ];
}
foreach ($layouts as $form) {
    $byCategory[xl('Blank layout forms') . ': ' . $form['category']][] = ['value' => 'layout:' . $form['id'], 'title' => $form['title'], 'about' => xl('The paper version of this form, in the order staff enter it')];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Clinic Forms'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .cf-cat { margin-top: 1rem; }
        .cf-about { color: #555; font-size: 0.85rem; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <h1 class="h4"><?php echo xlt('Clinic Forms'); ?></h1>
    <p class="text-muted"><?php echo xlt('Print paperwork with the clinic details filled in, and blank paper versions of layout forms for intake by hand and entry later.'); ?>
        <?php echo xlt('Consents and releases are starting points: have them reviewed for your state before use.'); ?></p>
    <?php if ($flash !== '') { ?>
        <div class="alert <?php echo $flashOk ? 'alert-success' : 'alert-warning'; ?>"><?php echo text($flash); ?></div>
    <?php } ?>
    <?php if ($problems !== []) { ?>
        <div class="alert alert-warning">
            <?php echo xlt('Some local documents or packets need attention'); ?>:
            <ul class="mb-0"><?php foreach ($problems as $problem) { ?><li><?php echo text($problem); ?></li><?php } ?></ul>
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
                    <option value="letter"><?php echo xlt('Letter'); ?></option>
                    <option value="a4">A4</option>
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
        </div>
        <?php if ($patient !== []) { ?>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="fill_patient" value="1" id="fill_patient">
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

        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="taglines" value="1" id="taglines" checked>
            <label class="form-check-label" for="taglines"><?php echo xlt('Add the language assistance taglines to patient pages'); ?>
                (<?php echo text(implode(', ', array_keys($taglines->taglines))); ?>)</label>
            <small class="form-text text-muted"><?php echo xlt('Section 1557 (45 CFR 92.11) asks for them on intake, consent and privacy forms, in English and the 15 languages most spoken in your state. Add yours in taglines.json in your Clinic Forms folder.'); ?></small>
        </div>
        <div class="mb-2">
            <span class="mr-2"><?php echo xlt('Ready-made'); ?>:</span>
            <?php foreach ($packets->packets as $p) { ?>
                <button type="button" class="btn btn-sm btn-secondary mb-1 cf-packet-btn" data-items="<?php echo attr(implode(',', $p['items'])); ?>"><?php echo text(xl($p['title'])); ?></button>
            <?php } ?>
            <button type="button" class="btn btn-sm btn-link mb-1" id="cf-clear"><?php echo xlt('Clear'); ?></button>
        </div>

        <?php foreach ($byCategory as $category => $items) { ?>
            <div class="cf-cat"><h2 class="h6"><?php echo text($category); ?></h2>
                <?php foreach ($items as $item) { ?>
                    <div class="form-check">
                        <input class="form-check-input cf-item" type="checkbox" name="items[]" value="<?php echo attr($item['value']); ?>" id="<?php echo attr('i-' . $item['value']); ?>">
                        <label class="form-check-label" for="<?php echo attr('i-' . $item['value']); ?>"><?php echo text($item['title']); ?><?php echo !empty($item['es']) ? ' <span class="badge badge-info">ES</span>' : ''; ?>
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
            <span class="ml-3 mr-1"><?php echo xlt('or edit first'); ?>:</span>
            <button type="submit" class="btn btn-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="docx"><?php echo xlt('Word'); ?> (.docx)</button>
            <button type="submit" class="btn btn-secondary" formaction="<?php echo attr($moduleUrl . '/public/export.php'); ?>" formtarget="_self" name="format" value="odt"><?php echo xlt('LibreOffice'); ?> (.odt)</button>
            <small class="form-text text-muted"><?php echo xlt('The ticked documents as one file to edit in Word, LibreOffice or Google Docs. Layout forms print from the browser only.'); ?></small>
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
