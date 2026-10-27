<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Blocks;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Words;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shared blocks (data/blocks): each written once, included by name, and put in place when a document
 * is read, so the document is the same as when it was written out.
 */
final class BlocksTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testEveryShippedBlockIsIncludedByAShippedDocument(): void
    {
        // So each is checked as part of a document, and none is left behind unused.
        $used = [];
        foreach (array_keys(Words::LANGUAGES) as $language) {
            foreach (DocumentLibrary::shipped($language)->all() as $doc) {
                foreach ($doc->includes as $include) {
                    $used[$language][$include['block']] = true;
                }
            }
        }
        $blocks = Blocks::shipped();
        self::assertGreaterThanOrEqual(13, count($blocks->all()));
        foreach (array_keys(Words::LANGUAGES) as $language) {
            foreach ($blocks->all($language) as $id => $block) {
                $nested = array_filter(array_keys($blocks->all($language)), static fn(string $outer): bool => in_array(
                    ['type' => 'include', 'block' => $id],
                    json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/blocks/' . ($language === 'en' ? '' : "$language/") . "$outer.json"), true)['blocks'] ?? [],
                    true,
                ));
                self::assertTrue(isset($used[$language][$id]) || $nested !== [], "$language $id is included by a document, or by a block one includes");
                self::assertGreaterThanOrEqual(1, $block->version, $id);
            }
        }
    }

    public function testADocumentWithIncludesIsTheDocumentWrittenOut(): void
    {
        $phq9 = DocumentLibrary::shipped()->get('phq-9');
        self::assertNotNull($phq9);
        self::assertSame('fields', $phq9->blocks[0]['type'], 'the screening header, put in place');
        self::assertSame(['Name', 'Date of birth', 'Date'], array_column($phq9->blocks[0]['fields'], 'label'));
        self::assertSame('Little interest or pleasure in doing things', $phq9->blocks[1]['items'][0], "the PHQ-2's questions open the scale");
        self::assertCount(9, $phq9->blocks[1]['items']);
        $at = static fn(string $id): array => array_values(array_filter($phq9->includes, static fn(array $i): bool => $i['block'] === $id))[0];
        self::assertSame(['at' => 1, 'count' => 2, 'row' => 0], array_intersect_key($at('phq-2-questions'), ['at' => 1, 'count' => 1, 'row' => 1]));
        self::assertSame(['at' => 0, 'count' => 1], array_intersect_key($at('screening-header'), ['at' => 1, 'count' => 1]));
        self::assertSame(1, $at('phq-2-questions')['version']);

        $consent = DocumentLibrary::shipped()->get('telehealth-consent');
        self::assertNotNull($consent);
        $signature = array_values(array_filter($consent->includes, static fn(array $i): bool => $i['block'] === 'consent-signature'))[0];
        self::assertSame(2, $signature['count'], 'a block that includes another puts both in place');
        self::assertSame(['signature', 'fields'], array_column(array_slice($consent->blocks, $signature['at'], 2), 'type'));
    }

    public function testATranslationIncludesTheBlocksTranslation(): void
    {
        $es = DocumentLibrary::shipped('es')->get('phq-9');
        self::assertNotNull($es);
        self::assertSame('es', $es->language);
        self::assertSame('Poco interés o placer en hacer cosas', $es->blocks[1]['items'][0]);
        self::assertSame(['Nombre', 'Fecha de nacimiento', 'Fecha'], array_column($es->blocks[0]['fields'], 'label'));
        self::assertSame(Blocks::shipped()->get('phq-2-questions')?->version, Blocks::shipped()->get('phq-2-questions', 'es')?->version, "a translation takes the English block's version");
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function mistakes(): array
    {
        $doc = static fn(array $blocks): array => ['id' => 'sample', 'title' => 'S', 'category' => 'Front desk', 'about' => 'A.', 'audience' => 'patient', 'blocks' => $blocks];
        $scale = static fn(array $items): array => ['type' => 'scale', 'label' => 'L', 'columns' => ['No', 'Yes'], 'items' => $items];
        return [
            'an unknown block' => [$doc([['type' => 'include', 'block' => 'nope']]), 'blocks[0]: no block "nope"'],
            "a scale's questions as a block" => [$doc([['type' => 'include', 'block' => 'phq-2-questions']]), "is a scale's questions; include it among a scale's items"],
            "blocks as a scale's questions" => [$doc([$scale([['include' => 'consent-signature']])]), 'is document blocks, not a scale\'s questions'],
            'an include with more' => [$doc([['type' => 'include', 'block' => 'representative', 'show_if' => ['id' => 'x', 'answer' => 1]]]), 'an include has only "type" and "block"'],
            'a block name that is not text' => [$doc([['type' => 'include', 'block' => 3]]), 'names a block by its id'],
        ];
    }

    #[DataProvider('mistakes')]
    public function testAnIncludeThatIsWrongIsRefusedWithItsPlace(array $data, string $message): void
    {
        $this->dir = sys_get_temp_dir() . '/cf-blocks-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/sample.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        DocumentLibrary::read($this->dir . '/sample.json');
    }

    public function testAClinicsDocumentsIncludeTheModulesBlocksAndTheirOwn(): void
    {
        $local = $this->site();
        mkdir("$local/blocks");
        file_put_contents("$local/blocks/visit-reason.json", json_encode(['id' => 'visit-reason', 'title' => 'Visit reason', 'about' => 'Why the patient came.',
            'blocks' => [['type' => 'lines', 'label' => 'Reason for the visit', 'count' => 2]]], JSON_THROW_ON_ERROR));
        // The module's name for a block isn't the clinic's to take: that would change the module's documents.
        file_put_contents("$local/blocks/representative.json", json_encode(['id' => 'representative', 'title' => 'Mine', 'about' => 'Mine.',
            'blocks' => [['type' => 'paragraph', 'text' => 'Changed']]], JSON_THROW_ON_ERROR));
        file_put_contents("$local/blocks/loops.json", json_encode(['id' => 'loops', 'title' => 'Loops', 'about' => 'Includes itself.',
            'blocks' => [['type' => 'include', 'block' => 'loops']]], JSON_THROW_ON_ERROR));
        file_put_contents("$local/blocks/skips.json", json_encode(['id' => 'skips', 'title' => 'Skips', 'about' => 'Has skip logic.',
            'blocks' => [['type' => 'paragraph', 'text' => 'X', 'show_if' => ['id' => 'a', 'answer' => 1]]]], JSON_THROW_ON_ERROR));
        file_put_contents("$local/intake.json", json_encode(['id' => 'intake', 'title' => 'Intake', 'category' => 'Front desk', 'about' => 'A clinic form.', 'audience' => 'patient',
            'blocks' => [['type' => 'include', 'block' => 'patient-identification'], ['type' => 'include', 'block' => 'visit-reason'], ['type' => 'include', 'block' => 'consent-signature']]], JSON_THROW_ON_ERROR));
        $library = DocumentLibrary::forSite(dirname($local, 2), 'en', null);
        $intake = $library->get('intake');
        self::assertNotNull($intake, implode("\n", $library->problems()));
        self::assertSame(['fields', 'lines', 'signature', 'fields'], array_column($intake->blocks, 'type'));
        self::assertSame(['If signed by a representative: name', 'Relationship or legal authority'], array_column($intake->blocks[3]['fields'], 'label'),
            "the module's representative block, not the clinic's");
        self::assertSame(['shipped', 'clinic', 'shipped'], array_column($intake->includes, 'source'));
        $problems = implode("\n", $library->problems());
        self::assertStringContainsString('blocks/representative.json: the module has a block named "representative"', $problems);
        self::assertStringContainsString('blocks/loops.json blocks[0]: block "loops" includes itself', $problems);
        self::assertStringContainsString('blocks/skips.json blocks[0]: skip logic (id, show_if) belongs to the document, not a block', $problems);
        self::assertNotNull($library->get('consent-to-treat'), "the module's documents still load");
    }

    public function testABlocksTranslationKeepsItsEnglishShape(): void
    {
        $local = $this->site();
        mkdir("$local/blocks/es", 0777, true);
        file_put_contents("$local/blocks/two.json", json_encode(['id' => 'two', 'title' => 'Two', 'about' => 'Two questions.', 'rows' => ['One?', 'Two?']], JSON_THROW_ON_ERROR));
        file_put_contents("$local/blocks/es/two.json", json_encode(['id' => 'two', 'title' => 'Dos', 'about' => 'Dos preguntas.', 'rows' => ['¿Una?']], JSON_THROW_ON_ERROR));
        $blocks = new Blocks(dirname(__DIR__, 2) . '/data/blocks', "$local/blocks", 'sites/test/documents/clinic-forms/blocks');
        self::assertNull($blocks->get('two', 'es'));
        self::assertStringContainsString('blocks/es/two.json rows: has 1 where the English has 2', implode("\n", $blocks->problems()));
        self::assertSame(['One?', 'Two?'], $blocks->get('two')?->rows);
    }

    /** A site folder with an empty clinic documents folder; returns the documents folder. */
    private function site(): string
    {
        $this->dir = sys_get_temp_dir() . '/cf-site-' . bin2hex(random_bytes(4));
        $local = $this->dir . '/test/' . DocumentLibrary::LOCAL_FOLDER;
        mkdir($local, 0777, true);
        return $local;
    }
}
