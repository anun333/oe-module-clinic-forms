<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests;

use PHPUnit\Framework\TestCase;

final class TranslatableStringsTest extends TestCase
{
    /**
     * OpenEMR's xl() turns ' and " into backticks, so a string passed through xl(), xlt() or xla()
     * prints "module`s" or {`country`: `us`}. Quotes belong outside the translated text.
     */
    public function testNoQuotesInsideTranslatedStrings(): void
    {
        $found = 0;
        $files = array_merge(glob(dirname(__DIR__) . '/public/*.php') ?: [], glob(dirname(__DIR__) . '/src/*/*.php') ?: [], glob(dirname(__DIR__) . '/src/*.php') ?: []);
        foreach ($files as $file) {
            preg_match_all("/\\bxl[at]?\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string)file_get_contents($file), $m);
            foreach ($m[1] as $string) {
                $found++;
                self::assertDoesNotMatchRegularExpression('/\\\\\'|"/', $string, basename($file) . ": xl('$string') has a quote, which xl() prints as a backtick");
            }
        }
        self::assertGreaterThanOrEqual(80, $found, 'the pattern still finds the translated strings');
    }
}
