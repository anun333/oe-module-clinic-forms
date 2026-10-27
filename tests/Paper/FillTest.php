<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Office\OfficeExport;
use OpenHealthcare\ClinicForms\Office\WordWriter;
use OpenHealthcare\ClinicForms\Office\Zip;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Fill;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Paper\Settings;
use OpenHealthcare\ClinicForms\Paper\Translation;
use PHPUnit\Framework\TestCase;

final class FillTest extends TestCase
{
    private const CHART = ['email' => 'sam.sample@example.org', 'mobile' => '555-0142', 'householdsize' => '4', 'monthlyincome' => '2100'];

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

    public function testACarriedChartAnswerPrintsToCheckAndCorrect(): void
    {
        $doc = self::us('communication-consent');
        $filled = (new Renderer(new Context(['name' => 'Seed Clinic'], ['name' => 'Sam Sample'], chart: self::CHART)))->document($doc);
        self::assertStringContainsString("cf-carried'>", $filled);
        self::assertStringContainsString('sam.sample@example.org', $filled);
        self::assertStringContainsString('555-0142', $filled);
        self::assertStringContainsString(Renderer::CARRIED, $filled, 'the page asks for corrections');

        $blank = (new Renderer(new Context(['name' => 'Seed Clinic'])))->document($doc);
        self::assertStringNotContainsString('cf-carried', $blank);
        self::assertStringNotContainsString(Renderer::CARRIED, $blank, 'nothing carried, nothing to check');
    }

    public function testTheClinicsOwnContactsFillWithoutAskingForCorrections(): void
    {
        $site = ['coordinator' => 'Pat Sample, Compliance', 'coordinatorphone' => '555-0101', 'coordinatoremail' => 'access@example.org'];
        $html = (new Renderer(new Context(['name' => 'Seed Clinic'], site: $site)))->document(self::us('notice-of-nondiscrimination'));
        foreach ($site as $value) {
            self::assertStringContainsString($value, $html);
        }
        self::assertStringNotContainsString('cf-carried', $html);
        self::assertStringNotContainsString(Renderer::CARRIED, $html);
        self::assertStringContainsString('Pat Sample', (new Renderer((new Context(['name' => 'C'], ['name' => 'Sam'], site: $site))->withoutPatient()))->document(self::us('grievance-form')), 'a document filed without a name keeps the clinic\'s contacts');
    }

    public function testAChecklistTicksTheStoredAnswerOnly(): void
    {
        $doc = self::us('housing-farm-work-service');
        $html = (new Renderer(new Context(['name' => 'C'], ['name' => 'Sam'], chartCodes: ['housing' => 'doubling_up', 'veteran' => 'not_veteran', 'agricultural' => 'something_else'])))->document($doc);
        self::assertSame(2, substr_count($html, 'cf-box-ticked'), 'housing and veteran; an option id no item has ticks nothing');
        self::assertMatchesRegularExpression('/cf-box-ticked[^<]*<\/span>With family or friends/', $html);
        self::assertMatchesRegularExpression('/cf-box-ticked[^<]*<\/span>No, I have never served/', $html);
        self::assertStringContainsString(Renderer::CARRIED, $html);
        self::assertStringNotContainsString('cf-box-ticked', (new Renderer(new Context(['name' => 'C'])))->document($doc));

        $word = Zip::read((new OfficeExport(new Context(['name' => 'C'], ['name' => 'Sam'], chartCodes: ['housing' => 'street'])))->write([$doc], new WordWriter(), 'letter'))['word/document.xml'];
        self::assertStringContainsString("\u{2611} On the street", $word);
        self::assertSame(1, substr_count($word, "\u{2611}"));
    }

    public function testWordAndPdfCopiesCarryTheSameAnswers(): void
    {
        $word = Zip::read((new OfficeExport(new Context(['name' => 'C'], ['name' => 'Sam'], chart: self::CHART)))->write([self::us('sliding-fee-application')], new WordWriter(), 'letter'))['word/document.xml'];
        self::assertStringContainsString('2100', $word);
        self::assertStringContainsString('Some answers are filled in from what we have on file.', $word);
    }

