<?php

/**
 * What some documents must contain (data/requirements.json): the elements of a HIPAA
 * authorization, a privacy-notice acknowledgment, a sliding fee application and so on, each
 * found by its wording. The tests hold the shipped documents to them; a clinic's own version
 * of one of these documents is checked too, and anything not found is shown as a warning.
 *
 * Finding wording is a check, not a legal review: a clinic's version may say the same thing in
 * other words, which is why it's a warning and names what to look for.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Requirements
{
    /**
     * @param array<string, array{source: string, elements: list<array{name: string, cite: string, any?: list<string>, all?: list<string>, signature?: bool, forbid?: list<string>}>}> $documents
     */
    public function __construct(public array $documents)
    {
    }

    public static function shipped(): self
    {
        $file = dirname(__DIR__, 2) . '/data/requirements.json';
        $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['documents'] ?? null)) {
            throw new \InvalidArgumentException('data/requirements.json: needs "documents"');
        }
        foreach ($data['documents'] as $id => $rule) {
            if (!is_array($rule) || !is_string($rule['source'] ?? null) || !is_array($rule['elements'] ?? null) || $rule['elements'] === []) {
                throw new \InvalidArgumentException("data/requirements.json $id: needs a source and elements");
            }
            foreach ($rule['elements'] as $i => $e) {
                $checks = array_intersect(array_keys($e), ['any', 'all', 'signature', 'forbid']);
                if (!is_string($e['name'] ?? null) || !is_string($e['cite'] ?? null) || count($checks) !== 1) {
                    throw new \InvalidArgumentException("data/requirements.json $id elements[$i]: needs a name, a cite and one of any, all, signature or forbid");
                }
            }
        }
        /** @var array<string, array{source: string, elements: list<array{name: string, cite: string, any?: list<string>, all?: list<string>, signature?: bool, forbid?: list<string>}>}> $documents */
        $documents = $data['documents'];
        return new self($documents);
    }

    /** @return ?string what the document is held to, or null when nothing */
    public function source(string $id): ?string
    {
        return $this->documents[$id]['source'] ?? null;
    }

    /**
     * The elements a document is held to that its wording doesn't show.
     *
     * @return list<string> each as "name (cite)"
     */
    public function missing(Document $doc): array
    {
        $rule = $this->documents[$doc->id] ?? null;
        if ($rule === null) {
            return [];
        }
        $text = mb_strtolower(self::text($doc));
        $hasSignature = array_filter($doc->blocks, static fn(array $b): bool => $b['type'] === 'signature') !== [];
        $missing = [];
        foreach ($rule['elements'] as $e) {
            $ok = match (true) {
                isset($e['any']) => array_filter($e['any'], static fn(string $p): bool => str_contains($text, mb_strtolower($p))) !== [],
                isset($e['all']) => array_filter($e['all'], static fn(string $p): bool => !str_contains($text, mb_strtolower($p))) === [],
                isset($e['forbid']) => array_filter($e['forbid'], static fn(string $p): bool => str_contains($text, mb_strtolower($p))) === [],
                default => $hasSignature,
            };
            if (!$ok) {
                $missing[] = $e['name'] . ' (' . $e['cite'] . ')';
            }
        }
        return $missing;
    }

    /** All of a document's wording, with its placeholders as written. */
    public static function text(Document $doc): string
    {
        $parts = [$doc->title];
        foreach ($doc->blocks as $b) {
            foreach (['text', 'label', 'total'] as $key) {
                if (isset($b[$key]) && is_string($b[$key])) {
                    $parts[] = $b[$key];
                }
            }
            foreach (['items', 'signers', 'columns'] as $key) {
                foreach ((array)($b[$key] ?? []) as $item) {
                    $parts[] = (string)$item;
                }
            }
            foreach ((array)($b['fields'] ?? []) as $f) {
                $parts[] = (string)($f['label'] ?? '') . ': ' . (string)($f['value'] ?? '');
            }
        }
        return implode("\n", $parts);
    }
}
