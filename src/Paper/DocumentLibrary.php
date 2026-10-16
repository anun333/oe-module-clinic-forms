<?php

/**
 * The printable documents in data/documents/*.json, read strictly: a document with an unknown
 * field, an unknown block type, or a placeholder that doesn't exist is refused with its file
 * and place named, so a mistake in the library can't print a broken page.
 *
 * A clinic's own documents go in sites/<site>/documents/clinic-forms/, in the same format, so
 * they survive module updates. One with the id of a shipped document replaces it (a clinic's
 * edited consent, say). A clinic file with a mistake is left out and reported on the page;
 * the shipped files are checked by the tests, so a mistake there stops the page instead.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class DocumentLibrary
{
    public const LOCAL_FOLDER = 'documents/clinic-forms';

    /** @var array<string, Document> by id, in title order within each category */
    private array $documents = [];
    /** @var list<string> clinic files that were left out, and why */
    private array $problems = [];

    /**
     * @param ?string $localDir   the clinic's own documents, or null for none
     * @param string  $localLabel how messages name the clinic's folder
     */
    public function __construct(
        private readonly string $dir,
        private readonly ?string $localDir = null,
        private readonly string $localLabel = 'sites/default/' . self::LOCAL_FOLDER,
    ) {
    }

    public static function shipped(): self
    {
        return new self(dirname(__DIR__, 2) . '/data/documents');
    }

    /** The shipped documents plus the clinic's own, from the site directory (OE_SITE_DIR). */
    public static function forSite(string $siteDir): self
    {
        return new self(dirname(__DIR__, 2) . '/data/documents', $siteDir . '/' . self::LOCAL_FOLDER, 'sites/' . basename($siteDir) . '/' . self::LOCAL_FOLDER);
    }

    /** @return array<string, Document> */
    public function all(): array
    {
        if ($this->documents === []) {
            $docs = [];
            foreach (glob($this->dir . '/*.json') ?: [] as $file) {
                $doc = self::read($file);
                $docs[$doc->id] = $doc;
            }
            if ($this->localDir !== null) {
                foreach (glob($this->localDir . '/*.json') ?: [] as $file) {
                    try {
                        $doc = self::read($file, $this->localLabel, 'clinic');
                    } catch (\InvalidArgumentException $e) {
                        $this->problems[] = $e->getMessage();
                        continue;
                    }
                    $docs[$doc->id] = $doc;
                }
            }
            uasort($docs, static fn(Document $a, Document $b): int => [$a->category, $a->title] <=> [$b->category, $b->title]);
            $this->documents = $docs;
        }
        return $this->documents;
    }

    /** @return list<string> the clinic's files that were left out, each with its file and the mistake */
    public function problems(): array
    {
        $this->all();
        return $this->problems;
    }

    public function get(string $id): ?Document
    {
        return $this->all()[$id] ?? null;
    }

    /** @param string $source 'shipped' or 'clinic' */
    public static function read(string $file, string $label = 'data/documents', string $source = 'shipped'): Document
    {
        $where = $label . '/' . basename($file);
        try {
            $data = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException("$where: must be an object");
        }
        self::onlyKeys($data, ['id', 'title', 'category', 'about', 'blocks'], [], $where);
        $id = self::text($data['id'], "$where id");
        if ($id . '.json' !== basename($file) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
            throw new \InvalidArgumentException("$where id: must be the file's name, lower case words joined by hyphens");
        }
        if (!is_array($data['blocks']) || !array_is_list($data['blocks']) || $data['blocks'] === []) {
            throw new \InvalidArgumentException("$where blocks: must be a list of at least one block");
        }
        $uses = [];
        $blocks = [];
        foreach ($data['blocks'] as $i => $block) {
            $at = "$where blocks[$i]";
            if (!is_array($block) || !isset($block['type']) || !in_array($block['type'], Document::BLOCK_TYPES, true)) {
                throw new \InvalidArgumentException("$at: type must be one of " . implode(', ', Document::BLOCK_TYPES));
            }
            $texts = [];
            switch ($block['type']) {
                case 'heading':
                case 'paragraph':
                    self::onlyKeys($block, ['type', 'text'], [], $at);
                    $texts[] = self::text($block['text'], "$at text");
                    break;
                case 'fields':
                    self::onlyKeys($block, ['type', 'fields'], [], $at);
                    if (!is_array($block['fields']) || !array_is_list($block['fields']) || $block['fields'] === []) {
                        throw new \InvalidArgumentException("$at fields: must be a list of at least one field");
                    }
                    foreach ($block['fields'] as $j => $field) {
                        if (!is_array($field)) {
                            throw new \InvalidArgumentException("$at fields[$j]: must be an object");
                        }
                        self::onlyKeys($field, ['label'], ['value'], "$at fields[$j]");
                        $texts[] = self::text($field['label'], "$at fields[$j] label");
                        if (isset($field['value'])) {
                            $texts[] = self::text($field['value'], "$at fields[$j] value");
                        }
                    }
                    break;
                case 'checklist':
                    self::onlyKeys($block, ['type', 'items'], ['label'], $at);
                    if (isset($block['label'])) {
                        $texts[] = self::text($block['label'], "$at label");
                    }
                    if (!is_array($block['items']) || !array_is_list($block['items']) || $block['items'] === []) {
                        throw new \InvalidArgumentException("$at items: must be a list of at least one item");
                    }
                    foreach ($block['items'] as $j => $item) {
                        $texts[] = self::text($item, "$at items[$j]");
                    }
                    break;
                case 'lines':
                    self::onlyKeys($block, ['type', 'label', 'count'], [], $at);
                    $texts[] = self::text($block['label'], "$at label");
                    if (!is_int($block['count']) || $block['count'] < 1 || $block['count'] > 30) {
                        throw new \InvalidArgumentException("$at count: must be a whole number from 1 to 30");
                    }
                    break;
                case 'signature':
                    self::onlyKeys($block, ['type', 'signers'], [], $at);
                    if (!is_array($block['signers']) || !array_is_list($block['signers']) || $block['signers'] === []) {
                        throw new \InvalidArgumentException("$at signers: must be a list of at least one signer");
                    }
                    foreach ($block['signers'] as $j => $signer) {
                        $texts[] = self::text($signer, "$at signers[$j]");
                    }
                    break;
            }
            foreach ($texts as $text) {
                foreach (Renderer::placeholders($text) as $placeholder) {
                    if (!Context::isKnown($placeholder)) {
                        throw new \InvalidArgumentException("$at: {{$placeholder}} is not a placeholder (see Context::KEYS)");
                    }
                    $uses[explode('.', $placeholder)[0]] = true;
                }
            }
            $blocks[] = $block;
        }
        return new Document(
            $id,
            self::text($data['title'], "$where title"),
            self::text($data['category'], "$where category"),
            self::text($data['about'], "$where about"),
            $blocks,
            array_values(array_intersect(array_keys($uses), ['patient', 'recipient'])),
            $source,
        );
    }

    /**
     * @param array<mixed>  $value
     * @param list<string>  $required
     * @param list<string>  $optional
     */
    private static function onlyKeys(array $value, array $required, array $optional, string $where): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new \InvalidArgumentException("$where: needs \"$key\"");
            }
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $required, true) && !in_array($key, $optional, true)) {
                throw new \InvalidArgumentException("$where: unknown field \"$key\"");
            }
        }
    }

    private static function text(mixed $value, string $where): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("$where: must be text");
        }
        return $value;
    }
}
