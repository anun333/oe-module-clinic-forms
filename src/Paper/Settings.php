<?php

/**
 * The clinic's settings.json, in its Clinic Forms folder (sites/<site>/documents/clinic-forms/):
 *
 *   {"country": "us", "hide": ["financial-policy"], "label_box": true, "documents_category": "Medical Record", "pdf_libreoffice": false}
 *
 * "country" chooses the country pack (a pack id, or "none"; see Pack). "hide" lists shipped
 * documents the clinic doesn't offer: they leave the picker and the packets, without having to be
 * replaced. "label_box" prints a box for a patient label at the top of a blank document's first
 * page, for clinics that stick labels on paper (off unless set). "documents_category" names the
 * Documents category a PDF saved to the patient's chart goes in (Medical Record unless set).
 * "pdf_libreoffice": false makes every PDF with mPDF even when LibreOffice is installed (it is used
 * when it is, for PDFs tagged for PDF/UA). The clinic's own contacts fill the documents that name
 * them (Fill): "privacy_contact" and "section_1557_coordinator", each {"name", "phone", "email"}
 * (any of them), and "notice_effective_date", the date its notice of privacy practices took effect,
 * as it should print. A file with a mistake is reported on the page and not used.
 *
 *   {"privacy_contact": {"name": "Privacy Officer", "phone": "555-0100", "email": "privacy@example.org"},
 *    "section_1557_coordinator": {"name": "Pat Sample, Compliance", "phone": "555-0101"}, "notice_effective_date": "January 1, 2026"}
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Settings
{
    /**
     * @param list<string> $hide
     * @param list<string> $problems
     * @param array<string, string> $contacts the clinic's own contacts, as Context site values (site())
     */
    public function __construct(
        public ?string $country = null,
        public array $hide = [],
        public array $problems = [],
        public bool $labelBox = false,
        public string $documentsCategory = 'Medical Record',
        public bool $pdfLibreOffice = true,
        public array $contacts = [],
    ) {
    }

    /** The clinic's own contacts as Context site values (privacy, privacyphone, ..., noticedate). @return array<string, string> */
    public function site(): array
    {
        return $this->contacts;
    }

    public static function forSite(?string $siteDir): self
    {
        $file = $siteDir === null ? null : $siteDir . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json';
        if ($file === null || !is_file($file)) {
            return new self();
        }
        try {
            $data = json_decode((string)file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_diff(array_keys($data), ['about', 'country', 'hide', 'label_box', 'documents_category', 'pdf_libreoffice', 'privacy_contact', 'section_1557_coordinator', 'notice_effective_date']) !== []) {
                throw new \JsonException('expected {"country": "us", "hide": ["document-id"], "label_box": true, "documents_category": "Medical Record", "pdf_libreoffice": false, "privacy_contact": {"name": "", "phone": "", "email": ""}, "section_1557_coordinator": {"name": "", "phone": "", "email": ""}, "notice_effective_date": ""} (all optional; country is a pack id or "none")');
            }
            $contacts = [];
            foreach (['privacy_contact' => 'privacy', 'section_1557_coordinator' => 'coordinator'] as $setting => $key) {
                $contact = $data[$setting] ?? [];
                if (!is_array($contact) || array_diff(array_keys($contact), ['name', 'phone', 'email']) !== []
                    || array_filter($contact, static fn(mixed $v): bool => !is_string($v)) !== []) {
                    throw new \JsonException("\"$setting\" must be {\"name\": \"\", \"phone\": \"\", \"email\": \"\"} (any of them)");
                }
                foreach (['name' => '', 'phone' => 'phone', 'email' => 'email'] as $part => $suffix) {
                    if (trim($contact[$part] ?? '') !== '') {
                        $contacts[$key . $suffix] = trim($contact[$part]);
                    }
                }
            }
            if (isset($data['notice_effective_date'])) {
                if (!is_string($data['notice_effective_date'])) {
                    throw new \JsonException('"notice_effective_date" must be the date as it should print, such as "January 1, 2026"');
                }
                if (trim($data['notice_effective_date']) !== '') {
                    $contacts['noticedate'] = trim($data['notice_effective_date']);
                }
            }
            if (isset($data['pdf_libreoffice']) && !is_bool($data['pdf_libreoffice'])) {
                throw new \JsonException('"pdf_libreoffice" must be true or false');
            }
            if (isset($data['documents_category']) && (!is_string($data['documents_category']) || trim($data['documents_category']) === '')) {
                throw new \JsonException('"documents_category" must be the name of a Documents category');
            }
            if (isset($data['label_box']) && !is_bool($data['label_box'])) {
                throw new \JsonException('"label_box" must be true or false');
            }
            if (isset($data['country']) && !is_string($data['country'])) {
                throw new \JsonException('"country" must be a pack id, such as "us", or "none"');
            }
            $hide = $data['hide'] ?? [];
            if (!is_array($hide) || !array_is_list($hide) || array_filter($hide, static fn(mixed $id): bool => !is_string($id)) !== []) {
                throw new \JsonException('"hide" must be a list of document ids');
            }
            return new self(isset($data['country']) ? strtolower(trim($data['country'])) : null, $hide, [], $data['label_box'] ?? false, trim($data['documents_category'] ?? 'Medical Record'), $data['pdf_libreoffice'] ?? true, $contacts);
        } catch (\JsonException $e) {
            return new self(null, [], ['settings.json in the Clinic Forms folder was not used: ' . $e->getMessage()]);
        }
    }
}
