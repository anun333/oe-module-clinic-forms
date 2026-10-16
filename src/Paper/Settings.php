<?php

/**
 * The clinic's settings.json, in its Clinic Forms folder (sites/<site>/documents/clinic-forms/):
 *
 *   {"country": "us", "hide": ["financial-policy"], "label_box": true, "documents_category": "Medical Record"}
 *
 * "country" chooses the country pack (a pack id, or "none"; see Pack). "hide" lists shipped
 * documents the clinic doesn't offer: they leave the picker and the packets, without having to be
 * replaced. "label_box" prints a box for a patient label at the top of a blank document's first
 * page, for clinics that stick labels on paper (off unless set). "documents_category" names the
 * Documents category a PDF saved to the patient's chart goes in (Medical Record unless set). A file
 * with a mistake is reported on the page and not used.
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
     */
    public function __construct(
        public ?string $country = null,
        public array $hide = [],
        public array $problems = [],
        public bool $labelBox = false,
        public string $documentsCategory = 'Medical Record',
    ) {
    }

    public static function forSite(?string $siteDir): self
    {
        $file = $siteDir === null ? null : $siteDir . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json';
        if ($file === null || !is_file($file)) {
            return new self();
        }
        try {
            $data = json_decode((string)file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_diff(array_keys($data), ['about', 'country', 'hide', 'label_box', 'documents_category']) !== []) {
                throw new \JsonException('expected {"country": "us", "hide": ["document-id"], "label_box": true, "documents_category": "Medical Record"} (all optional; country is a pack id or "none")');
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
            return new self(isset($data['country']) ? strtolower(trim($data['country'])) : null, $hide, [], $data['label_box'] ?? false, trim($data['documents_category'] ?? 'Medical Record'));
        } catch (\JsonException $e) {
            return new self(null, [], ['settings.json in the Clinic Forms folder was not used: ' . $e->getMessage()]);
        }
    }
}
