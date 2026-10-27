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

    /** @var ?array{en: array<string, string>, es: array<string, string>} */
    private static ?array $names = null;
    /** @var ?array<string, string> */
    private static ?array $definitions = null;

    /** data/loinc-names.json: LOINC's long common name for every LOINC code the module sends, written by tools/loinc-names.php. */
    public static function namesFile(): string
    {
        return dirname(__DIR__, 2) . '/data/loinc-names.json';
    }

    /** data/loinc-definitions.json: each LOINC question's item in LOINC's own questionnaire, written by tools/loinc-definitions.php. */
    public static function definitionsFile(): string
    {
        return dirname(__DIR__, 2) . '/data/loinc-definitions.json';
    }

    /**
     * Where LOINC defines a question: its item in LOINC's own FHIR questionnaire, as SDC's Questionnaire
     * library reference ("http://loinc.org/q/55757-9#32742"), the same in every form that asks it. Null for
     * a code LOINC's questionnaires for the module's instruments don't ask as a question.
     */
    public static function definition(string $code): ?string
    {
        if (self::$definitions === null) {
            $data = is_file(self::definitionsFile()) ? json_decode((string)file_get_contents(self::definitionsFile()), true) : null;
            self::$definitions = is_array($data['definitions'] ?? null) ? array_map('strval', $data['definitions']) : [];
        }
        return self::$definitions[$code] ?? null;
    }

    /** An answer code as LOINC writes them: LA, a number, a hyphen and a digit (not the terms' check digit). */
    public static function isAnswer(string $code): bool
    {
        return preg_match('/^LA\d{1,8}-\d$/', $code) === 1;
    }

    /**
     * LOINC's name for a code (a term's long common name, an answer's display text), which the LOINC
     * licence asks to go with the code wherever the module passes LOINC content on. In Spanish, LOINC's
     * Spanish long common name where it has one, else the English. Null when the names file doesn't
     * have the code.
     */
    public static function name(string $code, string $language = 'en'): ?string
    {
        if (self::$names === null) {
            $data = is_file(self::namesFile()) ? json_decode((string)file_get_contents(self::namesFile()), true) : null;
            self::$names = [
                'en' => is_array($data['names'] ?? null) ? array_map('strval', $data['names']) : [],
                'es' => is_array($data['names_es'] ?? null) ? array_map('strval', $data['names_es']) : [],
            ];
        }
        return ($language === 'es' ? self::$names['es'][$code] ?? null : null) ?? self::$names['en'][$code] ?? null;
    }

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
