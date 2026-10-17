<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\LayoutForm;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private const FACILITY = [
        'name' => 'Riverside <Community> Clinic',
        'address' => '12 Main St, Springfield, IL 62701',
        'phone' => '555-0100',
        'fax' => '555-0101',
        'email' => 'front@example.org',
        'website' => '',
        'npi' => '1234567893',
    ];

    public function testValuesAreEscaped(): void
    {
        $html = (new Renderer(new Context(self::FACILITY, ['name' => '"><script>alert(1)</script>'])))
            ->document($this->doc([['type' => 'paragraph', 'text' => 'Patient: {patient.name}']]));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<Community>', $html);
        self::assertStringContainsString('Riverside &lt;Community&gt; Clinic', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testDocumentTextIsEscapedToo(): void
    {
        $html = (new Renderer(new Context(self::FACILITY)))->document($this->doc([['type' => 'paragraph', 'text' => 'Use <b>pen</b> & ink']]));
        self::assertStringContainsString('Use &lt;b&gt;pen&lt;/b&gt; &amp; ink', $html);
    }

    public function testAMissingValueIsABlankInTextAndEmptyInAField(): void
    {
        $html = (new Renderer(new Context(self::FACILITY)))->document($this->doc([
            ['type' => 'paragraph', 'text' => 'I, {patient.name}, agree.'],
            ['type' => 'fields', 'fields' => [['label' => 'Name', 'value' => '{patient.name}']]],
        ]));
        self::assertStringContainsString("I, <span class='cf-fill-in'></span>, agree.", $html);
        self::assertStringContainsString("<span class='cf-label'>Name</span><span class='cf-blank'></span>", $html);
    }

    public function testAKnownValueIsFilledIn(): void
    {
        $html = (new Renderer(new Context(self::FACILITY, ['name' => 'Jane Q Sample'], [], '10/15/2026')))->document($this->doc([
            ['type' => 'paragraph', 'text' => 'I, {patient.name}, agree on {clinic.today} with {facility.name}.'],
        ]));
        self::assertStringContainsString("<span class='cf-filled'>Jane Q Sample</span>", $html);
        self::assertStringContainsString("<span class='cf-filled'>10/15/2026</span>", $html);
    }

    public function testLetterheadLeavesOutWhatTheClinicHasNot(): void
    {
        $head = (new Renderer(new Context(['name' => 'Small Clinic', 'phone' => '555-0100'])))->letterhead();
        self::assertStringContainsString('Small Clinic', $head);
        self::assertStringContainsString('Phone 555-0100', $head);
        self::assertStringNotContainsString('Fax', $head);
        self::assertStringNotContainsString('<img', $head);
        $withLogo = (new Renderer(new Context(['name' => 'Small Clinic'], logoUrl: '/sites/default/images/logo.png?x=1&y=2')))->letterhead();
        self::assertStringContainsString("<img class='cf-logo' src='/sites/default/images/logo.png?x=1&amp;y=2'", $withLogo);
    }

    public function testEveryShippedDocumentRendersBlankAndFilledWithNoPlaceholderLeft(): void
    {
        $blank = new Renderer(new Context(self::FACILITY));
        $filled = new Renderer(new Context(
            self::FACILITY,
            ['name' => 'Jane Q Sample', 'dob' => '01/02/1980', 'id' => 'SYN-1', 'address' => '1 Test Way', 'phone' => '555-0199'],
            ['name' => 'Dr Ann Example', 'organization' => 'Example Cardiology', 'specialty' => 'Cardiology', 'address' => '2 Test Way', 'phone' => '555-0200', 'fax' => '555-0201', 'email' => 'ref@example.org'],
            '10/15/2026',
        ));
        $docs = DocumentLibrary::shipped()->all();
        self::assertGreaterThanOrEqual(12, count($docs));
        foreach ($docs as $doc) {
            foreach ([$blank, $filled] as $renderer) {
                $html = $renderer->document($doc);
                self::assertDoesNotMatchRegularExpression('/\{[a-z]+\.[a-z]+\}/', $html, $doc->id);
                self::assertStringStartsWith("<section class='cf-page", $html);
                self::assertStringEndsWith('</section>', $html);
            }
        }
    }

    public function testEveryPrintedPageCarriesThePatientLine(): void
    {
        $doc = $this->doc([['type' => 'paragraph', 'text' => 'Hello']]);
        $filled = (new Renderer(new Context(self::FACILITY, ['name' => 'Jane Q Sample', 'dob' => '01/02/1980', 'id' => 'SYN-1'])))->document($doc);
        // The line sits in the frame's thead, which browsers repeat at the top of every printed page.
        self::assertSame(1, preg_match("#<thead><tr><td><div class='cf-running'>(.*?)</div></td></tr></thead>#", $filled, $m));
        self::assertStringContainsString("<span class='cf-filled'>Jane Q Sample</span>", $m[1]);
        self::assertStringContainsString("<span class='cf-filled'>SYN-1</span>", $m[1]);
        self::assertStringContainsString("<span class='cf-run-title'>Sample</span>", $m[1]);
        $blank = (new Renderer(new Context(self::FACILITY)))->document($doc);
        self::assertSame(3, substr_count($blank, "<span class='cf-run-blank'></span>"));
    }

    public function testAScaleNumbersItsQuestionsAndPrintsABoxPerAnswerWithScores(): void
    {
        $html = (new Renderer(new Context(self::FACILITY)))->document($this->doc([[
            'type' => 'scale', 'label' => 'How often?', 'columns' => ['Never', 'Often & more'], 'scores' => [0, 3],
            'items' => ['Sleeping <badly>', 'Eating'], 'total' => 'Total score',
        ]]));
        self::assertStringContainsString("<td class='cf-scale-item'>1. Sleeping &lt;badly&gt;</td>", $html);
        self::assertStringContainsString("<td class='cf-scale-item'>2. Eating</td>", $html);
        self::assertSame(4, substr_count($html, "<td class='cf-scale-box'><span class='cf-box'></span></td>"));
        self::assertStringContainsString("Often &amp; more<div class='cf-score'>3</div>", $html);
        self::assertStringContainsString("<span class='cf-label'>Total score</span>", $html);
        $unscored = (new Renderer(new Context(self::FACILITY)))->document($this->doc([['type' => 'scale', 'label' => 'Q', 'columns' => ['No', 'Yes'], 'items' => ['A']]]));
        self::assertStringNotContainsString('cf-score', $unscored);
        self::assertStringNotContainsString('cf-total', $unscored);
    }

    public function testATablePrintsItsHeadingsAndBlankRows(): void
    {
        $html = (new Renderer(new Context(self::FACILITY)))->document($this->doc([['type' => 'table', 'label' => 'Household', 'columns' => ['Name', 'Age'], 'rows' => 3]]));
        self::assertStringContainsString('<th>Name</th><th>Age</th>', $html);
        self::assertSame(3, substr_count($html, '<tr><td></td><td></td></tr>'));
    }

    public function testPlaceholdersAreFoundInOrder(): void
    {
        self::assertSame(['patient.name', 'facility.fax'], Renderer::placeholders('To {patient.name} by fax {facility.fax}; {Not.this} or {this}'));
    }

    public function testALayoutFormGetsTheLetterheadAndAPatientStrip(): void
    {
        $form = new LayoutForm('LBFintake', 'Intake <form>', ['1' => 'Reason'], [
            ['field_id' => 'reason', 'group_id' => '1', 'title' => 'Reason for visit', 'data_type' => 2, 'list_id' => '', 'fld_rows' => 0, 'description' => ''],
        ], []);
        $html = (new Renderer(new Context(self::FACILITY, ['name' => 'Jane Q Sample'])))->layoutForm($form);
        self::assertStringContainsString('<h1>Intake &lt;form&gt;</h1>', $html);
        self::assertStringContainsString("<span class='cf-filled'>Jane Q Sample</span>", $html);
        self::assertStringContainsString('Reason for visit', $html);
    }

    /** @param list<array<string, mixed>> $blocks */
    public function testAFilledPageCarriesTheChartNumberAsABarcodeAtTheFoot(): void
    {
        $doc = $this->doc([['type' => 'paragraph', 'text' => 'Hello.']]);
        $filled = (new Renderer(new Context(self::FACILITY, ['name' => 'Jane Q Sample', 'id' => '00042'])))->document($doc);
        $foot = substr($filled, (int)strpos($filled, '<tfoot>'), (int)strpos($filled, '</tfoot>') - (int)strpos($filled, '<tfoot>'));
        self::assertStringContainsString("<svg xmlns='http://www.w3.org/2000/svg' class='cf-barcode'", $foot, 'in the foot, so it repeats on every page');
        self::assertStringContainsString("aria-label='Patient ID 00042'", $foot);
        self::assertStringNotContainsString('cf-barcode', (new Renderer(new Context(self::FACILITY)))->document($doc), 'a blank print has none');
        $poster = new Document('notice', 'Notice', 'Front desk', 'A notice.', [['type' => 'paragraph', 'text' => 'Posted.']], [], poster: true);
        self::assertStringNotContainsString('cf-barcode', (new Renderer(new Context(self::FACILITY, ['id' => '00042'])))->document($poster), 'a poster has none');
        self::assertStringNotContainsString('cf-barcode', (new Renderer(new Context(self::FACILITY, ['id' => 'Peña-1'])))->document($doc), 'a chart number Code 128 cannot carry is left as text');
    }

    public function testTheLabelBoxIsOnlyOnBlankPrintsWhenTheClinicAsks(): void
    {
        $doc = $this->doc([['type' => 'paragraph', 'text' => 'Hello.']]);
        self::assertStringNotContainsString('cf-label-box', (new Renderer(new Context(self::FACILITY)))->document($doc), 'off unless asked');
        $blank = (new Renderer(new Context(self::FACILITY), 'en', [], true))->document($doc);
        self::assertSame(1, substr_count($blank, "<div class='cf-label-box'>Patient label</div>"), 'once, on the first page');
        self::assertStringNotContainsString('cf-label-box', (new Renderer(new Context(self::FACILITY, ['id' => '00042']), 'en', [], true))->document($doc), 'not when the patient is printed');
        self::assertStringContainsString('Etiqueta del paciente', (new Renderer(new Context(self::FACILITY), 'es', [], true))->document($doc));
        self::assertStringContainsString('cf-label-box', (new Renderer(new Context(self::FACILITY), 'en', [], true))->layoutForm(new LayoutForm('DEM', 'Registration', ['1' => 'Who'], [], [])));
    }

    public function testADocumentFiledWithoutANameNeverCarriesThePatient(): void
    {
        $grievance = DocumentLibrary::shipped()->get('grievance-form');
        self::assertNotNull($grievance);
        self::assertTrue($grievance->anonymous);
        $renderer = new Renderer(new Context(self::FACILITY, ['name' => 'Jane Q Sample', 'phone' => '555-0199', 'id' => '00042']));
        $html = $renderer->document($grievance);
        self::assertStringNotContainsString('Jane Q Sample', $html);
        self::assertStringNotContainsString('555-0199', $html);
        self::assertStringNotContainsString('cf-barcode', $html, 'nor the chart number');
        self::assertStringContainsString('Jane Q Sample', $renderer->document($this->doc([['type' => 'paragraph', 'text' => 'Hello.']])), 'other documents in the same print are filled');
    }

    public function testAScaleWithPointsPerQuestionShowsEachBoxsPoints(): void
    {
        $html = (new Renderer(new Context(self::FACILITY)))->document($this->doc([
            ['type' => 'scale', 'label' => 'Choose', 'columns' => ['Yes', 'No'], 'items' => ['Happy?', 'Bored?'], 'item_scores' => [[0, 1], [1, 0]]],
        ]));
        self::assertSame(4, substr_count($html, "class='cf-score cf-item-score'"));
        self::assertMatchesRegularExpression("/Happy\?<\/td><td class='cf-scale-box'><span class='cf-box'><\/span><span class='cf-score cf-item-score'>0<\/span>/", $html);
    }

    private function doc(array $blocks): Document
    {
        return new Document('sample', 'Sample', 'Front desk', 'A sample.', $blocks, []);
    }

    public function testLettersCarryTheSigningProvider(): void
    {
        $library = DocumentLibrary::shipped();
        $context = new Context(['name' => 'Clinic', 'phone' => '555-0100'], provider: ['name' => 'Ana Q Sample', 'credentials' => 'MD', 'npi' => '1234567893', 'specialty' => 'Family Medicine']);
        $letter = (new Renderer($context))->document($library->get('referral-letter') ?? throw new \LogicException());
        foreach (['Ana Q Sample', 'MD', '1234567893', 'Family Medicine'] as $value) {
            self::assertStringContainsString("<span class='cf-filled'>$value</span>", $letter);
        }
        self::assertLessThan(strpos($letter, 'Ana Q Sample'), strpos($letter, 'Referring provider'), 'the printed name is under the signature');
        $note = (new Renderer($context))->document($library->get('work-school-note') ?? throw new \LogicException());
        self::assertStringContainsString("<span class='cf-filled'>Ana Q Sample</span>", $note);
        self::assertStringNotContainsString('1234567893', $note, 'a school or employer gets no NPI');
        $blank = (new Renderer(new Context(['name' => 'Clinic'])))->document($library->get('referral-letter') ?? throw new \LogicException());
        self::assertStringContainsString("<span class='cf-label'>NPI</span><span class='cf-blank'></span>", $blank, 'with no provider chosen the lines stay blank');
    }
}
