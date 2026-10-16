<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentLibraryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cf-docs-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testEveryShippedDocumentLoads(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/data/documents/*.json') ?: [];
        $all = DocumentLibrary::shipped()->all();
        // A floor, so an empty glob can't pass as "every document loaded".
        self::assertGreaterThanOrEqual(12, count($files));
        self::assertCount(count($files), $all);
        foreach ($files as $file) {
            self::assertArrayHasKey(basename($file, '.json'), $all);
        }
    }

    public function testDocumentsAreOrderedByCategoryThenTitle(): void
    {
        $keys = array_map(static fn(Document $d): array => [$d->category, $d->title], array_values(DocumentLibrary::shipped()->all()));
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys);
    }

    public function testUsesNamesThePatientAndRecipientGroups(): void
    {
        $library = DocumentLibrary::shipped();
        $referral = $library->get('referral-letter');
        self::assertNotNull($referral);
        self::assertTrue($referral->usesPatient());
        self::assertTrue($referral->usesRecipient());
        $routing = $library->get('routing-slip');
        self::assertNotNull($routing);
        self::assertFalse($routing->usesRecipient());
        self::assertNull($library->get('no-such-document'));
    }

    public function testAValidDocumentReads(): void
    {
        $doc = DocumentLibrary::read($this->write('sample', $this->sample()));
        self::assertSame('sample', $doc->id);
        self::assertSame(['patient'], $doc->uses);
        self::assertCount(2, $doc->blocks);
    }

    /** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function brokenDocuments(): iterable
    {
        yield 'unknown top-level field' => [static fn(array $d): array => $d + ['colour' => 'blue'], 'unknown field "colour"'];
        yield 'missing title' => [static function (array $d): array {
            unset($d['title']);
            return $d;
        }, 'needs "title"'];
        yield 'unknown block type' => [static fn(array $d): array => ['blocks' => [['type' => 'video']]] + $d, 'type must be one of'];
        yield 'unknown placeholder' => [static fn(array $d): array => ['blocks' => [['type' => 'paragraph', 'text' => 'Dear {patient.nickname}']]] + $d, '{patient.nickname} is not a placeholder'];
        yield 'unknown block field' => [static fn(array $d): array => ['blocks' => [['type' => 'heading', 'text' => 'Hi', 'size' => 2]]] + $d, 'unknown field "size"'];
        yield 'too many lines' => [static fn(array $d): array => ['blocks' => [['type' => 'lines', 'label' => 'Notes', 'count' => 31]]] + $d, 'count: must be a whole number'];
        yield 'empty text' => [static fn(array $d): array => ['blocks' => [['type' => 'paragraph', 'text' => '  ']]] + $d, 'text: must be text'];
        yield 'no blocks' => [static fn(array $d): array => ['blocks' => []] + $d, 'blocks: must be a list'];
        yield 'empty signers' => [static fn(array $d): array => ['blocks' => [['type' => 'signature', 'signers' => []]]] + $d, 'signers: must be a list'];
        yield 'scale scores not one per column' => [static fn(array $d): array => ['blocks' => [['type' => 'scale', 'label' => 'Q', 'columns' => ['No', 'Yes'], 'items' => ['A'], 'scores' => [0]]]] + $d, 'scores: must be a whole number for each column'];
        yield 'scale with one column' => [static fn(array $d): array => ['blocks' => [['type' => 'scale', 'label' => 'Q', 'columns' => ['Yes'], 'items' => ['A']]]] + $d, 'columns: must be a list of 2 to 8 texts'];
        yield 'table with too many rows' => [static fn(array $d): array => ['blocks' => [['type' => 'table', 'label' => 'T', 'columns' => ['Name'], 'rows' => 31]]] + $d, 'rows: must be a whole number from 1 to 30'];
        yield 'unknown placeholder in a scale item' => [static fn(array $d): array => ['blocks' => [['type' => 'scale', 'label' => 'Q', 'columns' => ['No', 'Yes'], 'items' => ['{patient.ssn}']]]] + $d, '{patient.ssn} is not a placeholder'];
        yield 'id not the file name' => [static fn(array $d): array => ['id' => 'other'] + $d, "must be the file's name"];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenDocuments')]
    public function testABrokenDocumentIsRefusedWithItsPlaceNamed(callable $break, string $message): void
    {
        $file = $this->write('sample', $break($this->sample()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        try {
            DocumentLibrary::read($file);
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('data/documents/sample.json', $e->getMessage());
            throw $e;
        }
    }

    public function testAClinicDocumentIsAddedAndOneWithAShippedIdReplacesIt(): void
    {
        $this->write('sample', $this->sample());
        $consent = ['id' => 'consent-to-treat', 'title' => 'Our consent'] + $this->sample();
        $this->write('consent-to-treat', $consent);
        $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $this->dir, 'sites/default/documents/clinic-forms');
        self::assertSame('clinic', $library->get('sample')?->source);
        self::assertSame('Our consent', $library->get('consent-to-treat')?->title);
        self::assertSame('clinic', $library->get('consent-to-treat')?->source);
        self::assertSame('shipped', $library->get('referral-letter')?->source);
        self::assertSame([], $library->problems());
    }

    public function testABrokenClinicDocumentIsLeftOutAndReported(): void
    {
        $this->write('sample', ['blocks' => [['type' => 'paragraph', 'text' => '{patient.ssn}']]] + $this->sample());
        file_put_contents($this->dir . '/half.json', '{"id":');
        $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', $this->dir, 'sites/default/documents/clinic-forms');
        self::assertNull($library->get('sample'));
        self::assertNotNull($library->get('consent-to-treat'));
        $problems = $library->problems();
        self::assertCount(2, $problems);
        self::assertStringStartsWith('sites/default/documents/clinic-forms/half.json: not valid JSON', $problems[0]);
        self::assertStringContainsString('sample.json blocks[0]: {patient.ssn} is not a placeholder', $problems[1]);
    }

    public function testAMissingClinicFolderIsNotAProblem(): void
    {
        $library = DocumentLibrary::forSite($this->dir . '/no-such-site');
        self::assertNotNull($library->get('consent-to-treat'));
        self::assertSame([], $library->problems());
    }

    public function testInvalidJsonIsRefused(): void
    {
        file_put_contents($this->dir . '/bad.json', '{"id": "bad",');
        $this->expectExceptionMessage('data/documents/bad.json: not valid JSON');
        DocumentLibrary::read($this->dir . '/bad.json');
    }

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'id' => 'sample',
            'title' => 'Sample',
            'category' => 'Front desk',
            'about' => 'A sample.',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'I, {patient.name}, agree.'],
                ['type' => 'signature', 'signers' => ['Patient']],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function write(string $name, array $data): string
    {
        $file = $this->dir . "/$name.json";
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
        return $file;
    }
}