    public function testAFillIsNotWording(): void
    {
        $doc = self::us('sliding-fee-application');
        $without = new Document($doc->id, $doc->title, $doc->category, $doc->about, array_map(static function (array $b): array {
            unset($b['fill'], $b['fill_options']);
            if (isset($b['fields'])) {
                $b['fields'] = array_map(static function (array $f): array {
                    unset($f['fill']);
                    return $f;
                }, $b['fields']);
            }
            return $b;
        }, $doc->blocks), $doc->uses);
        self::assertSame(Translation::fingerprint($without), Translation::fingerprint($doc), 'no new version, and translations stay current');
        self::assertTrue($doc->usesPatient(), 'a document that carries chart facts is one to fill for a patient');
    }

    public function testOnScreenTheChartFactsAPatientResourceHoldsArePrefilledInTheRicherFormsOnly(): void
    {
        $doc = self::us('communication-consent');
        $rich = (new QuestionnaireExport(new Context(['name' => 'C']), 'en', [], true))->questionnaire($doc);
        $expressions = self::initialExpressions($rich['item']);
        self::assertSame([Fill::FHIRPATH['mobile'], Fill::FHIRPATH['email']], array_values($expressions));
        $declared = array_values(array_filter($rich['extension'] ?? [], static fn(array $e): bool => str_ends_with($e['url'], 'sdc-questionnaire-launchContext')));
        self::assertCount(1, $declared, 'the patient is declared once');

        $plain = (new QuestionnaireExport(new Context(['name' => 'C'])))->questionnaire($doc);
        self::assertSame([], self::initialExpressions($plain['item']), 'runtimes before 0.4.0 don\'t run initialExpression');
        self::assertSame(array_map(static fn(array $i): string => $i['linkId'], $plain['item']), array_map(static fn(array $i): string => $i['linkId'], $rich['item']), 'the same questions');

        // Household size isn't in a FHIR Patient: asked, not prefilled.
        self::assertSame([], self::initialExpressions((new QuestionnaireExport(new Context(['name' => 'C']), 'en', [], true))->questionnaire(self::us('sliding-fee-application'))['item']));
        // A clinic's own contact is the answer already given.
        $notice = (new QuestionnaireExport(new Context(['name' => 'C'], site: ['coordinator' => 'Pat Sample'])))->questionnaire(self::us('notice-of-nondiscrimination'));
        self::assertStringContainsString('"valueString":"Pat Sample"', (string)json_encode($notice));
    }

