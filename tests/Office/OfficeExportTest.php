<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\OfficeWriter;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfficeExportTest extends TestCase
{
    /** @return iterable<string, array{OfficeWriter, list<string>}> */
    public static function writers(): iterable
    {
        yield 'Word' => [new WordWriter(), ['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels', 'word/document.xml', 'word/styles.xml', 'word/header1.xml']];
        yield 'LibreOffice' => [new OdtWriter(), ['mimetype', 'META-INF/manifest.xml', 'content.xml', 'styles.xml']];
    }

    #[DataProvider('writers')]
    public function testEveryShippedDocumentExportsAsAWellFormedFile(OfficeWriter $writer, array $parts): void
    {
        $context = new Context(
            ['name' => 'Riverside & Sons <Clinic>', 'address' => '12 Main St', 'phone' => '555-0100'],
            ['name' => 'Jane Q Sample', 'dob' => '01/02/1980', 'id' => 'SYN-1'],
            ['name' => 'Dr Ann Example'],
            '10/15/2026',
        );
        $docs = array_values(DocumentLibrary::shipped()->all());
        self::assertGreaterThanOrEqual(16, count($docs));
        $entries = Zip::read((new OfficeExport($context))->write($docs, $writer, 'letter'));
        self::assertSame($parts, array_keys($entries));
        $text = '';
        foreach ($entries as $name => $xml) {
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $dom = new \DOMDocument();
                self::assertTrue($dom->loadXML($xml), "$name is well formed");
                $text .= $dom->textContent . "\n";
            }
        }
        self::assertStringContainsString('Riverside & Sons <Clinic>', $text);
        self::assertStringContainsString('Patient: Jane Q Sample', $text);
        self::assertStringContainsString('Dr Ann Example', $text);
        self::assertStringContainsString('Patient Health Questionnaire (PHQ-9)', $text);
        self::assertDoesNotMatchRegularExpression('/\{[a-z]+\.[a-z]+\}/', $text, 'no placeholder is left');
    }

    public function testAnOdtStartsWithItsMimetypeUncompressed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cft');
        self::assertIsString($file);
        file_put_contents($file, (new OfficeExport(new Context(['name' => 'C'])))->write([DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException()], new OdtWriter(), 'A4'));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $first = $zip->statIndex(0);
        self::assertIsArray($first);
        self::assertSame('mimetype', $first['name']);
        self::assertSame(\ZipArchive::CM_STORE, $first['comp_method']);
        self::assertStringContainsString('fo:page-width="8.268in"', (string)$zip->getFromName('styles.xml'));
        $zip->close();
        unlink($file);
    }

    public function testABlankExportLeavesLinesToWriteOn(): void
    {
        $entries = Zip::read((new OfficeExport(new Context(['name' => 'C'])))->write([DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException()], new WordWriter(), 'letter'));
        self::assertStringContainsString('Patient: ____________________', $entries['word/header1.xml']);
        self::assertStringContainsString('______________________________', $entries['word/document.xml']);
        self::assertStringContainsString('<w:pgSz w:w="12240" w:h="15840"/>', $entries['word/document.xml']);
    }
}
