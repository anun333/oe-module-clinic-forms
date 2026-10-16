<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testValues(): void
    {
        $c = new Context(['name' => ' Clinic '], ['dob' => '01/02/1980'], ['fax' => '555-0101'], '10/15/2026');
        self::assertSame('Clinic', $c->value('facility.name'));
        self::assertSame('01/02/1980', $c->value('patient.dob'));
        self::assertSame('555-0101', $c->value('recipient.fax'));
        self::assertSame('10/15/2026', $c->value('clinic.today'));
        self::assertSame('', $c->value('patient.name'));
        self::assertSame('', $c->value('clinic.tomorrow'));
        self::assertSame('', $c->value('nonsense'));
    }

    public function testKnownPlaceholders(): void
    {
        self::assertTrue(Context::isKnown('facility.npi'));
        self::assertTrue(Context::isKnown('clinic.today'));
        self::assertFalse(Context::isKnown('patient.ssn'));
        self::assertFalse(Context::isKnown('user.name'));
        self::assertFalse(Context::isKnown('facility'));
    }
}
