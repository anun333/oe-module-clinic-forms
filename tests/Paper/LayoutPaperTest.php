<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\LayoutForm;
use OpenHealthcare\ClinicForms\Paper\LayoutPaper;
use PHPUnit\Framework\TestCase;

final class LayoutPaperTest extends TestCase
{
    public function testEachTypePrintsItsOwnBlank(): void
    {
        $html = (new LayoutPaper())->render($this->form([
            $this->field('sex', 1, 'Sex', 'sex'),
            $this->field('symptoms', 21, 'Symptoms', 'symptoms'),
            $this->field('seen', 4, 'Date seen'),
            $this->field('notes', 3, 'Notes', rows: 5),
            $this->field('sig', 41, 'Patient signature'),
            $this->field('diagram', 40, 'Body diagram'),
            $this->field('phone', 2, 'Phone'),
        ], ['sex' => ['Female', 'Male', 'Unknown'], 'symptoms' => ['Cough', 'Fever']]));

        self::assertStringContainsString('tick one', $html);
        self::assertStringContainsString('tick all that apply', $html);
        self::assertSame(5, substr_count($html, "<span class='cf-check'><span class='cf-box'></span>"));
        self::assertStringContainsString('____ / ____ / ________', $html);
        self::assertSame(5, substr_count($html, "<div class='cf-line'></div>"));
        self::assertStringContainsString("<div class='cf-sign-line'></div>", $html);
        self::assertStringContainsString("<div class='cf-canvas'></div>", $html);
        self::assertStringContainsString("<div class='cf-label'>Phone</div><span class='cf-blank'></span>", $html);
    }

    public function testListsWithALinePerOptionPrintATableRowPerOption(): void
    {
        $html = (new LayoutPaper())->render($this->form([
            $this->field('exams', 23, 'Exams', 'exams'),
            $this->field('relatives', 22, 'Relatives', 'relatives'),
            $this->field('risks', 25, 'Risks', 'risks'),
        ], ['exams' => ['Mammogram', 'Colonoscopy'], 'relatives' => ['Cancer', 'Diabetes', 'Stroke'], 'risks' => ['Smoker']]));
        self::assertSame(1, substr_count($html, '<th>Exam or Test</th>'));
        // Two exams with three boxes each, and one tick for the checkbox-with-text row.
        self::assertSame(7, substr_count($html, "<span class='cf-box'></span>"));
        self::assertSame(6, substr_count($html, "<td class='cf-wide cf-under'></td>"));
        self::assertStringContainsString('<td>Diabetes</td>', $html);
    }

    public function testALifestyleFieldPrintsItsStatusTicks(): void
    {
        $html = (new LayoutPaper())->render($this->form([$this->field('alcohol', 28, 'Alcohol')]));
        foreach (['Current', 'Quit ____ / ____ / ________', 'Never', 'N/A'] as $status) {
            self::assertStringContainsString("<span class='cf-box'></span>$status</span>", $html);
        }
        self::assertStringContainsString('Notes', $html);
    }

    public function testRaceIsTickAllThatApply(): void
    {
        $html = (new LayoutPaper())->render($this->form([$this->field('race', 33, 'Race', 'race')], ['race' => ['Asian', 'White']]));
        self::assertStringContainsString('tick all that apply', $html);
    }

    public function testDemographicsFieldsTheClinicFillsPrintLastInAnOfficeBox(): void
    {
        $fields = [$this->field('pubpid', 2, 'External ID'), $this->field('fname', 2, 'First name'), $this->field('providerID', 11, 'Provider')];
        $dem = (new LayoutPaper())->render(new LayoutForm('DEM', 'Registration', ['1' => 'Who'], $fields, []));
        $office = strpos($dem, 'For office use');
        self::assertNotFalse($office);
        self::assertLessThan(strpos($dem, 'External ID'), strpos($dem, 'First name'));
        self::assertGreaterThan($office, strpos($dem, 'External ID'));
        self::assertGreaterThan($office, strpos($dem, 'Provider'));
        // The same field ids on another form are the patient's to fill.
        $lbf = (new LayoutPaper())->render(new LayoutForm('LBFx', 'X', ['1' => 'Who'], $fields, []));
        self::assertStringNotContainsString('For office use', $lbf);
    }

