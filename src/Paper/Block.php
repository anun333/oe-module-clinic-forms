<?php

/**
 * A part that documents share, written once in data/blocks/<id>.json (see Blocks): either document
 * blocks ("blocks", a consent's signature lines) or a scale's questions ("rows", the PHQ-2's two that
 * open the PHQ-9), with the block's own title, about and version.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Block
{
    /**
     * @param ?list<array<string, mixed>> $blocks document blocks, the block's own includes put in place; null for rows
     * @param ?list<string>               $rows   a scale's questions; null for blocks
     * @param int                         $version 0 when a clinic's block doesn't say
     * @param string                      $source 'shipped' with the module, or 'clinic'
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $about,
        public ?array $blocks,
        public ?array $rows,
        public int $version = 0,
        public string $language = 'en',
        public string $source = 'shipped',
    ) {
    }

    /** The block's wording in 16 hex digits, as Translation::fingerprint() gives a document's, for data/document-versions.json. */
    public function fingerprint(): string
    {
        $content = ['id' => $this->id, 'title' => $this->title, 'about' => $this->about]
            + ($this->rows !== null ? ['rows' => $this->rows] : ['blocks' => Translation::wording($this->blocks ?? [])]);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        return substr(hash('sha256', $json), 0, 16);
    }
}
