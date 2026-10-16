<?php

/**
 * LOINC codes as LOINC writes them: a number, a hyphen and a check digit computed from the
 * number (LOINC Users' Guide, "Calculating the check digit"), so a mistyped code is caught.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Loinc
{
    public const SYSTEM = 'http://loinc.org';

    public static function isValid(string $code): bool
    {
        if (preg_match('/^(\d{1,7})-(\d)$/', $code, $m) !== 1) {
            return false;
        }
        return self::checkDigit($m[1]) === (int)$m[2];
    }

    /**
     * Counting from the right: the digits in odd positions, read as a number and doubled, with
     * the even-position digits written in front; the check digit takes the sum of all those
     * digits up to the next multiple of ten.
     */
    public static function checkDigit(string $number): int
    {
        $digits = strrev($number);
        $odd = '';
        $even = '';
        for ($i = 0; $i < strlen($digits); $i++) {
            if ($i % 2 === 0) {
                $odd .= $digits[$i];
            } else {
                $even .= $digits[$i];
            }
        }
        $combined = strrev($even) . (string)((int)strrev($odd) * 2);
        $sum = array_sum(array_map('intval', str_split($combined)));
        return (10 - $sum % 10) % 10;
    }
}
