<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Loinc;
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
        $files = array_merge(glob(dirname(__DIR__, 2) . '/data/documents/*.json') ?: [], glob(dirname(__DIR__, 2) . '/data/packs/us/documents/*.json') ?: []);
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
        yield 'unknown audience' => [static fn(array $d): array => ['audience' => 'everyone'] + $d, 'audience: must be one of patient, staff, provider'];
        yield 'LOINC code with a wrong check digit' => [static fn(array $d): array => $d + ['code' => ['system' => 'http://loinc.org', 'code' => '57133-2', 'display' => 'Referral Note']], 'code: must be a LOINC code'];
        yield 'code from another system' => [static fn(array $d): array => $d + ['code' => ['system' => 'http://snomed.info/sct', 'code' => '57133-1', 'display' => 'x']], 'code: must be a LOINC code'];
        yield 'validated not a boolean' => [static fn(array $d): array => $d + ['validated' => 'yes'], 'validated: must be true or false'];
        yield 'id not the file name' => [static fn(array $d): array => ['id' => 'other'] + $d, "must be the file's name"];
        yield 'scale start below 1' => [static fn(array $d): array => ['blocks' => [['type' => 'scale', 'label' => 'L', 'columns' => ['No', 'Yes'], 'items' => ['Q'], 'start' => 0]]] + $d, "start: the first row's number"];
        // Skip logic: a show_if names a question above it, of the right kind, with an answer it has.
        $yesNo = ['type' => 'checklist', 'id' => 'q1', 'label' => 'Q1', 'items' => ['Yes', 'No']];
        $scale = ['type' => 'scale', 'id' => 'rows', 'label' => 'L', 'columns' => ['Yes', 'No'], 'items' => ['A', 'B']];
        $days = ['type' => 'fields', 'fields' => [['label' => 'Days', 'id' => 'days', 'number' => [0, 365]]]];
        $then = static fn(array $showIf): array => ['type' => 'lines', 'label' => 'Why?', 'count' => 1, 'show_if' => $showIf];
        yield 'show_if naming nothing' => [static fn(array $d): array => ['blocks' => [$then(['id' => 'q9', 'answer' => 1])]] + $d, '"id" must name a block or field above this one'];
        yield 'show_if naming a question below it' => [static fn(array $d): array => ['blocks' => [$then(['id' => 'q1', 'answer' => 1]), $yesNo]] + $d, '"id" must name a block or field above this one'];
        yield 'show_if answer out of range' => [static fn(array $d): array => ['blocks' => [$yesNo, $then(['id' => 'q1', 'answer' => 3])]] + $d, "answer: the answer's position, from 1 to 2"];
        yield 'show_if on a scale without its row' => [static fn(array $d): array => ['blocks' => [$scale, $then(['id' => 'rows', 'answer' => 1])]] + $d, 'needs "row"'];
        yield 'show_if row out of range' => [static fn(array $d): array => ['blocks' => [$scale, $then(['id' => 'rows', 'row' => 3, 'answer' => 1])]] + $d, "row: the scale's row, from 1 to 2"];
        yield 'show_if above on a choice' => [static fn(array $d): array => ['blocks' => [$yesNo, $then(['id' => 'q1', 'above' => 0])]] + $d, 'needs "answer"'];
        yield 'show_if above outside the range' => [static fn(array $d): array => ['blocks' => [$days, $then(['id' => 'days', 'above' => 365])]] + $d, "above: a whole number within the field's range"];
        yield 'show_if on something with nothing to answer' => [static fn(array $d): array => ['blocks' => [['type' => 'paragraph', 'id' => 'note', 'text' => 'Hi'], $then(['id' => 'note', 'answer' => 1])]] + $d, 'asks nothing to answer'];
        yield 'an id used twice' => [static fn(array $d): array => ['blocks' => [$yesNo, ['id' => 'q1'] + $scale]] + $d, '"q1" is already used'];
        yield 'an empty any' => [static fn(array $d): array => ['blocks' => [$yesNo, $then(['any' => []])]] + $d, 'one condition, or {"any"'];
        yield 'a number field the wrong way round' => [static fn(array $d): array => ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Days', 'number' => [365, 0]]]]]] + $d, 'number: the smallest and largest'];
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

    public function testPointsPerQuestionMustCoverEveryQuestionAndColumn(): void
    {
        $base = ['id' => 'x', 'title' => 'X', 'category' => 'C', 'about' => 'A', 'audience' => 'patient'];
        $scale = ['type' => 'scale', 'label' => 'L', 'columns' => ['Yes', 'No'], 'items' => ['One', 'Two']];
        $file = $this->dir . '/x.json';
        file_put_contents($file, json_encode($base + ['blocks' => [$scale + ['item_scores' => [[1, 0], [0, 1]]]]]));
        self::assertSame([[1, 0], [0, 1]], DocumentLibrary::read($file, 'test', 'clinic')->blocks[0]['item_scores']);
        foreach ([[[1, 0]], [[1, 0], [0]], [[1, 0], [0, 'x']]] as $bad) {
            file_put_contents($file, json_encode($base + ['blocks' => [$scale + ['item_scores' => $bad]]]));
            try {
                DocumentLibrary::read($file, 'test', 'clinic');
                self::fail('item_scores ' . json_encode($bad) . ' was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('item_scores', $e->getMessage());
            }
        }
    }

    public function testShippedDocumentsCarryTheirAudienceAndCodes(): void
    {
        $library = DocumentLibrary::shipped();
        self::assertSame(['system' => 'http://loinc.org', 'code' => '57133-1', 'display' => 'Referral Note'], $library->get('referral-letter')?->code);
        self::assertSame('provider', $library->get('referral-letter')?->audience);
        self::assertSame('staff', $library->get('routing-slip')?->audience);
        self::assertTrue($library->get('phq-9')?->validated);
        self::assertTrue($library->get('grievance-form')?->anonymous, 'a grievance may be filed without a name');
        self::assertFalse($library->get('consent-to-treat')?->anonymous);
        self::assertFalse($library->get('consent-to-treat')?->validated);
        self::assertNull($library->get('sliding-fee-application')?->code);
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
            'audience' => 'patient',
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

    public function testEachDocumentsLoincCodeHasLoincsNameForIt(): void
    {
        $checked = 0;
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            if (($doc->code['system'] ?? null) !== Loinc::SYSTEM) {
                continue;
            }
            self::assertSame(Loinc::name((string)$doc->code['code']), $doc->code['display'] ?? null,
                "$doc->id: its LOINC code {$doc->code['code']} goes with LOINC's long common name (data/loinc-names.json)");
            $checked++;
        }
        self::assertGreaterThan(20, $checked, 'the documents with LOINC codes were found');
    }
}
