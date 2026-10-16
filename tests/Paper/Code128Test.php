<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Code128;
use PHPUnit\Framework\TestCase;

final class Code128Test extends TestCase
{
    public function testTheCheckSymbolIsWorkedOutAsTheStandardSays(): void
    {
        // "AB": A is 33 and B 34 in code set B; (104 + 1 x 33 + 2 x 34) mod 103 = 102.
        self::assertSame([104, 33, 34, 102, 106], Code128::symbols('AB'));
    }

    public function testEverySymbolIsElevenModulesAndTheStopThirteen(): void
    {
        $widths = Code128::widths('PID 0042-x');
        // Start, ten characters and the check symbol at 11 modules each, then the 13-module stop.
        self::assertSame(12 * 11 + 13, array_sum($widths));
        self::assertCount(12 * 6 + 7, $widths);
    }

    public function testOnlyPrintableAsciiIsEncoded(): void
    {
        self::assertTrue(Code128::encodable('A-12 ~'));
        self::assertFalse(Code128::encodable(''));
        self::assertFalse(Code128::encodable('Peña'));
        $this->expectException(\InvalidArgumentException::class);
        Code128::symbols("tab\there");
    }

    /**
     * Read back by zbar, a reader written independently of this one: a wrong pattern in the table
     * would fool a decoder sharing it, but not this. CI installs zbar-tools and librsvg2-bin.
     */
    public function testAScannerReadsWhatWasEncoded(): void
    {
        $zbar = trim((string)shell_exec('command -v zbarimg'));
        $rsvg = trim((string)shell_exec('command -v rsvg-convert'));
        if ($zbar === '' || $rsvg === '') {
            self::markTestSkipped('zbarimg and rsvg-convert are needed (apt install zbar-tools librsvg2-bin)');
        }
        foreach (['1', '00042', 'PT-2026-7781', 'abc xyz ~!'] as $text) {
            $dir = sys_get_temp_dir() . '/cf-bc-' . bin2hex(random_bytes(4));
            mkdir($dir);
            try {
                file_put_contents("$dir/c.svg", Code128::svg($text));
                // Rendered at about 300 dpi, as a scanner would see a printed page.
                exec(escapeshellarg($rsvg) . ' --dpi-x 300 --dpi-y 300 -b white -o ' . escapeshellarg("$dir/c.png") . ' ' . escapeshellarg("$dir/c.svg"), $out, $status);
                self::assertSame(0, $status, 'rsvg-convert renders the SVG');
                $read = trim((string)shell_exec(escapeshellarg($zbar) . ' --quiet --raw -Sdisable -Scode128.enable ' . escapeshellarg("$dir/c.png") . ' 2>/dev/null'));
                self::assertSame($text, $read, "zbar reads \"$text\" back");
            } finally {
                array_map('unlink', glob("$dir/*") ?: []);
                rmdir($dir);
            }
        }
    }
}
