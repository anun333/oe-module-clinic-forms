<?php

/**
 * Checks that a translation keeps everything its English document has: the same blocks in the
 * same order, the same number of fields, items, lines, signers, columns and rows, the same
 * scores, and the same placeholders. Wording can't be compared across languages, but nothing
 * can be dropped or added without this noticing. Shipped translations must have no differences;
 * a clinic's own get them as warnings.
 *
 * Wording changes are caught another way: a translation records the fingerprint of the English
 * it was made from, and one whose English has changed since is out of date.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Translation
{
    /**
     * The English a translation was made from, in 16 hex digits: a hash of everything a translation
     * translates (id, title, category, about, blocks), so reformatting the file changes nothing but
     * any change of wording does. dev/clinic_forms_translate_es.py computes the same value.
     */
    public static function fingerprint(Document $english): string
    {
        $content = ['id' => $english->id, 'title' => $english->title, 'category' => $english->category, 'about' => $english->about, 'blocks' => $english->blocks];
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        return substr(hash('sha256', $json), 0, 16);
    }

    /** @return list<string> what differs, each naming the block */
    public static function differences(Document $english, Document $translation): array
    {
        $out = [];
        if (count($english->blocks) !== count($translation->blocks)) {
            $out[] = sprintf('has %d blocks where the English has %d', count($translation->blocks), count($english->blocks));
            return $out;
        }
        foreach ($english->blocks as $i => $e) {
            $t = $translation->blocks[$i];
            $at = "blocks[$i]";
            if ($e['type'] !== $t['type']) {
                $out[] = "$at is {$t['type']} where the English is {$e['type']}";
                continue;
            }
            $counts = static fn(array $b): array => [
                'fields' => count($b['fields'] ?? []),
                'items' => count($b['items'] ?? []),
                'signers' => count($b['signers'] ?? []),
                'columns' => count($b['columns'] ?? []),
                'count' => $b['count'] ?? null,
                'rows' => $b['rows'] ?? null,
                'scores' => $b['scores'] ?? null,
                'label' => isset($b['label']),
                'total' => isset($b['total']),
                'values' => array_map(static fn(array $f): bool => isset($f['value']), $b['fields'] ?? []),
            ];
            foreach (array_diff_assoc(array_map('json_encode', $counts($e)), array_map('json_encode', $counts($t))) as $what => $unused) {
                $out[] = "$at: its $what differ from the English";
            }
            $ep = self::placeholders($e);
            $tp = self::placeholders($t);
            if ($ep !== $tp) {
                $out[] = "$at uses placeholders " . implode(' ', $tp) . ' where the English uses ' . implode(' ', $ep);
            }
        }
        return $out;
    }

    /** @return list<string> the block's placeholders, sorted */
    private static function placeholders(array $block): array
    {
        $found = Renderer::placeholders((string)json_encode($block, JSON_UNESCAPED_UNICODE));
        sort($found);
        return $found;
    }
}
