<?php

/**
 * The printable documents in data/documents/*.json, read strictly: a document with an unknown
 * field, an unknown block type, or a placeholder that doesn't exist is refused with its file
 * and place named, so a mistake in the library can't print a broken page.
 *
 * The core documents work anywhere. A country pack (data/packs/<id>/documents, see Pack) adds the
 * documents of that country's law and programmes and may replace a core one by id; a region's
 * folder may do the same again.
 *
 * A clinic's own documents go in sites/<site>/documents/clinic-forms/, in the same format, so
 * they survive module updates. One with the id of a shipped document replaces it (a clinic's
 * edited consent, say). A clinic file with a mistake is left out and reported on the page;
 * the shipped files are checked by the tests, so a mistake there stops the page instead.
 *
 * A translation whose English has changed since it was made (its "translates" fingerprint no
 * longer matches) is out of date: the document prints in English and outOfDate() names it.
 * Shipped translations must carry the fingerprint; a clinic's own are checked when they do.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class DocumentLibrary
{
    /** The code systems a coded checklist's answers can be in: LOINC, SNOMED CT, and HL7's null flavors (an answer such as unknown). */
    public const ANSWER_SYSTEMS = [
        Loinc::SYSTEM => 'LOINC',
        'http://snomed.info/sct' => 'SNOMED CT',
        'http://terminology.hl7.org/CodeSystem/v3-NullFlavor' => 'HL7 null flavor',
    ];

    /** A block's keys for the screen only, checked by conditions(): printing ignores them. */
    private const BEHAVIOUR = ['id' => true, 'show_if' => true];

    public const LOCAL_FOLDER = 'documents/clinic-forms';

    /** @var array<string, Document> by id, in title order within each category */
    private array $documents = [];
    /** @var list<string> clinic files that were left out, and why */
    private array $problems = [];
    /** @var array<string, string> translations whose English has changed, by document id: the file */
    private array $outOfDate = [];
    /** @var array<string, string> documents not offered, by id: retired, or hidden by the clinic */
    private array $withdrawn = [];
    /** @var list<array{id: string, title: string, version: int, fingerprint: string}> clinic versions that don't say which of the module's they were copied from */
    private array $unstated = [];
    private ?Blocks $blocks = null;

    /**
     * @param ?string $localDir   the clinic's own documents, or null for none
     * @param string  $localLabel how messages name the clinic's folder
     * @param string  $language   the language to print in: a document with a translation in
     *                            <dir>/<language>/ uses it, any other stays in English
     * @param list<array{0: string, 1: string}> $packs folders between the core and the clinic's,
     *                            each [folder, how messages name it]: a country pack's documents, then a region's
     * @param list<string> $hide  documents the clinic doesn't offer (settings.json "hide")
     */
    public function __construct(
        private readonly string $dir,
        private readonly ?string $localDir = null,
        private readonly string $localLabel = 'sites/default/' . self::LOCAL_FOLDER,
        private readonly string $language = 'en',
        private readonly array $packs = [],
        private readonly array $hide = [],
    ) {
    }

    /**
     * The shipped documents: the core, and a pack's (the US pack unless another id, or null for
     * the core alone).
     */
    public static function shipped(string $language = 'en', ?string $pack = Pack::DEFAULT): self
    {
        $packs = $pack === null ? [] : self::packLayers(Pack::get($pack), null);
        return new self(dirname(__DIR__, 2) . '/data/documents', null, 'sites/default/' . self::LOCAL_FOLDER, $language, $packs);
    }

    /**
     * The core documents, the pack's and its region's, then the clinic's own, from the site
     * directory (OE_SITE_DIR). Each replaces the one before with the same id.
     */
    public static function forSite(string $siteDir, string $language = 'en', ?string $pack = Pack::DEFAULT, ?string $region = null): self
    {
        return new self(
            dirname(__DIR__, 2) . '/data/documents',
            $siteDir . '/' . self::LOCAL_FOLDER,
            'sites/' . basename($siteDir) . '/' . self::LOCAL_FOLDER,
            $language,
            $pack === null ? [] : self::packLayers(Pack::get($pack), $region),
            Settings::forSite($siteDir)->hide,
        );
    }

    /** @return list<array{0: string, 1: string}> */
    private static function packLayers(Pack $pack, ?string $region): array
    {
        $layers = [[$pack->dir . '/documents', "data/packs/$pack->id/documents"]];
        $regionDir = $pack->regionDir($region);
        if ($regionDir !== null) {
            $layers[] = [$regionDir, "data/packs/$pack->id/regions/$region/documents"];
        }
        return $layers;
    }

    /** The blocks this library's documents can include: the module's, and the clinic's own (sites/<site>/documents/clinic-forms/blocks). */
    public function blocks(): Blocks
    {
        if ($this->blocks === null) {
            $this->blocks = $this->localDir === null
                ? Blocks::shipped()
                : new Blocks(dirname(__DIR__, 2) . '/data/' . Blocks::FOLDER, $this->localDir . '/' . Blocks::FOLDER, $this->localLabel . '/' . Blocks::FOLDER);
        }
        return $this->blocks;
    }

    /** @return array<string, Document> */
    public function all(): array
    {
        if ($this->documents === []) {
            /** @var list<array{0: string, 1: string, 2: string}> $layers folder, label, source */
            $layers = [[$this->dir, 'data/documents', 'shipped']];
            foreach ($this->packs as [$dir, $label]) {
                $layers[] = [$dir, $label, 'shipped'];
            }
            if ($this->localDir !== null) {
                $layers[] = [$this->localDir, $this->localLabel, 'clinic'];
            }
            $docs = [];
            $files = [];
            foreach ($layers as [$dir, $label, $source]) {
                foreach (glob($dir . '/*.json') ?: [] as $file) {
                    try {
                        $doc = self::read($file, $label, $source, null, 'en', $this->blocks());
                    } catch (\InvalidArgumentException $e) {
                        if ($source === 'shipped') {
                            throw $e;
                        }
                        $this->problems[] = $e->getMessage();
                        continue;
                    }
                    $shipped = $docs[$doc->id] ?? null;
                    if ($source === 'clinic' && $shipped !== null && $shipped->source === 'shipped') {
                        $this->checkBasis($doc, $shipped, "$label/" . basename($file), $files[$doc->id]);
                    }
                    $docs[$doc->id] = $doc;
                    $files[$doc->id] = "$label/" . basename($file);
                }
            }
            if ($this->language !== 'en') {
                foreach ($layers as [$layerDir, $layerLabel, $source]) {
                    $dir = $layerDir . '/' . $this->language;
                    $label = $layerLabel . '/' . $this->language;
                    foreach (glob($dir . '/*.json') ?: [] as $file) {
                        $english = $docs[basename($file, '.json')] ?? null;
                        try {
                            if ($english === null) {
                                throw new \InvalidArgumentException("$label/" . basename($file) . ': a translation of a document that does not exist');
                            }
                            $doc = self::read($file, $label, $source, $english, $this->language, $this->blocks());
                            if ($doc->translates !== Translation::fingerprint($english) && ($doc->translates !== null || $source === 'shipped')) {
                                $this->outOfDate[$doc->id] = "$label/" . basename($file);
                                continue;
                            }
                        } catch (\InvalidArgumentException $e) {
                            if ($source === 'shipped') {
                                throw $e;
                            }
                            $this->problems[] = $e->getMessage();
                            continue;
                        }
                        foreach (Translation::differences($english, $doc) as $difference) {
                            $this->problems[] = "$label/" . basename($file) . ': ' . $difference;
                        }
                        $docs[$doc->id] = $doc;
                        unset($this->outOfDate[$doc->id]); // a later, current translation replaces an out-of-date earlier one
                    }
                }
            }
            foreach ($docs as $id => $doc) {
                if ($doc->status === 'retired') {
                    $this->withdrawn[$id] = 'retired';
                }
            }
            foreach ($this->hide as $id) {
                if (isset($docs[$id])) {
                    $this->withdrawn[$id] = 'hidden';
                } else {
                    $this->problems[] = "settings.json: \"hide\" names $id, which is not a document";
                }
            }
            $docs = array_diff_key($docs, $this->withdrawn);
            uasort($docs, static fn(Document $a, Document $b): int => [$a->category, $a->title] <=> [$b->category, $b->title]);
            $this->documents = $docs;
        }
        return $this->documents;
    }

    /** @return list<string> the clinic's files that were left out, each with its file and the mistake */
    public function problems(): array
    {
        $this->all();
        return array_merge($this->blocks()->problems(), $this->problems);
    }

    /**
     * Translations left out because their English changed after they were made (or, shipped,
     * because they don't say which English they were made from). Those documents print in English.
     *
     * @return array<string, string> the file, by document id
     */
    public function outOfDate(): array
    {
        $this->all();
        return $this->outOfDate;
    }

    /**
     * A clinic's version replacing a shipped document: say when the shipped one has changed since
     * it was copied ("based_on" no longer matches), and remember those that don't say.
     */
    private function checkBasis(Document $clinic, Document $shipped, string $file, string $shippedFile): void
    {
        $current = Translation::fingerprint($shipped);
        if ($clinic->basedOn === null) {
            $this->unstated[] = ['id' => $shipped->id, 'title' => $shipped->title, 'version' => $shipped->version, 'fingerprint' => $current];
        } elseif ($clinic->basedOn !== $current) {
            $this->problems[] = "$file: copied from an earlier version of the module's \"$shipped->title\", which is now version $shipped->version. "
                . "Compare yours with the module's ($shippedFile in the Clinic Forms module folder), then set \"based_on\": \"$current\".";
        }
    }

    /** Documents not offered: retired by the module, or hidden in settings.json. @return array<string, string> */
    public function withdrawn(): array
    {
        $this->all();
        return $this->withdrawn;
    }

    public function isWithdrawn(string $id): bool
    {
        return isset($this->withdrawn()[$id]);
    }

    /**
     * The clinic's versions of shipped documents that don't say which of the module's versions they
     * were copied from, with the value to put in "based_on".
     *
     * @return list<array{id: string, title: string, version: int, fingerprint: string}>
     */
    public function unstatedBasis(): array
    {
        $this->all();
        return $this->unstated;
    }

    public function get(string $id): ?Document
    {
        return $this->all()[$id] ?? null;
    }

    /** Whether a document has a translation into the library's language. */
    public function isTranslated(string $id): bool
    {
        return ($this->all()[$id] ?? null)?->language === $this->language;
    }

    /**
     * @param string    $source   'shipped' or 'clinic'
     * @param ?Document $english  for a translation, the English document it translates
     * @param ?Blocks   $blocks   the blocks it can include: the module's when null
     */
    public static function read(string $file, string $label = 'data/documents', string $source = 'shipped', ?Document $english = null, string $language = 'en', ?Blocks $blocks = null): Document
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
        // Shared blocks put in place first, so everything below checks the whole document.
        [$data['blocks'], $includes] = ($blocks ?? Blocks::shipped())->expand($data['blocks'] ?? null, $where, $language);
        $translates = null;
        $machineAssisted = false;
        if ($english !== null) {
            // A translation takes its audience, code and validated flag from the English document.
            self::onlyKeys($data, ['id', 'title', 'category', 'about', 'blocks'], ['translates', 'machine_assisted'], $where);
            // Made with machine help and not yet reviewed by a qualified translator: staff see it marked.
            if (isset($data['machine_assisted']) && !is_bool($data['machine_assisted'])) {
                throw new \InvalidArgumentException("$where machine_assisted: must be true or false");
            }
            $machineAssisted = ($data['machine_assisted'] ?? false) === true;
            unset($data['machine_assisted']);
            // A validated instrument is printed in its publisher's own translation, never one of ours.
            if ($machineAssisted && $english->validated && $source === 'shipped') {
                throw new \InvalidArgumentException("$where machine_assisted: a validated instrument ships only in its publisher's translation");
            }
            // ...and its version, status, source and licence.
            $data['status'] = $english->status;
            if ($english->version > 0) {
                $data['version'] = $english->version;
            }
            if ($english->origin !== null) {
                $data['source'] = $english->origin;
            }
            if ($english->licence !== null) {
                $data['licence'] = $english->licence;
            }
            if (isset($data['translates']) && (!is_string($data['translates']) || preg_match('/^[0-9a-f]{16}$/', $data['translates']) !== 1)) {
                throw new \InvalidArgumentException("$where translates: must be the 16-digit fingerprint of the English it translates");
            }
            $translates = $data['translates'] ?? null;
            unset($data['translates']);
            $data['audience'] = $english->audience;
            if ($english->code !== null) {
                $data['code'] = $english->code;
            }
            $data['validated'] = $english->validated;
            $data['poster'] = $english->poster;
            $data['anonymous'] = $english->anonymous;
        }
        self::onlyKeys($data, ['id', 'title', 'category', 'about', 'audience', 'blocks'], ['code', 'validated', 'poster', 'anonymous', 'version', 'status', 'source', 'licence', 'based_on'], $where);
        if (isset($data['anonymous']) && !is_bool($data['anonymous'])) {
            throw new \InvalidArgumentException("$where anonymous: must be true or false");
        }
        [$version, $status, $origin, $licence, $basedOn] = self::metadata($data, $where);
        if (isset($data['poster']) && !is_bool($data['poster'])) {
            throw new \InvalidArgumentException("$where poster: must be true or false");
        }
        if (!is_string($data['audience']) || !in_array($data['audience'], Document::AUDIENCES, true)) {
            throw new \InvalidArgumentException("$where audience: must be one of " . implode(', ', Document::AUDIENCES));
        }
        $code = null;
        if (isset($data['code'])) {
            if (!is_array($data['code'])) {
                throw new \InvalidArgumentException("$where code: must be an object with system, code and display");
            }
            self::onlyKeys($data['code'], ['system', 'code', 'display'], [], "$where code");
            if ($data['code']['system'] !== Loinc::SYSTEM || !is_string($data['code']['code']) || !Loinc::isValid($data['code']['code'])) {
                throw new \InvalidArgumentException("$where code: must be a LOINC code (system " . Loinc::SYSTEM . ") with a correct check digit");
            }
            $code = ['system' => Loinc::SYSTEM, 'code' => $data['code']['code'], 'display' => self::text($data['code']['display'], "$where code display")];
        }
        if (isset($data['validated']) && !is_bool($data['validated'])) {
            throw new \InvalidArgumentException("$where validated: must be true or false");
        }
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
            // id and show_if (skip logic on screen) are checked with the whole document, below.
            $checked = $block;
            $block = array_diff_key($block, self::BEHAVIOUR);
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
                        self::onlyKeys($field, ['label'], ['value', 'id', 'show_if', 'number'], "$at fields[$j]");
                        if (isset($field['number'])) {
                            $range = $field['number'];
                            if (!is_array($range) || !array_is_list($range) || count($range) !== 2 || !is_int($range[0]) || !is_int($range[1]) || $range[0] >= $range[1]) {
                                throw new \InvalidArgumentException("$at fields[$j] number: the smallest and largest whole number it takes, [min, max]");
                            }
                        }
                        $texts[] = self::text($field['label'], "$at fields[$j] label");
                        if (isset($field['value'])) {
                            $texts[] = self::text($field['value'], "$at fields[$j] value");
                        }
                    }
                    break;
                case 'checklist':
                    self::onlyKeys($block, ['type', 'items'], ['label', 'code', 'answer_codes', 'single', 'names'], $at);
                    // Items that are names (peoples, places, languages): reading-level formulas read them as hard words.
                    if (isset($block['names']) && $block['names'] !== true) {
                        throw new \InvalidArgumentException("$at names: true when the items are names, or leave it out");
                    }
                    if (isset($block['label'])) {
                        $texts[] = self::text($block['label'], "$at label");
                    }
                    if (!is_array($block['items']) || !array_is_list($block['items']) || $block['items'] === []) {
                        throw new \InvalidArgumentException("$at items: must be a list of at least one item");
                    }
                    foreach ($block['items'] as $j => $item) {
                        $texts[] = self::text($item, "$at items[$j]");
                    }
                    self::checkCodes($block, $at);
                    break;
                case 'lines':
                    self::onlyKeys($block, ['type', 'label', 'count'], [], $at);
                    $texts[] = self::text($block['label'], "$at label");
                    if (!is_int($block['count']) || $block['count'] < 1 || $block['count'] > 30) {
                        throw new \InvalidArgumentException("$at count: must be a whole number from 1 to 30");
                    }
                    break;
                case 'scale':
                    self::onlyKeys($block, ['type', 'label', 'columns', 'items'], ['scores', 'item_scores', 'total', 'start', 'item_sex'], $at);
                    // The first row's number, where the instrument numbers on from an earlier part (CRAFFT's 4 to 9).
                    if (isset($block['start']) && (!is_int($block['start']) || $block['start'] < 1 || $block['start'] > 99)) {
                        throw new \InvalidArgumentException("$at start: the first row's number, a whole number from 1 to 99");
                    }
                    $texts[] = self::text($block['label'], "$at label");
                    $texts = array_merge($texts, self::texts($block['columns'], "$at columns", 2, 8), self::texts($block['items'], "$at items", 1, 40));
                    // Rows asked of one sex only (TAPS-1's questions 2 and 3): skip logic on screen, like show_if.
                    if (isset($block['item_sex']) && (!is_array($block['item_sex']) || !array_is_list($block['item_sex'])
                        || count($block['item_sex']) !== count($block['items'])
                        || array_filter($block['item_sex'], static fn(mixed $s): bool => $s !== null && $s !== 'male' && $s !== 'female') !== [])) {
                        throw new \InvalidArgumentException("$at item_sex: one entry for each item, each null, \"male\" or \"female\"");
                    }
                    if (isset($block['scores'])) {
                        if (!is_array($block['scores']) || !array_is_list($block['scores']) || count($block['scores']) !== count($block['columns'])
                            || array_filter($block['scores'], static fn(mixed $s): bool => !is_int($s)) !== []) {
                            throw new \InvalidArgumentException("$at scores: must be a whole number for each column");
                        }
                    }
                    // Points per question, for a scale whose questions score differently (reverse-scored items).
                    if (isset($block['item_scores'])) {
                        $rowsOk = is_array($block['item_scores']) && array_is_list($block['item_scores']) && count($block['item_scores']) === count($block['items'])
                            && array_filter($block['item_scores'], static fn(mixed $r): bool => !is_array($r) || !array_is_list($r) || count($r) !== count($block['columns'])
                                || array_filter($r, static fn(mixed $s): bool => !is_int($s)) !== []) === [];
                        if (!$rowsOk || isset($block['scores'])) {
                            throw new \InvalidArgumentException("$at item_scores: a whole number for each column, for each item (and no scores beside it)");
                        }
                    }
                    if (isset($block['total'])) {
                        $texts[] = self::text($block['total'], "$at total");
                    }
                    break;
                case 'table':
                    self::onlyKeys($block, ['type', 'label', 'columns', 'rows'], [], $at);
                    $texts[] = self::text($block['label'], "$at label");
                    $texts = array_merge($texts, self::texts($block['columns'], "$at columns", 1, 8));
                    if (!is_int($block['rows']) || $block['rows'] < 1 || $block['rows'] > 30) {
                        throw new \InvalidArgumentException("$at rows: must be a whole number from 1 to 30");
                    }
                    break;
                case 'taglines':
                    self::onlyKeys($block, ['type'], [], $at);
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
            $blocks[] = $checked;
        }
        self::conditions($blocks, $where);
        // A coded question's code is its linkId on screen, so each code once in a document.
        $questionCodes = array_values(array_filter(array_map(static fn(array $b): ?string => $b['code']['code'] ?? null, $blocks)));
        if (count($questionCodes) !== count(array_unique($questionCodes))) {
            throw new \InvalidArgumentException("$where blocks: each coded question's code once (" . implode(', ', array_unique(array_diff_assoc($questionCodes, array_unique($questionCodes)))) . ' twice)');
        }
        return new Document(
            $id,
            self::text($data['title'], "$where title"),
            self::text($data['category'], "$where category"),
            self::text($data['about'], "$where about"),
            $blocks,
            array_values(array_intersect(array_keys($uses), ['patient', 'recipient'])),
            $source,
            $data['audience'],
            $code,
            ($data['validated'] ?? false) === true,
            $language,
            ($data['poster'] ?? false) === true,
            $translates,
            $version,
            $status,
            $origin,
            $licence,
            $basedOn,
            ($data['anonymous'] ?? false) === true,
            $machineAssisted,
            $includes,
        );
    }

    /**
     * version, status, source, licence and based_on, checked.
     *
     * @param array<mixed> $data
     * @return array{0: int, 1: string, 2: ?array{kind: string, name: string, url?: string}, 3: ?array{holder: string, terms: string, permission?: string}, 4: ?string}
     */
    private static function metadata(array $data, string $where): array
    {
        $version = $data['version'] ?? 0;
        if (!is_int($version) || $version < 0 || (array_key_exists('version', $data) && $version === 0)) {
            throw new \InvalidArgumentException("$where version: must be a whole number from 1, raised when the wording changes");
        }
        $status = $data['status'] ?? 'active';
        if (!is_string($status) || !in_array($status, Document::STATUSES, true)) {
            throw new \InvalidArgumentException("$where status: must be one of " . implode(', ', Document::STATUSES));
        }
        $origin = null;
        if (isset($data['source'])) {
            if (!is_array($data['source'])) {
                throw new \InvalidArgumentException("$where source: must be an object with kind and name");
            }
            self::onlyKeys($data['source'], ['kind', 'name'], ['url'], "$where source");
            if (!in_array($data['source']['kind'], Document::SOURCE_KINDS, true)) {
                throw new \InvalidArgumentException("$where source kind: must be one of " . implode(', ', Document::SOURCE_KINDS));
            }
            $origin = ['kind' => (string)$data['source']['kind'], 'name' => self::text($data['source']['name'], "$where source name")];
            if (isset($data['source']['url'])) {
                $url = self::text($data['source']['url'], "$where source url");
                if (!str_starts_with($url, 'https://')) {
                    throw new \InvalidArgumentException("$where source url: must start with https://");
                }
                $origin['url'] = $url;
            }
        }
        $licence = null;
        if (isset($data['licence'])) {
            if (!is_array($data['licence'])) {
                throw new \InvalidArgumentException("$where licence: must be an object with holder and terms");
            }
            self::onlyKeys($data['licence'], ['holder', 'terms'], ['permission'], "$where licence");
            $licence = ['holder' => self::text($data['licence']['holder'], "$where licence holder"), 'terms' => self::text($data['licence']['terms'], "$where licence terms")];
            if (isset($data['licence']['permission'])) {
                $licence['permission'] = self::text($data['licence']['permission'], "$where licence permission");
            }
        }
        $basedOn = $data['based_on'] ?? null;
        if ($basedOn !== null && (!is_string($basedOn) || preg_match('/^[0-9a-f]{16}$/', $basedOn) !== 1)) {
            throw new \InvalidArgumentException("$where based_on: must be the 16-digit fingerprint of the module's version it was copied from");
        }
        return [$version, $status, $origin, $licence, $basedOn];
    }

    /**
     * @param array<mixed>  $value
     * @param list<string>  $required
     * @param list<string>  $optional
     */
    /**
     * A labelled checklist can say what it asks in code: "code", the question's LOINC code;
     * "answer_codes", one code for each item, in order (LOINC answers, SNOMED CT or HL7's null
     * flavors, each but LOINC's with the code system's own "display"); and "single": true when one
     * answer is chosen. The FHIR export carries them, so a saved response holds the codes a report
     * reads (FPAR 2.0's, for the Title X visit record). Codes aren't wording: they change no version,
     * and a translation copies them.
     *
     * @param array<string, mixed> $block
     */
    private static function checkCodes(array $block, string $at): void
    {
        if (array_key_exists('single', $block) && $block['single'] !== true) {
            throw new \InvalidArgumentException("$at single: true, or left out");
        }
        if (!isset($block['code']) && !isset($block['answer_codes'])) {
            if (isset($block['single']) && !isset($block['label'])) {
                throw new \InvalidArgumentException("$at single: only a labelled checklist is one question");
            }
            return;
        }
        if (!isset($block['label'], $block['code'], $block['answer_codes'])) {
            throw new \InvalidArgumentException("$at: a coded checklist has a label, a code and answer_codes");
        }
        $code = $block['code'];
        if (!is_array($code) || count($code) !== 2 || ($code['system'] ?? null) !== Loinc::SYSTEM || !is_string($code['code'] ?? null) || !Loinc::isValid($code['code'])) {
            throw new \InvalidArgumentException("$at code: {\"system\": \"" . Loinc::SYSTEM . "\", \"code\": a LOINC question code, check digit right}");
        }
        $answers = $block['answer_codes'];
        if (!is_array($answers) || !array_is_list($answers) || count($answers) !== count($block['items'])) {
            throw new \InvalidArgumentException("$at answer_codes: one for each item, in the same order");
        }
        $seen = [];
        foreach ($answers as $k => $a) {
            $where = "$at answer_codes[$k]";
            $system = is_array($a) ? ($a['system'] ?? null) : null;
            if (!is_string($system) || !isset(self::ANSWER_SYSTEMS[$system]) || !is_string($a['code'] ?? null) || $a['code'] === ''
                || array_diff(array_keys($a), ['system', 'code', 'display']) !== []) {
                throw new \InvalidArgumentException("$where: {\"system\", \"code\"} and, but for LOINC, \"display\"; the system one of " . implode(', ', array_keys(self::ANSWER_SYSTEMS)));
            }
            if ($system === Loinc::SYSTEM) {
                if (!Loinc::isAnswer($a['code']) || isset($a['display'])) {
                    throw new \InvalidArgumentException("$where: a LOINC answer code (LA...), whose display comes from data/loinc-names.json");
                }
            } elseif (!is_string($a['display'] ?? null) || trim($a['display']) === '') {
                throw new \InvalidArgumentException("$where display: the code system's own name for the code");
            }
            if ($system === 'http://snomed.info/sct' && preg_match('/^\d{6,18}$/', $a['code']) !== 1) {
                throw new \InvalidArgumentException("$where code: a SNOMED CT concept id");
            }
            if (isset($seen["$system|{$a['code']}"])) {
                throw new \InvalidArgumentException("$where: each answer's code once");
            }
            $seen["$system|{$a['code']}"] = true;
        }
    }

    /**
     * The skip logic a document may carry for the screen (FHIR enableWhen), which printing ignores: a
     * block or field named by an "id" can be the target of a later "show_if", which is one condition
     * or {"any": [conditions]}. A condition names its target and either the answer, by its position
     * from 1 ({"id": "acuity", "answer": 1}; a scale's row too: {"id": "screen", "row": 4, "answer": 1}),
     * or, for a number field, a value it must be above ({"id": "tobacco", "above": 0}). Positions don't
     * change with the language, so a translation carries the same conditions.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private static function conditions(array $blocks, string $where): void
    {
        $targets = [];
        $name = static function (mixed $id, string $at) use (&$targets): string {
            if (!is_string($id) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
                throw new \InvalidArgumentException("$at id: lower case words joined by hyphens");
            }
            if (isset($targets[$id])) {
                throw new \InvalidArgumentException("$at id: \"$id\" is already used in this document");
            }
            return $id;
        };
        $check = static function (mixed $showIf, string $at) use (&$targets): void {
            $conditions = is_array($showIf) && array_keys($showIf) === ['any'] ? $showIf['any'] : [$showIf];
            if (!is_array($conditions) || !array_is_list($conditions) || $conditions === [] || count($conditions) > 20) {
                throw new \InvalidArgumentException("$at show_if: one condition, or {\"any\": [1 to 20 conditions]}");
            }
            foreach ($conditions as $k => $c) {
                $where = "$at show_if" . (count($conditions) > 1 ? "[$k]" : '');
                $target = is_array($c) && is_string($c['id'] ?? null) ? ($targets[$c['id']] ?? null) : null;
                if ($target === null) {
                    throw new \InvalidArgumentException("$where: \"id\" must name a block or field above this one");
                }
                if ($target['kind'] === 'number') {
                    self::onlyKeys($c, ['id', 'above'], [], $where);
                    if (!is_int($c['above']) || $c['above'] < $target['min'] - 1 || $c['above'] >= $target['max']) {
                        throw new \InvalidArgumentException("$where above: a whole number within the field's range");
                    }
                } elseif ($target['kind'] === 'scale') {
                    self::onlyKeys($c, ['id', 'row', 'answer'], [], $where);
                    if (!is_int($c['row']) || $c['row'] < 1 || $c['row'] > $target['rows']) {
                        throw new \InvalidArgumentException("$where row: the scale's row, from 1 to {$target['rows']}");
                    }
                } elseif ($target['kind'] === 'choice') {
                    self::onlyKeys($c, ['id', 'answer'], [], $where);
                } else {
                    throw new \InvalidArgumentException("$where: \"{$c['id']}\" asks nothing to answer (a labelled checklist, a scale or a number field)");
                }
                if (isset($c['answer']) && (!is_int($c['answer']) || $c['answer'] < 1 || $c['answer'] > $target['answers'])) {
                    throw new \InvalidArgumentException("$where answer: the answer's position, from 1 to {$target['answers']}");
                }
            }
        };
        foreach ($blocks as $i => $block) {
            $at = "$where blocks[$i]";
            if (isset($block['show_if'])) {
                $check($block['show_if'], $at);
            }
            foreach ($block['type'] === 'fields' ? $block['fields'] : [] as $j => $field) {
                if (isset($field['show_if'])) {
                    if (isset($block['show_if'])) {
                        throw new \InvalidArgumentException("$at fields[$j] show_if: the block already has one");
                    }
                    $check($field['show_if'], "$at fields[$j]");
                }
                if (isset($field['id'])) {
                    $id = $name($field['id'], "$at fields[$j]");
                    $targets[$id] = isset($field['number']) ? ['kind' => 'number', 'min' => $field['number'][0], 'max' => $field['number'][1]] : ['kind' => 'other'];
                }
            }
            if (isset($block['id'])) {
                $id = $name($block['id'], $at);
                $targets[$id] = match (true) {
                    $block['type'] === 'scale' => ['kind' => 'scale', 'rows' => count($block['items']), 'answers' => count($block['columns'])],
                    $block['type'] === 'checklist' && isset($block['label']) => ['kind' => 'choice', 'answers' => count($block['items'])],
                    default => ['kind' => 'other'],
                };
            }
        }
    }

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

    /** @return list<string> */
    private static function texts(mixed $value, string $where, int $min, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new \InvalidArgumentException("$where: must be a list of $min to $max texts");
        }
        $texts = [];
        foreach ($value as $i => $text) {
            $texts[] = self::text($text, "{$where}[$i]");
        }
        return $texts;
    }

    private static function text(mixed $value, string $where): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("$where: must be text");
        }
        return $value;
    }
}
