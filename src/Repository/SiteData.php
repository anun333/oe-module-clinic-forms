<?php

/**
 * What the print shop reads from the site: its facilities (the letterhead), Address Book entries
 * (referral recipients), a patient's header details, and its layout forms. Read only.
 *
 * Layout labels and list options go through OpenEMR's own translation functions, so the paper
 * follows the site's "translate layout labels" and list settings, as the screens do.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\ClinicForms\Paper\LayoutForm;
use OpenHealthcare\ClinicForms\Paper\LayoutPaper;
use OpenHealthcare\ClinicForms\Paper\OnFile;

final class SiteData
{
    /** Address Book types that make sense as the recipient of a letter or referral. */
    public const RECIPIENT_TYPES = ['external_provider', 'external_org', 'spe', 'ccda', 'emr_direct', 'ord_img', 'ord_lab', 'ord_imm'];

    /** How many of a long list's most used options print. */
    public const MOST_USED = 12;

    /** @var array<string, list<string>> table => columns, read once */
    private array $columns = [];

    /** @return list<array{id: int, name: string}> active facilities, the primary business entity first */
    public function facilities(): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords("SELECT id, name FROM facility WHERE COALESCE(inactive, 0) = 0 ORDER BY primary_business_entity DESC, name") as $row) {
            $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $out;
    }

    /** @return array<string, string> the Context facility values, or [] when there is no such facility */
    public function facility(int $id): array
    {
        $rows = QueryUtils::fetchRecords("SELECT * FROM facility WHERE id = ?", [$id]);
        if ($rows === []) {
            return [];
        }
        $f = $rows[0];
        return [
            'name' => self::s($f['name'] ?? ''),
            'address' => self::address(self::s($f['street'] ?? ''), '', self::s($f['city'] ?? ''), self::s($f['state'] ?? ''), self::s($f['postal_code'] ?? '')),
            'phone' => self::s($f['phone'] ?? ''),
            'fax' => self::s($f['fax'] ?? ''),
            'email' => self::s($f['email'] ?? ''),
            'website' => self::s($f['website'] ?? ''),
            'npi' => self::s($f['facility_npi'] ?? ''),
        ];
    }

    /** @return list<array{id: int, label: string}> Address Book entries a letter can go to */
    public function recipients(): array
    {
        $in = implode(',', array_fill(0, count(self::RECIPIENT_TYPES), '?'));
        $out = [];
        foreach (QueryUtils::fetchRecords(
            "SELECT id, fname, lname, organization, specialty FROM users
              WHERE active = 1 AND abook_type IN ($in) AND (COALESCE(organization, '') <> '' OR COALESCE(lname, '') <> '')
              ORDER BY organization, lname, fname",
            self::RECIPIENT_TYPES,
        ) as $row) {
            $person = trim(self::s($row['fname'] ?? '') . ' ' . self::s($row['lname'] ?? ''));
            $label = implode(' — ', array_filter([self::s($row['organization'] ?? ''), $person, self::s($row['specialty'] ?? '')]));
            $out[] = ['id' => (int)$row['id'], 'label' => $label];
        }
        return $out;
    }

    /** @return array<string, string> the Context recipient values, or [] when it isn't an Address Book entry */
    public function recipient(int $id): array
    {
        $in = implode(',', array_fill(0, count(self::RECIPIENT_TYPES), '?'));
        $rows = QueryUtils::fetchRecords("SELECT * FROM users WHERE id = ? AND abook_type IN ($in)", [$id, ...self::RECIPIENT_TYPES]);
        if ($rows === []) {
            return [];
        }
        $u = $rows[0];
        return [
            'name' => trim(implode(' ', array_filter([self::s($u['title'] ?? ''), self::s($u['fname'] ?? ''), self::s($u['lname'] ?? '')]))),
            'organization' => self::s($u['organization'] ?? ''),
            'specialty' => self::s($u['specialty'] ?? ''),
            'address' => self::address(self::s($u['street'] ?? ''), self::s($u['streetb'] ?? ''), self::s($u['city'] ?? ''), self::s($u['state'] ?? ''), self::s($u['zip'] ?? '')),
            'phone' => self::s($u['phonew1'] ?? '') !== '' ? self::s($u['phonew1'] ?? '') : self::s($u['phone'] ?? ''),
            'fax' => self::s($u['fax'] ?? ''),
            'email' => self::s($u['email'] ?? ''),
        ];
    }

    /** @return array<string, string> the Context patient values, or [] when there is no such patient */
    public function patient(int $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT p.fname, p.mname, p.lname, p.DOB, p.pubpid, p.street, p.city, p.state, p.postal_code, p.phone_home, p.phone_cell, COALESCE(s.title, p.sex) AS sex
               FROM patient_data AS p LEFT JOIN list_options AS s ON s.list_id = 'sex' AND s.option_id = p.sex AND s.activity = 1
              WHERE p.pid = ?",
            [$pid],
        );
        if ($rows === []) {
            return [];
        }
        $p = $rows[0];
        $dob = self::s($p['DOB'] ?? '');
        $issues = ['allergy' => [], 'medication' => [], 'medical_problem' => []];
        // Current issues, as OpenEMR's own document templates list them: no end date.
        foreach (QueryUtils::fetchRecords(
            "SELECT type, title FROM lists WHERE pid = ? AND activity = 1 AND enddate IS NULL AND type IN ('allergy', 'medication', 'medical_problem') ORDER BY begdate, id",
            [$pid],
        ) as $issue) {
            $issues[self::s($issue['type'] ?? '')][] = self::s($issue['title'] ?? '');
        }
        return [
            'name' => trim(implode(' ', array_filter([self::s($p['fname'] ?? ''), self::s($p['mname'] ?? ''), self::s($p['lname'] ?? '')]))),
            'dob' => $dob !== '' && function_exists('oeFormatShortDate') ? (string)oeFormatShortDate($dob) : $dob,
            'id' => self::s($p['pubpid'] ?? ''),
            'address' => self::address(self::s($p['street'] ?? ''), '', self::s($p['city'] ?? ''), self::s($p['state'] ?? ''), self::s($p['postal_code'] ?? '')),
            'street' => self::s($p['street'] ?? ''),
            'city' => self::s($p['city'] ?? ''),
            'state' => self::s($p['state'] ?? ''),
            'zip' => self::s($p['postal_code'] ?? ''),
            'phone' => self::s($p['phone_cell'] ?? '') !== '' ? self::s($p['phone_cell'] ?? '') : self::s($p['phone_home'] ?? ''),
            'sex' => self::s($p['sex'] ?? ''),
            'allergies' => implode('; ', array_filter($issues['allergy'])),
            'medications' => implode('; ', array_filter($issues['medication'])),
            'problems' => implode('; ', array_filter($issues['medical_problem'])),
        ];
    }

    /** @return array<string, string> the Context user values for the person printing */
    public function user(int $id): array
    {
        $rows = QueryUtils::fetchRecords("SELECT fname, lname FROM users WHERE id = ?", [$id]);
        if ($rows === []) {
            return [];
        }
        return ['name' => trim(self::s($rows[0]['fname'] ?? '') . ' ' . self::s($rows[0]['lname'] ?? ''))];
    }

    /**
     * The layout forms that can be printed: Demographics and History, the active LBF encounter
     * forms by category (as Reports > Blank Forms lists them), then the active transaction forms
     * (Referral and the like).
     *
     * @return list<array{id: string, title: string, category: string}>
     */
    public function layoutForms(): array
    {
        $out = [
            ['id' => 'DEM', 'title' => xl('Registration (Demographics)'), 'category' => xl('Front desk')],
            ['id' => 'HIS', 'title' => xl('Medical history (History)'), 'category' => xl('Front desk')],
        ];
        foreach (QueryUtils::fetchRecords(
            "SELECT grp_form_id, grp_title, grp_mapping FROM layout_group_properties
              WHERE (grp_form_id LIKE 'LBF%' OR grp_form_id LIKE 'LBT%') AND grp_group_id = '' AND grp_activity = 1
              ORDER BY grp_form_id LIKE 'LBT%', grp_mapping, grp_seq, grp_title",
        ) as $row) {
            $id = (string)$row['grp_form_id'];
            $category = str_starts_with($id, 'LBT') ? xl('Transactions')
                : self::label(self::s($row['grp_mapping'] ?? '') !== '' ? self::s($row['grp_mapping'] ?? '') : 'Clinical');
            $out[] = ['id' => $id, 'title' => self::label(self::s($row['grp_title'] ?? '')), 'category' => $category];
        }
        return $out;
    }

    public function layoutForm(string $formId): ?LayoutForm
    {
        if (!LayoutForm::isPrintable($formId)) {
            return null;
        }
        $title = match ($formId) {
            'DEM' => xl('Registration (Demographics)'),
            'HIS' => xl('Medical history (History)'),
            default => null,
        };
        $groups = [];
        foreach (QueryUtils::fetchRecords(
            "SELECT grp_group_id, grp_title FROM layout_group_properties WHERE grp_form_id = ? ORDER BY grp_group_id",
            [$formId],
        ) as $row) {
            $gid = self::s($row['grp_group_id'] ?? '');
            if ($gid === '') {
                $title ??= self::label(self::s($row['grp_title'] ?? ''));
                continue;
            }
            $groups[$gid] = self::label(self::s($row['grp_title'] ?? ''));
        }
        if ($title === null) {
            return null;
        }
        $fields = [];
        $listIds = [];
        foreach (QueryUtils::fetchRecords(
            "SELECT field_id, group_id, title, data_type, list_id, fld_rows, description FROM layout_options
              WHERE form_id = ? AND uor > 0 ORDER BY group_id, seq, title",
            [$formId],
        ) as $row) {
            $field = [
                'field_id' => self::s($row['field_id'] ?? ''),
                'group_id' => self::s($row['group_id'] ?? ''),
                'title' => self::label(self::s($row['title'] ?? '')),
                'data_type' => (int)$row['data_type'],
                'list_id' => self::s($row['list_id'] ?? ''),
                'fld_rows' => (int)$row['fld_rows'],
                'description' => self::s($row['description'] ?? ''),
            ];
            $fields[] = $field;
            $groups[$field['group_id']] ??= '';
            if ($field['list_id'] !== '') {
                $listIds[$field['list_id']] = true;
            }
        }
        $lists = [];
        /** @var array<string, array<string, string>> $titles list id => option id => title */
        $titles = [];
        foreach (array_chunk(array_keys($listIds), 200) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                "SELECT list_id, option_id, title FROM list_options WHERE activity = 1 AND list_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") ORDER BY list_id, seq, title",
                $chunk,
            ) as $row) {
                $optionTitle = function_exists('xl_list_label') ? (string)xl_list_label(self::s($row['title'] ?? '')) : self::s($row['title'] ?? '');
                $lists[self::s($row['list_id'] ?? '')][] = $optionTitle;
                $titles[self::s($row['list_id'] ?? '')][self::s($row['option_id'] ?? '')] = $optionTitle;
            }
        }
        $common = [];
        foreach ($fields as $field) {
            $options = $titles[$field['list_id']] ?? [];
            if (count($options) > LayoutPaper::MAX_PRINTED_OPTIONS) {
                $used = $this->mostUsed($formId, $field['field_id'], $options);
                if ($used !== []) {
                    $common[$field['field_id']] = $used;
                }
            }
        }
        return new LayoutForm($formId, $title, $groups, $fields, $lists, $common, $titles);
    }

    /**
     * What a patient has on file for each field of Demographics or History (the latest History
     * row), for the patient to check and correct. Other forms have no single record to read.
     *
     * @return array<string, array<string, mixed>> field id => OnFile::value()
     */
    public function onFile(LayoutForm $form, int $pid): array
    {
        $rows = match ($form->id) {
            'DEM' => QueryUtils::fetchRecords("SELECT * FROM patient_data WHERE pid = ?", [$pid]),
            'HIS' => QueryUtils::fetchRecords("SELECT * FROM history_data WHERE pid = ? ORDER BY date DESC, id DESC LIMIT 1", [$pid]),
            default => [],
        };
        if ($rows === []) {
            return [];
        }
        $row = $rows[0];
        $date = static fn(string $d): string => function_exists('oeFormatShortDate') ? (string)oeFormatShortDate(substr($d, 0, 10)) : substr($d, 0, 10);
        $values = [];
        foreach ($form->fields as $field) {
            $raw = $row[$field['field_id']] ?? null;
            if (!is_scalar($raw)) {
                continue;
            }
            $raw = (string)$raw;
            $record = in_array($field['data_type'], OnFile::RECORD_TYPES, true) ? $this->recordName($field['data_type'], $raw) : null;
            $value = OnFile::value($field, $raw, $form->optionTitles[$field['list_id']] ?? [], $record, $date);
            if ($value !== null) {
                $values[$field['field_id']] = $value;
            }
        }
        return $values;
    }

    /** The name of the provider, pharmacy, address book entry or facility a field points at. */
    private function recordName(int $type, string $id): ?string
    {
        if (preg_match('/^\d+$/', $id) !== 1 || $id === '0') {
            return null;
        }
        $rows = match ($type) {
            10, 11 => QueryUtils::fetchRecords("SELECT CONCAT_WS(' ', fname, lname) AS name FROM users WHERE id = ?", [(int)$id]),
            14 => QueryUtils::fetchRecords("SELECT COALESCE(NULLIF(organization, ''), CONCAT_WS(' ', fname, lname)) AS name FROM users WHERE id = ?", [(int)$id]),
            12 => QueryUtils::fetchRecords("SELECT name FROM pharmacies WHERE id = ?", [(int)$id]),
            35 => QueryUtils::fetchRecords("SELECT name FROM facility WHERE id = ?", [(int)$id]),
            default => [],
        };
        $name = $rows === [] ? '' : self::s($rows[0]['name'] ?? '');
        return $name !== '' ? $name : null;
    }

    /**
     * The titles of the options this site's patients have for a field, most used first, for a
     * list too long to print in full. Counts only: no patient's answer leaves this method.
     *
     * @param array<string, string> $options option id => title
     * @return list<string>
     */
    private function mostUsed(string $formId, string $fieldId, array $options): array
    {
        if (preg_match('/^\w+$/', $fieldId) !== 1) {
            return [];
        }
        if ($formId === 'DEM' || $formId === 'HIS') {
            // The field id is a column of the table (checked against its columns), never data.
            if (!in_array($fieldId, $this->columns($formId === 'DEM' ? 'patient_data' : 'history_data'), true)) {
                return [];
            }
            $rows = QueryUtils::fetchRecords($formId === 'DEM'
                ? "SELECT `$fieldId` AS value, COUNT(DISTINCT pid) AS n FROM patient_data WHERE `$fieldId` IS NOT NULL AND `$fieldId` <> '' GROUP BY `$fieldId`"
                : "SELECT `$fieldId` AS value, COUNT(DISTINCT pid) AS n FROM history_data WHERE `$fieldId` IS NOT NULL AND `$fieldId` <> '' GROUP BY `$fieldId`");
        } elseif (str_starts_with($formId, 'LBF')) {
            $rows = QueryUtils::fetchRecords(
                "SELECT d.field_value AS value, COUNT(DISTINCT f.pid) AS n FROM lbf_data AS d
                   JOIN forms AS f ON f.form_id = d.form_id AND f.formdir = ? AND f.deleted = 0
                  WHERE d.field_id = ? AND d.field_value <> '' GROUP BY d.field_value",
                [$formId, $fieldId],
            );
        } else {
            return [];
        }
        // Multiple-choice answers are stored as "a|b"; count each option. Option ids are matched
        // without regard to case, as OpenEMR's own lookups (and GROUP BY) match them: a group of
        // "English" and "english" comes back under either spelling.
        $titles = array_change_key_case($options, CASE_LOWER);
        $counts = [];
        foreach ($rows as $row) {
            foreach (explode('|', self::s($row['value'] ?? '')) as $optionId) {
                $key = strtolower($optionId);
                if (isset($titles[$key])) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int)$row['n'];
                }
            }
        }
        arsort($counts);
        return array_map(static fn(string $key): string => $titles[$key], array_slice(array_keys($counts), 0, self::MOST_USED, false));
    }

    /** @return list<string> the table's column names */
    private function columns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = array_map(
                static fn(array $row): string => self::s($row['Field'] ?? ''),
                QueryUtils::fetchRecords($table === 'patient_data' ? "SHOW COLUMNS FROM patient_data" : "SHOW COLUMNS FROM history_data"),
            );
        }
        return $this->columns[$table];
    }

    private static function label(string $text): string
    {
        return function_exists('xl_layout_label') ? (string)xl_layout_label($text) : $text;
    }

    private static function address(string $street, string $street2, string $city, string $state, string $zip): string
    {
        $cityLine = trim(implode(', ', array_filter([$city, trim($state . ' ' . $zip)])));
        return implode(', ', array_filter([$street, $street2, $cityLine]));
    }

    private static function s(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
