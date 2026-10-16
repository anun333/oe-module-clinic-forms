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
 * Text may use the placeholders in Context::KEYS, written {group.key}.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Document
{
    public const BLOCK_TYPES = ['heading', 'paragraph', 'fields', 'checklist', 'lines', 'signature'];

    /**
     * @param list<array<string, mixed>> $blocks validated by DocumentLibrary
     * @param list<string>               $uses   the context groups its placeholders use (patient, recipient)
     * @param string                     $source 'shipped' with the module, or 'clinic' (the site's own folder)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $category,
        public string $about,
        public array $blocks,
        public array $uses,
        public string $source = 'shipped',
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
