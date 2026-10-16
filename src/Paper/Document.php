<?php

/**
 * One printable document, read from data/documents/<id>.json: the master copy, the same on every
 * OpenEMR release. Blocks are printed in order.
 *
 * Block types:
 *   heading    {"type": "heading", "text": "..."}
 *   paragraph  {"type": "paragraph", "text": "... {facility.name} ..."}
 *   fields     {"type": "fields", "fields": [{"label": "...", "value": "{patient.name}"}]}   value optional
 *   checklist  {"type": "checklist", "label": "...", "items": ["...", "..."]}               label optional
 *   lines      {"type": "lines", "label": "...", "count": 4}
 *   signature  {"type": "signature", "signers": ["Patient or legal representative", "Witness"]}
 *   scale      {"type": "scale", "label": "...", "columns": ["Not at all", "Several days"], "items": ["...", "..."],
 *               "scores": [0, 1], "total": "Total score"}     a question per row, a box per answer; scores and total optional
 *   table      {"type": "table", "label": "...", "columns": ["Name", "Age"], "rows": 6}   a grid of blank rows
 * Text may use the placeholders in Context::KEYS, written {group.key}.
 *
 * Every document also says who reads it ("audience": patient, staff or provider) and may carry a
 * LOINC document code ("code": {"system": "http://loinc.org", "code": "57133-1", "display": "Referral Note"})
 * and "validated": true for a validated instrument (PHQ-9…) whose wording must not be changed.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Document
{
    public const BLOCK_TYPES = ['heading', 'paragraph', 'fields', 'checklist', 'lines', 'signature', 'scale', 'table'];
    public const AUDIENCES = ['patient', 'staff', 'provider'];

    /**
     * @param list<array<string, mixed>> $blocks validated by DocumentLibrary
     * @param list<string>               $uses   the context groups its placeholders use (patient, recipient)
     * @param string                     $source 'shipped' with the module, or 'clinic' (the site's own folder)
     * @param string                     $audience who reads it: patient, staff or provider
     * @param ?array{system: string, code: string, display: string} $code its LOINC document code, if it has one
     * @param bool                       $validated a validated instrument: its wording must not be changed
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $category,
        public string $about,
        public array $blocks,
        public array $uses,
        public string $source = 'shipped',
        public string $audience = 'patient',
        public ?array $code = null,
        public bool $validated = false,
    ) {
    }

    public function usesPatient(): bool
    {
        return in_array('patient', $this->uses, true);
    }

    public function usesRecipient(): bool
    {
        return in_array('recipient', $this->uses, true);
    }
}
