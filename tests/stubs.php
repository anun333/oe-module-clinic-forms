<?php

/**
 * Stand-ins for OpenEMR's global translation helpers, which the isolated tests cannot load.
 * Dev only: loaded through autoload-dev, never installed.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

if (!function_exists('xl')) {
    function xl(string $constant): string
    {
        return $constant;
    }
}
