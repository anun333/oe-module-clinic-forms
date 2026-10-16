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
    private function doc(array $blocks): Document
    {
        return new Document('sample', 'Sample', 'Front desk', 'A sample.', $blocks, []);
    }
}
