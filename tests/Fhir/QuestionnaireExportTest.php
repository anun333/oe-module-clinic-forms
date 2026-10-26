<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Fhir;

use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Loinc;
use OpenHealthcare\ClinicForms\Paper\Translation;
use OpenHealthcare\ClinicForms\Paper\Words;
use PHPUnit\Framework\TestCase;

/**
 * Documents as FHIR R4 Questionnaires. The workspace checks the files further: the HL7 FHIR
 * validator, OpenEMR's import check, and OpenEMR's questionnaire runtime working out each total.
 */
final class QuestionnaireExportTest extends TestCase
{
    private const PATIENT = ['name' => 'Jane Q Sample', 'dob' => '1970-01-01', 'id' => 'WL9001'];

    public function testAValidatedInstrumentCarriesLoincCodesPointsAndAWorkedOutTotal(): void
    {
        $q = $this->export('phq-9');
        self::assertSame('Questionnaire', $q['resourceType']);
        self::assertSame(['http://loinc.org/q/44249-1'], $q['derivedFrom']);
        self::assertSame([['system' => 'http://loinc.org', 'code' => '44249-1', 'display' => 'PHQ-9 quick depression assessment panel [Reported.PHQ]']], $q['code'],
            "every LOINC code with LOINC's name for it, as the LOINC licence asks");
        $items = self::flatten($q['item']);
        $first = $items['/44250-9'];
        self::assertSame('1.', $first['prefix']);
        self::assertSame('Little interest or pleasure in doing things', $first['text']);
        self::assertSame([['system' => 'http://loinc.org', 'code' => '44250-9', 'display' => 'Little interest or pleasure in doing things in last 2 weeks']], $first['code']);
        self::assertSame(['LA6568-5', 'LA6569-3', 'LA6570-1', 'LA6571-9'], array_map(static fn(array $o): string => $o['valueCoding']['code'], $first['answerOption']));
        self::assertSame([0, 1, 2, 3], array_map(static fn(array $o): int => $o['valueCoding']['extension'][0]['valueDecimal'], $first['answerOption']),
            'the points on the Coding, where a response answer carries them');
        self::assertCount(10, array_filter(array_keys($items), static fn(string $id): bool => str_starts_with($id, '/')), 'nine questions and the difficulty question');
        $total = array_values(array_filter($items, static fn(array $i): bool => $i['type'] === 'decimal'));
        self::assertCount(1, $total);
        self::assertTrue($total[0]['readOnly']);
        self::assertSame(QuestionnaireExport::CALCULATED, $total[0]['extension'][0]['url']);
        self::assertSame([['system' => 'http://loinc.org', 'code' => '44261-6', 'display' => 'Patient Health Questionnaire 9 item (PHQ-9) total score [Reported]']], $total[0]['code'],
            "LOINC's term for the total, from the reference");
        self::assertSame([], array_filter($items, static fn(array $i): bool => in_array($i['text'] ?? '', ['Name', 'Date of birth'], true)),
            "the patient's own fields are left out: the response names its patient");
        self::assertSame('date', array_values(array_filter($items, static fn(array $i): bool => ($i['text'] ?? '') === 'Date'))[0]['type']);
    }

