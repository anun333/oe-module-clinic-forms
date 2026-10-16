<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Translation;
use OpenHealthcare\ClinicForms\Paper\Words;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase
{
    public function testEveryPatientDocumentHasASpanishVersionThatKeepsEverything(): void
    {
        $english = DocumentLibrary::shipped();
        $spanish = DocumentLibrary::shipped('es');
        $patient = array_filter($english->all(), static fn(Document $d): bool => $d->audience === 'patient');
        self::assertGreaterThanOrEqual(22, count($patient));
        foreach ($patient as $id => $doc) {
            $es = $spanish->get($id);
            self::assertNotNull($es);
            self::assertSame('es', $es->language, "$id has a Spanish version");
            self::assertSame([], Translation::differences($doc, $es), $id);
            self::assertSame($doc->audience, $es->audience);
            self::assertSame($doc->code, $es->code);
            self::assertSame($doc->validated, $es->validated);
            self::assertSame($doc->poster, $es->poster);
            self::assertNotSame($doc->title, $es->title, "$id title is translated");
        }
        self::assertSame([], $spanish->problems());
    }

    public function testSpanishVersionsKeepTheirRequiredWording(): void
    {
        $requirements = Requirements::shipped();
        $spanish = DocumentLibrary::shipped('es');
        foreach (array_keys($requirements->documents) as $id) {
            self::assertSame([], $requirements->missing($spanish->get($id) ?? throw new \LogicException($id)), $id);
        }
    }

    public function testStaffDocumentsStayInEnglish(): void
    {
        $spanish = DocumentLibrary::shipped('es');
        self::assertSame('en', $spanish->get('routing-slip')?->language);
        self::assertFalse($spanish->isTranslated('routing-slip'));
        self::assertTrue($spanish->isTranslated('consent-to-treat'));
    }

    public function testADroppedElementIsADifference(): void
    {
        $english = DocumentLibrary::shipped()->get('release-of-information') ?? throw new \LogicException();
        $es = DocumentLibrary::shipped('es')->get('release-of-information') ?? throw new \LogicException();
        $blocks = $es->blocks;
        array_pop($blocks[0]['fields']);
        $fewer = new Document($es->id, $es->title, $es->category, $es->about, $blocks, $es->uses, language: 'es');
        self::assertSame([
            'blocks[0]: its fields differ from the English',
            'blocks[0]: its values differ from the English',
            'blocks[0] uses placeholders patient.dob patient.name where the English uses patient.dob patient.id patient.name',
        ], Translation::differences($english, $fewer));
        $blocks = $es->blocks;
        $blocks[16]['text'] = str_replace('{facility.name}', 'la clínica', $blocks[16]['text']);
        $placeholder = new Document($es->id, $es->title, $es->category, $es->about, $blocks, $es->uses, language: 'es');
        self::assertStringContainsString('blocks[16] uses placeholders', implode(' ', Translation::differences($english, $placeholder)));
    }

    public function testABrokenClinicTranslationIsReported(): void
    {
        $dir = sys_get_temp_dir() . '/cf-es-' . bin2hex(random_bytes(4));
        mkdir($dir . '/es', 0777, true);
        file_put_contents($dir . '/es/consent-to-treat.json', json_encode(['id' => 'consent-to-treat', 'title' => 'Mi consentimiento', 'category' => 'Recepción', 'about' => 'x', 'blocks' => [['type' => 'paragraph', 'text' => 'Acepto.']]], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/es/not-a-document.json', json_encode(['id' => 'not-a-document', 'title' => 'x', 'category' => 'x', 'about' => 'x', 'blocks' => [['type' => 'paragraph', 'text' => 'x']]], JSON_THROW_ON_ERROR));
        try {
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $dir, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('Mi consentimiento', $library->get('consent-to-treat')?->title);
            $problems = implode("\n", $library->problems());
            self::assertStringContainsString('consent-to-treat.json: has 1 blocks where the English has', $problems);
            self::assertStringContainsString('not-a-document.json: a translation of a document that does not exist', $problems);
        } finally {
            array_map('unlink', glob($dir . '/es/*') ?: []);
            rmdir($dir . '/es');
            rmdir($dir);
        }
    }

    public function testEveryWordTheCodePrintsHasASpanishEntry(): void
    {
        $found = 0;
        foreach (['src/Paper/Renderer.php', 'src/Paper/LayoutPaper.php', 'src/Office/OfficeExport.php'] as $file) {
            preg_match_all("/\\\$this->t\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file), $m);
            foreach ($m[1] as $word) {
                $found++;
                self::assertArrayHasKey(stripslashes($word), Words::ES, "$file prints \"$word\"");
            }
        }
        self::assertGreaterThanOrEqual(20, $found);
        self::assertSame('Teléfono', Words::t('Phone', 'es'));
        self::assertSame('Phone', Words::t('Phone', 'en'));
        self::assertSame('en', Words::language('fr'));
        self::assertSame('es', Words::language('es'));
    }

    public function testASpanishPageIsInSpanishAndCarriesTheTaglines(): void
    {
        $doc = DocumentLibrary::shipped('es')->get('consent-to-treat') ?? throw new \LogicException();
        $taglines = Taglines::forSite(null)->forPage('es');
        $html = (new Renderer(new Context(['name' => 'Clínica', 'phone' => '555-0100']), 'es', $taglines))->document($doc);
        self::assertStringContainsString("lang='es'", $html);
        self::assertStringContainsString('Teléfono 555-0100', $html);
        self::assertStringContainsString('<h1>Consentimiento para recibir tratamiento</h1>', $html);
        self::assertStringContainsString("<div class='cf-taglines'><p>ATENCIÓN: Si habla español", $html);
        self::assertStringContainsString('ATTENTION: If you speak English', $html);
        self::assertStringContainsString('<span>Fecha</span>', $html);
    }

    public function testStaffDocumentsGetNoTaglinesAndPostersPrintLarge(): void
    {
        $library = DocumentLibrary::shipped();
        $taglines = Taglines::forSite(null)->forPage('en');
        $renderer = new Renderer(new Context(['name' => 'C', 'phone' => '1']), 'en', $taglines);
        self::assertStringNotContainsString('cf-taglines', $renderer->document($library->get('routing-slip') ?? throw new \LogicException()));
        $notice = $renderer->document($library->get('notice-of-availability') ?? throw new \LogicException());
        self::assertStringContainsString("<section class='cf-page cf-poster'", $notice);
        self::assertSame(1, substr_count($notice, "<div class='cf-taglines"), 'the notice prints the taglines once, in full');
        self::assertStringContainsString('cf-taglines-full', $notice);
        $filled = (new Renderer(new Context(['name' => 'C'], ['name' => 'Jane Q Sample']), 'en', $taglines))->document($library->get('notice-of-nondiscrimination') ?? throw new \LogicException());
        self::assertStringNotContainsString('Jane Q Sample', $filled, 'a poster never shows the patient');
        self::assertStringNotContainsString('cf-running', $filled);
    }
}