    public function testATextareaGetsAtLeastThreeLinesAndAtMostTwelve(): void
    {
        $paper = new LayoutPaper();
        self::assertSame(3, substr_count($paper->render($this->form([$this->field('a', 3, 'A', rows: 0)])), "<div class='cf-line'></div>"));
        self::assertSame(12, substr_count($paper->render($this->form([$this->field('a', 3, 'A', rows: 40)])), "<div class='cf-line'></div>"));
    }

    public function testALongListPrintsAsALine(): void
    {
        $options = array_map(static fn(int $i): string => "Option $i", range(1, 25));
        $html = (new LayoutPaper())->render($this->form([$this->field('country', 1, 'Country', 'country')], ['country' => $options]));
        self::assertStringNotContainsString('cf-box', $html);
        self::assertStringContainsString("<span class='cf-blank'></span>", $html);
    }

    public function testALongListPrintsTheOptionsMostUsedHereAndAnOtherLine(): void
    {
        $options = array_map(static fn(int $i): string => "Language $i", range(1, 30));
        $form = new LayoutForm('DEM', 'Registration', ['1' => 'Group'], [$this->field('language', 1, 'Language', 'language')], ['language' => $options], ['language' => ['Language 9', 'Language 2']]);
        $html = (new LayoutPaper())->render($form);
        self::assertSame(3, substr_count($html, "<span class='cf-box'></span>"));
        self::assertLessThan(strpos($html, 'Language 2<'), strpos($html, 'Language 9<'));
        self::assertStringContainsString("<span class='cf-check cf-other'><span class='cf-box'></span>Other: <span class='cf-blank'></span></span>", $html);
        self::assertStringNotContainsString('Language 1<', $html);
    }

    public function testValuesOnFileArePrintedForThePatientToCheck(): void
    {
        $form = new LayoutForm('DEM', 'Registration', ['1' => 'Who'], [
            $this->field('fname', 2, 'First name'),
            $this->field('sex', 1, 'Sex', 'sex'),
            $this->field('DOB', 4, 'Date of birth'),
            $this->field('language', 1, 'Language', 'language'),
            $this->field('alcohol', 28, 'Alcohol'),
            $this->field('exams', 23, 'Exams', 'exams'),
            $this->field('notes', 3, 'Notes', rows: 6),
        ], ['sex' => ['Female', 'Male'], 'language' => array_map(static fn(int $i): string => "Language $i", range(1, 30)), 'exams' => ['Breast Exam', 'ECG']],
            ['language' => ['Language 9']]);
        $html = (new LayoutPaper())->render($form, [
            'fname' => ['text' => 'Ann <Q>'],
            'sex' => ['ticked' => ['Female']],
            'DOB' => ['text' => '08/31/1958'],
            'language' => ['ticked' => ['Language 20']],
            'alcohol' => ['status' => 'quit', 'date' => '01/15/2020', 'note' => 'beer'],
            'exams' => ['rows' => ['ECG' => ['mark' => '1', 'note' => 'fine']]],
            'notes' => ['text' => "line one\nline two"],
        ]);
        self::assertStringContainsString("<span class='cf-blank'><span class='cf-filled'>Ann &lt;Q&gt;</span></span>", $html);
        self::assertStringContainsString("<span class='cf-box cf-on'>X</span>Female", $html);
        self::assertStringContainsString("<span class='cf-box'></span>Male", $html);
        self::assertStringContainsString("<span class='cf-filled'>08/31/1958</span>", $html);
        self::assertStringNotContainsString('____ / ____ / ________</span></div>', substr($html, 0, (int)strpos($html, 'Alcohol')));
        // A long list prints the site's common options and this patient's answer, ticked, then Other.
        self::assertStringContainsString("<span class='cf-box'></span>Language 9", $html);
        self::assertStringContainsString("<span class='cf-box cf-on'>X</span>Language 20", $html);
        self::assertStringContainsString("<span class='cf-box cf-on'>X</span>Quit <span class='cf-filled'>01/15/2020</span>", $html);
        self::assertStringContainsString("<span class='cf-filled'>beer</span>", $html);
        self::assertStringContainsString("<tr><td>ECG</td><td><span class='cf-box'></span></td><td><span class='cf-box cf-on'>X</span></td><td><span class='cf-box'></span></td><td class='cf-wide cf-under'><span class='cf-filled'>fine</span></td></tr>", $html);
        self::assertStringContainsString("<div class='cf-prefill cf-filled'>line one<br />\nline two</div>", $html);
        self::assertSame(2, substr_count($html, "<div class='cf-line'></div>"));
    }

