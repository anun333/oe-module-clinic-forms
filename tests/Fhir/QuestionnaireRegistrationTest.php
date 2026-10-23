<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Fhir;

use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Fhir\QuestionnaireRegistration;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Adding Questionnaires to OpenEMR's questionnaires, against a stand-in for its repository. The
 * workspace's end-to-end check adds them to a running OpenEMR and opens them in the encounter form.
 */
final class QuestionnaireRegistrationTest extends TestCase
{
    /** @var array<string, array{id: int, questionnaire_id: string, source_url: string, active: bool, questionnaire: string}> by title */
    private array $rows = [];

    /** @var list<array{0: array<string, mixed>, 1: string}> */
    private array $saved = [];

    public function testANewQuestionnaireIsAddedForOpenEmrToNameAndKeepsItsIdentifier(): void
    {
        $q = $this->export('phq-9');
        self::assertSame([['system' => 'https://github.com/anun333/oe-module-clinic-forms/questionnaire', 'value' => 'phq-9']], $q['identifier'],
            'the identifier names the document, since OpenEMR replaces the id and url');

        self::assertSame([QuestionnaireRegistration::ADDED, 1], $this->registration()->register($q));
        self::assertCount(1, $this->saved);
        [$stored, $title] = $this->saved[0];
        self::assertSame('Patient Health Questionnaire (PHQ-9)', $title);
        self::assertArrayNotHasKey('id', $stored, 'OpenEMR gives a new questionnaire its own id');
        self::assertArrayNotHasKey('url', $stored);
        self::assertSame($q['identifier'], $stored['identifier']);
        self::assertSame($q['item'], $stored['item']);
    }

    public function testAddingTheSameDocumentAgainUpdatesItKeepingOpenEmrsIdAndUrl(): void
    {
        $q = $this->export('phq-9');
        $this->rows[$q['title']] = $this->row(7, ['resourceType' => 'Questionnaire', 'identifier' => $q['identifier']]);

        self::assertSame([QuestionnaireRegistration::UPDATED, 7], $this->registration()->register($q));
        [$stored] = $this->saved[0];
        self::assertSame('9a1b2c3d-0000-4000-8000-000000000007', $stored['id']);
        self::assertSame('https://clinic.example/apis/default/fhir/Questionnaire/9a1b2c3d-0000-4000-8000-000000000007', $stored['url']);
    }

    public function testATitleAlreadyThereForAnotherReasonIsLeftAlone(): void
    {
        $q = $this->export('phq-9');
        $registration = $this->registration();

        $this->rows[$q['title']] = $this->row(3, ['resourceType' => 'Questionnaire', 'title' => $q['title']]);
        self::assertSame([QuestionnaireRegistration::NOT_OURS, 3], $registration->register($q), 'one the clinic added itself');

        $this->rows[$q['title']] = $this->row(3, ['resourceType' => 'Questionnaire', 'identifier' => [QuestionnaireRegistration::identifier('phq-9-es')]]);
        self::assertSame([QuestionnaireRegistration::NOT_OURS, 3], $registration->register($q), 'one Clinic Forms added from another document');

        $this->rows[$q['title']] = $this->row(3, ['resourceType' => 'Questionnaire', 'identifier' => [['system' => 'urn:other', 'value' => 'phq-9']]]);
        self::assertSame([QuestionnaireRegistration::NOT_OURS, 3], $registration->register($q), 'the same value from another system');

        $this->rows[$q['title']]['questionnaire'] = 'not json';
        self::assertSame([QuestionnaireRegistration::NOT_OURS, 3], $registration->register($q));
        self::assertSame([], $this->saved);
    }

    public function testOneTheClinicTurnedOffStaysOff(): void
    {
        $q = $this->export('phq-9');
        $this->rows[$q['title']] = ['active' => false] + $this->row(5, ['resourceType' => 'Questionnaire', 'identifier' => $q['identifier']]);
        self::assertSame([QuestionnaireRegistration::TURNED_OFF, 5], $this->registration()->register($q));
        self::assertSame([], $this->saved);
    }

    public function testADocumentWithNothingToAnswerIsLeftOut(): void
    {
        $q = $this->export('phq-9');
        $q['item'] = [['linkId' => 'g', 'type' => 'group', 'text' => 'About', 'item' => [['linkId' => 't', 'type' => 'display', 'text' => 'Read this.']]]];
        self::assertSame([QuestionnaireRegistration::NOTHING_TO_ANSWER, null], $this->registration()->register($q));
        self::assertSame([], $this->saved);

        $q['item'][0]['item'][] = ['linkId' => 's', 'type' => 'string', 'text' => 'Signature'];
        self::assertSame(QuestionnaireRegistration::ADDED, $this->registration()->register($q)[0], 'a question inside a group counts');
    }

    public function testOnlyANoticeWithNothingToFillInIsLeftOut(): void
    {
        $export = new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], []));
        $left = [];
        $added = 0;
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            [$what] = $this->registration()->register($export->questionnaire($doc));
            if ($what === QuestionnaireRegistration::ADDED) {
                $added++;
            } else {
                $left[$doc->id] = $what;
            }
        }
        ksort($left);
        self::assertSame([
            'chaperone-notice' => QuestionnaireRegistration::NOTHING_TO_ANSWER,
            'notice-of-availability' => QuestionnaireRegistration::NOTHING_TO_ANSWER,
            'title-x-confidential-services' => QuestionnaireRegistration::NOTHING_TO_ANSWER,
        ], $left);
        self::assertGreaterThan(20, $added);
    }

    private function registration(): QuestionnaireRegistration
    {
        return new QuestionnaireRegistration(
            fn(string $title): ?array => $this->rows[$title] ?? null,
            function (array $q, string $title): int {
                $this->saved[] = [$q, $title];
                return $this->rows[$title]['id'] ?? count($this->saved);
            },
        );
    }

    /**
     * @param array<string, mixed> $questionnaire
     * @return array{id: int, questionnaire_id: string, source_url: string, active: bool, questionnaire: string}
     */
    private function row(int $id, array $questionnaire): array
    {
        $uuid = sprintf('9a1b2c3d-0000-4000-8000-%012d', $id);
        return [
            'id' => $id,
            'questionnaire_id' => $uuid,
            'source_url' => 'https://clinic.example/apis/default/fhir/Questionnaire/' . $uuid,
            'active' => true,
            'questionnaire' => json_encode($questionnaire, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, mixed> */
    private function export(string $id): array
    {
        $doc = DocumentLibrary::shipped()->get($id);
        self::assertNotNull($doc, $id);
        return (new QuestionnaireExport(new Context(['name' => 'Seed Clinic'], [])))->questionnaire($doc);
    }
}
