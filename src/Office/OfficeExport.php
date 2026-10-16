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
            if ($i > 0) {
                $writer->pageBreak();
            }
            $writer->letterhead($this->context->value('facility.name'), $this->letterheadLines());
            $writer->title($this->text($doc->title));
            foreach ($doc->blocks as $block) {
                match ($block['type']) {
                    'heading' => $writer->heading($this->text($block['text'])),
                    'paragraph' => $writer->paragraph($this->text($block['text'])),
                    'fields' => $writer->fields(array_map(
                        fn(array $f): array => [$this->text($f['label']), isset($f['value']) ? $this->value($f['value']) : ''],
                        $block['fields'],
                    )),
                    'checklist' => $writer->checklist(isset($block['label']) ? $this->text($block['label']) : null, array_map($this->text(...), $block['items'])),
                    'lines' => $writer->lines($this->text($block['label']), $block['count']),
                    'signature' => $writer->signatures(array_map($this->text(...), $block['signers'])),
                    'scale' => $writer->scale(
                        $this->text($block['label']),
                        array_map($this->text(...), $block['columns']),
                        array_map($this->text(...), $block['items']),
                        $block['scores'] ?? null,
                        isset($block['total']) ? $this->text($block['total']) : null,
                    ),
                    'table' => $writer->table($this->text($block['label']), array_map($this->text(...), $block['columns']), $block['rows']),
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
        return $writer->file($this->running(), $paper);
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
