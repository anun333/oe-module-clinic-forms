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

final class SiteData
{
    /** Address Book types that make sense as the recipient of a letter or referral. */
    public const RECIPIENT_TYPES = ['external_provider', 'external_org', 'spe', 'ccda', 'emr_direct', 'ord_img', 'ord_lab', 'ord_imm'];

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
        $rows = QueryUtils::fetchRecords("SELECT fname, mname, lname, DOB, pubpid, street, city, state, postal_code, phone_home, phone_cell FROM patient_data WHERE pid = ?", [$pid]);
        if ($rows === []) {
            return [];
        }
        $p = $rows[0];
        $dob = self::s($p['DOB'] ?? '');
        return [
            'name' => trim(implode(' ', array_filter([self::s($p['fname'] ?? ''), self::s($p['mname'] ?? ''), self::s($p['lname'] ?? '')]))),
            'dob' => $dob !== '' && function_exists('oeFormatShortDate') ? (string)oeFormatShortDate($dob) : $dob,
            'id' => self::s($p['pubpid'] ?? ''),
            'address' => self::address(self::s($p['street'] ?? ''), '', self::s($p['city'] ?? ''), self::s($p['state'] ?? ''), self::s($p['postal_code'] ?? '')),
            'phone' => self::s($p['phone_cell'] ?? '') !== '' ? self::s($p['phone_cell'] ?? '') : self::s($p['phone_home'] ?? ''),
        ];
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
        foreach (array_chunk(array_keys($listIds), 200) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                "SELECT list_id, title FROM list_options WHERE activity = 1 AND list_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") ORDER BY list_id, seq, title",
                $chunk,
            ) as $row) {
                $lists[self::s($row['list_id'] ?? '')][] = function_exists('xl_list_label') ? (string)xl_list_label(self::s($row['title'] ?? '')) : self::s($row['title'] ?? '');
            }
        }
        return new LayoutForm($formId, $title, $groups, $fields, $lists);
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
