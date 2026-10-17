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
        self::assertSame([['system' => 'http://loinc.org', 'code' => '44249-1']], $q['code']);
        $items = self::flatten($q['item']);
        $first = $items['/44250-9'];
        self::assertSame('1.', $first['prefix']);
        self::assertSame('Little interest or pleasure in doing things', $first['text']);
        self::assertSame(['LA6568-5', 'LA6569-3', 'LA6570-1', 'LA6571-9'], array_map(static fn(array $o): string => $o['valueCoding']['code'], $first['answerOption']));
        self::assertSame([0, 1, 2, 3], array_map(static fn(array $o): int => $o['valueCoding']['extension'][0]['valueDecimal'], $first['answerOption']),
            'the points on the Coding, where a response answer carries them');
        self::assertCount(10, array_filter(array_keys($items), static fn(string $id): bool => str_starts_with($id, '/')), 'nine questions and the difficulty question');
        $total = array_values(array_filter($items, static fn(array $i): bool => $i['type'] === 'decimal'));
        self::assertCount(1, $total);
        self::assertTrue($total[0]['readOnly']);
        self::assertSame(QuestionnaireExport::CALCULATED, $total[0]['extension'][0]['url']);
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
        self::assertSame('Not at all', array_values(array_filter($items, static fn(array $i): bool => $i['type'] === 'choice'))[0]['answerOption'][0]['valueString']);
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
        self::assertSame('LA6568-5', $first['answerOption'][0]['valueCoding']['code']);
        self::assertSame('Ningún día', $first['answerOption'][0]['valueCoding']['display'], 'shown in Spanish');
        self::assertArrayNotHasKey('/44250-9', self::flatten($export->questionnaire($spanish)['item']), 'without its English document, no codes');
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
}
