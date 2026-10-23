<?php

/**
 * Clinic Forms module bootstrap, loaded by OpenEMR on every request while the module is enabled.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenHealthcare\ClinicForms\Bootstrap;
use OpenHealthcare\ClinicForms\Compat\OpenEmr;

// OEGlobalsBag is 8.0+; 7.0 keeps the project directory in $GLOBALS['fileroot'].
$projectDir = class_exists('OpenEMR\\Core\\OEGlobalsBag') ? \OpenEMR\Core\OEGlobalsBag::getInstance()->getProjectDir() : (string)($GLOBALS['fileroot'] ?? '');
$classLoader = new ModulesClassLoader($projectDir);
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\ClinicForms\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

// openhealthcare-common: in OpenEMR's vendor when Composer installed this module; in this module's own
// when it was copied in and `composer install --no-dev` run in its folder. Registered before anything
// asks for it (Composer remembers a class it once failed to find); this leaves OpenEMR's own mapping
// alone. Without it, the module stays switched off rather than stop a page.
$common = __DIR__ . '/vendor/anun333/openhealthcare-common/src';
if (is_dir($common)) {
    $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\', $common);
}
if (!class_exists(\OpenHealthcare\Common\OpenEmr::class)) {
    return;
}

(new Bootstrap(OpenEmr::eventDispatcher()))->subscribeToEvents();