    public function testWithoutValuesNothingIsTicked(): void
    {
        $form = new LayoutForm('DEM', 'Registration', ['1' => 'Who'], [$this->field('sex', 1, 'Sex', 'sex')], ['sex' => ['Female', 'Male']]);
        self::assertStringNotContainsString('cf-on', (new LayoutPaper())->render($form));
    }

    public function testAListWithNoOptionsPrintsAsALine(): void
    {
        $html = (new LayoutPaper())->render($this->form([$this->field('x', 1, 'Missing list', 'nothing')]));
        self::assertStringNotContainsString('cf-box', $html);
        self::assertStringContainsString("<span class='cf-blank'></span>", $html);
    }

    public function testStaticTextLosesItsTagsAndItsFieldReferences(): void
    {
        $html = (new LayoutPaper())->render($this->form([
            $this->field('s', 31, '', description: 'Read <b>this</b><br>Your weight is {weight} kg <script>x</script>'),
        ]));
        self::assertStringContainsString("<p class='cf-static'>Read this<br />\nYour weight is ________ kg x</p>", $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testGroupsWithNoFieldsAreLeftOutAndLabelsAreEscaped(): void
    {
        $form = new LayoutForm('LBFtest', 'Test', ['1' => 'First <group>', '2' => 'Empty'], [$this->field('a', 2, 'Name & title')], []);
        $html = (new LayoutPaper())->render($form);
        self::assertStringContainsString('<h2>First &lt;group&gt;</h2>', $html);
        self::assertStringNotContainsString('Empty', $html);
        self::assertStringContainsString('Name &amp; title', $html);
    }

    public function testAFieldWithNoTitleUsesItsDescriptionThenItsId(): void
    {
        $html = (new LayoutPaper())->render($this->form([
            $this->field('fname', 2, 'Name', description: 'First Name'),
            $this->field('lname', 2, '', description: 'Last Name'),
            $this->field('reason_code', 2, ' '),
            $this->field('city', 2, 'City', description: 'City'),
        ]));
        self::assertStringContainsString("<div class='cf-label'>Name (First Name)</div>", $html);
        self::assertStringContainsString("<div class='cf-label'>Last Name</div>", $html);
        self::assertStringContainsString("<div class='cf-label'>reason_code</div>", $html);
        self::assertStringContainsString("<div class='cf-label'>City</div>", $html);
    }

    /**
     * @param list<array{field_id: string, group_id: string, title: string, data_type: int, list_id: string, fld_rows: int, description: string}> $fields
     * @param array<string, list<string>> $lists
     */
    private function form(array $fields, array $lists = []): LayoutForm
    {
        return new LayoutForm('LBFtest', 'Test', ['1' => 'Group'], $fields, $lists);
    }

    /** @return array{field_id: string, group_id: string, title: string, data_type: int, list_id: string, fld_rows: int, description: string} */
    private function field(string $id, int $type, string $title, string $list = '', int $rows = 0, string $description = ''): array
    {
        return ['field_id' => $id, 'group_id' => '1', 'title' => $title, 'data_type' => $type, 'list_id' => $list, 'fld_rows' => $rows, 'description' => $description];
    }
}
