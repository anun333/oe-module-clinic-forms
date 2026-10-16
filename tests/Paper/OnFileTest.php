<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\OnFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnFileTest extends TestCase
{
    private const TITLES = ['male' => 'Male', 'female' => 'Female', 'English' => 'English', 'spanish' => 'Spanish', 'cancer' => 'Cancer', 'diabetes' => 'Diabetes',
        'breast' => 'Breast Exam', '449868002' => 'Current every day smoker'];

    /** @return iterable<string, array{string, int, string, ?string, mixed}> */
    public static function values(): iterable
    {
        yield 'text' => ['street', 2, ' 12 Main St ', null, ['text' => '12 Main St']];
        yield 'empty' => ['street', 2, '   ', null, null];
        yield 'date' => ['DOB', 4, '1958-08-31', null, ['text' => 'date:1958-08-31']];
        yield 'empty date' => ['DOB', 4, '0000-00-00', null, null];
        yield 'single list, any case' => ['sex', 1, 'FEMALE', null, ['ticked' => ['Female']]];
        yield 'option not in the list' => ['sex', 1, 'retired_option', null, ['ticked' => ['retired_option']]];
        yield 'multiple' => ['race', 36, 'english|spanish', null, ['ticked' => ['English', 'Spanish']]];
        yield 'list with comment' => ['language', 46, 'spanish|Mexican dialect', null, ['ticked' => ['Spanish'], 'note' => 'Mexican dialect']];
        yield 'SSN masked' => ['ss', 2, '123-45-6789', null, ['text' => '*****6789']];
        yield 'short SSN masked whole' => ['ss', 2, '6789', null, ['text' => '*****']];
        yield 'provider by name' => ['providerID', 11, '7', 'Ann Example', ['text' => 'Ann Example']];
        yield 'provider not found' => ['providerID', 11, '7', null, null];
        yield 'lifestyle quit' => ['alcohol', 28, 'beer on weekends|quitalcohol|2020-01-15', null, ['note' => 'beer on weekends', 'status' => 'quit', 'date' => 'date:2020-01-15']];
        yield 'lifestyle status for another field is ignored' => ['alcohol', 28, '|currentcoffee|', null, null];
        yield 'smoking status' => ['tobacco', 32, 'half a pack|currenttobacco||449868002|0', null, ['note' => 'half a pack', 'status' => 'current', 'ticked' => ['Current every day smoker']]];
        yield 'textbox list' => ['relatives', 22, 'cancer:mother|diabetes:', null, ['rows' => ['Cancer' => ['mark' => '', 'note' => 'mother']]]];
        yield 'exam results' => ['exams', 23, 'breast:2:lump left|cancer:0:', null, ['rows' => ['Breast Exam' => ['mark' => '2', 'note' => 'lump left'], 'Cancer' => ['mark' => '0', 'note' => '']]]];
        yield 'checkbox with text' => ['risks', 25, 'diabetes:1:type 2|cancer:0:', null, ['rows' => ['Diabetes' => ['mark' => '1', 'note' => 'type 2']]]];
        yield 'picture' => ['diagram', 40, 'data:image/png;base64,AAAA', null, null];
        yield 'related persons' => ['related_persons', 56, 'whatever', null, null];
    }

    #[DataProvider('values')]
    public function testValue(string $fieldId, int $type, string $raw, ?string $record, mixed $expected): void
    {
        $value = OnFile::value(['field_id' => $fieldId, 'data_type' => $type], $raw, self::TITLES, $record, static fn(string $d): string => "date:$d");
        self::assertSame($expected, $value);
    }
}
