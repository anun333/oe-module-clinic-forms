<?php

/**
 * The paper version of a layout form: each group as a heading, each field in use with its label
 * and a blank shaped for its type, so staff can type the answers in later in the same order.
 *
 * - Lists, checkboxes and radio buttons print their options as tick boxes.
 * - Lists with a line per option (textbox lists, checkboxes with text, exam results) print as a
 *   table with a row per option, as OpenEMR's own blank forms do.
 * - Lifestyle fields (coffee, alcohol, exercise...) print the Current / Quit / Never / N/A ticks.
 * - A textarea prints as ruled lines (its row count, at least three).
 * - Dates print as day, month and year boxes; signatures as a signature line.
 * - Static text prints as written (as plain text; a {field} in it prints as a blank).
 * - Fields that pick a record (providers, facilities, insurances, codes) print as a line.
 * - Demographics fields the clinic fills, not the patient (chart number, providers, portal login,
 *   deceased date...), print last, in a "For office use" box.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class LayoutPaper
{
    /** Data types whose answer is one or more options of the field's list. */
    private const LIST_TYPES = [1, 21, 26, 27, 32, 33, 36, 43, 46];
    /** Data types that hold more than one tick (the rest are "choose one"). */
    private const MULTI_TYPES = [21, 33, 36];
    private const TEXT_LIST_TYPE = 22;
    private const EXAM_TYPE = 23;
    private const CHECK_TEXT_TYPE = 25;
    private const LIFESTYLE_TYPE = 28;
    private const DATE_TYPE = 4;
    private const TEXTAREA_TYPE = 3;
    private const STATIC_TYPE = 31;
    private const SIGNATURE_TYPES = [41, 42];
    private const CANVAS_TYPE = 40;
    /**
     * A list printed in full only up to this many options. A longer one prints the options this
     * site's patients use most (LayoutForm::$common) and an "Other" line, or just a line when
     * there's no usage to go on (a new site).
     */
    public const MAX_PRINTED_OPTIONS = 24;
    /** A list with a row per option is printed in full up to this many rows. */
    private const MAX_PRINTED_ROWS = 40;

    /**
     * Fields the clinic fills rather than the patient, by form, printed last in a "For office
     * use" box. Ids a site doesn't have are ignored.
     */
    public const OFFICE_FIELDS = [
        'DEM' => [
            'pubpid', 'providerID', 'ref_providerID', 'financial_review', 'provider_since_date', 'status_change_date',
            'cmsportal_login', 'prevent_portal_apps', 'email_direct', 'vfc', 'patient_groups', 'billing_note',
            'deceased_date', 'deceased_reason', 'imm_reg_status', 'imm_reg_stat_effdate', 'publicity_code',
            'publ_code_eff_date', 'protect_indicator', 'prot_indi_effdate',
            'genericname1', 'genericval1', 'genericname2', 'genericval2',
        ],
    ];

    /**
     * @param array<string, array<string, mixed>> $values field id => what the patient has on file
     *                                                    (OnFile::value()); empty for a blank form
     */
    public function render(LayoutForm $form, array $values = []): string
    {
        $office = self::OFFICE_FIELDS[$form->id] ?? [];
        $byGroup = [];
        $officeFields = [];
        foreach ($form->fields as $field) {
            if (in_array($field['field_id'], $office, true)) {
                $officeFields[] = $field;
            } else {
                $byGroup[$field['group_id']][] = $field;
            }
        }
        $html = '';
        foreach ($form->groups as $groupId => $title) {
            if (empty($byGroup[$groupId])) {
                continue;
            }
            $html .= "<div class='cf-group'><h2>" . self::e($title) . "</h2>";
            foreach ($byGroup[$groupId] as $i => $field) {
                $html .= $this->field($field, $form->lists[$field['list_id']] ?? [], self::label($field, $byGroup[$groupId][$i + 1] ?? null), $form->common[$field['field_id']] ?? [], $values[$field['field_id']] ?? null);
            }
            $html .= "</div>";
        }
        if ($officeFields !== []) {
            $html .= "<div class='cf-group cf-office'><h2>" . self::e(xl('For office use')) . "</h2>";
            foreach ($officeFields as $i => $field) {
                $html .= $this->field($field, $form->lists[$field['list_id']] ?? [], self::label($field, $officeFields[$i + 1] ?? null), $form->common[$field['field_id']] ?? [], $values[$field['field_id']] ?? null);
            }
            $html .= "</div>";
        }
        return $html;
    }

    /**
     * A field's printed label. On screen a field with no title sits on the row of the field
     * before it (first, middle and last name share the "Name" row), which paper can't show, so:
     * an untitled field is labelled with its description ("Middle Name"), and a titled field that
     * starts such a row adds its own ("Name (First Name)"). The field id is the last resort.
     *
     * @param array{field_id: string, title: string, description: string} $field
     * @param ?array{field_id: string, title: string, description: string} $next
     */
    private static function label(array $field, ?array $next): string
    {
        $title = trim($field['title']);
        $description = trim(strip_tags($field['description']));
        if ($title === '') {
            return $description !== '' ? $description : $field['field_id'];
        }
        if ($next !== null && trim($next['title']) === '' && $description !== '' && strcasecmp($description, $title) !== 0) {
            return "$title ($description)";
        }
        return $title;
    }

    /**
     * @param array{field_id: string, group_id: string, title: string, data_type: int, list_id: string, fld_rows: int, description: string} $field
     * @param list<string> $options
     * @param list<string> $common  for a long list, the options most used at this site
     */
    private function field(array $field, array $options, string $label, array $common = [], ?array $value = null): string
    {
        $type = $field['data_type'];
        if ($type === self::STATIC_TYPE) {
            $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $field['description'])));
            $text = preg_replace('/\{\w+\}/', '________', $text) ?? $text;
            return $text === '' ? '' : "<p class='cf-static'>" . nl2br(self::e($text)) . "</p>";
        }
        $head = "<div class='cf-label'>" . self::e($label) . "</div>";
        $ticked = array_map('strval', (array)($value['ticked'] ?? []));
        $text = is_string($value['text'] ?? null) ? $value['text'] : '';
        $note = is_string($value['note'] ?? null) ? $value['note'] : '';
        $rowTypes = [self::TEXT_LIST_TYPE, self::EXAM_TYPE, self::CHECK_TEXT_TYPE];
        if (in_array($type, $rowTypes, true) && $options !== [] && count($options) <= self::MAX_PRINTED_ROWS) {
            $rows = is_array($value['rows'] ?? null) ? $value['rows'] : [];
            return "<div class='cf-item'>" . $head . self::rows($type, $options, $rows) . "</div>";
        }
        if ($type === self::LIFESTYLE_TYPE) {
            $status = $value['status'] ?? '';
            $quit = is_string($value['date'] ?? null) ? $value['date'] : '';
            $ticks = '';
            foreach (['current' => xl('Current'), 'quit' => xl('Quit'), 'never' => xl('Never'), 'not_applicable' => xl('N/A')] as $key => $name) {
                $extra = $key === 'quit' ? ' ' . ($quit !== '' ? "<span class='cf-filled'>" . self::e($quit) . "</span>" : '____ / ____ / ________') : '';
                $ticks .= "<span class='cf-check'>" . self::box($status === $key) . self::e($name) . $extra . "</span>";
            }
            return "<div class='cf-item'>" . $head . "<div class='cf-options'>" . $ticks . "</div>"
                . "<div class='cf-inline'><span class='cf-hint'>" . self::e(xl('Notes')) . "</span>" . self::blank($note) . "</div></div>";
        }
        if (in_array($type, self::LIST_TYPES, true) && ($options !== [] || $ticked !== [])) {
            $long = count($options) > self::MAX_PRINTED_OPTIONS;
            if (!$long || $common !== [] || $ticked !== []) {
                // A long list prints the options used here, plus whatever this patient has, then Other.
                $printed = $long ? array_values(array_unique(array_merge($common, $ticked))) : array_values(array_unique(array_merge($options, $ticked)));
                $hint = in_array($type, self::MULTI_TYPES, true) ? xl('tick all that apply') : xl('tick one');
                $html = "<div class='cf-item cf-choices'>" . $head . "<div class='cf-hint'>" . self::e($hint) . "</div><div class='cf-options'>";
                foreach ($printed as $option) {
                    $html .= "<span class='cf-check'>" . self::box(in_array($option, $ticked, true)) . self::e($option) . "</span>";
                }
                if ($long) {
                    $html .= "<span class='cf-check cf-other'>" . self::box(false) . self::e(xl('Other')) . ": <span class='cf-blank'></span></span>";
                }
                $html .= "</div>";
                if ($note !== '') {
                    $html .= "<div class='cf-inline'><span class='cf-hint'>" . self::e(xl('Notes')) . "</span>" . self::blank($note) . "</div>";
                }
                return $html . "</div>";
            }
        }
        if ($type === self::DATE_TYPE) {
            return "<div class='cf-item cf-inline'>" . $head . ($text !== '' ? self::blank($text) : "<span class='cf-date'>____ / ____ / ________</span>") . "</div>";
        }
        if ($type === self::TEXTAREA_TYPE) {
            $lines = max(3, min(12, $field['fld_rows']));
            return "<div class='cf-item'>" . $head . ($text !== '' ? "<div class='cf-prefill cf-filled'>" . nl2br(self::e($text)) . "</div>" : '')
                . str_repeat("<div class='cf-line'></div>", $text !== '' ? 2 : $lines) . "</div>";
        }
        if (in_array($type, self::SIGNATURE_TYPES, true)) {
            return "<div class='cf-item cf-signature'><div class='cf-sign-line'></div><div class='cf-sign-under'><span>"
                . self::e($label) . "</span><span>" . self::e(xl('Date')) . "</span></div></div>";
        }
        if ($type === self::CANVAS_TYPE) {
            return "<div class='cf-item'>" . $head . "<div class='cf-canvas'></div></div>";
        }
        return "<div class='cf-item cf-inline'>" . $head . self::blank($text) . "</div>";
    }

    /** A tick box, marked when the patient has that answer on file. */
    private static function box(bool $on): string
    {
        return $on ? "<span class='cf-box cf-on'>X</span>" : "<span class='cf-box'></span>";
    }

    /** A blank line, with what's on file written on it. */
    private static function blank(string $text): string
    {
        return "<span class='cf-blank'>" . ($text !== '' ? "<span class='cf-filled'>" . self::e($text) . "</span>" : '') . "</span>";
    }

    /** @param list<string> $options */
    /**
     * @param list<string>                                  $options
     * @param array<string, array{mark: string, note: string}> $values option title => what's on file
     */
    private static function rows(int $type, array $options, array $values = []): string
    {
        $html = "<table class='cf-table'>";
        if ($type === self::EXAM_TYPE) {
            $html .= "<tr><th>" . self::e(xl('Exam or Test')) . "</th><th>" . self::e(xl('N/A')) . "</th><th>"
                . self::e(xl('Normal')) . "</th><th>" . self::e(xl('Abnormal')) . "</th><th class='cf-wide'>" . self::e(xl('Date/Notes')) . "</th></tr>";
        }
        foreach ($options as $option) {
            $mark = $values[$option]['mark'] ?? '';
            $note = $values[$option]['note'] ?? '';
            $cell = "<td class='cf-wide cf-under'>" . ($note !== '' ? "<span class='cf-filled'>" . self::e($note) . "</span>" : '') . "</td>";
            $html .= match ($type) {
                self::EXAM_TYPE => "<tr><td>" . self::e($option) . "</td><td>" . self::box($mark === '0') . "</td><td>" . self::box($mark === '1') . "</td><td>" . self::box($mark === '2') . "</td>$cell</tr>",
                self::CHECK_TEXT_TYPE => "<tr><td>" . self::box($mark !== '' && $mark !== '0') . " " . self::e($option) . "</td>$cell</tr>",
                default => "<tr><td>" . self::e($option) . "</td>$cell</tr>",
            };
        }
        return $html . "</table>";
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
