<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\LibreOfficePdf;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\TestCase;

/**
 * The PDF LibreOffice makes from the module's .odt: found only in LibreOffice's own places, tagged,
 * carrying the patient's details and the chart number's barcode. The conversion tests need LibreOffice
 * (CI installs libreoffice-writer-nogui), and pdfinfo, pdftotext and zbarimg to read the PDF back;
 * veraPDF's PDF/UA-1 check runs weekly in the workspace (dev/conformance/clinic_forms_a11y.py).
 */
final class LibreOfficePdfTest extends TestCase
{
    private const PATIENT = ['name' => 'Jane Q Sample', 'dob' => '1970-01-01', 'id' => 'WL9001'];

    public function testItIsFoundOnlyWhereLibreOfficeInstallsItself(): void
    {
        $dir = sys_get_temp_dir() . '/cf-lo-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch("$dir/not-executable");
        touch("$dir/soffice");
        chmod("$dir/soffice", 0755);
        self::assertNull(LibreOfficePdf::find(["$dir/missing", "$dir/not-executable"]));
        self::assertSame("$dir/soffice", LibreOfficePdf::find(["$dir/missing", "$dir/not-executable", "$dir/soffice"])?->program());
        foreach (LibreOfficePdf::PLACES as $place) {
            self::assertMatchesRegularExpression('#(^/|^[A-Z]:\\\\)#', $place, 'every place is a fixed, absolute path');
        }
        array_map('unlink', ["$dir/not-executable", "$dir/soffice"]);
        rmdir($dir);
    }

    public function testAProgramThatMakesNoPdfGivesNone(): void
    {
        $dir = sys_get_temp_dir() . '/cf-lo-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/soffice", "#!/bin/sh\nexit 0\n");
        chmod("$dir/soffice", 0755);
        self::assertNull(LibreOfficePdf::find(["$dir/soffice"])?->convert('not an odt'));
        self::assertSame([], glob(sys_get_temp_dir() . '/clinic-forms-pdf-*') ?: [], 'its working folder is removed');
        unlink("$dir/soffice");
        rmdir($dir);
    }

    public function testTheOdtFooterCarriesTheChartNumberAsABarcodeOnlyWhenGivenOne(): void
    {
        $doc = DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException();
        $with = Zip::read((new OfficeExport(new Context(['name' => 'C'], self::PATIENT)))->write([$doc], new OdtWriter('Date', 'en', 'WL9001', 'Chart number WL9001'), 'letter'));
        self::assertStringContainsString('<style:footer>', $with['styles.xml']);
        self::assertStringContainsString('<svg:title>Chart number WL9001</svg:title>', $with['styles.xml']);
        self::assertStringContainsString('full-path="Pictures/barcode.svg"', $with['META-INF/manifest.xml']);
        self::assertStringStartsWith('<svg', $with['Pictures/barcode.svg']);
        self::assertSame('mimetype', array_key_first($with), 'the mimetype still comes first');
        $without = Zip::read((new OfficeExport(new Context(['name' => 'C'], self::PATIENT)))->write([$doc], new OdtWriter(), 'letter'));
        self::assertStringNotContainsString('<style:footer>', $without['styles.xml']);
        self::assertArrayNotHasKey('Pictures/barcode.svg', $without);
    }

    public function testThePdfIsTaggedAndCarriesThePatientAndBarcode(): void
    {
        $lo = LibreOfficePdf::find();
        if ($lo === null) {
            self::markTestSkipped('LibreOffice is needed (apt install libreoffice-writer-nogui)');
        }
        foreach (['pdfinfo', 'pdftotext', 'pdftoppm', 'zbarimg'] as $tool) {
            if (trim((string)shell_exec('command -v ' . $tool)) === '') {
                self::markTestSkipped("$tool is needed (apt install poppler-utils zbar-tools)");
            }
        }
        $library = DocumentLibrary::shipped();
        $docs = [$library->get('consent-to-treat') ?? throw new \LogicException(), $library->get('phq-9') ?? throw new \LogicException()];
        $odt = (new OfficeExport(new Context(['name' => 'Sample Community Clinic'], self::PATIENT)))->write($docs, new OdtWriter('Date', 'en', 'WL9001', 'Chart number WL9001'), 'letter');
        $pdf = $lo->convert($odt);
        self::assertIsString($pdf);
        $file = sys_get_temp_dir() . '/cf-lo-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($file, $pdf);
        $info = (string)shell_exec('pdfinfo ' . escapeshellarg($file));
        self::assertMatchesRegularExpression('/^Tagged:\s+yes$/m', $info);
        self::assertMatchesRegularExpression('/^Title:\s+Consent to Treatment; Patient Health Questionnaire \(PHQ-9\)$/m', $info);
        $text = (string)shell_exec('pdftotext ' . escapeshellarg($file) . ' -');
        self::assertStringContainsString('Patient: Jane Q Sample', $text);
        self::assertStringContainsString('Sample Community Clinic', $text);
        shell_exec('pdftoppm -r 200 -f 1 -l 1 -png ' . escapeshellarg($file) . ' ' . escapeshellarg($file));
        self::assertSame('CODE-128:WL9001', trim((string)shell_exec('zbarimg -q ' . escapeshellarg($file . '-1.png'))));
        array_map('unlink', glob($file . '*') ?: []);
    }
}
