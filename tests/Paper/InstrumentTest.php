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
            // Cut-offs on a total, or for a screen read by rule (the ASQ, TAPS-1), its published rules.
            $cutoffs = $r['cutoffs'] ?? [];
            $rules = $r['rules'] ?? [];
            $subscales = $r['subscales'] ?? [];
            self::assertTrue($cutoffs !== [] || $rules !== [] || $subscales !== [], "$code: the published cut-offs, rules, or subscales with their cut-offs");
            foreach ($cutoffs as $cut) {
                self::assertGreaterThan($r['total']['min'], $cut['score'], "$code: a cut-off inside the range");
                self::assertLessThanOrEqual($r['total']['max'], $cut['score'], "$code: a cut-off inside the range");
                self::assertNotSame('', trim((string)$cut['meaning']), "$code: what the cut-off means");
            }
            // A paper, by its PubMed id, or the instrument owner's own page (CDC's brochure, the developers' scoring sheet).
            foreach (array_filter(['cutoffs_source' => $cutoffs, 'rules_source' => $rules, 'subscales_source' => $subscales]) as $field => $unused) {
                self::assertTrue(preg_match('/^\d+$/', (string)($r[$field]['pmid'] ?? '')) === 1 || str_starts_with((string)($r[$field]['url'] ?? ''), 'https://'),
                    "$code: the $field, by PubMed id or the owner's URL");
                self::assertNotSame('', trim((string)($r[$field]['stated'] ?? '')), "$code: where the $field states them");
            }
            $answers = array_column(array_map(static fn(array $q): array => [$q['code'], array_column($q['answers'], 'text')], $r['questions']), 1, 0);
            foreach ($rules as $rule) {
                self::assertContains($rule['if'] ?? null, ['any', 'all'], "$code: a rule is \"any\" or \"all\" of its questions");
                self::assertNotSame('', trim((string)($rule['meaning'] ?? '')), "$code: what a rule means");
                self::assertNotEmpty($rule['questions'] ?? [], "$code: a rule's questions");
                self::assertNotEmpty($rule['answers'] ?? [], "$code: a rule's answers");
                foreach ($rule['questions'] as $q) {
                    self::assertArrayHasKey($q, $answers, "$code: a rule's question is one of the instrument's");
                    foreach ($rule['answers'] as $a) {
                        self::assertContains($a, $answers[$q], "$code: $q offers the rule's answer \"$a\"");
                    }
                }
            }
            if (isset($r['questions_from'])) {
                foreach (['name', 'url', 'why'] as $key) {
                    self::assertNotSame('', trim((string)($r['questions_from'][$key] ?? '')), "$code: written by hand, so whose instrument, where, and why LOINC's isn't used ($key)");
                }
            }
            $byCode = array_column($r['questions'], 'answers', 'code');
            foreach ($subscales as $s) {
                self::assertNotSame('', trim((string)($s['name'] ?? '')), "$code: a subscale's name");
                self::assertNotSame('', trim((string)($s['field'] ?? '')), "$code: the line a subscale's score goes on");
                foreach ($s['questions'] as $q) {
                    self::assertArrayHasKey($q, $byCode, "$code: subscale {$s['name']}'s question is one of the instrument's");
                }
                foreach ($s['one_of'] ?? [] as $group) {
                    self::assertSame([], array_diff($group, $s['questions']), "$code: subscale {$s['name']}'s one-of group is among its questions");
                }
                $range = Instrument::subscaleRange(array_intersect_key($byCode, array_flip($s['questions'])), $s['one_of'] ?? []);
                self::assertNotEmpty($s['cutoffs'] ?? [], "$code: subscale {$s['name']}'s published cut-offs");
                foreach ($s['cutoffs'] as $cut) {
                    self::assertGreaterThan($range['min'], $cut['score'], "$code: {$s['name']} cut-off inside its range");
                    self::assertLessThanOrEqual($range['max'], $cut['score'], "$code: {$s['name']} cut-off inside its range");
                }
            }
            foreach ($r['free_text'] ?? [] as $free) {
                self::assertNotSame('', trim((string)($free['text'] ?? '')), "$code: free text has its wording");
            }
            if (!empty((array)($r['scoring']['overrides'] ?? []))) {
                self::assertNotSame('', trim((string)($r['scoring']['source']['stated'] ?? '')), "$code: where the published scoring comes from, where it differs from LOINC");
            }
            foreach (['questions', 'extra'] as $kind) {
                foreach ($r['accepted'][$kind] ?? [] as $n => $why) {
                    self::assertNotSame('', trim((string)$why), "$code: accepted $kind $n says why");
                }
            }
            foreach ($r['accepted']['answers'] ?? [] as $printed => $a) {
                self::assertNotSame('', trim((string)($a['why'] ?? '')), "$code: accepted answer \"$printed\" says why");
                self::assertNotSame('', trim((string)($a['instead_of'] ?? '')), "$code: accepted answer \"$printed\" names LOINC's text");
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

    public function testAScreenReadByRuleMustSayItsRules(): void
    {
        // The ASQ's result is a rule, not a total: dropping the acute line leaves "non-acute positive
        // screen" on the page, which doesn't say it.
        $asq = self::doc('asq');
        self::assertSame([], Instrument::differences($asq, self::ref($asq)));
        $without = array_values(array_filter($asq->blocks, static fn(array $b): bool => !($b['type'] === 'paragraph' && str_starts_with($b['text'], 'Yes to question 5'))));
        self::assertSame(['it doesn\'t say the published rule "Yes to question 5: acute positive screen"'], Instrument::differences(self::with($asq, $without), self::ref($asq)));
        // The right words with the wrong questions.
        $renumbered = array_map(static fn(array $b): array => $b['type'] === 'paragraph' && str_starts_with($b['text'], 'Yes to any of questions 1 to 4')
            ? ['type' => 'paragraph', 'text' => 'Yes to any of questions 1 to 3 is a positive screen.'] : $b, $asq->blocks);
        self::assertSame(['it doesn\'t say the published rule "Yes to any of questions 1 to 4: positive screen"'], Instrument::differences(self::with($asq, $renumbered), self::ref($asq)));
        // TAPS-1's cut-points for a possible substance use disorder are two rules in one paragraph.
        $taps = self::doc('taps-1');
        $cut = array_values(array_filter($taps->blocks, static fn(array $b): bool => !($b['type'] === 'paragraph' && str_contains($b['text'], 'Gryczynski'))));
        self::assertCount(2, Instrument::differences(self::with($taps, $cut), self::ref($taps)));
        // And a rule naming a question the instrument doesn't have is the reference's fault, said as such.
        $ref = self::ref($asq);
        $ref['rules'][0]['questions'][] = '44250-9';
        self::assertStringContainsString("names 44250-9, which isn't one of the instrument's questions", implode("\n", Instrument::differences($asq, $ref)));
    }

    public function testAnInstrumentScoredInPartsIsCheckedPartByPart(): void
    {
        // TAPS-2 scores each substance: alcohol runs 0 to 4, since a person answers the 4-or-more
        // question (women) or the 5-or-more (men), not both.
        $taps2 = self::doc('taps-2');
        $ref = self::ref($taps2);
        $alcohol = array_values(array_filter($ref['subscales'], static fn(array $s): bool => $s['name'] === 'Alcohol'))[0];
        $answers = array_intersect_key(array_column($ref['questions'], 'answers', 'code'), array_flip($alcohol['questions']));
        self::assertSame(['min' => 0.0, 'max' => 4.0], Instrument::subscaleRange($answers, $alcohol['one_of']));
        self::assertSame(['min' => 0.0, 'max' => 5.0], Instrument::subscaleRange($answers), 'counting both would be 5');
        self::assertSame([], Instrument::differences($taps2, $ref));
        // A cut-off no part publishes.
        $blocks = array_map(static fn(array $b): array => $b['type'] === 'paragraph' && str_contains($b['text'], 'McNeely')
            ? ['type' => 'paragraph', 'text' => 'Published cut-offs: 3 or more is a likely substance use disorder.'] : $b, $taps2->blocks);
        self::assertSame(['it prints a cut-off of 3 where the published ones are 1, 2'], Instrument::differences(self::with($taps2, $blocks), $ref));
        // A part naming a question the instrument doesn't have is the reference's fault, said as such.
        $broken = $ref;
        $broken['subscales'][0]['questions'][] = '44250-9';
        self::assertStringContainsString("subscale \"Tobacco\" names 44250-9", implode("\n", Instrument::differences($taps2, $broken)));
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

    public function testAClinicsOwnVersionIsCheckedToo(): void
    {
        // A clinic's copy is still the instrument, even without "validated": AMRS's PHQ-9 scores 1 as "7 or less days".
        $site = sys_get_temp_dir() . '/cf-inst-' . bin2hex(random_bytes(4));
        $folder = $site . '/' . DocumentLibrary::LOCAL_FOLDER;
        mkdir($folder, 0777, true);
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/phq-9.json'), true);
        try {
            file_put_contents("$folder/phq-9.json", json_encode($data));
            $exact = DocumentLibrary::forSite($site)->get('phq-9');
            self::assertSame('clinic', $exact?->source);
            self::assertSame([], Instrument::localDifferences($exact, DocumentLibrary::shipped()->get('phq-9')));

            unset($data['validated']);
            foreach ($data['blocks'] as &$b) {
                if ($b['type'] === 'scale') {
                    $b['columns'][1] = '7 or less days of the last 2 weeks';
                }
            }
            unset($b);
            file_put_contents("$folder/phq-9.json", json_encode($data));
            $changed = DocumentLibrary::forSite($site)->get('phq-9');
            $problems = Instrument::localDifferences($changed, DocumentLibrary::shipped()->get('phq-9'));
            self::assertSame(['questions 1 to 9, answer 2 is "7 or less days of the last 2 weeks" where the instrument has "Several days"'], $problems, 'one changed column, said once');
            self::assertSame([], Instrument::localDifferences(DocumentLibrary::shipped()->get('phq-9')), 'the shipped one is the tests\' business, not the page\'s');
        } finally {
            array_map('unlink', glob("$folder/*") ?: []);
            rmdir($folder);
            rmdir(dirname($folder));
            rmdir($site);
        }
    }

    public function testAScaleCanScoreEachQuestionItsOwnWay(): void
    {
        // The GDS-15 scores No on some questions and Yes on others: points per question (item_scores).
        $gds = self::doc('gds-15');
        $questions = Instrument::questions($gds);
        self::assertSame(1.0, $questions[0]['answers'][1]['score'], 'question 1 scores No');
        self::assertSame(1.0, $questions[1]['answers'][0]['score'], 'question 2 scores Yes');
        $blocks = $gds->blocks;
        foreach ($blocks as &$b) {
            if ($b['type'] === 'scale') {
                $b['item_scores'][0] = [1, 0];
            }
        }
        unset($b);
        self::assertContains('question 1, "Yes" scores 1 where the instrument scores 0', Instrument::differences(self::with($gds, $blocks), self::ref($gds)));
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
