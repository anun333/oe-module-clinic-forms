<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Loinc;
use PHPUnit\Framework\TestCase;

final class LoincTest extends TestCase
{
    public function testRealCodesPassAndMistypedOnesDoNot(): void
    {
        // Real codes, confirmed at NLM's LOINC search (clinicaltables.nlm.nih.gov) on 2026-10-16.
        foreach (['57133-1', '11488-4', '59284-0', '57016-8', '64292-6', '11506-3', '44249-1', '69737-5', '34133-9', '108235-3'] as $code) {
            self::assertTrue(Loinc::isValid($code), $code);
        }
        foreach (['57133-2', '11506-0', '44249', '44249-11', 'LP1234-5', '-1', ''] as $code) {
            self::assertFalse(Loinc::isValid($code), $code);
        }
    }

    public function testTheGuidesWorkedExample(): void
    {
        // The method's worked example: for 12345 the check digit is 5.
        self::assertSame(5, Loinc::checkDigit('12345'));
    }

    public function testEachDefinitionIsAnItemOfLoincsQuestionnaireForAnInstrumentWeHave(): void
    {
        $data = json_decode((string)file_get_contents(Loinc::definitionsFile()), true, 8, JSON_THROW_ON_ERROR);
        $panels = array_map(static fn(string $f): string => basename($f, '.json'), glob(dirname(__DIR__, 2) . '/data/instruments/*.json') ?: []);
        self::assertGreaterThan(100, count($data['definitions']));
        foreach ($data['definitions'] as $code => $definition) {
            self::assertTrue(Loinc::isValid((string)$code), (string)$code);
            self::assertMatchesRegularExpression('~^http://loinc\.org/q/(\d+-\d)#\d+$~', $definition, (string)$code);
            preg_match('~/q/(\d+-\d)#~', $definition, $m);
            self::assertContains($m[1], $panels, "$code: LOINC's questionnaire for one of data/instruments' panels");
            self::assertSame($definition, Loinc::definition((string)$code));
        }
        self::assertNull(Loinc::definition('59284-0'), 'a document code is no question');
    }
}
