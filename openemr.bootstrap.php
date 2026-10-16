<?php

/**
 * Clinic Forms module bootstrap, loaded by OpenEMR on every request while the module is enabled.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\ClinicForms\Bootstrap;

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\ClinicForms\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
