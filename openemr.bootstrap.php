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

(new Bootstrap(OpenEmr::eventDispatcher()))->subscribeToEvents();
