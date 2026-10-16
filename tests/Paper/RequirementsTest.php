<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Readability;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
    public function testEveryShippedDocumentHasWhatItIsHeldTo(): void
    {
        $requirements = Requirements::shipped();
        $library = DocumentLibrary::shipped();
        // A floor, so an empty requirements file can't pass as "nothing missing".
        self::assertGreaterThanOrEqual(7, count($requirements->documents));
        foreach (array_keys($requirements->documents) as $id) {
            $doc = $library->get($id);
            self::assertNotNull($doc, "requirements name a document that exists: $id");
            self::assertSame([], $requirements->missing($doc), $id);
        }
    }

    public function testARemovedElementIsReported(): void
    {
        $roi = DocumentLibrary::shipped()->get('release-of-information') ?? throw new \LogicException();
        $withoutExpiry = array_values(array_filter($roi->blocks, static fn(array $b): bool => !str_contains(json_encode($b, JSON_THROW_ON_ERROR), 'expires')));
        $withoutSignature = array_values(array_filter($roi->blocks, static fn(array $b): bool => $b['type'] !== 'signature'));
        $requirements = Requirements::shipped();
        self::assertSame(['An expiration date or event (164.508(c)(1)(v))'], $requirements->missing(self::with($roi, $withoutExpiry)));
        self::assertSame(['Signature and date (164.508(c)(1)(vi))'], $requirements->missing(self::with($roi, $withoutSignature)));
    }

    public function testForbiddenWordingIsReported(): void
    {
        $note = DocumentLibrary::shipped()->get('work-school-note') ?? throw new \LogicException();
        $blocks = [...$note->blocks, ['type' => 'fields', 'fields' => [['label' => 'Problems', 'value' => '{patient.problems}']]]];
        self::assertSame(['No diagnosis, medications, allergies or problems (164.502(b))'], Requirements::shipped()->missing(self::with($note, $blocks)));
    }

    public function testADocumentWithNoRequirementsHasNothingMissing(): void
    {
        self::assertSame([], Requirements::shipped()->missing(DocumentLibrary::shipped()->get('routing-slip') ?? throw new \LogicException()));
    }

    public function testPatientDocumentsReadAtEighthGradeOrBelow(): void
    {
        $measured = 0;
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            if ($doc->audience !== 'patient' || $doc->validated) {
                continue;
            }
            $measured++;
            self::assertLessThanOrEqual(8.0, Readability::measure($doc)['grade'], $doc->id);
        }
        self::assertGreaterThanOrEqual(8, $measured);
    }

    public function testTheReadabilityMeasure(): void
    {
        self::assertSame(1, Readability::syllables('cat'));
        self::assertSame(2, Readability::syllables('doctor'));
        self::assertSame(3, Readability::syllables('consenting'));
        self::assertSame(4, Readability::syllables('information'));
        $plain = new Document('a', 'A', 'x', 'x', [['type' => 'paragraph', 'text' => 'I agree. You may ask. We will help you.']], []);
        $dense = new Document('b', 'B', 'x', 'x', [['type' => 'paragraph', 'text' => 'Notwithstanding any contemporaneous authorization, documentation pertaining to psychotherapeutic interventions necessitates individualized consideration.']], []);
        self::assertLessThan(3.0, Readability::measure($plain)['grade']);
        self::assertGreaterThan(16.0, Readability::measure($dense)['grade']);
    }

    /** @param list<array<string, mixed>> $blocks */
    private static function with(Document $doc, array $blocks): Document
    {
        return new Document($doc->id, $doc->title, $doc->category, $doc->about, $blocks, $doc->uses, $doc->source, $doc->audience, $doc->code, $doc->validated);
    }
}
