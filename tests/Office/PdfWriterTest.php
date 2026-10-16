<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\PdfWriter;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\TestCase;

/**
 * The PDF for the patient's Documents, written by mPDF (a development dependency here; OpenEMR
 * ships it), read back as text by pdftotext and as barcodes by zbar. CI installs poppler-utils and
 * zbar-tools for them.
 */
final class PdfWriterTest extends TestCase
{
    private const PATIENT = ['name' => 'Jane Q Sample', 'dob' => '1970-01-01', 'id' => 'WL9001'];

    private string $dir;

    protected function setUp(): void
    {
        foreach (['pdftotext', 'pdftoppm', 'zbarimg'] as $tool) {
            if (trim((string)shell_exec('command -v ' . $tool)) === '') {
                self::markTestSkipped("$tool is needed (apt install poppler-utils zbar-tools)");
            }
        }
        $this->dir = sys_get_temp_dir() . '/cf-pdf-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            // mPDF's own temp files sit in a folder of their own under it.
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testEachDocumentStartsAPageAndEveryPageNamesItsDocumentAndThePatient(): void
    {
        $library = DocumentLibrary::shipped();
        $docs = [$library->get('consent-to-treat'), $library->get('phq-9')];
        $context = new Context(['name' => 'Seed Clinic', 'phone' => '555-0100'], self::PATIENT);
        $pdf = (new OfficeExport($context))->write(array_values(array_filter($docs)), new PdfWriter('Date', 'WL9001', $this->config()), 'letter');
        self::assertStringStartsWith('%PDF-', $pdf);

        $pages = $this->pages($pdf);
        self::assertGreaterThanOrEqual(2, count($pages));
        self::assertStringContainsString('Consent to Treatment', $pages[0]);
        self::assertStringContainsString('consent-to-treat v1', $pages[0]);
        self::assertStringNotContainsString('phq-9 v', $pages[0], "a document's stamp stays on its own pages");
        $last = $pages[count($pages) - 1];
        self::assertStringContainsString('phq-9 v2', $last);
        self::assertStringNotContainsString('consent-to-treat v1', $last);
        foreach ($pages as $n => $text) {
            self::assertStringContainsString('Jane Q Sample', $text, 'page ' . ($n + 1) . ' carries the patient line');
        }
        self::assertSame(array_fill(0, count($pages), 'WL9001'), $this->codes($pdf), 'every page carries the chart number, as a scanner reads it');
    }

    public function testABlankPdfHasNoBarcode(): void
    {
        $doc = DocumentLibrary::shipped()->get('consent-to-treat');
        self::assertNotNull($doc);
        $pdf = (new OfficeExport(new Context(['name' => 'Seed Clinic'])))->write([$doc], new PdfWriter('Date', '', $this->config()), 'A4');
        self::assertSame([''], $this->codes($pdf));
        self::assertStringContainsString('____', $this->pages($pdf)[0], 'the patient line is left to fill in');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['tempDir' => $this->dir . '/mpdf', 'default_font' => 'dejavusans', 'mode' => 'utf-8'];
    }

    /** @return list<string> each page's text */
    private function pages(string $pdf): array
    {
        file_put_contents($this->dir . '/out.pdf', $pdf);
        $text = (string)shell_exec('pdftotext -layout ' . escapeshellarg($this->dir . '/out.pdf') . ' -');
        return array_values(array_filter(explode("\f", $text), static fn(string $p): bool => trim($p) !== ''));
    }

    /** @return list<string> what zbar reads on each page, rendered at 300 dpi; '' for none */
    private function codes(string $pdf): array
    {
        file_put_contents($this->dir . '/out.pdf', $pdf);
        exec('pdftoppm -r 300 -png ' . escapeshellarg($this->dir . '/out.pdf') . ' ' . escapeshellarg($this->dir . '/p'));
        $pngs = glob($this->dir . '/p-*.png') ?: [];
        natsort($pngs);
        return array_values(array_map(
            static fn(string $png): string => trim((string)shell_exec('zbarimg --quiet --raw -Sdisable -Scode128.enable ' . escapeshellarg($png) . ' 2>/dev/null')),
            $pngs,
        ));
    }
}
