<?php

/**
 * Answers kept on file from an earlier form: a fact core has no column for (the personal
 * representative, pack-years, the countries a patient lived in, the school a student goes to) is
 * read back from the patient's own saved questionnaire responses, the latest one that counts, and
 * carried into the next form that asks it, for the patient to check. Nothing new is stored: the
 * answers are the ones OpenEMR already saved when the form was filled in on screen.
 *
 * A document's question says where a fact comes from and where it goes:
 *
 *   {"label": "Name", "defines": "repname", "fill": "kept.repname"}      asked here, and carried forward
 *   {"label": "If signed by a representative: name", "fill": "kept.repname"}   carried in from where it's asked
 *
 * "defines" names the fact the question asks; on screen its item carries that fact's definition
 * (DEFINITION), so the answer can be found whatever the form's later wording or layout. Only the
 * module's own documents define facts, and never one a patient signs for confidential care (Title
 * X, Part 2, a minor's own consent), so nothing said there is carried out of it; a validated
 * instrument's questions neither define nor take one.
 *
 * Each fact keeps for a while (KEEPS): a representative until the authority's end date, pack-years
 * and the people a patient lives with for a year, a school until the school year ends, the countries
 * lived in until the patient changes them. An older answer isn't carried.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Kept
{
    /** The definition a question that asks a kept fact carries on screen: this, then the fact's key. */
    public const DEFINITION = 'https://github.com/anun333/oe-module-clinic-forms/definition/';

    /**
     * The facts, and how long an answer keeps: "authority" until the representative's end date,
     * "year" a year, "school-year" until the next 1 August, "changed" until the patient changes it.
     */
    public const KEEPS = [
        'repname' => 'authority',
        'reprelationship' => 'authority',
        'repauthority' => 'authority',
        'repphone' => 'authority',
        'repaddress' => 'authority',
        'repemail' => 'authority',
        'repother' => 'authority',
        'repstarts' => 'authority',
        'repends' => 'authority',
        'packsperday' => 'year',
        'yearssmoked' => 'year',
        'yearquit' => 'year',
        'familycancers' => 'changed',
        'countries' => 'changed',
        'school' => 'school-year',
        'grade' => 'school-year',
        'livewith' => 'year',
        'othercare' => 'year',
        'medicines' => 'year',
        'readlanguage' => 'changed',
    ];

    /**
     * Facts filled from others: a consent's "Relationship or legal authority" is the relationship the
     * Personal Representative form gives, with the authority ticked there.
     */
    public const COMPOSITE = ['reprole' => ['reprelationship', 'repauthority']];

    /** The facts that hold a representative's authority, carried only while it's in force. */
    public const AUTHORITY = ['repname', 'reprelationship', 'repauthority', 'repphone', 'repaddress', 'repemail', 'repother', 'repstarts', 'repends', 'reprole'];

    /** Every fact a document may fill. */
    public static function keys(): array
    {
        return [...array_keys(self::KEEPS), ...array_keys(self::COMPOSITE)];
    }

    /** Whether a fill is a kept answer ("kept.repname"). */
    public static function isKept(?string $fill): bool
    {
        return $fill !== null && str_starts_with($fill, 'kept.');
    }

    /**
     * Whether an answer given on $answered still holds on $today for this fact.
     */
    public static function holds(string $key, \DateTimeImmutable $answered, \DateTimeImmutable $today): bool
    {
        $answered = $answered->setTime(0, 0);
        $today = $today->setTime(0, 0);
        if ($answered > $today) {
            return false;
        }
        return match (self::KEEPS[$key] ?? null) {
            'authority', 'changed' => true,
            'year' => $answered > $today->modify('-1 year'),
            'school-year' => $answered >= self::schoolYearStart($today),
            default => false,
        };
    }

    /** The 1 August the current school year began. */
    public static function schoolYearStart(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $year = (int)$today->format('Y') - ((int)$today->format('n') < 8 ? 1 : 0);
        return new \DateTimeImmutable(sprintf('%04d-08-01', $year));
    }

    /**
     * Whether the representative's authority is in force on $today, by the start and end dates the
     * form gives: a date that can't be read leaves it to the patient to check.
     *
     * @param array<string, string> $values the kept answers, by fact
     */
    public static function authorityInForce(array $values, \DateTimeImmutable $today): bool
    {
        $today = $today->setTime(0, 0);
        $starts = self::date($values['repstarts'] ?? '');
        $ends = self::date($values['repends'] ?? '');
        return !($starts !== null && $starts > $today) && !($ends !== null && $ends < $today);
    }

    /** A date as people write it on a form (2027-03-31, 3/31/2027, 3/31/27), or null. */
    public static function date(string $text): ?\DateTimeImmutable
    {
        $text = trim($text);
        foreach (['!Y-m-d', '!n/j/Y', '!n/j/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $text);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }
        return null;
    }

    /**
     * The facts' values with the composites added and the representative's facts dropped when the
     * authority isn't in force.
     *
     * @param array<string, array{value: string, from: string, date: string}> $kept
     * @return array<string, array{value: string, from: string, date: string}>
     */
    public static function complete(array $kept, \DateTimeImmutable $today): array
    {
        $values = array_map(static fn(array $k): string => $k['value'], $kept);
        if (!self::authorityInForce($values, $today)) {
            $kept = array_diff_key($kept, array_flip(self::AUTHORITY));
        }
        foreach (self::COMPOSITE as $key => $parts) {
            $found = array_values(array_filter($parts, static fn(string $p): bool => trim($kept[$p]['value'] ?? '') !== ''));
            if ($found !== []) {
                $kept[$key] = [
                    'value' => implode('; ', array_map(static fn(string $p): string => trim($kept[$p]['value']), $found)),
                    'from' => $kept[$found[0]]['from'],
                    'date' => $kept[$found[0]]['date'],
                ];
            }
        }
        return $kept;
    }
}