    public function testWhatAFillIsRefused(): void
    {
        $base = ['id' => 'my-form', 'title' => 'My form', 'category' => 'Front desk', 'about' => 'A test', 'audience' => 'patient'];
        $cases = [
            'an unknown fact' => [[['type' => 'fields', 'fields' => [['label' => 'Email', 'fill' => 'chart.ssn']]]], 'fill: a chart fact or the clinic'],
            'the patient group' => [[['type' => 'fields', 'fields' => [['label' => 'Name', 'fill' => 'patient.name']]]], 'fill: a chart fact or the clinic'],
            'a value as well' => [[['type' => 'fields', 'fields' => [['label' => 'Email', 'value' => '{patient.name}', 'fill' => 'chart.email']]]], 'a value or a fill, not both'],
            'a checklist of several answers' => [[['type' => 'checklist', 'items' => ['Yes', 'No'], 'fill' => 'chart.veteran', 'fill_options' => ['veteran', 'not_veteran']]], 'only with "single": true'],
            'a checklist of a fact no list stores' => [[['type' => 'checklist', 'single' => true, 'items' => ['Yes', 'No'], 'fill' => 'chart.email', 'fill_options' => ['a', 'b']]], 'from a chart fact a list stores'],
            'an option missing' => [[['type' => 'checklist', 'single' => true, 'items' => ['Yes', 'No'], 'fill' => 'chart.veteran', 'fill_options' => ['veteran']]], 'one option id for each item'],
        ];
        foreach ($cases as $what => [$blocks, $message]) {
            $library = $this->site(['my-form.json' => $base + ['blocks' => $blocks]]);
            self::assertStringContainsString($message, $library->problems()[0] ?? '', $what);
        }
        $instrument = $base + ['validated' => true, 'blocks' => [['type' => 'fields', 'fields' => [['label' => 'Email', 'fill' => 'chart.email']]]]];
        self::assertStringContainsString("a validated instrument's questions are asked fresh each time", $this->site(['my-form.json' => $instrument])->problems()[0] ?? '');
        $minor = ['id' => 'minor-consent-ca'] + $base + ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Parent', 'fill' => 'chart.guardian']]]]];
        self::assertStringContainsString("would carry a family's details into a document signed for confidential care", $this->site(['minor-consent-ca.json' => $minor])->problems()[0] ?? '');
        $ok = $this->site(['my-form.json' => $base + ['blocks' => [['type' => 'fields', 'fields' => [['label' => 'Email', 'fill' => 'chart.email']]]]]]);
        self::assertSame([], $ok->problems());
    }

    public function testNoShippedFillBreaksTheRules(): void
    {
        $library = DocumentLibrary::shipped();
        $count = 0;
        foreach ($library->all() as $doc) {
            foreach ($doc->blocks as $block) {
                foreach (array_merge([$block], $block['type'] === 'fields' ? $block['fields'] : []) as $q) {
                    $fill = Fill::of($q);
                    if ($fill === null) {
                        continue;
                    }
                    $count++;
                    self::assertFalse($doc->validated, $doc->id);
                    self::assertFalse(in_array($doc->id, Fill::CONFIDENTIAL, true) && in_array($fill, Fill::NOT_IN_CONFIDENTIAL, true), $doc->id);
                }
            }
        }
        self::assertGreaterThanOrEqual(40, $count, 'the 38 fields and 3 checklists of 0.42.0');
    }

    public function testTheClinicsContactsComeFromSettings(): void
    {
        $this->site(['settings.json' => [
            'privacy_contact' => ['name' => ' Privacy Officer ', 'phone' => '555-0100', 'email' => 'privacy@example.org'],
            'section_1557_coordinator' => ['name' => 'Pat Sample'],
            'notice_effective_date' => 'January 1, 2026',
        ]]);
        self::assertSame(
            ['privacy' => 'Privacy Officer', 'privacyphone' => '555-0100', 'privacyemail' => 'privacy@example.org', 'coordinator' => 'Pat Sample', 'noticedate' => 'January 1, 2026'],
            Settings::forSite($this->site)->site(),
        );
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"privacy_contact": {"name": "x", "fax": "y"}}');
        $bad = Settings::forSite($this->site);
        self::assertSame([], $bad->site());
        self::assertStringContainsString('"privacy_contact" must be {"name"', $bad->problems[0] ?? '');
    }

    /** @return array<string, string> linkId => expression */
    private static function initialExpressions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            foreach ($item['extension'] ?? [] as $e) {
                if (str_ends_with($e['url'], 'sdc-questionnaire-initialExpression')) {
                    $out[$item['linkId']] = $e['valueExpression']['expression'];
                }
            }
            $out += self::initialExpressions($item['item'] ?? []);
        }
        return $out;
    }

    private static function us(string $id): Document
    {
        return DocumentLibrary::shipped()->get($id) ?? throw new \LogicException("no $id");
    }

    private function site(array $files): DocumentLibrary
    {
        if ($this->site === '') {
            $this->site = sys_get_temp_dir() . '/cf-fill-' . bin2hex(random_bytes(4));
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
