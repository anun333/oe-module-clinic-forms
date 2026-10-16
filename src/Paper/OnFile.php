<?php

/**
 * What a layout field holds for a patient, read from its stored value the way OpenEMR's own
 * screens read it, shaped for paper: text, ticked options, a lifestyle status, or a row per
 * option. Used to print Demographics and History filled with what's on file, for the patient to
 * check and correct.
 *
 * Stored formats (library/options.inc.php, SmokingStatusType):
 *   lists                       an option id; multiple choice "a|b"; list with comment "id|comment"
 *   lifestyle (28)              "note|status|date", status "current<field id>", "quit…", "never…", "not_applicable…"
 *   smoking status (32)         "note|status|date|smoking_status option|packs"
 *   textbox list (22)           "option:text|option:text"
 *   exam results (23)           "option:R:note", R 0 = N/A, 1 = normal, 2 = abnormal
 *   checkbox with text (25)     "option:C:note", C 1 = ticked
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class OnFile
{
    /** Fields printed only as their last four characters. */
    public const MASKED = ['ss', 'drivers_license'];

    /** Data types that point at a record (provider, pharmacy, address book, facility): printed by name. */
    public const RECORD_TYPES = [10, 11, 12, 14, 35];

    /** Data types with nothing to print from the stored value (pictures, signatures, related tables). */
    private const NOT_PRINTED = [31, 40, 41, 42, 52, 53, 54, 55, 56];

    private const SINGLE_TYPES = [1, 26, 27, 43];
    private const MULTI_TYPES = [21, 33, 36];
    private const STATUSES = ['current', 'quit', 'never', 'not_applicable'];

    /**
     * @param array{field_id: string, data_type: int} $field
     * @param array<string, string>                   $titles     the field's list: option id => title
     * @param ?string                                 $recordName for a record type, the record's name
     * @param \Closure(string): string                $date       a stored date as the site shows dates
     * @return ?array{text?: string, ticked?: list<string>, status?: string, date?: string, note?: string, rows?: array<string, array{mark: string, note: string}>}
     *         null when there's nothing to print
     */
    public static function value(array $field, string $raw, array $titles, ?string $recordName, \Closure $date): ?array
    {
        $raw = trim($raw);
        $type = $field['data_type'];
        if ($raw === '' || in_array($type, self::NOT_PRINTED, true)) {
            return null;
        }
        if (in_array($field['field_id'], self::MASKED, true)) {
            return ['text' => strlen($raw) > 4 ? str_repeat('*', 5) . substr($raw, -4) : str_repeat('*', 5)];
        }
        $lower = array_change_key_case($titles, CASE_LOWER);
        $title = static fn(string $id): string => $lower[strtolower(trim($id))] ?? trim($id);
        if (in_array($type, self::RECORD_TYPES, true)) {
            return $recordName !== null && trim($recordName) !== '' ? ['text' => trim($recordName)] : null;
        }
        if ($type === 4) {
            return str_starts_with($raw, '0000') ? null : ['text' => $date($raw)];
        }
        if (in_array($type, self::SINGLE_TYPES, true)) {
            return ['ticked' => [$title($raw)]];
        }
        if ($type === 46) {
            [$id, $comment] = array_pad(explode('|', $raw, 2), 2, '');
            return array_filter(['ticked' => [$title($id)], 'note' => trim($comment)], static fn(mixed $v): bool => $v !== '');
        }
        if (in_array($type, self::MULTI_TYPES, true)) {
            $ids = array_values(array_filter(array_map('trim', explode('|', $raw)), static fn(string $id): bool => $id !== ''));
            return $ids === [] ? null : ['ticked' => array_map($title, $ids)];
        }
        if ($type === 28 || $type === 32) {
            $parts = array_pad(explode('|', $raw), 4, '');
            $value = ['note' => trim($parts[0])];
            $status = trim($parts[1]);
            foreach (self::STATUSES as $name) {
                if ($status === $name . $field['field_id']) {
                    $value['status'] = $name;
                }
            }
            $quit = trim($parts[2]);
            if ($quit !== '' && !str_starts_with($quit, '0000')) {
                $value['date'] = $date($quit);
            }
            if ($type === 32 && trim($parts[3]) !== '') {
                $value['ticked'] = [$title($parts[3])];
            }
            $value = array_filter($value, static fn(mixed $v): bool => $v !== '');
            return $value === [] ? null : $value;
        }
        if ($type === 22 || $type === 23 || $type === 25) {
            $rows = [];
            foreach (explode('|', $raw) as $entry) {
                if (preg_match('/^([^:]+):(.*)$/s', $entry, $m) !== 1) {
                    continue;
                }
                $rest = $m[2];
                $rows[$title($m[1])] = $type === 22
                    ? ['mark' => '', 'note' => trim($rest)]
                    : ['mark' => substr($rest, 0, 1), 'note' => trim(substr($rest, 2))];
            }
            $rows = array_filter($rows, static fn(array $r): bool => $r['note'] !== '' || ($r['mark'] !== '' && $r['mark'] !== '0') || ($type === 23 && $r['mark'] === '0'));
            return $rows === [] ? null : ['rows' => $rows];
        }
        return ['text' => $raw];
    }
}
