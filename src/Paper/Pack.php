<?php

/**
 * A country pack: the documents, rules (requirements.json), packets, taglines and settings that
 * belong to one country's law and programmes, in data/packs/<id>/. The core documents in
 * data/documents work anywhere; a pack adds its own and may replace a core one by id, a region
 * (a state) may add or replace again, and the clinic's own folder has the last word.
 *
 *   data/packs/<id>/pack.json            name, jurisdiction (ISO 3166-1), the facility country names it answers to, paper size,
 *                                        layout forms it adds (the US pack's UDS short form)
 *   data/packs/<id>/documents/[<lang>/]  documents and their translations
 *   data/packs/<id>/requirements.json    legal elements, as data/requirements.json
 *   data/packs/<id>/packets.json         packets, replacing core packets by id
 *   data/packs/<id>/taglines.json        language assistance taglines (Section 1557 in the US)
 *   data/packs/<id>/regions.json         regions (states): name and the languages the tagline rule counts
 *   data/packs/<id>/regions/<code>/documents/  a region's own documents, when it has any
 *
 * Which pack a site uses: "country" in the clinic's settings.json (Settings: a pack id, or "none"), else the
 * country of its first facility, else the US pack when that country is blank, which is how a default
 * OpenEMR install leaves it and how Clinic Forms behaved before packs.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Pack
{
    public const DEFAULT = 'us';

    /**
     * @param list<string> $countries facility country values that choose this pack, compared without regard to case
     * @param list<string> $layouts   extra layout forms the pack offers (UDS)
     * @param array<string, array{name: string, languages: list<array{census: string, code: ?string, people: int}>}> $regions by code
     * @param string $jurisdiction the ISO 3166-1 code of the country; a region's ISO 3166-2 code is "<jurisdiction>-<region>"
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $dir,
        public string $jurisdiction = '',
        public array $countries = [],
        public string $paper = 'letter',
        public array $layouts = [],
        public array $regions = [],
    ) {
    }

    /** The packs shipped in data/packs, by id. @return array<string, self> */
    public static function all(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2) . '/data/packs';
        $packs = [];
        foreach (glob($root . '/*/pack.json') ?: [] as $file) {
            $pack = self::read(dirname($file));
            $packs[$pack->id] = $pack;
        }
        ksort($packs);
        return $packs;
    }

    /** A shipped pack by id. */
    public static function get(string $id): self
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException("no pack \"$id\" in data/packs");
    }

    public static function read(string $dir): self
    {
        $id = basename($dir);
        $where = "data/packs/$id/pack.json";
        try {
            $data = json_decode((string)file_get_contents($dir . '/pack.json'), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data) || !is_string($data['name'] ?? null) || preg_match('/^[a-z]{2}$/', $id) !== 1
            || ($data['jurisdiction'] ?? null) !== strtoupper($id)) {
            throw new \InvalidArgumentException("$where: needs a name and \"jurisdiction\" (the ISO 3166-1 code, " . strtoupper($id) . "), in a folder named by that code in lower case");
        }
        if (array_diff(array_keys($data), ['about', 'name', 'jurisdiction', 'countries', 'paper', 'layouts']) !== []) {
            throw new \InvalidArgumentException("$where: unknown field");
        }
        $paper = $data['paper'] ?? 'a4';
        if (!in_array($paper, ['letter', 'a4'], true)) {
            throw new \InvalidArgumentException("$where paper: letter or a4");
        }
        $strings = static fn(mixed $v): bool => is_array($v) && array_is_list($v) && array_filter($v, static fn(mixed $s): bool => !is_string($s)) === [];
        if (!$strings($data['countries'] ?? []) || !$strings($data['layouts'] ?? [])) {
            throw new \InvalidArgumentException("$where: countries and layouts are lists of text");
        }
        $regions = [];
        if (is_file($dir . '/regions.json')) {
            $r = json_decode((string)file_get_contents($dir . '/regions.json'), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($r) || !is_array($r['regions'] ?? null)) {
                throw new \InvalidArgumentException("data/packs/$id/regions.json: needs \"regions\"");
            }
            /** @var array<string, array{name: string, languages: list<array{census: string, code: ?string, people: int}>}> $regions */
            $regions = $r['regions'];
        }
        return new self($id, $data['name'], $dir, $data['jurisdiction'], $data['countries'] ?? [], $paper, $data['layouts'] ?? [], $regions);
    }

    /**
     * The pack a site uses, and a note for the page when it isn't the obvious one.
     *
     * @param ?string $setting  "country" from the clinic's settings.json: a pack id, "none", or null when unset
     * @param string  $country  the first facility's country, as typed in Administration > Facilities
     * @return array{0: ?self, 1: list<string>}
     */
    public static function choose(?string $setting, string $country, ?string $root = null): array
    {
        $packs = self::all($root);
        if ($setting !== null) {
            if ($setting === 'none') {
                return [null, []];
            }
            if (isset($packs[$setting])) {
                return [$packs[$setting], []];
            }
            $notes = ["settings.json: there is no \"$setting\" pack (" . implode(', ', array_keys($packs)) . ' or none); choosing from the facility country instead'];
        }
        $notes ??= [];
        $country = trim($country);
        if ($country === '') {
            return [$packs[self::DEFAULT] ?? null, $notes];
        }
        foreach ($packs as $pack) {
            foreach ($pack->countries as $name) {
                if (strcasecmp($name, $country) === 0 || strcasecmp($pack->id, $country) === 0) {
                    return [$pack, $notes];
                }
            }
        }
        $notes[] = "The facility country is \"$country\", and Clinic Forms has no pack for it yet, so only the documents that apply anywhere are offered. "
            . 'To use a pack anyway, put {"country": "' . self::DEFAULT . '"} in settings.json in the Clinic Forms folder.';
        return [null, $notes];
    }

    /**
     * The pack a site uses: its settings.json in the Clinic Forms folder, then its first facility's
     * country (Pack::choose).
     *
     * @return array{0: ?self, 1: list<string>} the pack and notes for the page
     */
    public static function forSite(string $siteDir, string $firstFacilityCountry): array
    {
        $settings = Settings::forSite($siteDir);
        [$pack, $notes] = self::choose($settings->country, $firstFacilityCountry);
        return [$pack, array_merge($settings->problems, $notes)];
    }

    /** The region a facility's state names: its code ("TX" or "Texas" give TX), or null. */
    public function region(string $state): ?string
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }
        foreach ($this->regions as $code => $region) {
            if (strcasecmp($code, $state) === 0 || strcasecmp($region['name'], $state) === 0) {
                return $code;
            }
        }
        return null;
    }

    /** A region's ISO 3166-2 code (US-TX), or null. */
    public function isoRegion(?string $region): ?string
    {
        return $region === null ? null : $this->jurisdiction . '-' . $region;
    }

    /** A region's own documents folder, or null when it has none. */
    public function regionDir(?string $region): ?string
    {
        return $region !== null && is_dir("$this->dir/regions/$region/documents") ? "$this->dir/regions/$region/documents" : null;
    }

    /**
     * Which of a region's tagline languages the taglines don't cover.
     *
     * @param list<string> $have the tagline language codes there are (en, es, zh-Hant…)
     * @return array{missing: list<string>, groups: list<string>} languages without a tagline, by Census name;
     *         and Census groups, for which HHS's list names the language
     */
    public function taglineGaps(string $region, array $have): array
    {
        $primary = array_map(static fn(string $c): string => strtolower(explode('-', $c)[0]), $have);
        $missing = [];
        $groups = [];
        foreach ($this->regions[$region]['languages'] ?? [] as $lang) {
            if ($lang['code'] === null) {
                $groups[] = $lang['census'];
            } elseif (!in_array(strtolower($lang['code']), $primary, true)) {
                $missing[] = $lang['census'];
            }
        }
        return ['missing' => $missing, 'groups' => $groups];
    }
}
