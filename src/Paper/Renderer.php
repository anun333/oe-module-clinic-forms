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
    public function __construct(private Context $context)
    {
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
                $f->value('facility.phone') !== '' ? xl('Phone') . ' ' . $f->value('facility.phone') : '',
                $f->value('facility.fax') !== '' ? xl('Fax') . ' ' . $f->value('facility.fax') : '',
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
    private function open(string $title): string
    {
        return "<section class='cf-page'><table class='cf-frame'><thead><tr><td>" . $this->running($title) . "</td></tr></thead><tbody><tr><td>";
    }

    private function close(): string
    {
        return "</td></tr></tbody></table></section>";
    }

    /** The line at the top of every printed page: patient name, date of birth, ID, and the document. */
    public function running(string $title): string
    {
        $parts = '';
        foreach (['patient.name' => xl('Patient'), 'patient.dob' => xl('DOB'), 'patient.id' => xl('ID')] as $key => $label) {
            $value = $this->context->value($key);
            $parts .= "<span class='cf-run-item'><span class='cf-run-label'>" . self::e($label) . "</span> "
                . ($value !== '' ? "<span class='cf-filled'>" . self::e($value) . "</span>" : "<span class='cf-run-blank'></span>") . "</span>";
        }
        return "<div class='cf-running'>" . $parts . "<span class='cf-run-title'>" . self::e($title) . "</span></div>";
    }

    public function document(Document $doc): string
    {
        $html = $this->open($doc->title) . $this->letterhead() . "<h1>" . $this->text($doc->title) . "</h1>";
        foreach ($doc->blocks as $block) {
            $html .= match ($block['type']) {
                'heading' => "<h2>" . $this->text($block['text']) . "</h2>",
                'paragraph' => "<p>" . $this->text($block['text']) . "</p>",
                'fields' => $this->fields($block['fields']),
                'checklist' => $this->checklist($block['label'] ?? null, $block['items']),
                'lines' => $this->lines($block['label'], $block['count']),
                'signature' => $this->signatures($block['signers']),
                default => '',
            };
        }
        return $html . $this->close();
    }

    /** A blank layout form (or one with the patient's header filled), as LayoutPaper renders it. */
    public function layoutForm(LayoutForm $form): string
    {
        return $this->open($form->title) . $this->letterhead() . "<h1>" . self::e($form->title) . "</h1>"
            . $this->patientStrip() . (new LayoutPaper())->render($form) . $this->close();
    }

    /** Name, date of birth and ID at the top of a form: filled when a patient is chosen, else blank. */
    public function patientStrip(): string
    {
        return $this->fields([
            ['label' => xl('Patient name'), 'value' => '{patient.name}'],
            ['label' => xl('Date of birth'), 'value' => '{patient.dob}'],
            ['label' => xl('Patient ID'), 'value' => '{patient.id}'],
        ]);
    }

    /** @param list<array{label: string, value?: string}> $fields */
    private function fields(array $fields): string
    {
        $html = "<div class='cf-fields'>";
        foreach ($fields as $field) {
            $value = isset($field['value']) ? $this->text($field['value'], false) : '';
            $html .= "<div class='cf-field'><span class='cf-label'>" . $this->text($field['label']) . "</span>"
                . "<span class='cf-blank'>" . $value . "</span></div>";
        }
        return $html . "</div>";
    }

    /** @param list<string> $items */
    private function checklist(?string $label, array $items): string
    {
        $html = "<div class='cf-checklist'>" . ($label !== null ? "<div class='cf-label'>" . $this->text($label) . "</div>" : '');
        foreach ($items as $item) {
            $html .= "<div class='cf-check'><span class='cf-box'></span>" . $this->text($item) . "</div>";
        }
        return $html . "</div>";
    }

    private function lines(string $label, int $count): string
    {
        return "<div class='cf-lines'><div class='cf-label'>" . $this->text($label) . "</div>"
            . str_repeat("<div class='cf-line'></div>", $count) . "</div>";
    }

    /** @param list<string> $signers */
    private function signatures(array $signers): string
    {
        $html = "<div class='cf-signatures'>";
        foreach ($signers as $signer) {
            $html .= "<div class='cf-signature'><div class='cf-sign-line'></div>"
                . "<div class='cf-sign-under'><span>" . $this->text($signer) . "</span><span>" . self::e(xl('Date')) . "</span></div></div>";
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
