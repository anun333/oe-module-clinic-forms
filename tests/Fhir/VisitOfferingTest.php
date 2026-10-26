<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Fhir;

use OpenHealthcare\ClinicForms\Fhir\VisitOffering;
use PHPUnit\Framework\TestCase;

/**
 * Offering an added questionnaire in visits of the types picked, against stand-ins for OpenEMR's
 * registry and questionnaire availability (8.5 and later).
 */
final class VisitOfferingTest extends TestCase
{
    /** @var list<array{0: int, 1: string}> */
    private array $registered = [];

    /** @var list<array{0: int, 1: int}> */
    private array $assigned = [];

    private bool $everywhere = false;

    private function offering(): VisitOffering
    {
        return new VisitOffering(
            function (int $id, string $title): void {
                $this->registered[] = [$id, $title];
            },
            function (int $id, int $visitType): void {
                $this->assigned[] = [$id, $visitType];
            },
            fn(int $id): bool => $this->everywhere,
        );
    }

    public function testWithNoVisitTypeNothingChanges(): void
    {
        self::assertSame(['offered' => false, 'everywhere' => false], $this->offering()->offer(12, 'PHQ-9', []));
        self::assertSame([], $this->registered, 'offered as before, through New Questionnaire');
        self::assertSame([], $this->assigned);
    }

    public function testEachVisitTypeGetsARuleAndTheQuestionnaireBecomesAForm(): void
    {
        self::assertSame(['offered' => true, 'everywhere' => false], $this->offering()->offer(12, 'PHQ-9', [5, 9]));
        self::assertSame([[12, 'PHQ-9']], $this->registered, 'one encounter form of its own');
        self::assertSame([[12, 5], [12, 9]], $this->assigned, 'one rule per visit type');
    }

    public function testARuleForEveryVisitIsReportedAndLeftAlone(): void
    {
        $this->everywhere = true;
        self::assertSame(['offered' => true, 'everywhere' => true], $this->offering()->offer(12, 'PHQ-9', [5]));
        self::assertSame([[12, 5]], $this->assigned, 'the rule is still added; nothing is removed');
    }

    public function testNoRowMeansNothingToOffer(): void
    {
        self::assertSame(['offered' => false, 'everywhere' => false], $this->offering()->offer(0, 'PHQ-9', [5]));
        self::assertSame([], $this->assigned);
    }

    public function testOnlyTheSitesVisitTypesAreKeptEachOnceInItsOrder(): void
    {
        $known = [9 => 'Office Visit', 5 => 'New Patient', 2 => 'Telehealth'];
        self::assertSame([9, 5], VisitOffering::chosen(['5', '9', '5', '77', 'x', '', ['2']], $known));
        self::assertSame([2], VisitOffering::chosen([2], $known));
        self::assertSame([], VisitOffering::chosen('5', $known), 'not a list');
        self::assertSame([], VisitOffering::chosen(null, $known));
    }
}
