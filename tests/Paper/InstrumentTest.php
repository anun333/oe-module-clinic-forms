<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Instrument;
use OpenHealthcare\ClinicForms\Paper\Loinc;
use PHPUnit\Framework\TestCase;

/**
 * A validated instrument's score means what its study says only when its questions, their order,
 * its answers and their points, and the cut-offs printed beside it are the instrument's. These
 * tests hold every validated document to its reference in data/instruments, and show the check
 * catches the faults found in other projects' copies (docs/products/clinic-forms/forms-review-2026-10.md
 * in the openhealthcare workspace).
 */
final class InstrumentTest extends TestCase
{
    /** @return array<string, Document> */
    private static function validated(): array
    {
        return array_filter(DocumentLibrary::shipped()->all(), static fn(Document $d): bool => $d->validated);
    }

    public function testEveryValidatedDocumentMatchesItsInstrument(): void
    {
        $docs = self::validated();
        self::assertGreaterThanOrEqual(4, count($docs), 'the validated documents are found');
        foreach ($docs as $doc) {
            self::assertSame(Loinc::SYSTEM, $doc->code['system'] ?? null, "$doc->id: a validated instrument carries its LOINC code");
            $reference = Instrument::reference((string)$doc->code['code']);
            self::assertNotNull($reference, "$doc->id: data/instruments/{$doc->code['code']}.json (tools/instrument-references.php writes it)");
            self::assertSame([], Instrument::differences($doc, $reference), "$doc->id differs from its instrument");
        }
    }

