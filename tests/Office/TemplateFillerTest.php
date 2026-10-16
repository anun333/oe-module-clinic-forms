<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\TemplateFiller;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use PHPUnit\Framework\TestCase;

final class TemplateFillerTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    private function context(): Context
    {
        return new Context(
            ['name' => 'Smith & Jones Clinic', 'phone' => '555-0100'],
            ['name' => 'Jane <Q> Sample', 'dob' => '01/02/1980', 'street' => '1 Test Way', 'medications' => 'metformin; lisinopril'],
            ['name' => 'Dr Ann Example', 'address' => '2 Test Way'],
            '10/15/2026',
            null,
            ['name' => 'Pat Provider'],
        );
    }

    /** A .docx with the given paragraphs (already WordprocessingML), plus a header. */
    private function docx(string $paragraphs, string $header = ''): string
    {
        return Zip::build([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document ' . self::W . '><w:body>' . $paragraphs . '</w:body></w:document>',
            'word/header1.xml' => '<?xml version="1.0"?><w:hdr ' . self::W . '>' . ($header !== '' ? $header : '<w:p/>') . '</w:hdr>',
            'word/media/logo.png' => "\x89PNG not touched",
        ]);
    }

    /** @return array<string, string> */
    private function fill(string $template): array
    {
        $entries = Zip::read(TemplateFiller::fill($template, $this->context()));
        foreach ($entries as $name => $xml) {
            if (str_ends_with($name, '.xml')) {
                self::assertTrue((new \DOMDocument())->loadXML($xml), "$name stays well formed");
            }
        }
        return $entries;
    }

    private static function text(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        return $dom->textContent;
    }

    public function testPlaceholdersAreFilledAndEscaped(): void
    {
        $out = $this->fill($this->docx('<w:p><w:r><w:t xml:space="preserve">Dear {recipient.name}, re {patient.name} of {facility.name}. Signed {user.name}</w:t></w:r></w:p>'));
        self::assertSame('Dear Dr Ann Example, re Jane <Q> Sample of Smith & Jones Clinic. Signed Pat Provider', self::text($out['word/document.xml']));
        self::assertStringContainsString('Smith &amp; Jones', $out['word/document.xml']);
        self::assertSame("\x89PNG not touched", $out['word/media/logo.png']);
    }

    public function testAPlaceholderWordSplitAcrossRunsIsFilledAndKeepsTheFirstRunsFormatting(): void
    {
        $split = '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>To: {pat</w:t></w:r><w:proofErr w:type="spellStart"/>'
            . '<w:r><w:rPr><w:i/></w:rPr><w:t>ient.na</w:t></w:r><w:r><w:t>me} today</w:t></w:r></w:p>';
        $out = $this->fill($this->docx($split));
        self::assertSame('To: Jane <Q> Sample today', self::text($out['word/document.xml']));
        self::assertStringContainsString('<w:b/></w:rPr><w:t>To: Jane &lt;Q&gt; Sample</w:t>', $out['word/document.xml']);
    }

    public function testOpenEmrTemplateTagsAreFilled(): void
    {
        $out = $this->fill($this->docx('<w:p><w:r><w:t xml:space="preserve">{PatientName} born {PatientDOB} at {Address} on {CurrentDate}: {Medications}</w:t></w:r></w:p>'));
        self::assertSame('Jane <Q> Sample born 01/02/1980 at 1 Test Way on 10/15/2026: metformin; lisinopril', self::text($out['word/document.xml']));
    }

    public function testHeadersAreFilledToo(): void
    {
        $out = $this->fill($this->docx('<w:p/>', '<w:p><w:r><w:t>{facility.name} · {facility.phone}</w:t></w:r></w:p>'));
        self::assertSame('Smith & Jones Clinic · 555-0100', self::text($out['word/header1.xml']));
    }

    public function testAMissingValueBecomesALineAndUnknownTagsStay(): void
    {
        $out = $this->fill($this->docx('<w:p><w:r><w:t xml:space="preserve">Fax {recipient.fax} {patient.ssn} {DOS} { not a tag }</w:t></w:r></w:p>'));
        self::assertSame('Fax __________ {patient.ssn} {DOS} { not a tag }', self::text($out['word/document.xml']));
    }

    public function testAPlaceholderNeverSpansParagraphs(): void
    {
        $out = $this->fill($this->docx('<w:p><w:r><w:t>{patient.</w:t></w:r></w:p><w:p><w:r><w:t>name}</w:t></w:r></w:p>'));
        self::assertSame('{patient.name}', self::text($out['word/document.xml']));
    }

    public function testAnOdtSpanThatEndsInsideThePlaceholderStaysWellFormed(): void
    {
        $content = '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            . '<office:body><office:text><text:p>Dear <text:span text:style-name="T1">{recipient.</text:span>name}, thanks.</text:p></office:text></office:body></office:document-content>';
        $odt = Zip::build(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => $content, 'styles.xml' => '<?xml version="1.0"?><x/>']);
        $out = $this->fill($odt);
        self::assertSame('Dear Dr Ann Example, thanks.', self::text($out['content.xml']));
        self::assertStringContainsString('<text:span text:style-name="T1">Dr Ann Example</text:span>', $out['content.xml']);
        self::assertSame('mimetype', array_key_first($out));
    }

    public function testPlaceholdersAreListedForCheckingATemplate(): void
    {
        $found = TemplateFiller::placeholders($this->docx('<w:p><w:r><w:t xml:space="preserve">{patient.name} {pat</w:t></w:r><w:r><w:t>ient.ssn} {PatientName} {DOS} {LBFvitals:bps} {x}</w:t></w:r></w:p>'));
        self::assertSame(['{patient.name}', '{PatientName}'], $found['known']);
        self::assertSame(['{patient.ssn}', '{DOS}', '{LBFvitals:bps}'], $found['unknown']);
    }

    public function testSomethingElseIsRefused(): void
    {
        $this->expectExceptionMessage('not a Word (.docx) or LibreOffice (.odt) document');
        TemplateFiller::fill(Zip::build(['readme.txt' => 'hello']), $this->context());
    }

    public function testNotAZipIsRefused(): void
    {
        $this->expectExceptionMessage('not a zip package');
        TemplateFiller::fill('%PDF-1.4 not a zip', $this->context());
    }
}
