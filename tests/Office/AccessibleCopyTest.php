<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\AccessibleCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The accessible copy is a tagged PDF or the Word file, never mPDF's untagged PDF, whatever the
 * server has and whatever is ticked.
 */
final class AccessibleCopyTest extends TestCase
{
    /**
     * @return array<string, array{bool, bool, bool, bool, string}>
     */
    public static function cases(): array
    {
        return [
            'LibreOffice, blank' => [true, false, false, true, AccessibleCopy::LIBREOFFICE_PDF],
            'LibreOffice, filled for a patient' => [true, false, true, false, AccessibleCopy::LIBREOFFICE_PDF],
            'LibreOffice, a nameless document beside named ones' => [true, true, true, false, AccessibleCopy::WORD],
            'no LibreOffice, blank, release copies for all' => [false, false, false, true, AccessibleCopy::BLANK_PDF],
            'no LibreOffice, blank, a document without a release copy' => [false, false, false, false, AccessibleCopy::WORD],
            'no LibreOffice, filled for a patient' => [false, false, true, true, AccessibleCopy::WORD],
        ];
    }

    #[DataProvider('cases')]
    public function testTheAccessibleCopyFitsTheServerAndTheDocuments(bool $libreOffice, bool $mixed, bool $withPatient, bool $releaseCopies, string $route): void
    {
        $this->assertSame($route, AccessibleCopy::route($libreOffice, $mixed, $withPatient, $releaseCopies));
    }

    public function testItIsNeverThePrintCopy(): void
    {
        foreach ([true, false] as $a) {
            foreach ([true, false] as $b) {
                foreach ([true, false] as $c) {
                    foreach ([true, false] as $d) {
                        $this->assertContains(AccessibleCopy::route($a, $b, $c, $d), [AccessibleCopy::LIBREOFFICE_PDF, AccessibleCopy::BLANK_PDF, AccessibleCopy::WORD]);
                    }
                }
            }
        }
    }
}
