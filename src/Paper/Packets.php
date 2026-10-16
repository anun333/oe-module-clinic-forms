<?php

/**
 * Ready-made selections from data/packets.json: each an ordered list of doc:<id> and
 * layout:<form id> items. Document ids are checked against the library; layout ids are
 * checked against the site's forms when the packet is printed, since sites differ.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Packets
{
    /** @param list<array{id: string, title: string, items: list<string>}> $packets */
    public function __construct(public array $packets)
    {
    }

    public static function shipped(DocumentLibrary $library): self
    {
        return self::read(dirname(__DIR__, 2) . '/data/packets.json', $library);
    }

    public static function read(string $file, DocumentLibrary $library): self
    {
        $where = 'data/' . basename($file);
        try {
            $data = json_decode((string)file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data) || !isset($data['packets']) || !is_array($data['packets']) || !array_is_list($data['packets'])) {
            throw new \InvalidArgumentException("$where: needs a list of packets");
        }
        $packets = [];
        $seen = [];
        foreach ($data['packets'] as $i => $p) {
            $at = "$where packets[$i]";
            if (!is_array($p) || array_diff(array_keys($p), ['id', 'title', 'items']) !== [] || !isset($p['id'], $p['title'], $p['items'])) {
                throw new \InvalidArgumentException("$at: needs exactly id, title and items");
            }
            if (!is_string($p['id']) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $p['id']) !== 1 || isset($seen[$p['id']])) {
                throw new \InvalidArgumentException("$at id: must be a new id of lower case words joined by hyphens");
            }
            $seen[$p['id']] = true;
            if (!is_string($p['title']) || trim($p['title']) === '' || !is_array($p['items']) || !array_is_list($p['items']) || $p['items'] === []) {
                throw new \InvalidArgumentException("$at: needs a title and at least one item");
            }
            foreach ($p['items'] as $j => $item) {
                if (!is_string($item) || !self::isItem($item)) {
                    throw new \InvalidArgumentException("$at items[$j]: must be doc:<id> or layout:<form id>");
                }
                if (str_starts_with($item, 'doc:') && $library->get(substr($item, 4)) === null) {
                    throw new \InvalidArgumentException("$at items[$j]: no document " . substr($item, 4));
                }
            }
            $packets[] = ['id' => $p['id'], 'title' => $p['title'], 'items' => $p['items']];
        }
        return new self($packets);
    }

    /** doc:<document id> or layout:<a form LayoutForm can print>. */
    public static function isItem(string $item): bool
    {
        return preg_match('/^doc:[a-z0-9]+(-[a-z0-9]+)*$/', $item) === 1
            || (str_starts_with($item, 'layout:') && LayoutForm::isPrintable(substr($item, 7)));
    }
}
