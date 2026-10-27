<?php

/**
 * Renders documents and blank layout forms as one printable HTML packet. Every value is escaped;
 * a placeholder with no value prints as a blank line, so the paper can be filled in by hand.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Renderer
{
    /** @param list<string> $taglines language assistance taglines, one per language, printed on patient pages */
    public function __construct(private Context $context, private string $language = 'en', private array $taglines = [], private bool $labelBox = false)
    {
    }

    /** Field labels longer than this many characters take the whole width (isLongLabel). */
    public const LONG_LABEL = 40;

    /** What a document says at the top when it carries answers from the chart. */
    public const CARRIED = 'Some answers are filled in from what we have on file. Cross out anything that is wrong and write the correction beside it.';

    private function t(string $english): string
    {
        return Words::t($english, $this->language);
    }

    /** The placeholders in a text, without braces, in order. @return list<string> */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z]+\.[a-z]+)\}/', $text, $m);
        return $m[1];
    }

    /** The clinic's letterhead, printed at the top of each document. */
    public function letterhead(): string
    {
        $f = $this->context;
        $lines = array_filter([
            $f->value('facility.address'),
            implode(' · ', array_filter([
                $f->value('facility.phone') !== '' ? $this->t('Phone') . ' ' . $f->value('facility.phone') : '',
                $f->value('facility.fax') !== '' ? $this->t('Fax') . ' ' . $f->value('facility.fax') : '',
            ])),
            implode(' · ', array_filter([$f->value('facility.email'), $f->value('facility.website')])),
        ]);
        $html = "<header class='cf-letterhead'>";
        if ($f->logoUrl !== null && $f->logoUrl !== '') {
            $html .= "<img class='cf-logo' src='" . self::e($f->logoUrl) . "' alt=''>";
        }
        $html .= "<div><div class='cf-clinic'>" . self::e($f->value('facility.name')) . "</div>";
        foreach ($lines as $line) {
            $html .= "<div>" . self::e($line) . "</div>";
        }
        return $html . "</div></header>";
    }

    /**
     * Opens a document's page: a table whose header row repeats at the top of every printed page
     * (browsers repeat a table's thead), so a loose page still names the patient.
     */
    private function open(string $title, bool $poster = false, string $stamp = ''): string
    {
        // A notice for posting goes on a wall: it never carries the patient line.
        $head = $poster ? '' : "<thead><tr><td>" . $this->running($title) . "</td></tr></thead>";
        // The foot of every page names the document and its version, so a signed copy says which it was,
        // and carries the patient's chart number as a barcode, so a scanned page can be filed to the chart.
        $foot = $stamp === '' ? '' : "<tfoot><tr><td><div class='cf-foot'>" . ($poster ? '' : $this->patientCode()) . "<div class='cf-stamp'>" . self::e($stamp) . "</div></div></td></tr></tfoot>";
        return "<section class='cf-page" . ($poster ? " cf-poster" : '') . "' lang='" . self::e($this->language) . "'><table class='cf-frame'>" . $head . $foot . "<tbody><tr><td>";
    }

    /**
     * The chart number (pubpid) as a Code 128 barcode, as OpenEMR's own patient barcode label encodes
     * it. Nothing on a blank print, or for a chart number Code 128 can't carry.
     */
    public function patientCode(): string
    {
        $id = $this->context->value('patient.id');
        return Code128::encodable($id) ? "<div class='cf-code'>" . Code128::svg($id, label: $this->t('Patient ID') . ' ' . $id) . "</div>" : '';
    }

    /** A box for a patient label, at the top of a blank document's first page, when the clinic asks for it. */
    private function labelBox(bool $poster): string
    {
        return $this->labelBox && !$poster && $this->context->value('patient.id') === ''
            ? "<div class='cf-label-box'>" . self::e($this->t('Patient label')) . "</div>" : '';
    }

    /** What the foot of a document's pages says: its id and version, and whether it's a draft or the clinic's own. */
    public function stamp(Document $doc): string
    {
        return $doc->stamp() . ($doc->status === 'draft' ? ' · ' . $this->t('draft') : '') . ($doc->source === 'clinic' ? ' · ' . $this->t('local version') : '');
    }

    private function close(): string
    {
        return "</td></tr></tbody></table></section>";
    }

    /** The line at the top of every printed page: patient name, date of birth, ID, and the document. */
    public function running(string $title): string
    {
        $parts = '';
        foreach (['patient.name' => $this->t('Patient'), 'patient.dob' => $this->t('DOB'), 'patient.id' => $this->t('ID')] as $key => $label) {
            $value = $this->context->value($key);
            $parts .= "<span class='cf-run-item'><span class='cf-run-label'>" . self::e($label) . "</span> "
                . ($value !== '' ? "<span class='cf-filled'>" . self::e($value) . "</span>" : "<span class='cf-run-blank'></span>") . "</span>";
        }
        return "<div class='cf-running'>" . $parts . "<span class='cf-run-title'>" . self::e($title) . "</span></div>";
    }

    public function document(Document $doc): string
    {
        if ($doc->anonymous && $this->context->patient !== []) {
            return (new self($this->context->withoutPatient(), $this->language, $this->taglines, $this->labelBox))->document($doc);
        }
        $html = $this->open($doc->title, $doc->poster, $this->stamp($doc)) . $this->labelBox($doc->poster) . $this->letterhead() . "<h1>" . $this->text($doc->title) . "</h1>";
        // Answers carried from the chart are there to be checked, never silently reused.
        if (Fill::carries($doc, $this->context)) {
            $html .= "<p class='cf-intro cf-carried-intro'>" . self::e($this->t(self::CARRIED)) . "</p>";
        }
        foreach ($doc->blocks as $block) {
            $html .= match ($block['type']) {
                'heading' => "<h2>" . $this->text($block['text']) . "</h2>",
                'paragraph' => "<p>" . $this->text($block['text']) . "</p>",
                'fields' => $this->fields($block['fields']),
                'checklist' => $this->checklist($block['label'] ?? null, $block['items'], Fill::tickedItem($block, $this->context)),
                'lines' => $this->lines($block['label'], $block['count']),
                'signature' => $this->signatures($block['signers']),
                'scale' => $this->scale($block['label'], $block['columns'], $block['items'], $block['scores'] ?? null, $block['total'] ?? null, $block['item_scores'] ?? null, $block['start'] ?? 1),
                'table' => $this->table($block['label'], $block['columns'], $block['rows'], $block['values'] ?? [], $block['note'] ?? null),
                'taglines' => $this->taglines(true),
                default => '',
            };
        }
        // Patient documents carry the language assistance taglines (45 CFR 92.11(c)(5)), unless
        // the document is the taglines notice itself.
        $hasBlock = array_filter($doc->blocks, static fn(array $b): bool => $b['type'] === 'taglines') !== [];
        if ($doc->audience === 'patient' && !$hasBlock) {
            $html .= $this->taglines(false);
        }
        return $html . $this->close();
    }

    /** The language assistance taglines: a small block at a page's end, or the whole notice. */
    public function taglines(bool $full): string
    {
        if ($this->taglines === []) {
            return '';
        }
        $html = "<div class='cf-taglines" . ($full ? " cf-taglines-full" : '') . "'>";
        foreach ($this->taglines as $tagline) {
            $html .= "<p>" . $this->text($tagline, false) . "</p>";
        }
        return $html . "</div>";
    }

    /**
     * A layout form as LayoutPaper renders it: blank, or (with $values) filled with what the
     * patient has on file, to check and correct.
     *
     * @param array<string, array<string, mixed>> $values field id => OnFile::value()
     */
    public function layoutForm(LayoutForm $form, array $values = []): string
    {
        $title = $values === [] ? $form->title : $form->title . ': ' . $this->t('please check and correct');
        $intro = $values === [] ? '' : "<p class='cf-intro'>" . self::e($this->t('This is what we have on file. Cross out anything that is wrong and write the correction beside it, and fill in anything missing.')) . "</p>";
        return $this->open($title, false, 'layout ' . $form->id) . $this->labelBox(false) . $this->letterhead() . "<h1>" . self::e($title) . "</h1>"
            . $this->patientStrip() . $intro . (new LayoutPaper($this->language))->render($form, $values) . $this->taglines(false) . $this->close();
    }

    /** Name, date of birth and ID at the top of a form: filled when a patient is chosen, else blank. */
    public function patientStrip(): string
    {
        return $this->fields([
            ['label' => $this->t('Patient name'), 'value' => '{patient.name}'],
            ['label' => $this->t('Date of birth'), 'value' => '{patient.dob}'],
            ['label' => $this->t('Patient ID'), 'value' => '{patient.id}'],
        ]);
    }

    /** @param list<array{label: string, value?: string}> $fields */
    private function fields(array $fields): string
    {
        $html = "<div class='cf-fields'>";
        foreach ($fields as $field) {
            $raw = Fill::fieldValue($field);
            $value = $raw !== null ? $this->text($raw, false) : '';
            $class = self::isLongLabel($field['label']) ? 'cf-field cf-field-long' : 'cf-field';
            $fill = Fill::of($field);
            $carried = $fill !== null && Fill::isChart($fill) && $value !== '' ? ' cf-carried' : '';
            $html .= "<div class='$class'><span class='cf-label'>" . $this->text($field['label']) . "</span>"
                . "<span class='cf-blank$carried'>" . $value . "</span></div>";
        }
        return $html . "</div>";
    }

    /**
     * Whether a field's label is a question rather than a name ("2. Now thinking about your physical
     * health… Number of days"): it takes the whole width and wraps, on the page and in the PDF, where a
     * label kept on one line would run into the next field or shrink the page to fit.
     */
    public static function isLongLabel(string $label): bool
    {
        return mb_strlen($label) > self::LONG_LABEL;
    }

    /**
     * @param list<string> $items
     * @param ?int $ticked the item the chart's answer ticks (Fill), printed ticked for the patient to check
     */
    private function checklist(?string $label, array $items, ?int $ticked = null): string
    {
        $html = "<div class='cf-checklist'>" . ($label !== null ? "<div class='cf-label'>" . $this->text($label) . "</div>" : '');
        foreach ($items as $i => $item) {
            $html .= $i === $ticked
                ? "<div class='cf-check cf-carried'><span class='cf-box cf-box-ticked' role='img' aria-label='" . self::e($this->t('ticked')) . "'>✓</span>" . $this->text($item) . "</div>"
                : "<div class='cf-check'><span class='cf-box'></span>" . $this->text($item) . "</div>";
        }
        return $html . "</div>";
    }

    private function lines(string $label, int $count): string
    {
        return "<div class='cf-lines'><div class='cf-label'>" . $this->text($label) . "</div>"
            . str_repeat("<div class='cf-line'></div>", $count) . "</div>";
    }

    /**
     * A question per row and a box per answer, numbered, with each column's score under its
     * heading when the scale is scored, and a total line for the office.
     *
     * @param list<string>   $columns
     * @param list<string>   $items
     * @param ?list<int>     $scores
     */
    private function scale(string $label, array $columns, array $items, ?array $scores, ?string $total, ?array $itemScores = null, int $start = 1): string
    {
        $html = "<div class='cf-scale'><div class='cf-label'>" . $this->text($label) . "</div><table class='cf-table cf-scale-table'><tr><th></th>";
        foreach ($columns as $i => $column) {
            $html .= "<th class='cf-scale-col'>" . $this->text($column) . ($scores !== null ? "<div class='cf-score'>" . self::e((string)$scores[$i]) . "</div>" : '') . "</th>";
        }
        $html .= "</tr>";
        foreach ($items as $n => $item) {
            $html .= "<tr><td class='cf-scale-item'>" . self::e((string)($start + $n)) . ". " . $this->text($item) . "</td>";
            foreach ($columns as $i => $unused) {
                // Each question its own points, beside the box, when the questions score differently.
                $html .= "<td class='cf-scale-box'><span class='cf-box'></span>" . (isset($itemScores[$n][$i]) ? "<span class='cf-score cf-item-score'>" . self::e((string)$itemScores[$n][$i]) . "</span>" : '') . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</table>";
        if ($total !== null) {
            $html .= "<div class='cf-field cf-total'><span class='cf-label'>" . $this->text($total) . "</span><span class='cf-blank'></span></div>";
        }
        return $html . "</div>";
    }

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $values  rows another module filled (DocumentFill); none prints blank rows
     */
    private function table(string $label, array $columns, int $rows, array $values = [], ?string $note = null): string
    {
        $html = "<div class='cf-grid-block'><div class='cf-label'>" . ($values === [] ? $this->text($label) : $this->text(DocumentFill::label($label))) . "</div><table class='cf-grid'><tr>";
        foreach ($columns as $column) {
            $html .= "<th>" . $this->text($column) . "</th>";
        }
        $html .= "</tr>";
        if ($values === []) {
            return $html . str_repeat("<tr>" . str_repeat("<td></td>", count($columns)) . "</tr>", $rows) . "</table></div>";
        }
        foreach ($values as $row) {
            $html .= "<tr>" . implode('', array_map(static fn(int $i): string => "<td class='cf-filled'>" . self::e($row[$i] ?? '') . "</td>", array_keys($columns))) . "</tr>";
        }
        return $html . "</table>" . ($note !== null && $note !== '' ? "<div class='cf-grid-note'>" . self::e($note) . "</div>" : '') . "</div>";
    }

    /** @param list<string> $signers */
    private function signatures(array $signers): string
    {
        $html = "<div class='cf-signatures'>";
        foreach ($signers as $signer) {
            $html .= "<div class='cf-signature'><div class='cf-sign-line'></div>"
                . "<div class='cf-sign-under'><span>" . $this->text($signer) . "</span><span>" . self::e($this->t('Date')) . "</span></div></div>";
        }
        return $html . "</div>";
    }

    /**
     * Text with its placeholders filled. In running text an unknown value becomes a blank to fill
     * in by hand; in a field ($blank false) it stays empty, since the field is already a blank.
     */
    private function text(string $text, bool $blank = true): string
    {
        $parts = preg_split('/(\{[a-z]+\.[a-z]+\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z]+\.[a-z]+)\}$/', $part, $m) === 1) {
                $value = $this->context->value($m[1]);
                $html .= $value !== '' ? "<span class='cf-filled'>" . self::e($value) . "</span>" : ($blank ? "<span class='cf-fill-in'></span>" : '');
            } else {
                $html .= self::e($part);
            }
        }
        return $html;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
