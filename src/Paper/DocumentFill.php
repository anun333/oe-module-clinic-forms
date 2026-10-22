<?php

/**
 * Lets other modules fill a document's named tables before it's printed or downloaded (Event\
 * DocumentFillEvent). A filled table carries "values" (its rows) and "note"; the renderers print
 * them, and drop the parenthetical instruction for filling by hand from its label.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

use OpenHealthcare\ClinicForms\Event\DocumentFillEvent;

final class DocumentFill
{
    /**
     * @param list<Document> $documents
     * @param callable(DocumentFillEvent): void $dispatch sends the event to OpenEMR's dispatcher
     * @return list<Document> the same documents, a filled table's block carrying its values
     */
    public static function apply(array $documents, callable $dispatch, ?int $pid = null): array
    {
        $out = [];
        foreach ($documents as $doc) {
            $ids = [];
            foreach ($doc->blocks as $block) {
                if ($block['type'] === 'table' && isset($block['id'])) {
                    $ids[] = (string)$block['id'];
                }
            }
            if ($ids === []) {
                $out[] = $doc;
                continue;
            }
            $event = new DocumentFillEvent($doc->id, $doc->language, $ids, $pid);
            $dispatch($event);
            $filled = $event->filled();
            if ($filled === []) {
                $out[] = $doc;
                continue;
            }
            $blocks = array_map(static function (array $b) use ($filled): array {
                $id = $b['type'] === 'table' ? (string)($b['id'] ?? '') : '';
                return isset($filled[$id]) ? $b + ['values' => $filled[$id]['rows'], 'note' => $filled[$id]['note']] : $b;
            }, $doc->blocks);
            $out[] = $doc->withBlocks($blocks);
        }
        return $out;
    }

    /** A filled table's label, without the parenthetical left for filling it by hand ("(the clinic fills in its own schedule)"). */
    public static function label(string $label): string
    {
        return trim((string)preg_replace('/\s*\([^()]*\)\s*$/u', '', $label));
    }
}
