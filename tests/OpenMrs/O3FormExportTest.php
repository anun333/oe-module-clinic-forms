<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\OpenMrs;

use OpenHealthcare\ClinicForms\OpenMrs\O3FormExport;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Instruments as OpenMRS 3 forms saved as CIEL concepts. The workspace checks the files further:
 * OpenMRS's form schema, and each concept and answer against the CIEL release (dev/o3_check.py).
 */
final class O3FormExportTest extends TestCase
{
    public function testThePhq9IsSavedAsCielConceptsWithItsUnscoredClosingQuestionLeftOut(): void
    {
        $form = O3FormExport::form($this->doc('phq-9'));
        self::assertNotNull($form);
        self::assertSame('EncounterFormProcessor', $form['processor']);
        $obs = $this->obs($form);
        self::assertCount(9, $obs);
        self::assertSame('167006AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $obs[0]['questionOptions']['concept'], 'little interest or pleasure, CIEL 167006');
        self::assertSame('1. Little interest or pleasure in doing things', $obs[0]['label']);
        self::assertSame(['Not at all', 'Several days', 'More than half the days', 'Nearly every day'], array_column($obs[0]['questionOptions']['answers'], 'label'));
        self::assertSame('160215AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $obs[0]['questionOptions']['answers'][0]['concept']);
        self::assertStringContainsString('LOINC 69722-7', $form['description'], 'the difficulty question, which CIEL lacks, is named as left out');
        self::assertSame(count($obs), count(array_unique(array_column($obs, 'id'))));
    }

    public function testOnlyInstrumentsCielCarriesGetAForm(): void
    {
        self::assertCount(7, $this->obs(O3FormExport::form($this->doc('gad-7')) ?? []));
        self::assertCount(2, $this->obs(O3FormExport::form($this->doc('phq-2')) ?? []));
        foreach (['pc-ptsd-5', 'audit-c', 'asq', 'consent-to-treat'] as $id) {
            self::assertNull(O3FormExport::form($this->doc($id)), "$id: no form, CIEL has no concepts for its scored questions or it isn't an instrument");
        }
    }

    public function testADocumentThatDiffersFromItsInstrumentGetsNoForm(): void
    {
        $phq9 = $this->doc('phq-9');
        $blocks = $phq9->blocks;
        foreach ($blocks as &$b) {
            if ($b['type'] === 'scale') {
                $b['items'][0] = 'Feeling restless';
            }
        }
        unset($b);
        self::assertNull(O3FormExport::form(new Document($phq9->id, $phq9->title, $phq9->category, $phq9->about, $blocks, $phq9->uses, 'clinic', $phq9->audience, $phq9->code, true)));
    }

    public function testATranslationKeepsTheConceptsInItsOwnWords(): void
    {
        $spanish = DocumentLibrary::shipped('es')->get('phq-9');
        self::assertNotNull($spanish);
        $form = O3FormExport::form($spanish, $this->doc('phq-9'));
        self::assertNotNull($form);
        $obs = $this->obs($form);
        self::assertSame('167006AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $obs[0]['questionOptions']['concept']);
        self::assertSame('Ningún día', $obs[0]['questionOptions']['answers'][0]['label']);
        self::assertNull(O3FormExport::form($spanish), 'without its English document, no form');
    }

    public function testTheCielFileNamesItsRelease(): void
    {
        $ciel = O3FormExport::ciel();
        self::assertMatchesRegularExpression('/^v\d{4}-\d{2}-\d{2}$/', $ciel['ciel_release']);
        self::assertGreaterThanOrEqual(16, count($ciel['questions']));
        foreach ($ciel['questions'] as $loinc => $q) {
            self::assertMatchesRegularExpression('/^\d+$/', $q['ciel'], "$loinc: a CIEL number");
            self::assertContains($q['map'], ['SAME-AS', 'NARROWER-THAN'], "$loinc: how CIEL's concept relates to LOINC's");
            self::assertNotEmpty($q['answers'], "$loinc: its answers");
        }
    }

    /** @return list<array<string, mixed>> */
    private function obs(array $form): array
    {
        $out = [];
        foreach ($form['pages'] ?? [] as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['questions'] as $q) {
                    if ($q['type'] === 'obs') {
                        $out[] = $q;
                    }
                }
            }
        }
        return $out;
    }

    private function doc(string $id): Document
    {
        $doc = DocumentLibrary::shipped()->get($id);
        self::assertNotNull($doc, $id);
        return $doc;
    }
}
