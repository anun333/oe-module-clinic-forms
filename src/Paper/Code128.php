<?php

/**
 * Code 128 barcodes as inline SVG, for the patient's chart number at the foot of each printed page,
 * so a page scanned back can be filed to the right chart. OpenEMR's own patient barcode label
 * (interface/patient_file/barcode_label.php) encodes the same number, pubpid.
 *
 * Code set B (ASCII 32 to 127): a start symbol, one symbol per character, a check symbol (the
 * start's value plus each symbol's value times its position, modulo 103) and the stop pattern,
 * with ten modules of quiet zone either side. Each symbol is six bar and space widths summing to
 * eleven modules; the stop is seven, summing to thirteen.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Code128
{
    public const START_B = 104;
    public const STOP = 106;
    private const QUIET = 10;

    /** Bar and space widths of symbols 0 to 106 (ISO/IEC 15417, table 1). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Whether the text can be encoded: printable ASCII only, and something to encode. */
    public static function encodable(string $text): bool
    {
        return $text !== '' && preg_match('/^[\x20-\x7E]+$/', $text) === 1;
    }

    /** @return list<int> the symbols: start B, the characters, the check symbol, stop */
    public static function symbols(string $text): array
    {
        if (!self::encodable($text)) {
            throw new \InvalidArgumentException('Code 128 B encodes printable ASCII only');
        }
        $symbols = [self::START_B];
        $sum = self::START_B;
        foreach (str_split($text) as $i => $char) {
            $value = ord($char) - 32;
            $symbols[] = $value;
            $sum += ($i + 1) * $value;
        }
        $symbols[] = $sum % 103;
        $symbols[] = self::STOP;
        return $symbols;
    }

    /** @return list<int> bar and space widths in modules, starting with a bar */
    public static function widths(string $text): array
    {
        $widths = [];
        foreach (self::symbols($text) as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $w) {
                $widths[] = (int)$w;
            }
        }
        return $widths;
    }

    /**
     * The barcode as an SVG element sized in millimetres for print: $module mm per module (0.33 mm
     * scans on ordinary laser printers), $height mm tall. Its title names what it encodes.
     */
    public static function svg(string $text, float $module = 0.33, float $height = 8.0, string $label = ''): string
    {
        $x = self::QUIET;
        $rects = '';
        foreach (self::widths($text) as $i => $w) {
            if ($i % 2 === 0) {
                $rects .= "<rect x='$x' y='0' width='$w' height='1'/>";
            }
            $x += $w;
        }
        $total = $x + self::QUIET;
        $title = htmlspecialchars($label !== '' ? $label : $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return sprintf(
            "<svg xmlns='http://www.w3.org/2000/svg' class='cf-barcode' width='%smm' height='%smm' viewBox='0 0 %d 1' preserveAspectRatio='none' role='img' aria-label='%s'><title>%s</title><rect x='0' y='0' width='%d' height='1' fill='#fff'/><g fill='#000'>%s</g></svg>",
            rtrim(rtrim(number_format($total * $module, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.'),
            $total,
            $title,
            $title,
            $total,
            $rects
        );
    }
}
