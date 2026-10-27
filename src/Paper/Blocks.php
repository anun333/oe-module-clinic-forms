<?php

/**
 * The parts documents share, each written once in data/blocks/<id>.json: the patient's identification
 * lines, a consent's signature lines, the PHQ-2's two questions that open the PHQ-9. A document
 * includes one by its name, as one of its blocks or, for a scale's questions, as one of the scale's
 * items:
 *
 *   {"type": "include", "block": "consent-signature"}
 *   {"type": "scale", ..., "items": [{"include": "phq-2-questions"}, "Trouble falling or staying asleep..."]}
 *
 * DocumentLibrary puts the block's content in its place when it reads the document, so printing, the
 * checks, versions and fingerprints all see the whole document, the same as when it was written out.
 * A block file has an id (its file name), a title, what it is ("about"), a version (raised when its
 * wording changes, like a document's) and either "blocks" (document blocks, which may include other
 * blocks) or "rows" (a scale's questions). Skip logic belongs to the document, so a block's content
 * has no "id" or "show_if". A translation (data/blocks/<language>/<id>.json) has the same id, its own
 * title, about and wording, and the English block's version; a translated document includes the
 * block's translation.
 *
 * A clinic's own documents can include the module's blocks, and the clinic's own blocks in
 * sites/<site>/documents/clinic-forms/blocks/. A clinic block can't take the name of one of the
 * module's, since that would change the module's documents; one that does, or has a mistake, is left
 * out and reported on the page, like a clinic document.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Blocks
{
    public const FOLDER = 'blocks';

    /** @var array<string, self> the module's blocks, by folder: read once per request */
    private static array $shipped = [];

    /** @var ?array<string, array<string, array{data: array<string, mixed>, where: string, source: string}>> each file, by language then id */
    private ?array $files = null;
    /** @var array<string, Block> by "language/id" */
    private array $resolved = [];
    /** @var list<string> clinic files that were left out, and why */
    private array $problems = [];

    /**
     * @param ?string $localDir   the clinic's own blocks, or null for none
     * @param string  $localLabel how messages name the clinic's folder
     */
    public function __construct(
        private readonly string $dir,
        private readonly ?string $localDir = null,
        private readonly string $localLabel = 'sites/default/' . DocumentLibrary::LOCAL_FOLDER . '/' . self::FOLDER,
    ) {
    }

    /** The module's blocks. */
    public static function shipped(?string $root = null): self
    {
        $dir = ($root ?? dirname(__DIR__, 2)) . '/data/' . self::FOLDER;
        return self::$shipped[$dir] ??= new self($dir);
    }

    /** A block by its id, in a language (a translation's), or null when there isn't one. */
    public function get(string $id, string $language = 'en'): ?Block
    {
        return $this->block($id, $language, []);
    }

    /** @return array<string, Block> every block in a language, by id */
    public function all(string $language = 'en'): array
    {
        $out = [];
        foreach (array_keys($this->files()[$language] ?? []) as $id) {
            $out[$id] = $this->block($id, $language, []);
        }
        ksort($out);
        return array_filter($out);
    }

    /** @return list<string> the clinic's block files that were left out, each with its file and the mistake */
    public function problems(): array
    {
        foreach ($this->files() as $language => $files) {
            foreach (array_keys($files) as $id) {
                $this->block($id, $language, []);
            }
        }
        return $this->problems;
    }

    /**
     * A document's blocks with each include replaced by the block's content, and where each went: a
     * block's at the index of its first block, with how many; a scale's questions at the scale's index,
     * from its row "row". Blocks that aren't a list are returned as they are, for the document's own
     * check to refuse.
     *
     * @param list<string> $stack the blocks being put in place, to refuse one that includes itself
     * @return array{0: mixed, 1: list<array{block: string, version: int, title: string, about: string, source: string, at: int, count: int, row?: int}>}
     */
    public function expand(mixed $blocks, string $where, string $language = 'en', array $stack = []): array
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            return [$blocks, []];
        }
        $out = [];
        $includes = [];
        foreach ($blocks as $i => $block) {
            $at = "$where blocks[$i]";
            if (is_array($block) && ($block['type'] ?? null) === 'include') {
                $included = $this->included($block, ['type', 'block'], $at, $language, $stack);
                if ($included->blocks === null) {
                    throw new \InvalidArgumentException("$at: \"$included->id\" is a scale's questions; include it among a scale's items ({\"include\": \"$included->id\"})");
                }
                $includes[] = self::includeOf($included, count($out)) + ['count' => count($included->blocks)];
                array_push($out, ...$included->blocks);
                continue;
            }
            if (is_array($block) && ($block['type'] ?? null) === 'scale' && is_array($block['items'] ?? null) && array_is_list($block['items'])) {
                $items = [];
                foreach ($block['items'] as $j => $item) {
                    if (!is_array($item)) {
                        $items[] = $item;
                        continue;
                    }
                    $included = $this->included($item, ['include'], "$at items[$j]", $language, $stack);
                    if ($included->rows === null) {
                        throw new \InvalidArgumentException("$at items[$j]: \"$included->id\" is document blocks, not a scale's questions");
                    }
                    $includes[] = self::includeOf($included, count($out)) + ['count' => count($included->rows), 'row' => count($items)];
                    array_push($items, ...$included->rows);
                }
                $block['items'] = $items;
            }
            $out[] = $block;
        }
        return [$out, $includes];
    }

    /** @return array{block: string, version: int, title: string, about: string, source: string, at: int} */
    private static function includeOf(Block $block, int $at): array
    {
        return ['block' => $block->id, 'version' => $block->version, 'title' => $block->title, 'about' => $block->about, 'source' => $block->source, 'at' => $at];
    }

    /**
     * The block an include names.
     *
     * @param array<mixed> $ref
     * @param list<string> $keys the include's keys: type and block for a block, include for a scale's questions
     * @param list<string> $stack
     */
    private function included(array $ref, array $keys, string $at, string $language, array $stack): Block
    {
        $have = array_keys($ref);
        sort($have);
        $want = $keys;
        sort($want);
        if ($have !== $want) {
            throw new \InvalidArgumentException("$at: an include has only " . implode(' and ', array_map(static fn(string $k): string => "\"$k\"", $keys)));
        }
        $id = $ref[end($keys)];
        if (!is_string($id)) {
            throw new \InvalidArgumentException("$at: names a block by its id");
        }
        if (in_array($id, $stack, true)) {
            throw new \InvalidArgumentException("$at: block \"$id\" includes itself");
        }
        $block = $this->block($id, $language, $stack);
        if ($block === null) {
            throw new \InvalidArgumentException("$at: no block \"$id\"" . ($language !== 'en' ? " in language $language" : '') . ' (data/' . self::FOLDER . ')');
        }
        return $block;
    }

    /** @param list<string> $stack */
    private function block(string $id, string $language, array $stack): ?Block
    {
        $key = "$language/$id";
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }
        $file = $this->files()[$language][$id] ?? null;
        if ($file === null) {
            return null;
        }
        try {
            $block = $this->read($id, $language, $file, [...$stack, $id]);
        } catch (\InvalidArgumentException $e) {
            if ($file['source'] === 'shipped') {
                throw $e;
            }
            $this->problems[] = $e->getMessage();
            $block = null;
        }
        return $this->resolved[$key] = $block;
    }

    /**
     * @param array{data: array<string, mixed>, where: string, source: string} $file
     * @param list<string> $stack
     */
    private function read(string $id, string $language, array $file, array $stack): Block
    {
        ['data' => $data, 'where' => $where, 'source' => $source] = $file;
        $english = $language === 'en' ? null : $this->block($id, 'en', []);
        if ($language !== 'en' && $english === null) {
            throw new \InvalidArgumentException("$where: a translation of a block that does not exist");
        }
        $kind = array_key_exists('rows', $data) ? 'rows' : 'blocks';
        $allowed = $language === 'en' ? ['id', 'title', 'about', $kind, 'version'] : ['id', 'title', 'about', $kind];
        foreach (['id', 'title', 'about', $kind] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("$where: needs \"$required\"");
            }
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("$where: unknown field \"$key\"" . ($key === 'version' ? ' (a translation takes the English block\'s version)' : ''));
            }
        }
        if ($data['id'] !== $id) {
            throw new \InvalidArgumentException("$where id: must be the file's name, lower case words joined by hyphens");
        }
        foreach (['title', 'about'] as $key) {
            if (!is_string($data[$key]) || trim($data[$key]) === '') {
                throw new \InvalidArgumentException("$where $key: must be text");
            }
        }
        $version = $english?->version ?? ($data['version'] ?? 0);
        if (!is_int($version) || $version < 0 || ($source === 'shipped' && $version < 1)) {
            throw new \InvalidArgumentException("$where version: must be a whole number from 1, raised when the wording changes");
        }
        $rows = null;
        $blocks = null;
        if ($kind === 'rows') {
            $rows = $data['rows'];
            if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 40
                || array_filter($rows, static fn(mixed $r): bool => !is_string($r) || trim($r) === '') !== []) {
                throw new \InvalidArgumentException("$where rows: a list of 1 to 40 questions, each text");
            }
            if ($english !== null && count($rows) !== count($english->rows ?? [])) {
                throw new \InvalidArgumentException(sprintf('%s rows: has %d where the English has %d', $where, count($rows), count($english->rows ?? [])));
            }
        } else {
            if (!is_array($data['blocks']) || !array_is_list($data['blocks']) || $data['blocks'] === []) {
                throw new \InvalidArgumentException("$where blocks: must be a list of at least one block");
            }
            foreach ($data['blocks'] as $i => $b) {
                $types = [...Document::BLOCK_TYPES, 'include'];
                if (!is_array($b) || !in_array($b['type'] ?? null, $types, true)) {
                    throw new \InvalidArgumentException("$where blocks[$i]: type must be one of " . implode(', ', $types));
                }
                $fields = is_array($b['fields'] ?? null) ? $b['fields'] : [];
                if (isset($b['id']) || isset($b['show_if']) || array_filter($fields, static fn(mixed $f): bool => is_array($f) && (isset($f['id']) || isset($f['show_if']))) !== []) {
                    throw new \InvalidArgumentException("$where blocks[$i]: skip logic (id, show_if) belongs to the document, not a block");
                }
            }
            [$blocks] = $this->expand($data['blocks'], $where, $language, $stack);
            if ($english !== null && array_column($blocks, 'type') !== array_column($english->blocks ?? [], 'type')) {
                throw new \InvalidArgumentException("$where blocks: must be the English block's, in the same order (" . implode(', ', array_column($english->blocks ?? [], 'type')) . ')');
            }
        }
        return new Block($id, $data['title'], $data['about'], $blocks, $rows, $version, $language, $source);
    }

    /** @return array<string, array<string, array{data: array<string, mixed>, where: string, source: string}>> */
    private function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }
        $this->files = [];
        $layers = [[$this->dir, 'data/' . self::FOLDER, 'shipped']];
        if ($this->localDir !== null) {
            $layers[] = [$this->localDir, $this->localLabel, 'clinic'];
        }
        foreach ($layers as [$dir, $label, $source]) {
            $folders = ['en' => [$dir, $label]];
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (isset(Words::LANGUAGES[basename($sub)]) && basename($sub) !== 'en') {
                    $folders[basename($sub)] = [$sub, $label . '/' . basename($sub)];
                }
            }
            foreach ($folders as $language => [$folder, $folderLabel]) {
                foreach (glob($folder . '/*.json') ?: [] as $path) {
                    $id = basename($path, '.json');
                    $where = "$folderLabel/" . basename($path);
                    try {
                        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
                            throw new \InvalidArgumentException("$where: the file's name must be lower case words joined by hyphens");
                        }
                        if ($source === 'clinic' && isset($this->files[$language][$id])) {
                            throw new \InvalidArgumentException("$where: the module has a block named \"$id\"; give the clinic's another name, so the module's documents stay as they are");
                        }
                        try {
                            $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
                        }
                        if (!is_array($data) || array_is_list($data)) {
                            throw new \InvalidArgumentException("$where: must be an object");
                        }
                    } catch (\InvalidArgumentException $e) {
                        if ($source === 'shipped') {
                            throw $e;
                        }
                        $this->problems[] = $e->getMessage();
                        continue;
                    }
                    $this->files[$language][$id] = ['data' => $data, 'where' => $where, 'source' => $source];
                }
            }
        }
        return $this->files;
    }
}
