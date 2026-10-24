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
        self::assertSame('Not at all', array_values(array_filter($items, static fn(array $i): bool => $i['type'] === 'choice'))[0]['answerOption'][0]['valueString']);
    }

    public function testEachDepthsSumIsGuardedSoAnEmptyDepthCannotEmptyTheTotal(): void
    {
        $points = ".answer.valueCoding.extension.where(url = '" . QuestionnaireExport::ORDINAL . "').valueDecimal";
        $guard = static fn(string $d, string $which = ''): string => "iif($d$which$points.exists(), $d$which$points.sum(), 0)";
        $depths = ['%resource.item', '%resource.item.item', '%resource.item.item.item'];
        $phq9 = array_values(array_filter(self::flatten($this->export('phq-9')['item']), static fn(array $i): bool => $i['type'] === 'decimal'))[0];
        self::assertSame(implode(' + ', array_map($guard, $depths)), $phq9['extension'][0]['valueExpression']['expression'],
            'an empty sum is empty in fhirpath.js 5, so each depth falls back to 0; no repeat(), which OpenEMR 8.3 and 8.4 lack');
        $parts = array_values(array_filter(self::flatten($this->export('taps-2')['item']), static fn(array $i): bool => $i['type'] === 'decimal' && !isset($i['code'])));
        self::assertNotSame([], $parts);
        foreach ($parts as $part) {
            $expression = $part['extension'][0]['valueExpression']['expression'];
            self::assertSame(3, substr_count($expression, 'iif('), "a part's score is guarded at each depth too");
            self::assertStringNotContainsString('repeat(', $expression);
        }
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
        $methods = ['60890002', '22523008', '428987008', '449038007', '448962006', '312081001', '268464009', '268458002', '169467008', '413116005',
            '450850003', '450851004', '442450006', '228479001', '831141000124107', '268466006', '169530003', '455501000124105', '454441000124107',
            '169513000', '268463003', '455491000124102', '454451000124109', '169450001'];
        $reasons = ['454731000124108', '454361000124100', '15296000', '454411000124108', '74964007'];
        $yesNo = ['373066001', '373067005'];
        $expected = [
            '98076-3' => ['http://loinc.org' => ['LA33-6', 'LA32389-1', 'LA32390-9', 'LA32393-3', 'LA32391-7', 'LA32392-5']],
            '86645-9' => [$sct => ['454411000124108', '454401000124105', '454391000124108', '454381000124105']],
            '86649-1' => [$sct => $methods],
            '86650-9' => [$sct => $reasons],
            '82810-3' => [$sct => ['77386006', '60001007'], 'http://terminology.hl7.org/CodeSystem/v3-NullFlavor' => ['UNK']],
            '86654-1' => [$sct => $yesNo],
            '86655-8' => [$sct => $yesNo],
            '86651-7' => [$sct => $methods],
            '86653-3' => [$sct => $reasons],
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
        self::assertSame([['question' => $drugs['linkId'], 'operator' => '=', 'answerString' => 'Cocaine or crack']], $cocaine['enableWhen']);
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
