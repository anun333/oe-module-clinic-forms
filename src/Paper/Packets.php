<?php

/**
 * Ready-made selections from data/packets.json: each an ordered list of doc:<id> and
 * layout:<form id> items. Document ids are checked against the library; layout ids are
 * checked against the site's forms when the packet is printed, since sites differ.
 *
 * A clinic's own packets go in sites/<site>/documents/clinic-forms/packets.json, in the same
 * format. One with the id of a shipped packet replaces it. A clinic file with a mistake is
 * left out and reported on the page, as clinic documents are.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Packets
{
    /**
     * @param list<array{id: string, title: string, items: list<string>, source?: string}> $packets
     * @param list<string>                                                                  $problems
     */
    public function __construct(public array $packets, public array $problems = [])
    {
    }

    /** The core packets and a pack's (the US pack unless another id, or null for none), the pack's replacing the core's by id. */
    public static function shipped(DocumentLibrary $library, ?string $pack = Pack::DEFAULT): self
    {
        $packets = self::read(dirname(__DIR__, 2) . '/data/packets.json', $library)->packets;
        $file = $pack === null ? null : Pack::get($pack)->dir . '/packets.json';
        if ($file !== null && is_file($file)) {
            $byId = array_column($packets, null, 'id');
            foreach (self::read($file, $library, "data/packs/$pack")->packets as $packet) {
                $byId[$packet['id']] = $packet;
            }
            $packets = array_values($byId);
        }
        return new self($packets);
    }

    /** The shipped packets plus the clinic's own, from the site directory (OE_SITE_DIR). */
    public static function forSite(DocumentLibrary $library, string $siteDir, ?string $pack = Pack::DEFAULT): self
    {
        $packets = self::shipped($library, $pack)->packets;
        $file = $siteDir . '/' . DocumentLibrary::LOCAL_FOLDER . '/packets.json';
        if (!is_file($file)) {
            return new self($packets);
        }
        try {
            $local = self::read($file, $library, 'sites/' . basename($siteDir) . '/' . DocumentLibrary::LOCAL_FOLDER)->packets;
        } catch (\InvalidArgumentException $e) {
            return new self($packets, [$e->getMessage()]);
        }
        $byId = array_column($packets, null, 'id');
        foreach ($local as $packet) {
            $byId[$packet['id']] = $packet + ['source' => 'clinic'];
        }
        return new self(array_values($byId));
    }

    public static function read(string $file, DocumentLibrary $library, string $label = 'data'): self
    {
        $where = $label . '/' . basename($file);
        try {
            $data = json_decode((string)file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data) || array_diff(array_keys($data), ['about', 'packets']) !== [] || !isset($data['packets']) || !is_array($data['packets']) || !array_is_list($data['packets'])) {
            throw new \InvalidArgumentException("$where: needs \"packets\", a list (\"about\" is the only other field)");
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
                if (str_starts_with($item, 'doc:') && $library->get(substr($item, 4)) === null && !$library->isWithdrawn(substr($item, 4))) {
                    throw new \InvalidArgumentException("$at items[$j]: no document " . substr($item, 4));
                }
            }
            // A retired document, or one the clinic hides, leaves the packet; a packet left empty goes.
            $items = array_values(array_filter($p['items'], static fn(string $item): bool => !str_starts_with($item, 'doc:') || !$library->isWithdrawn(substr($item, 4))));
            if ($items === []) {
                continue;
            }
            $packets[] = ['id' => $p['id'], 'title' => $p['title'], 'items' => $items];
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
