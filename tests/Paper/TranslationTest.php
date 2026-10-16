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
        $outOfDate = $spanish->outOfDate();
        foreach ($patient as $id => $doc) {
            if (isset($outOfDate[$id])) {
                continue;
            }
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
        if ($outOfDate !== []) {
            // Not a failure: while the English is being worked on, its Spanish waits for the language
            // pass (dev/clinic_forms_translate_es.py), and those documents print in English meanwhile.
            self::markTestIncomplete('Spanish out of date, printing in English: ' . implode(', ', array_keys($outOfDate)));
        }
    }

    public function testTheFingerprintIsTheGeneratorsToo(): void
    {
        // The same document and value as FINGERPRINT_CHECK in dev/clinic_forms_translate_es.py.
        $doc = new Document('x', 'Título', 'Cat', 'About / "q"', [['type' => 'paragraph', 'text' => "Line\nnext <b> & 'q' \u{2610} \u{1D11E} \u{2028}"], ['type' => 'lines', 'label' => 'L', 'count' => 2]], []);
        self::assertSame('19e49de3cf3a43f4', Translation::fingerprint($doc));
        $reworded = new Document('x', 'Título', 'Cat', 'About / "q"', [['type' => 'paragraph', 'text' => "Line\nnext <b> & 'q' \u{2610} \u{1D11E} \u{2028}."], ['type' => 'lines', 'label' => 'L', 'count' => 2]], []);
        self::assertNotSame(Translation::fingerprint($doc), Translation::fingerprint($reworded));
        $more = new Document('x', 'Título', 'Cat', 'About / "q"', [['type' => 'paragraph', 'text' => "Line\nnext <b> & 'q' \u{2610} \u{1D11E} \u{2028}"], ['type' => 'lines', 'label' => 'L', 'count' => 3]], []);
        self::assertNotSame(Translation::fingerprint($doc), Translation::fingerprint($more));
    }

    public function testEveryShippedTranslationSaysWhichEnglishItTranslates(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/data/documents/es/*.json') ?: [];
        self::assertGreaterThanOrEqual(22, count($files));
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['translates'] ?? '', basename($file));
        }
    }

    public function testAChangedEnglishDocumentPrintsInEnglishUntilItsTranslationIsUpdated(): void
    {
        $dir = self::copyOf('consent-to-treat');
        try {
            $library = new DocumentLibrary($dir, null, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('es', $library->get('consent-to-treat')?->language, 'unchanged, the translation is used');
            self::assertSame([], $library->outOfDate());

            $data = json_decode((string)file_get_contents($dir . '/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            file_put_contents($dir . '/consent-to-treat.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary($dir, null, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('es', $library->get('consent-to-treat')?->language, 'reformatting the English changes nothing');

            $data['blocks'][1]['text'] .= ' One more sentence.';
            file_put_contents($dir . '/consent-to-treat.json', json_encode($data, JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary($dir, null, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('en', $library->get('consent-to-treat')?->language, 'reworded, the document prints in English');
            self::assertStringEndsWith('One more sentence.', $library->get('consent-to-treat')->blocks[1]['text'] ?? '');
            self::assertSame(['consent-to-treat' => 'data/documents/es/consent-to-treat.json'], $library->outOfDate());
            self::assertFalse($library->isTranslated('consent-to-treat'));
            self::assertSame([], $library->problems(), 'out of date is not a mistake in the file');
        } finally {
            self::remove($dir);
        }
    }

    public function testAShippedTranslationThatDoesNotSayWhatItTranslatesIsOutOfDate(): void
    {
        $dir = self::copyOf('consent-to-treat');
        try {
            $data = json_decode((string)file_get_contents($dir . '/es/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            unset($data['translates']);
            file_put_contents($dir . '/es/consent-to-treat.json', json_encode($data, JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary($dir, null, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('en', $library->get('consent-to-treat')?->language);
            self::assertArrayHasKey('consent-to-treat', $library->outOfDate());

            $data['translates'] = 'not-a-fingerprint';
            file_put_contents($dir . '/es/consent-to-treat.json', json_encode($data, JSON_THROW_ON_ERROR));
            $this->expectExceptionMessage('es/consent-to-treat.json translates: must be the 16-digit fingerprint');
            (new DocumentLibrary($dir, null, 'sites/default/documents/clinic-forms', 'es'))->all();
        } finally {
            self::remove($dir);
        }
    }

    public function testAClinicsOwnEnglishVersionIsNotPrintedWithTheShippedSpanish(): void
    {
        // Before fingerprints, a clinic's edited consent printed in Spanish as the shipped original.
        $dir = sys_get_temp_dir() . '/cf-local-' . bin2hex(random_bytes(4));
        mkdir($dir . '/es', 0777, true);
        try {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            $data['blocks'][1]['text'] = 'Our own wording.';
            file_put_contents($dir . '/consent-to-treat.json', json_encode($data, JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $dir, 'sites/default/documents/clinic-forms', 'es');
            $doc = $library->get('consent-to-treat');
            self::assertSame(['clinic', 'en', 'Our own wording.'], [$doc?->source, $doc?->language, $doc?->blocks[1]['text'] ?? null]);
            self::assertSame('data/documents/es/consent-to-treat.json', $library->outOfDate()['consent-to-treat'] ?? null);

            // The clinic's own Spanish of its own wording, with that fingerprint, is used.
            $english = DocumentLibrary::read($dir . '/consent-to-treat.json', 'sites/default/documents/clinic-forms', 'clinic');
            $es = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/es/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            $es['blocks'][1]['text'] = 'Nuestro propio texto.';
            $es['translates'] = Translation::fingerprint($english);
            file_put_contents($dir . '/es/consent-to-treat.json', json_encode($es, JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $dir, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame(['es', 'Nuestro propio texto.'], [$library->get('consent-to-treat')?->language, $library->get('consent-to-treat')?->blocks[1]['text'] ?? null]);
            self::assertArrayNotHasKey('consent-to-treat', $library->outOfDate());
            self::assertSame([], $library->problems());

            // Then the clinic edits its English again: its Spanish is out of date too.
            $data['blocks'][1]['text'] = 'Our own wording, edited.';
            file_put_contents($dir . '/consent-to-treat.json', json_encode($data, JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $dir, 'sites/default/documents/clinic-forms', 'es');
            self::assertSame('en', $library->get('consent-to-treat')?->language);
            self::assertSame('sites/default/documents/clinic-forms/es/consent-to-treat.json', $library->outOfDate()['consent-to-treat'] ?? null);
        } finally {
            self::remove($dir);
        }
    }

    /** A shipped-style folder holding one document and its Spanish, current whatever the shipped one is. */
    private static function copyOf(string $id): string
    {
        $dir = sys_get_temp_dir() . '/cf-copy-' . bin2hex(random_bytes(4));
        mkdir($dir . '/es', 0777, true);
        copy(dirname(__DIR__, 2) . "/data/documents/$id.json", "$dir/$id.json");
        $es = json_decode((string)file_get_contents(dirname(__DIR__, 2) . "/data/documents/es/$id.json"), true, 64, JSON_THROW_ON_ERROR);
        $es['translates'] = Translation::fingerprint(DocumentLibrary::read("$dir/$id.json"));
        file_put_contents("$dir/es/$id.json", json_encode($es, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $dir;
    }

    private static function remove(string $dir): void
    {
        array_map('unlink', array_merge(glob($dir . '/es/*') ?: [], glob($dir . '/*.json') ?: []));
        rmdir($dir . '/es');
        rmdir($dir);
    }

    /** The shipped Spanish of a document, read whether or not it is up to date. */
    private static function shippedSpanish(string $id): Document
    {
        $english = DocumentLibrary::shipped()->get($id) ?? throw new \LogicException($id);
        return DocumentLibrary::read(dirname(__DIR__, 2) . "/data/documents/es/$id.json", 'data/documents/es', 'shipped', $english, 'es');
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
        self::assertTrue($spanish->isTranslated('consent-to-treat') || isset($spanish->outOfDate()['consent-to-treat']));
    }

    public function testADroppedElementIsADifference(): void
    {
        $english = DocumentLibrary::shipped()->get('release-of-information') ?? throw new \LogicException();
        $es = self::shippedSpanish('release-of-information');
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
        $doc = self::shippedSpanish('consent-to-treat');
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