    public function testADocumentThatDiffersFromItsInstrumentClaimsNoCodes(): void
    {
        $phq9 = $this->doc('phq-9');
        $blocks = $phq9->blocks;
        foreach ($blocks as &$b) {
            if ($b['type'] === 'scale') {
                $b['scores'] = [0, 1, 2, 4];
            }
        }
        unset($b);
        $changed = new Document($phq9->id, $phq9->title, $phq9->category, $phq9->about, $blocks, $phq9->uses, 'clinic', $phq9->audience, $phq9->code, true);
        $q = (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'])))->questionnaire($changed);
        self::assertArrayNotHasKey('derivedFrom', $q);
        self::assertArrayNotHasKey('url', $q, "a clinic's own version doesn't take the shipped document's canonical URL");
        self::assertSame('Seed Clinic', $q['publisher']);
        $items = self::flatten($q['item']);
        self::assertSame([], array_filter(array_keys($items), static fn(string $id): bool => str_starts_with($id, '/')), 'no LOINC question codes');
        self::assertSame([], array_filter($items, static fn(array $i): bool => isset($i['code'])), 'and no code on its total');
        self::assertSame(['extension' => [['url' => QuestionnaireExport::ORDINAL, 'valueDecimal' => 0]], 'system' => QuestionnaireExport::ANSWER_SYSTEM, 'code' => '1', 'display' => 'Not at all'],
            array_values(array_filter($items, static fn(array $i): bool => $i['type'] === 'choice'))[0]['answerOption'][0]['valueCoding'],
            "its answers in the module's own system, by position, with their points on the Coding: never a plain string");
    }

    public function testNoShippedDocumentHasAStringAnswerOption(): void
    {
        $strings = [];
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            $q = (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], [])))->questionnaire($doc);
            foreach (self::flatten($q['item']) as $item) {
                foreach ($item['answerOption'] ?? [] as $option) {
                    if (!isset($option['valueCoding'])) {
                        $strings[] = $doc->id . ' ' . $item['linkId'];
                    }
                }
            }
        }
        self::assertSame([], $strings, "LHC-Forms saves a string option as a display-only Coding, which OpenEMR's runtime doesn't match");
    }

    public function testEachDepthsSumIsGuardedSoAnEmptyDepthCannotEmptyTheTotal(): void
    {
        $points = ".answer.valueCoding.extension.where(url = '" . QuestionnaireExport::ORDINAL . "').valueDecimal";
        $guard = static fn(string $d, string $which = ''): string => "iif($d$which$points.exists(), $d$which$points.sum(), 0)";
        $depths = ['%resource.item', '%resource.item.item', '%resource.item.item.item'];
        $phq9 = array_values(array_filter(self::flatten($this->export('phq-9')['item']), static fn(array $i): bool => $i['type'] === 'decimal'))[0];
        $nine = '.where(' . implode(' or ', array_map(static fn(string $c): string => "linkId = '/$c'",
            ['44250-9', '44255-8', '44259-0', '44254-1', '44251-7', '44258-2', '44252-5', '44253-3', '44260-8'])) . ')';
        $answered = implode(' + ', array_map(static fn(string $d): string => "$d$nine.answer.count()", $depths));
        self::assertSame('iif(' . $answered . ' = 9, ' . implode(' + ', array_map($guard, $depths)) . ', {})', $phq9['extension'][0]['valueExpression']['expression'],
            'an empty sum is empty in fhirpath.js 5, so each depth falls back to 0; no repeat(), which OpenEMR 8.3 and 8.4 lack; '
            . 'and the total is empty until all nine questions are answered');
        $parts = array_values(array_filter(self::flatten($this->export('taps-2')['item']), static fn(array $i): bool => $i['type'] === 'decimal' && !isset($i['code'])));
        self::assertNotSame([], $parts);
        foreach ($parts as $part) {
            $expression = $part['extension'][0]['valueExpression']['expression'];
            self::assertSame(4, substr_count($expression, 'iif('), "a part's score is guarded at each depth too, and waits for its questions");
            self::assertStringNotContainsString('repeat(', $expression);
        }
    }

    public function testATotalWaitsForEveryScoredQuestionThatEveryoneIsAsked(): void
    {
        $required = static function (array $q): array {
            $out = [];
            foreach (self::flatten($q['item']) as $item) {
                if ($item['type'] === 'decimal' && preg_match('/^iif\((.*) = (\d+), /', $item['extension'][0]['valueExpression']['expression'] ?? '', $m) === 1) {
                    $out[$item['linkId']] = (int)$m[2];
                }
            }
            return array_values($out);
        };
        self::assertSame([9], $required($this->export('phq-9')), 'a blank answer is not 0 points: no PHQ-9 total until all nine are answered');
        self::assertSame([7], $required($this->export('gad-7')));
        self::assertSame([2], $required($this->export('phq-2')));
        self::assertSame([2, 2, 4], $required($this->export('phq-4')), "PHQ-4's two parts wait for their own questions, its total for all four");
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            $q = (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], [])))->questionnaire($doc);
            foreach (self::flatten($q['item']) as $item) {
                self::assertArrayNotHasKey('_scope', $item, "the export's own bookkeeping never reaches a Questionnaire");
                if ($item['type'] === 'decimal' && isset($item['extension'])) {
                    self::assertStringStartsWith('iif(', $item['extension'][0]['valueExpression']['expression'], $doc->id . ' ' . $item['linkId']);
                }
            }
        }
        // A scored question asked only of some patients doesn't hold the total back.
        $guard = new \ReflectionMethod(QuestionnaireExport::class, 'guardTotals');
        $ord = ['extension' => [['url' => QuestionnaireExport::ORDINAL, 'valueDecimal' => 1]]];
        $items = $guard->invoke(null, [
            ['linkId' => 'a', 'type' => 'choice', 'answerOption' => [['valueCoding' => $ord + ['code' => '1']]]],
            ['linkId' => 'b', 'type' => 'choice', 'enableWhen' => [['question' => 'a', 'operator' => 'exists', 'answerBoolean' => true]], 'answerOption' => [['valueCoding' => $ord + ['code' => '1']]]],
            ['linkId' => 't', 'type' => 'decimal', 'extension' => [['url' => QuestionnaireExport::CALCULATED, 'valueExpression' => ['expression' => 'SUM']]], '_scope' => null],
        ]);
        self::assertSame("iif(%resource.item.where(linkId = 'a').answer.count() + %resource.item.item.where(linkId = 'a').answer.count() + %resource.item.item.item.where(linkId = 'a').answer.count() = 1, SUM, {})",
            $items[2]['extension'][0]['valueExpression']['expression']);
    }

    public function testAStopHereAnswerNeedsNothingMoreButAQuestionItAsksStillCounts(): void
    {
        $expression = static fn(array $q): string => current(array_filter(
            self::flatten($q['item']),
            static fn(array $i): bool => $i['type'] === 'decimal' && isset($i['extension'])
        ))['extension'][0]['valueExpression']['expression'];
        $english = $this->doc('audit-c');
        $spanish = DocumentLibrary::shipped('es')->get('audit-c');
        self::assertNotNull($spanish);
        foreach (['en' => $this->export('audit-c'), 'es' => (new QuestionnaireExport(new Context([]), 'es'))->questionnaire($spanish, $english)] as $language => $q) {
            $items = self::flatten($q['item']);
            // AUDIT-C: questions 2 and 3 only after an answer other than Never (the paper's "If you answered Never, stop here").
            foreach (['/68519-8', '/68520-6'] as $linkId) {
                self::assertSame('any', $items[$linkId]['enableBehavior'] ?? null, "$language $linkId");
                self::assertSame(['/68518-0'], array_unique(array_column($items[$linkId]['enableWhen'], 'question')), "$language $linkId");
                self::assertCount(4, $items[$linkId]['enableWhen'], "$language $linkId: every answer but Never");
            }
            $total = $expression($q);
            self::assertStringStartsWith("iif(%resource.item.where(linkId = '/68518-0').answer.count()", $total, 'question 1 is always required');
            self::assertSame(2, substr_count($total, ".answer.exists()), true)"), 'questions 2 and 3, each when asked');
        }
        // PC-PTSD-5: the five questions only after Yes to the trauma question ("If no, stop here: the screen total is 0").
        $q = $this->export('pc-ptsd-5');
        $groups = array_filter(self::flatten($q['item']), static fn(array $i): bool => $i['type'] === 'group' && isset($i['enableWhen']));
        self::assertCount(1, $groups, "the scale's group carries the condition");
        $total = $expression($q);
        self::assertStringNotContainsString('answer.count()', $total, 'the trauma question is unscored, so nothing is always required');
        self::assertSame(5, substr_count($total, ".answer.exists()), true)"), 'each of the five, when asked');

        // The guard's test for a coded condition, and for a count above a value.
        $guard = new \ReflectionMethod(QuestionnaireExport::class, 'guardTotals');
        $ord = ['extension' => [['url' => QuestionnaireExport::ORDINAL, 'valueDecimal' => 1]]];
        $items = $guard->invoke(null, [
            ['linkId' => 'gate', 'type' => 'choice', 'answerOption' => [['valueCoding' => ['system' => 's', 'code' => 'y']]]],
            ['linkId' => 'days', 'type' => 'integer'],
            ['linkId' => 'b', 'type' => 'choice', 'enableWhen' => [['question' => 'gate', 'operator' => '=', 'answerCoding' => ['system' => 's', 'code' => 'y']]], 'answerOption' => [['valueCoding' => $ord + ['code' => '1']]]],
            ['linkId' => 'c', 'type' => 'choice', 'enableWhen' => [['question' => 'days', 'operator' => '>', 'answerInteger' => 0]], 'answerOption' => [['valueCoding' => $ord + ['code' => '1']]]],
            ['linkId' => 't', 'type' => 'decimal', 'extension' => [['url' => QuestionnaireExport::CALCULATED, 'valueExpression' => ['expression' => 'SUM']]], '_scope' => null],
        ]);
        $t = $items[4]['extension'][0]['valueExpression']['expression'];
        self::assertStringContainsString("%resource.item.where(linkId = 'gate').answer.valueCoding.where(code = 'y' and system = 's').exists()", $t);
        self::assertStringContainsString("%resource.item.where(linkId = 'days').answer.where(valueInteger > 0).exists()", $t);
        self::assertStringContainsString("%resource.item.where(linkId = 'b').answer.exists()", $t);
        self::assertStringNotContainsString('not()', $t, "OpenEMR 8.3 and 8.4's runtime has no not()");
        self::assertStringEndsWith(', SUM, {})', $t);
    }

    public function testEachInstrumentsTotalCarriesItsReferencesCodeAndAPartsScoreNone(): void
    {
        $coded = [];
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            $q = (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], [])))->questionnaire($doc);
            foreach (self::flatten($q['item']) as $item) {
                if ($item['type'] === 'decimal' && isset($item['code'])) {
                    $coded[$doc->id][] = $item['code'][0]['code'];
                }
            }
        }
        ksort($coded);
        self::assertSame([
            'audit-c' => ['75626-2'], 'food-security' => ['95379-4'], 'gad-7' => ['70274-6'], 'gds-15' => ['48545-8'], 'hark' => ['76504-0'],
            'pc-ptsd-5' => ['102017-1'], 'pcl-5' => ['101698-9'], 'phq-15' => ['70273-8'], 'phq-2' => ['55758-7'], 'phq-4' => ['70272-0'],
            'phq-9' => ['44261-6'], 'stay-independent' => ['97885-8'],
        ], $coded, "one coded total each; TAPS-2's per-substance scores and the screens read by rule have none");
    }

    public function testATranslationCarriesItsInstrumentsCodesInItsOwnWords(): void
    {
        $english = $this->doc('phq-9');
        $spanish = DocumentLibrary::shipped('es')->get('phq-9');
        self::assertNotNull($spanish);
        self::assertSame('es', $spanish->language);
        $export = new QuestionnaireExport(new Context([]), 'es');
        $q = $export->questionnaire($spanish, $english);
        self::assertSame(['phq-9-es', 'es'], [$q['id'], $q['language']]);
        $first = self::flatten($q['item'])['/44250-9'] ?? null;
        self::assertNotNull($first, 'the LOINC codes, through its English document');
        self::assertSame('44250-9', $first['code'][0]['code'], "the question keeps LOINC's code and name");
        self::assertSame(['system' => QuestionnaireExport::ANSWER_SYSTEM, 'code' => '1', 'display' => 'Ningún día'],
            array_diff_key($first['answerOption'][0]['valueCoding'], ['extension' => 1]),
            "shown in Spanish, Pfizer's words, so coded in the module's own system rather than given as LOINC's answer");
        self::assertSame([0, 1, 2, 3], array_map(static fn(array $o): int => $o['valueCoding']['extension'][0]['valueDecimal'], $first['answerOption']), 'with the same points');
        self::assertArrayNotHasKey('/44250-9', self::flatten($export->questionnaire($spanish)['item']), 'without its English document, no codes');
    }

    public function testEveryLoincCodeGoesWithLoincsNameForIt(): void
    {
        $checked = 0;
        foreach (array_keys(Words::LANGUAGES) as $language) {
            $export = new QuestionnaireExport(new Context(['name' => 'Seed Clinic']), $language);
            foreach (DocumentLibrary::shipped($language)->all() as $doc) {
                if ($doc->language !== $language) {
                    continue;
                }
                $english = $language === 'en' ? null : DocumentLibrary::shipped()->get($doc->id);
                $walk = static function (mixed $node) use (&$walk, &$checked, $language, $doc): void {
                    if (!is_array($node)) {
                        return;
                    }
                    if (($node['system'] ?? null) === Loinc::SYSTEM && isset($node['code'])) {
                        $code = (string)$node['code'];
                        $want = Loinc::name($code, $language);
                        self::assertNotNull($want, "$language/$doc->id: LOINC code $code has a LOINC name to go with it");
                        self::assertSame($want, $node['display'] ?? null, "$language/$doc->id: LOINC code $code goes with LOINC's name");
                        $checked++;
                    }
                    foreach ($node as $child) {
                        $walk($child);
                    }
                };
                $walk($export->questionnaire($doc, $english));
            }
        }
        self::assertGreaterThan(300, $checked, 'the walk found the codes: a control against a walk that sees none');
    }

    public function testEveryShippedDocumentMakesAWellFormedQuestionnaireWithNoPatient(): void
    {
        $checked = 0;
        foreach (array_keys(Words::LANGUAGES) as $language) {
            $export = new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], self::PATIENT), $language);
            foreach (DocumentLibrary::shipped($language)->all() as $doc) {
                if ($doc->language !== $language) {
                    continue;
                }
                $checked++;
                $q = $export->questionnaire($doc, $language === 'en' ? null : DocumentLibrary::shipped()->get($doc->id));
                $where = "$language/$doc->id";
                self::assertMatchesRegularExpression('/^[A-Za-z0-9\-.]{1,64}$/', $q['id'], "$where: a FHIR id");
                self::assertMatchesRegularExpression('/^[A-Z][A-Za-z0-9_]{0,254}$/', $q['name'], "$where: a computer-usable name");
                $json = json_encode($q, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('Jane Q Sample', $json, "$where: never a patient's details");
                self::assertStringNotContainsString('WL9001', $json, "$where: never a patient's details");
                self::assertDoesNotMatchRegularExpression('/\{[a-z]+\.[a-z]+\}/', $json, "$where: placeholders filled or blank");
                $ids = [];
                $walk = static function (array $items) use (&$walk, &$ids, $where): void {
                    foreach ($items as $item) {
                        $ids[] = $item['linkId'];
                        self::assertNotSame('', trim((string)($item['text'] ?? '')), "$where {$item['linkId']}: has text");
                        if ($item['type'] === 'group') {
                            self::assertNotEmpty($item['item'] ?? [], "$where {$item['linkId']}: a group holds items");
                        }
                        if ($item['type'] === 'choice') {
                            self::assertNotEmpty($item['answerOption'] ?? [], "$where {$item['linkId']}: a choice has answers");
                        }
                        $walk($item['item'] ?? []);
                    }
                };
                $walk($q['item']);
                self::assertSame(count($ids), count(array_unique($ids)), "$where: each linkId once");
            }
        }
        self::assertGreaterThanOrEqual(60, $checked, 'the documents and their translations are found');
    }

    public function testSignaturesAndStatementsBecomeFields(): void
    {
        $items = self::flatten($this->export('consent-to-treat')['item']);
        $signers = array_filter($items, static fn(array $i): bool => $i['type'] === 'group' && isset($items[$i['linkId'] . '.name']));
        self::assertNotEmpty($signers, 'each signer a group with a name and a date');
        foreach ($signers as $signer) {
            self::assertSame('date', $items[$signer['linkId'] . '.date']['type']);
        }
    }

    public function testSeveralDocumentsMakeABundle(): void
    {
        $bundle = (new QuestionnaireExport(new Context([])))->export([$this->doc('phq-9'), $this->doc('gad-7')]);
        self::assertSame(['Bundle', 'collection'], [$bundle['resourceType'], $bundle['type']]);
        self::assertSame(['phq-9', 'gad-7'], array_map(static fn(array $e): string => $e['resource']['id'], $bundle['entry']));
        self::assertSame('Questionnaire', (new QuestionnaireExport(new Context([])))->export([$this->doc('phq-9')])['resourceType']);
    }

    /**
     * The Title X visit record asks each FPAR 2.0 family planning item with OPA's own codes (valid
     * values file of 4/3/2026, docs/products/title-x/fpar2-elements-2026-10.md in the openhealthcare
     * workspace): the question's LOINC code as its linkId, every current answer code and no other,
     * one answer each. The Title X Report reads a saved response by these codes.
     */
    public function testTheTitleXVisitRecordCarriesFparCodes(): void
    {
        $sct = 'http://snomed.info/sct';
        $loinc = 'http://loinc.org';
        // Where OPA's SNOMED CT value is only in the US Extension, the document carries OPA's LOINC answer for it.
        $methods = [
            $sct => ['60890002', '22523008', '428987008', '449038007', '448962006', '312081001', '268464009', '268458002', '169467008', '413116005',
                '450850003', '450851004', '442450006', '228479001', '268466006', '169530003', '169513000', '268463003', '169450001'],
            $loinc => ['LA33038-3', 'LA27918-4', 'LA27919-2', 'LA27920-0', 'LA27922-6'],
        ];
        $reasons = [$loinc => ['LA14550-0', 'LA27925-9', 'LA27928-3'], $sct => ['15296000', '74964007']];
        $yesNo = ['373066001', '373067005'];
        $expected = [
            '98076-3' => ['http://loinc.org' => ['LA33-6', 'LA32389-1', 'LA32390-9', 'LA32393-3', 'LA32391-7', 'LA32392-5']],
            '86645-9' => [$loinc => ['LA26438-4', 'LA26440-0', 'LA26439-2', 'LA14072-5']],
            '86649-1' => $methods,
            '86650-9' => $reasons,
            '82810-3' => [$sct => ['77386006', '60001007'], 'http://terminology.hl7.org/CodeSystem/v3-NullFlavor' => ['UNK']],
            '86654-1' => [$sct => $yesNo],
            '86655-8' => [$sct => $yesNo],
            '86651-7' => $methods,
            '86653-3' => $reasons,
            '86652-5' => [$sct => ['308335008', '16076005', '281100006']],
        ];
        $items = self::flatten($this->export('title-x-visit-record')['item']);
        foreach ($expected as $code => $bySystem) {
            $item = $items["/$code"] ?? null;
            self::assertNotNull($item, "FPAR question $code is asked");
            self::assertSame(['system' => 'http://loinc.org', 'code' => $code], array_intersect_key($item['code'][0], ['system' => 1, 'code' => 1]));
            self::assertNotSame('', $item['code'][0]['display'], "$code goes with LOINC's name");
            self::assertFalse($item['repeats'], "$code takes one answer");
            $got = [];
            foreach ($item['answerOption'] as $option) {
                self::assertNotSame('', $option['valueCoding']['display'] ?? '', "$code's answers each have a display");
                $got[$option['valueCoding']['system']][] = $option['valueCoding']['code'];
            }
            self::assertSame($bySystem, $got, "$code's answers are OPA's current codes, in the document's order");
        }
        // The reason for no method appears on screen only when the method is "none", by its code.
        self::assertSame([['question' => '/86649-1', 'operator' => '=', 'answerCoding' => ['system' => $sct, 'code' => '169450001', 'display' => 'No contraceptive precautions']]], $items['/86650-9']['enableWhen']);
        self::assertSame('169450001', $items['/86653-3']['enableWhen'][0]['answerCoding']['code']);
    }

    public function testCodesAreNotWording(): void
    {
        $plain = ['type' => 'checklist', 'label' => 'Pregnancy status', 'items' => ['Pregnant', 'Not pregnant']];
        $coded = $plain + ['code' => ['system' => 'http://loinc.org', 'code' => '82810-3'], 'single' => true,
            'answer_codes' => [['system' => 'http://snomed.info/sct', 'code' => '77386006', 'display' => 'Pregnancy'], ['system' => 'http://snomed.info/sct', 'code' => '60001007', 'display' => 'Not pregnant']]];
        self::assertSame(Translation::wording([$plain]), Translation::wording([$coded]), 'adding codes changes no version');
    }

    public function testNamesAreComputerUsable(): void
    {
        self::assertSame('PatientHealthQuestionnairePHQ9', QuestionnaireExport::name('Patient Health Questionnaire (PHQ-9)'));
        self::assertSame('Q2024Update', QuestionnaireExport::name('2024 update'));
    }

    /** @return array<string, mixed> */
    private function export(string $id): array
    {
        return (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], self::PATIENT)))->questionnaire($this->doc($id));
    }

    private const RICH_ONLY = [
        'http://hl7.org/fhir/StructureDefinition/questionnaire-itemControl',
        'http://hl7.org/fhir/StructureDefinition/questionnaire-signatureRequired',
        'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-enableWhenExpression',
        'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-launchContext',
    ];

    /** @return list<string> the extension urls on the Questionnaire and every item */
    private static function urls(array $q): array
    {
        $urls = array_column($q['extension'] ?? [], 'url');
        foreach (self::flat($q['item']) as $item) {
            array_push($urls, ...array_column($item['extension'] ?? [], 'url'));
        }
        return $urls;
    }

    public function testBelowRuntime040EveryDocumentExportsWithoutTheRicherForms(): void
    {
        $plain = new QuestionnaireExport(new Context(['name' => 'C']));
        $checked = 0;
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            self::assertSame([], array_values(array_intersect(self::urls($plain->questionnaire($doc)), self::RICH_ONLY)), $doc->id);
            $checked++;
        }
        self::assertGreaterThan(60, $checked, 'every shipped document');
    }

    public function testOnRuntime040AScaleIsOneGridATableAGtableAndOnlyThePatientMustSign(): void
    {
        $rich = new QuestionnaireExport(new Context(['name' => 'C']), 'en', [], true);
        $control = static fn(array $item): ?string => array_values(array_filter($item['extension'] ?? [],
            static fn(array $e): bool => $e['url'] === self::RICH_ONLY[0]))[0]['valueCodeableConcept']['coding'][0]['code'] ?? null;

        $grids = array_filter(self::flat($rich->questionnaire($this->doc('phq-9'))['item']), static fn(array $i): bool => $control($i) === 'table');
        self::assertCount(1, $grids, "PHQ-9's scale is one answer grid");
        self::assertCount(9, reset($grids)['item'], 'with its nine questions as rows');

        $plan = $rich->questionnaire($this->doc('plan-of-care'));
        $tables = array_filter(self::flat($plan['item']), static fn(array $i): bool => $control($i) === 'gtable');
        self::assertNotEmpty($tables, 'a table is a gtable');
        self::assertTrue(reset($tables)['repeats'], 'still a repeating group');
        $signers = array_filter(self::flat($plan['item']), static fn(array $i): bool => in_array($i['text'] ?? '', ['Patient', 'Clinician'], true));
        $signs = array_combine(array_column($signers, 'text'), array_map(static fn(array $i): bool => in_array(self::RICH_ONLY[1], array_column($i['extension'] ?? [], 'url'), true), $signers));
        ksort($signs);
        self::assertSame(['Clinician' => false, 'Patient' => true], $signs, 'the patient signs on screen; the clinician signs as before');
        $patient = array_values(array_filter($signers, static fn(array $i): bool => $i['text'] === 'Patient'))[0];
        self::assertSame(['name', 'date'], array_map(static fn(array $c): string => substr($c['linkId'], strrpos($c['linkId'], '.') + 1), $patient['item']), 'its name and date stay');
        self::assertSame('1.2.840.10065.1.12.1.1', $patient['extension'][0]['valueCodeableConcept']['coding'][0]['code'], "the author's signature");

        $consent = array_filter(self::flat($rich->questionnaire($this->doc('consent-to-treat'))['item']), static fn(array $i): bool => in_array(self::RICH_ONLY[1], array_column($i['extension'] ?? [], 'url'), true));
        self::assertCount(1, $consent, 'the patient or representative signs; the witness signs as before');
        self::assertSame('1.2.840.10065.1.12.1.7', reset($consent)['extension'][0]['valueCodeableConcept']['coding'][0]['code'], 'a consent signature');
    }

    public function testOnRuntime040TapsOnesAlcoholQuestionsAreAskedByThePatientsSex(): void
    {
        $q = (new QuestionnaireExport(new Context(['name' => 'C']), 'en', [], true))->questionnaire($this->doc('taps-1'));
        $context = array_values(array_filter($q['extension'], static fn(array $e): bool => $e['url'] === self::RICH_ONLY[3]));
        self::assertCount(1, $context, 'the form asks the host for the patient');
        self::assertSame('patient', $context[0]['extension'][0]['valueCoding']['code']);
        $when = [];
        foreach (self::flat($q['item']) as $item) {
            foreach ($item['extension'] ?? [] as $e) {
                if ($e['url'] === self::RICH_ONLY[2]) {
                    $when[$item['prefix'] ?? $item['linkId']] = $e['valueExpression']['expression'];
                }
            }
        }
        self::assertSame([
            '2.' => "iif(%patient.gender.exists(), %patient.gender != 'female', true)",
            '3.' => "iif(%patient.gender.exists(), %patient.gender != 'male', true)",
        ], $when, 'question 2 for men, 3 for women, both when the gender is unknown');
    }

    public function testTheRicherFormsFollowTheRuntimeTheSiteServes(): void
    {
        $project = static function (?string $version): string {
            $dir = sys_get_temp_dir() . '/cf-runtime-' . bin2hex(random_bytes(6));
            mkdir($dir);
            if ($version !== null) {
                $file = $dir . '/' . \OpenHealthcare\Common\QuestionnaireRuntime::PATH;
                mkdir(dirname($file), 0777, true);
                file_put_contents($file, "    static info = {\n        version: '$version',\n    };\n");
            }
            return $dir;
        };
        self::assertTrue(QuestionnaireExport::rich($project('0.4.0')), 'master and 8.5.0 with the backport');
        self::assertFalse(QuestionnaireExport::rich($project('0.2.0')), '8.3 to 8.4.x, and 8.5.0 before the backport');
        self::assertFalse(QuestionnaireExport::rich($project(null)), 'before 8.3: LHC-Forms');
    }

    private function doc(string $id): Document
    {
        $doc = DocumentLibrary::shipped()->get($id);
        self::assertNotNull($doc, $id);
        return $doc;
    }

    /** @return array<string, array<string, mixed>> every item at any depth, by linkId */
    private static function flatten(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['linkId']] = $item;
            $out += self::flatten($item['item'] ?? []);
        }
        return $out;
    }

    /**
     * Skip logic on screen: ASQ's acuity question only after a Yes to any of 1 to 4, the attempt date
     * only after a Yes to 4, and the description only after a Yes to 5; BSTAD's other drugs only after
     * any days of use, and each substance's days only when it is ticked. Each condition is the target
     * question's own answer: a LOINC coding with LOINC's text, a translation's own coding by position,
     * or an uncoded answer's text.
     */
    public function testSkipLogicIsExportedAsEnableWhenOnTheTargetsOwnAnswers(): void
    {
        $library = DocumentLibrary::shipped();
        $asq = (new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($library->get('asq') ?? throw new \LogicException());
        $items = self::flat($asq['item']);
        $byText = static fn(string $start): array => array_values(array_filter($items, static fn(array $i): bool => str_starts_with((string)($i['text'] ?? ''), $start)))[0] ?? [];
        $acuity = $byText('Are you having thoughts of killing yourself right now');
        self::assertSame('any', $acuity['enableBehavior'] ?? null);
        self::assertCount(4, $acuity['enableWhen']);
        $rows = array_column($acuity['enableWhen'], 'question');
        self::assertSame(array_map(static fn(array $i): string => $i['linkId'], array_slice(array_values(array_filter($items, static fn(array $i): bool => ($i['type'] ?? '') === 'choice')), 0, 4)), $rows);
        foreach ($acuity['enableWhen'] as $when) {
            self::assertSame(['question', 'operator', 'answerCoding'], array_keys($when));
            self::assertSame(['system' => 'http://loinc.org', 'code' => 'LA33-6', 'display' => 'Yes'], $when['answerCoding']);
        }
        $attempt = $byText('If yes, when was the most recent attempt');
        self::assertCount(1, $attempt['enableWhen']);
        self::assertSame($rows[3], $attempt['enableWhen'][0]['question']);
        self::assertArrayNotHasKey('enableBehavior', $attempt);
        self::assertSame($acuity['linkId'], $byText('If yes, describe briefly')['enableWhen'][0]['question']);
        self::assertSame([QuestionnaireExport::SDC_PROFILE], $asq['meta']['profile']);

        $es = DocumentLibrary::shipped('es')->get('asq') ?? throw new \LogicException();
        $q = (new QuestionnaireExport(new Context(['name' => 'C']), 'es'))->questionnaire($es, $library->get('asq'));
        $conditioned = array_values(array_filter(self::flat($q['item']), static fn(array $i): bool => isset($i['enableWhen'])));
        self::assertCount(4, $conditioned, 'the Spanish has the same skip logic');
        $when = $conditioned[0]['enableWhen'][0]['answerCoding'] ?? [];
        self::assertSame(['system' => QuestionnaireExport::ANSWER_SYSTEM, 'code' => '1'], array_diff_key($when, ['display' => 1]), 'a translation conditions on its own coding, by position');

        $bstad = self::flat((new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($library->get('bstad') ?? throw new \LogicException())['item']);
        $first = array_values(array_filter($bstad, static fn(array $i): bool => ($i['type'] ?? '') === 'integer'));
        self::assertCount(12, $first, 'the twelve day counts are whole numbers');
        self::assertSame([0, 365], array_column($first[0]['extension'], 'valueInteger'));
        $drugs = array_values(array_filter($bstad, static fn(array $i): bool => str_starts_with((string)($i['text'] ?? ''), 'In the PAST YEAR, which of the following substances')))[0];
        self::assertSame(array_map(static fn(array $i): array => ['question' => $i['linkId'], 'operator' => '>', 'answerInteger' => 0], array_slice($first, 0, 3)), $drugs['enableWhen']);
        $cocaine = array_values(array_filter($bstad, static fn(array $i): bool => str_contains((string)($i['text'] ?? ''), 'cocaine or crack? Number of days')))[0];
        self::assertSame([['question' => $drugs['linkId'], 'operator' => '=', 'answerCoding' => ['system' => QuestionnaireExport::ANSWER_SYSTEM, 'code' => '1', 'display' => 'Cocaine or crack']]],
            $cocaine['enableWhen'], "on the option's own Coding, as the answer carries it");
    }

    public function testACodedTotalIsMarkedForExtractionAndAPartsScoreIsNot(): void
    {
        $library = DocumentLibrary::shipped();
        $phq9 = self::flat((new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($library->get('phq-9') ?? throw new \LogicException())['item']);
        $total = array_values(array_filter($phq9, static fn(array $i): bool => ($i['code'][0]['code'] ?? '') === '44261-6'))[0];
        self::assertContains(['url' => QuestionnaireExport::EXTRACT, 'valueBoolean' => true], $total['extension']);
        $taps2 = self::flat((new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($library->get('taps-2') ?? throw new \LogicException())['item']);
        foreach ($taps2 as $item) {
            if (!isset($item['code']) && ($item['readOnly'] ?? false)) {
                self::assertNotContains(QuestionnaireExport::EXTRACT, array_column($item['extension'] ?? [], 'url'), "a part's score has no code to extract under: {$item['text']}");
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function flat(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item;
            array_push($out, ...self::flat($item['item'] ?? []));
        }
        return $out;
    }
}
