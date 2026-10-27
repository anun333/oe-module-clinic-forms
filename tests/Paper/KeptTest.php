<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Fill;
use OpenHealthcare\ClinicForms\Paper\Kept;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Repository\KeptAnswers;
use PHPUnit\Framework\TestCase;

final class KeptTest extends TestCase
{
    private const TODAY = '2026-10-27';

    private string $site = '';

    protected function tearDown(): void
    {
        if ($this->site !== '') {
            $dir = $this->site . '/' . DocumentLibrary::LOCAL_FOLDER;
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($this->site);
            $this->site = '';
        }
    }

    public function testEachFactKeepsForItsOwnPeriod(): void
    {
        $today = new \DateTimeImmutable(self::TODAY);
        self::assertTrue(Kept::holds('packsperday', new \DateTimeImmutable('2025-10-28'), $today));
        self::assertFalse(Kept::holds('packsperday', new \DateTimeImmutable('2025-10-27'), $today), 'a year old is too old');
        self::assertTrue(Kept::holds('countries', new \DateTimeImmutable('2019-01-01'), $today), 'until the patient changes it');
        self::assertTrue(Kept::holds('school', new \DateTimeImmutable('2026-08-01'), $today));
        self::assertFalse(Kept::holds('school', new \DateTimeImmutable('2026-07-31'), $today), 'last school year');
        self::assertTrue(Kept::holds('school', new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-06-01')), 'the school year runs to the next 1 August');
        self::assertFalse(Kept::holds('countries', new \DateTimeImmutable('2026-10-28'), $today), 'never an answer from after today');
        self::assertFalse(Kept::holds('nonesuch', new \DateTimeImmutable('2026-10-01'), $today));
    }

    public function testARepresentativeIsCarriedOnlyWhileTheAuthorityIsInForce(): void
    {
        $today = new \DateTimeImmutable(self::TODAY);
        $from = static fn(string $v): array => ['value' => $v, 'from' => 'Personal Representative', 'date' => '3/2/2026'];
        $kept = ['repname' => $from('Riley Sample'), 'reprelationship' => $from('Daughter'), 'repauthority' => $from('Health care power of attorney or health care agent'), 'packsperday' => $from('1')];
        $complete = Kept::complete($kept + ['repends' => $from('12/31/2027')], $today);
        self::assertSame('Daughter; Health care power of attorney or health care agent', $complete['reprole']['value']);
        self::assertSame('Riley Sample', $complete['repname']['value']);

        $ended = Kept::complete($kept + ['repends' => $from('2026-10-26')], $today);
        self::assertArrayNotHasKey('repname', $ended, 'the authority ended yesterday');
        self::assertArrayNotHasKey('reprole', $ended);
        self::assertSame('1', $ended['packsperday']['value'], 'other facts stay');
        self::assertArrayNotHasKey('repname', Kept::complete($kept + ['repstarts' => $from('1/1/2027')], $today), 'not started yet');
        self::assertArrayHasKey('repname', Kept::complete($kept + ['repends' => $from('when she is well again')], $today), 'a date that can\'t be read is left to the patient to check');
    }

    public function testTheContextKnowsEveryKeptFact(): void
    {
        $keys = Context::KEYS['kept'];
        sort($keys);
        $kept = Kept::keys();
        sort($kept);
        self::assertSame($kept, $keys);
    }

    public function testEveryKeptFillHasADocumentThatAsksIt(): void
    {
        $defined = [];
        $fills = [];
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            foreach (self::questions($doc) as $q) {
                if (isset($q['defines'])) {
                    $defined[$q['defines']] = $doc->id;
                    self::assertNotContains($doc->id, Fill::CONFIDENTIAL, 'nothing said for confidential care is carried out of it');
                    self::assertFalse($doc->validated, $doc->id);
                }
                $fill = Fill::of($q);
                if (Kept::isKept($fill)) {
                    $fills[$doc->id][] = substr($fill, 5);
                }
            }
        }
        self::assertSame([], array_diff(array_keys(Kept::KEEPS), array_keys($defined)), 'every fact is asked somewhere');
        foreach ($fills as $id => $keys) {
            foreach ($keys as $key) {
                $parts = Kept::COMPOSITE[$key] ?? [$key];
                self::assertSame([], array_diff($parts, array_keys($defined)), "$id fills $key");
            }
        }
        $representative = array_keys(array_filter($fills, static fn(array $k): bool => in_array('repname', $k, true)));
        self::assertGreaterThanOrEqual(15, count($representative), 'the consents a representative may sign');
        foreach (Fill::CONFIDENTIAL as $id) {
            self::assertArrayNotHasKey($id, $fills, "$id is signed for confidential care: nothing kept is filled into it");
        }
        self::assertContains('consent-to-treat', $representative);
    }

    public function testWhereAKeptFactIsRefused(): void
    {
        $base = ['id' => 'my-form', 'title' => 'My form', 'category' => 'Front desk', 'about' => 'A test', 'audience' => 'patient'];
        $cases = [
            'an unknown fact' => [$base + ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Shoe size', 'defines' => 'shoesize']]]]], 'defines: one of'],
            'a confidential document' => [['id' => 'part-2-consent'] + $base + ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Name', 'defines' => 'repname']]]]], 'never carried out of it'],
            'a validated instrument' => [$base + ['validated' => true, 'blocks' => [['type' => 'fields', 'fields' => [['label' => 'Packs', 'defines' => 'packsperday']]]]], 'asked fresh each time'],
            'lines filled from the chart' => [$base + ['blocks' => [['type' => 'lines', 'label' => 'Email', 'count' => 1, 'fill' => 'chart.email']]], 'lines take only an answer kept on file'],
            'a checklist filled from an earlier form' => [$base + ['blocks' => [['type' => 'checklist', 'single' => true, 'items' => ['Yes', 'No'], 'fill' => 'kept.school', 'fill_options' => ['a', 'b']]]], 'a checklist takes only a chart fact'],
        ];
        foreach ($cases as $what => [$data, $message]) {
            $library = $this->site([$data['id'] . '.json' => $data]);
            self::assertStringContainsString($message, implode("\n", $library->problems()), $what);
        }
        // A confidential document's kept fill is left out rather than refused: it may come with a shared block.
        $minor = ['id' => 'minor-consent-ca'] + $base + ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Representative', 'fill' => 'kept.repname']]]]];
        $library = $this->site(['minor-consent-ca.json' => $minor]);
        self::assertSame([], $library->problems());
        self::assertNull(Fill::of($library->get('minor-consent-ca')->blocks[0]['fields'][0]));
    }

    public function testAKeptAnswerPrintsWithWhereItCameFromToCheck(): void
    {
        $context = new Context(['name' => 'Seed Clinic'], ['name' => 'Sam Sample'], kept: [
            'repname' => ['value' => 'Riley Sample', 'from' => 'Personal Representative', 'date' => '3/2/2026'],
            'reprole' => ['value' => 'Daughter; Guardian or conservator named by a court', 'from' => 'Personal Representative', 'date' => '3/2/2026'],
            'familycancers' => ['value' => 'Mother, breast, 52', 'from' => 'Preventive Care Questions', 'date' => '1/5/2026'],
        ]);
        $html = (new Renderer($context))->document(self::us('consent-to-treat'));
        self::assertStringContainsString('Riley Sample', $html);
        self::assertStringContainsString('(from Personal Representative on 3/2/2026: still right?)', $html);
        self::assertStringContainsString(Renderer::CARRIED, $html);
        $lines = (new Renderer($context))->document(self::us('preventive-care-questions'));
        self::assertStringContainsString("cf-line cf-carried'>Mother, breast, 52", $lines);

        $spanish = (new Renderer($context, 'es'))->document(DocumentLibrary::shipped('es')->get('preventive-care-questions'));
        self::assertStringContainsString('(de Preventive Care Questions del 1/5/2026: ¿sigue siendo correcto?)', $spanish);

        $blank = (new Renderer(new Context(['name' => 'Seed Clinic'])))->document(self::us('consent-to-treat'));
        self::assertStringNotContainsString('still right?', $blank);
        self::assertStringNotContainsString(Renderer::CARRIED, $blank);
    }

    public function testAQuestionThatAsksAKeptFactCarriesItsDefinitionOnScreen(): void
    {
        $q = (new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire(self::us('personal-representative'));
        $definitions = [];
        self::walk($q['item'], static function (array $item) use (&$definitions): void {
            if (str_starts_with($item['definition'] ?? '', Kept::DEFINITION)) {
                $definitions[$item['text']] = substr($item['definition'], strlen(Kept::DEFINITION));
            }
        });
        self::assertSame('repname', $definitions['Name']);
        self::assertSame('repauthority', $definitions['Their legal authority (tick one)']);
        self::assertSame('repother', $definitions['Other authority, and any limits on it']);
        self::assertCount(9, $definitions);
        // A consent only fills the representative: it asks nothing of its own.
        $consent = json_encode((new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire(self::us('consent-to-treat')));
        self::assertStringNotContainsString(str_replace('/', '\/', Kept::DEFINITION), (string)$consent);
    }

    public function testASavedResponseIsReadByDefinitionOrByItsWording(): void
    {
        $doc = self::us('personal-representative');
        $reader = new KeptAnswers([$doc]);
        $q = (new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($doc);
        $response = self::response($q, ['Name' => 'Riley Sample', 'Relationship to the patient' => 'Daughter', 'Ends (if known)' => '12/31/2030'],
            ['Their legal authority (tick one)' => ['code' => '2', 'display' => 'Guardian or conservator named by a court']]);
        $date = static fn(string $d): string => $d;
        $read = $reader->read('personal-representative', $q, $response, $date);
        self::assertEqualsCanonicalizing(['repname' => 'Riley Sample', 'reprelationship' => 'Daughter', 'repauthority' => 'Guardian or conservator named by a court', 'repends' => '12/31/2030'], $read);

        // A copy saved before 0.44.0 has no definitions: the wording under its heading finds the same answers,
        // and the signature's "Name" isn't taken for the representative's.
        $old = $q;
        self::strip($old['item']);
        self::assertStringNotContainsString('definition', (string)json_encode($old));
        self::assertSame($read, $reader->read('personal-representative', $old, $response, $date));
    }

    public function testTheLatestResponseThatCountsIsCarried(): void
    {
        $doc = self::us('personal-representative');
        $prevent = self::us('preventive-care-questions');
        $reader = new KeptAnswers([$doc, $prevent]);
        $export = new QuestionnaireExport(new Context(['name' => 'C']));
        $pr = $export->questionnaire($doc);
        $pc = $export->questionnaire($prevent);
        $today = new \DateTimeImmutable(self::TODAY);
        $date = static fn(string $d): string => $d;
        $at = static fn(string $d): \DateTimeImmutable => new \DateTimeImmutable($d . ' 10:00:00');

        $kept = $reader->pick([
            ['questionnaire' => $pc, 'response' => self::response($pc, ['If you have ever smoked: packs a day, on average' => '1', 'Countries you were born in or lived in' => 'Mexico']), 'answered' => $at('2026-06-01')],
            ['questionnaire' => $pr, 'response' => self::response($pr, ['Name' => 'Riley Sample', 'Relationship to the patient' => 'Daughter']), 'answered' => $at('2026-03-02')],
            ['questionnaire' => $pc, 'response' => self::response($pc, ['If you have ever smoked: packs a day, on average' => '2', 'For how many years' => '20']), 'answered' => $at('2024-01-01')],
            ['questionnaire' => $pr, 'response' => self::response($pr, ['Name' => 'Someone Older']), 'answered' => $at('2025-01-01')],
        ], $today, $date);
        self::assertSame('1', $kept['packsperday']['value'], 'the newest');
        self::assertSame('2026-06-01', $kept['packsperday']['date']);
        self::assertSame('Preventive Care Questions', $kept['packsperday']['from']);
        self::assertArrayNotHasKey('yearssmoked', $kept, 'the only answer is more than a year old');
        self::assertSame('Mexico', $kept['countries']['value']);
        self::assertSame('Riley Sample', $kept['repname']['value']);
        self::assertSame('Daughter', $kept['reprole']['value']);

        // The newest Personal Representative names no one: an older one isn't carried in its place.
        $none = $reader->pick([
            ['questionnaire' => $pr, 'response' => self::response($pr, ['Phone' => '555-0100']), 'answered' => $at('2026-09-01')],
            ['questionnaire' => $pr, 'response' => self::response($pr, ['Name' => 'Riley Sample']), 'answered' => $at('2026-03-02')],
        ], $today, $date);
        self::assertArrayNotHasKey('repname', $none);
        self::assertSame('555-0100', $none['repphone']['value']);

        // A form that isn't a source, or a clinic's copy with another url, is never read.
        $other = $export->questionnaire(self::us('consent-to-treat'));
        self::assertSame([], $reader->pick([['questionnaire' => $other, 'response' => self::response($other, ['If signed by a representative: name' => 'X']), 'answered' => $at('2026-10-01')]], $today, $date));
    }

    /** @return list<array<string, mixed>> */
    private static function questions(Document $doc): array
    {
        $out = [];
        foreach ($doc->blocks as $block) {
            $out[] = $block;
            foreach ($block['type'] === 'fields' ? $block['fields'] : [] as $field) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * A response to a form: string answers by item text, coded answers by item text.
     *
     * @param array<string, string> $answers
     * @param array<string, array{code: string, display: string}> $coded
     */
    private static function response(array $q, array $answers, array $coded = []): array
    {
        $items = [];
        self::walk($q['item'], static function (array $item) use (&$items, $answers, $coded): void {
            if (isset($answers[$item['text'] ?? '']) && !isset($items[$item['linkId']])) {
                $items[$item['linkId']] = ['linkId' => $item['linkId'], 'answer' => [['valueString' => $answers[$item['text']]]]];
            } elseif (isset($coded[$item['text'] ?? ''])) {
                $items[$item['linkId']] = ['linkId' => $item['linkId'], 'answer' => [['valueCoding' => $coded[$item['text']]]]];
            }
        });
        return ['resourceType' => 'QuestionnaireResponse', 'status' => 'completed', 'item' => array_values($items)];
    }

    private static function walk(array $items, \Closure $visit): void
    {
        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'group') {
                self::walk($item['item'] ?? [], $visit);
            } else {
                $visit($item);
            }
        }
    }

    private static function strip(array &$items): void
    {
        foreach ($items as &$item) {
            unset($item['definition']);
            if (isset($item['item'])) {
                self::strip($item['item']);
            }
        }
    }

    private static function us(string $id): Document
    {
        return DocumentLibrary::shipped()->get($id) ?? throw new \LogicException("no $id");
    }

    private function site(array $files): DocumentLibrary
    {
        if ($this->site === '') {
            $this->site = sys_get_temp_dir() . '/cf-kept-' . bin2hex(random_bytes(4));
            mkdir($this->site . '/' . DocumentLibrary::LOCAL_FOLDER, 0777, true);
        }
        $dir = $this->site . '/' . DocumentLibrary::LOCAL_FOLDER;
        array_map('unlink', glob($dir . '/*') ?: []);
        foreach ($files as $name => $value) {
            file_put_contents("$dir/$name", json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return DocumentLibrary::forSite($this->site);
    }
}