    public function testTranslationsKeepTheirInstrument(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/data/documents/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $language = basename($dir);
            $translated = DocumentLibrary::shipped($language)->all();
            foreach (self::validated() as $id => $english) {
                $doc = $translated[$id] ?? null;
                if ($doc === null || $doc->language !== $language) {
                    continue;
                }
                $checked++;
                self::assertSame([], Instrument::translationDifferences($english, $doc), "$language/$id differs from the English instrument");
            }
        }
        self::assertGreaterThanOrEqual(4, $checked, 'the translations of the validated documents are found');
    }

    public function testEveryReferenceIsComplete(): void
    {
        $files = glob(Instrument::dir() . '/*.json') ?: [];
        self::assertGreaterThanOrEqual(4, count($files));
        foreach ($files as $file) {
            $code = basename($file, '.json');
            $r = Instrument::reference($code);
            self::assertNotNull($r, "$code: a valid LOINC code and JSON");
            self::assertSame($code, $r['loinc']);
            self::assertStringContainsString('Regenstrief', (string)$r['notice'], "$code: LOINC's notice");
            self::assertNotEmpty($r['questions'], "$code: questions from LOINC");
            $range = Instrument::totalRange(array_map(static fn(array $q): array => ['answers' => array_map(
                static fn(array $a): array => ['score' => is_numeric($a['score']) ? (float)$a['score'] : null], $q['answers'])], $r['questions']));
            self::assertEquals($range, $r['total'], "$code: the total is its questions' range");
            self::assertNotEmpty($r['cutoffs'], "$code: the published cut-offs");
            foreach ($r['cutoffs'] as $cut) {
                self::assertGreaterThan($r['total']['min'], $cut['score'], "$code: a cut-off inside the range");
                self::assertLessThanOrEqual($r['total']['max'], $cut['score'], "$code: a cut-off inside the range");
                self::assertNotSame('', trim((string)$cut['meaning']), "$code: what the cut-off means");
            }
            self::assertMatchesRegularExpression('/^\d+$/', (string)($r['cutoffs_source']['pmid'] ?? ''), "$code: the cut-offs' paper, by PubMed id");
            self::assertNotSame('', trim((string)($r['cutoffs_source']['stated'] ?? '')), "$code: where the paper states them");
            foreach (['questions', 'extra'] as $kind) {
                foreach ($r['accepted'][$kind] ?? [] as $n => $why) {
                    self::assertNotSame('', trim((string)$why), "$code: accepted $kind $n says why");
                }
            }
        }
    }

    // The check must be able to fail: each fault below was found in another project's copy.

    public function testCatchesAQuestionFromAnotherInstrument(): void
    {
        // KenyaEMR's GAD-7 asks PHQ-9's item 6 in place of "Becoming easily annoyed or irritable".
        $gad7 = self::doc('gad-7');
        $blocks = $gad7->blocks;
        foreach ($blocks as &$b) {
            if ($b['type'] === 'scale') {
                $b['items'][5] = 'Feeling bad about yourself, or that you are a failure, or that you have let yourself or your family down';
            }
        }
        unset($b);
        $problems = Instrument::differences(self::with($gad7, $blocks), self::ref($gad7));
        self::assertCount(1, $problems);
        self::assertStringContainsString('question 6', $problems[0]);
        self::assertStringContainsString('irritable', $problems[0]);
    }

    public function testCatchesAnAddedAnswerAndAChangedScore(): void
    {
        // AMRS's AUDIT-C adds "0 drinks" to question 2; a changed point is the quieter version of the same fault.
        $audit = self::doc('audit-c');
        $added = $changed = $audit->blocks;
        foreach ($added as &$b) {
            if ($b['type'] === 'checklist' && str_starts_with($b['label'], '2.')) {
                array_unshift($b['items'], '0 drinks (0)');
            }
        }
        unset($b);
        foreach ($changed as &$b) {
            if ($b['type'] === 'checklist' && str_starts_with($b['label'], '3.')) {
                $b['items'][4] = 'Daily or almost daily (5)';
            }
        }
        unset($b);
        $problems = Instrument::differences(self::with($audit, $added), self::ref($audit));
        self::assertNotEmpty(array_filter($problems, static fn(string $p): bool => str_contains($p, 'question 2 has 6 answers where the instrument has 5')));
        $problems = Instrument::differences(self::with($audit, $changed), self::ref($audit));
        self::assertContains('question 3, "Daily or almost daily" scores 5 where the instrument scores 4', $problems);
        self::assertContains('the total runs 0 to 13 where the instrument runs 0 to 12', $problems);
    }

    public function testCatchesAWrongCutoffAndAWrongRange(): void
    {
        $phq2 = self::doc('phq-2');
        $blocks = array_map(static fn(array $b): array => $b['type'] === 'paragraph' && str_contains($b['text'], 'cut-off')
            ? ['type' => 'paragraph', 'text' => 'A total of 2 or more is the usual cut-off for giving the full PHQ-9.'] : $b, $phq2->blocks);
        self::assertContains('it prints a cut-off of 2 where the published ones are 3', Instrument::differences(self::with($phq2, $blocks), self::ref($phq2)));
        $phq9 = self::doc('phq-9');
        $wrong = new Document($phq9->id, $phq9->title, $phq9->category, str_replace('0 to 27', '0 to 21', $phq9->about), $phq9->blocks, $phq9->uses, code: $phq9->code, validated: true);
        self::assertContains('the description says "scored 0 to 21" but the questions total 0 to 27', Instrument::differences($wrong, self::ref($phq9)));
    }

    public function testCatchesATranslationThatChangesTheScoring(): void
    {
        $english = self::doc('phq-9');
        $spanish = DocumentLibrary::shipped('es')->get('phq-9');
        self::assertNotNull($spanish);
        $blocks = $spanish->blocks;
        foreach ($blocks as &$b) {
            if ($b['type'] === 'scale') {
                $b['scores'] = [1, 2, 3, 4];
            }
        }
        unset($b);
        $problems = Instrument::translationDifferences($english, self::with($spanish, $blocks));
        self::assertNotEmpty($problems);
        self::assertStringContainsString('scores 1/2/3/4 where the English scores 0/1/2/3', $problems[0]);
    }

    public function testWordingAllowsSpellingButNotAnotherQuestion(): void
    {
        self::assertTrue(Instrument::same('How often do you have six or more drinks on one occasion?', 'How often do you have 6 or more drinks on 1 occasion?'));
        self::assertTrue(Instrument::same('2 to 4 times a month', '2-4 times a month'));
        self::assertFalse(Instrument::same('General examination findings', 'Oral examination findings'));
        self::assertFalse(Instrument::same('Feeling bad about yourself', 'Becoming easily annoyed or irritable'));
    }

    private static function doc(string $id): Document
    {
        $doc = DocumentLibrary::shipped()->get($id);
        self::assertNotNull($doc, $id);
        return $doc;
    }

    private static function ref(Document $doc): array
    {
        $ref = Instrument::reference((string)$doc->code['code']);
        self::assertNotNull($ref);
        return $ref;
    }

    private static function with(Document $doc, array $blocks): Document
    {
        return new Document($doc->id, $doc->title, $doc->category, $doc->about, $blocks, $doc->uses, $doc->source, $doc->audience, $doc->code, $doc->validated, $doc->language);
    }
}
