<?php

/**
 * Clinic Forms documents as an editable Word or LibreOffice file: each document's letterhead,
 * title and blocks, with placeholders filled from the Context, one document per page.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentFill;
use OpenHealthcare\ClinicForms\Paper\Fill;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Paper\Words;

final readonly class OfficeExport
{
    private const BLANK = '__________';

    /** @param list<string> $taglines language assistance taglines, printed at the end of patient documents */
    public function __construct(private Context $context, private string $language = 'en', private array $taglines = [])
    {
    }

    private function t(string $english): string
    {
        return Words::t($english, $this->language);
    }

    /** @param list<Document> $documents */
    public function write(array $documents, OfficeWriter $writer, string $paper): string
    {
        foreach ($documents as $i => $doc) {
            if ($doc->anonymous && $this->context->patient !== []) {
                // Filed without a name: written as if printed blank, and with no barcode in a PDF.
                (new self($this->context->withoutPatient(), $this->language, $this->taglines))->writeOne($doc, $writer, $i);
                continue;
            }
            $this->writeOne($doc, $writer, $i);
        }
        // A Word or LibreOffice file has one page header for all its pages: with a document filed without a
        // name among them, it carries the blank patient line, so that document's pages don't name the patient.
        // (A PDF gives each document its own header.)
        $anonymous = $this->context->patient !== [] && !$writer instanceof PdfWriter
            && array_filter($documents, static fn(Document $d): bool => $d->anonymous) !== [];
        return $writer->file($anonymous ? (new self($this->context->withoutPatient(), $this->language, $this->taglines))->running() : $this->running(), $paper);
    }

    private function writeOne(Document $doc, OfficeWriter $writer, int $i): void
    {
        if ($i > 0) {
            $writer->pageBreak();
        }
        if ($writer instanceof PdfWriter && $doc->anonymous) {
            // Its pages carry the blank patient line and no barcode.
            $writer->withoutPatient($this->running());
        }
        $writer->letterhead($this->context->value('facility.name'), $this->letterheadLines());
        $writer->title($this->text($doc->title));
        // Answers carried from the chart are there to be checked, never silently reused.
        if (Fill::carries($doc, $this->context)) {
            $writer->paragraph($this->t(Renderer::CARRIED));
        }
        foreach ($doc->blocks as $block) {
            match ($block['type']) {
                'heading' => $writer->heading($this->text($block['text'])),
                'paragraph' => $writer->paragraph($this->text($block['text'])),
                'fields' => $writer->fields(array_map(
                    fn(array $f): array => [$this->text($f['label']), ($v = Fill::fieldValue($f)) !== null ? $this->value($v) : ''],
                    $block['fields'],
                )),
                'checklist' => $writer->checklist(isset($block['label']) ? $this->text($block['label']) : null, array_map($this->text(...), $block['items']), Fill::tickedItem($block, $this->context)),
                'lines' => $writer->lines($this->text($block['label']), $block['count']),
                'signature' => $writer->signatures(array_map($this->text(...), $block['signers'])),
                'scale' => $writer->scale(
                    $this->text($block['label']),
                    array_map($this->text(...), $block['columns']),
                    array_map($this->text(...), $block['items']),
                    $block['scores'] ?? null,
                    isset($block['total']) ? $this->text($block['total']) : null,
                    $block['item_scores'] ?? null,
                    $block['start'] ?? 1,
                ),
                'table' => isset($block['values'])
                    ? $writer->table($this->text(DocumentFill::label($block['label'])), array_map($this->text(...), $block['columns']), $block['rows'], $block['values'], $block['note'] ?? null)
                    : $writer->table($this->text($block['label']), array_map($this->text(...), $block['columns']), $block['rows']),
                'taglines' => $this->taglines($writer),
                default => null,
            };
        }
        $hasBlock = array_filter($doc->blocks, static fn(array $b): bool => $b['type'] === 'taglines') !== [];
        if ($doc->audience === 'patient' && !$hasBlock) {
            $this->taglines($writer);
        }
        $writer->stamp($doc->stamp() . ($doc->status === 'draft' ? ' · ' . $this->t('draft') : '') . ($doc->source === 'clinic' ? ' · ' . $this->t('local version') : ''));
    }

    private function taglines(OfficeWriter $writer): void
    {
        foreach ($this->taglines as $tagline) {
            $writer->paragraph($this->text($tagline));
        }
    }

    /** The line at the top of every page: patient name, date of birth and ID, or blanks to fill in. */
    public function running(): string
    {
        $parts = [];
        foreach (['patient.name' => 'Patient', 'patient.dob' => 'DOB', 'patient.id' => 'ID'] as $key => $label) {
            $value = $this->context->value($key);
            $parts[] = $this->t($label) . ': ' . ($value !== '' ? $value : '____________________');
        }
        return implode('     ', $parts);
    }

    /** @return list<string> */
    private function letterheadLines(): array
    {
        $c = $this->context;
        return array_values(array_filter([
            $c->value('facility.address'),
            implode(' · ', array_filter([
                $c->value('facility.phone') !== '' ? $this->t('Phone') . ' ' . $c->value('facility.phone') : '',
                $c->value('facility.fax') !== '' ? $this->t('Fax') . ' ' . $c->value('facility.fax') : '',
            ])),
            implode(' · ', array_filter([$c->value('facility.email'), $c->value('facility.website')])),
        ]));
    }

    /** Running text: a placeholder with no value becomes a blank to fill in by hand. */
    private function text(string $text): string
    {
        return (string)preg_replace_callback('/\{([a-z]+\.[a-z]+)\}/', function (array $m): string {
            $value = $this->context->value($m[1]);
            return $value !== '' ? $value : self::BLANK;
        }, $text);
    }

    /** A field's value: placeholders with no value become nothing, since the field prints its own line. */
    private function value(string $text): string
    {
        return trim((string)preg_replace_callback('/\{([a-z]+\.[a-z]+)\}/', fn(array $m): string => $this->context->value($m[1]), $text));
    }
}
