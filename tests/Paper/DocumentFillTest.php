<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Event\DocumentFillEvent;
use OpenHealthcare\ClinicForms\Office\OdtWriter;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentFill;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Paper\Translation;
use PHPUnit\Framework\TestCase;

final class DocumentFillTest extends TestCase
{
    private const ROWS = [['1', '$15,960', '$31,920'], ['2', '$21,640', '$43,280']];

    /** A listener as Sliding Fee's: fills the notice's discounts and nothing else. */
    private static function listener(): \Closure
    {
        return static function (DocumentFillEvent $e): void {
            if ($e->documentId === 'sliding-fee-notice') {
                $e->fillTable('discounts', self::ROWS, 'Our schedule in force.');
            }
        };
    }

    private static function notice(string $language = 'en'): Document
    {
        return DocumentLibrary::shipped($language)->get('sliding-fee-notice') ?? throw new \LogicException();
    }

    public function testANamedTableIsFilledAndOtherDocumentsAreUntouched(): void
    {
        $consent = DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException();
        $asked = [];
        [$notice, $same] = DocumentFill::apply([self::notice(), $consent], static function (DocumentFillEvent $e) use (&$asked): void {
            $asked[] = $e->documentId . ':' . implode(',', $e->tableIds);
            (self::listener())($e);
        }, 42);
        $this->assertSame(['sliding-fee-notice:discounts'], $asked, 'only a document with a named table is asked about');
        $table = array_values(array_filter($notice->blocks, static fn(array $b): bool => $b['type'] === 'table'))[0];
        $this->assertSame(self::ROWS, $table['values']);
        $this->assertSame('Our schedule in force.', $table['note']);
        $this->assertSame($consent, $same);
    }

    public function testWithNobodyListeningTheDocumentIsTheSame(): void
    {
        $notice = self::notice();
        $this->assertSame($notice, DocumentFill::apply([$notice], static function (DocumentFillEvent $e): void {
        })[0]);
    }

    public function testATableTheDocumentDoesntNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentFill::apply([self::notice()], static fn(DocumentFillEvent $e) => $e->fillTable('other', []));
    }

    public function testEveryFormatPrintsTheRowsAndDropsTheLabelsInstructionForFillingByHand(): void
    {
        [$filled] = DocumentFill::apply([self::notice()], self::listener());
        $html = (new Renderer(new Context(['name' => 'Clinic'])))->document($filled);
        $this->assertStringContainsString('$21,640', $html);
        $this->assertStringContainsString('>Our discounts<', $html);
        $this->assertStringNotContainsString('the clinic fills in its own schedule', $html);
        $this->assertStringContainsString('Our schedule in force.', $html);
        foreach ([[new WordWriter(), 'word/document.xml'], [new OdtWriter(), 'content.xml']] as [$writer, $part]) {
            $xml = Zip::read((new OfficeExport(new Context(['name' => 'Clinic'])))->write([$filled], $writer, 'letter'))[$part];
            $this->assertStringContainsString('$43,280', $xml, $part);
            $this->assertStringNotContainsString('the clinic fills in its own schedule', $xml, $part);
        }
        $blank = (new Renderer(new Context(['name' => 'Clinic'])))->document(self::notice());
        $this->assertStringContainsString('the clinic fills in its own schedule', $blank, 'unfilled, the instruction stays');
        $this->assertStringNotContainsString('$21,640', $blank);
    }

    public function testTheSpanishNoticeNamesTheSameTableAndKeepsItsTranslation(): void
    {
        $es = self::notice('es');
        $this->assertSame('es', $es->language);
        $this->assertSame($es->translates, Translation::fingerprint(self::notice()), 'naming the table is not wording');
        [$filled] = DocumentFill::apply([$es], self::listener());
        $html = (new Renderer(new Context(['name' => 'Clinic']), 'es'))->document($filled);
        $this->assertStringContainsString('>Nuestros descuentos<', $html);
        $this->assertStringContainsString('$15,960', $html);
    }
}
