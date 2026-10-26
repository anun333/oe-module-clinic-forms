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
 *              with a label, optionally coded: "code" (the question's LOINC code), "answer_codes" (one per item:
 *              LOINC, SNOMED CT or HL7 null flavor, with "display" but for LOINC) and "single": true (one answer)
 *   lines      {"type": "lines", "label": "...", "count": 4}
 *   signature  {"type": "signature", "signers": ["Patient or legal representative", "Witness"]}
 *   scale      {"type": "scale", "label": "...", "columns": ["Not at all", "Several days"], "items": ["...", "..."],
 *               "scores": [0, 1], "total": "Total score"}     a question per row, a box per answer; scores and total optional
 *               "item_scores": [[1, 0], [0, 1]] in place of scores, when each question scores its answers differently
 *               "start": 4, the first row's number, where the instrument numbers on from an earlier part (default 1)
 *               "item_sex": [null, "male", "female"], rows asked of one sex only, on screen (TAPS-1); paper ignores it
 *   table      {"type": "table", "label": "...", "columns": ["Name", "Age"], "rows": 6}   a grid of blank rows
 *   taglines   {"type": "taglines"}   the language assistance taglines (data/taglines.json and the clinic's own)
 *
 * A translation (data/documents/es/<id>.json) has the same id, title, category, about and blocks,
 * and takes its audience, code and validated flag from the English document. Its "translates" names
 * the English it was made from (Translation::fingerprint); when the English changes, the translation
 * is out of date and the document prints in English until it is updated.
 * Text may use the placeholders in Context::KEYS, written {group.key}.
 *
 * Every document also says who reads it ("audience": patient, staff or provider) and may carry a
 * LOINC document code ("code": {"system": "http://loinc.org", "code": "57133-1", "display": "Referral Note"})
 * and "validated": true for a validated instrument (PHQ-9…) whose wording must not be changed.
 *
 * What a document says about itself (required on shipped documents, optional on a clinic's own):
 *   "version": 3          a whole number, raised whenever the wording changes (data/document-versions.json
 *                         records each version's fingerprint, and the tests fail on a change without one);
 *                         printed small at the foot of each page with the id, so a signed copy names its version
 *   "status": "active"    draft (offered, marked draft), active, or retired (no longer offered)
 *   "source": {"kind": "written" | "rule" | "instrument", "name": "...", "url": "..."}   where the wording comes from
 *   "licence": {"holder": "...", "terms": "...", "permission": "..."}                       who owns it and on what terms
 * "anonymous": true marks a document that may be filed without a name (a grievance): it never prints
 * the patient's details, the patient line or the chart number's barcode, even when printed for a patient.
 * A clinic's own version of a shipped document may say "based_on": the fingerprint (Translation::fingerprint)
 * of the module's version it was copied from, so the page can say when the module's has changed since.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Document
{
    public const BLOCK_TYPES = ['heading', 'paragraph', 'fields', 'checklist', 'lines', 'signature', 'scale', 'table', 'taglines'];
    public const AUDIENCES = ['patient', 'staff', 'provider'];
    public const STATUSES = ['draft', 'active', 'retired'];
    public const SOURCE_KINDS = ['written', 'rule', 'instrument'];

    /**
     * @param list<array<string, mixed>> $blocks validated by DocumentLibrary
     * @param list<string>               $uses   the context groups its placeholders use (patient, recipient)
     * @param string                     $source 'shipped' with the module, or 'clinic' (the site's own folder)
     * @param string                     $audience who reads it: patient, staff or provider
     * @param ?array{system: string, code: string, display: string} $code its LOINC document code, if it has one
     * @param bool                       $validated a validated instrument: its wording must not be changed
     * @param string                     $language  the language of its wording (en, es)
     * @param bool                       $poster    a notice for posting: printed in 20-point sans serif, as 45 CFR 92.10-92.11 require
     * @param ?string                    $translates for a translation, the fingerprint of the English it was made from
     * @param int                        $version   0 when the document doesn't say
     * @param string                     $status    draft, active or retired
     * @param ?array{kind: string, name: string, url?: string} $origin where the wording comes from (the JSON's "source")
     * @param ?array{holder: string, terms: string, permission?: string} $licence
     * @param ?string                    $basedOn   for a clinic's own version, the fingerprint of the module's version it was copied from
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
        public string $language = 'en',
        public bool $poster = false,
        public ?string $translates = null,
        public int $version = 0,
        public string $status = 'active',
        public ?array $origin = null,
        public ?array $licence = null,
        public ?string $basedOn = null,
        public bool $anonymous = false,
    ) {
    }

    /** What the foot of each printed page says: the id and version, and draft or local when it is. */
    /** The same document with other blocks: a table another module filled (DocumentFill). */
    public function withBlocks(array $blocks): self
    {
        return new self($this->id, $this->title, $this->category, $this->about, $blocks, $this->uses, $this->source, $this->audience,
            $this->code, $this->validated, $this->language, $this->poster, $this->translates, $this->version, $this->status,
            $this->origin, $this->licence, $this->basedOn, $this->anonymous);
    }

    public function stamp(): string
    {
        return $this->id . ($this->version > 0 ? ' v' . $this->version : '');
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
