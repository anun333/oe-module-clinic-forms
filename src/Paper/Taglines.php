<?php

/**
 * The language assistance taglines 45 CFR 92.11 requires: a short notice, in English and at least
 * the 15 languages most spoken by people with limited English in the clinic's state, that free
 * language assistance and auxiliary aids and services are available. It must go with the notice of
 * privacy practices, intake and consent forms, billing communications and complaint forms, among
 * others.
 *
 * They come with a country pack (data/packs/us/taglines.json ships English and Spanish). Which
 * other languages a clinic needs depends on its state (Pack::taglineGaps), so the clinic adds them
 * in sites/<site>/documents/clinic-forms/taglines.json, in the same format; one with a shipped
 * language's code replaces it. Without a pack only the clinic's own are used.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Taglines
{
    /**
     * @param array<string, string> $taglines language code => tagline, in print order
     * @param list<string>          $problems
     */
    public function __construct(public array $taglines, public array $problems = [])
    {
    }

    /** A pack's taglines (the US pack's unless another id, or null for none) and the clinic's own. */
    public static function forSite(?string $siteDir, ?string $pack = Pack::DEFAULT): self
    {
        $file = $pack === null ? null : Pack::get($pack)->dir . '/taglines.json';
        $taglines = $file !== null && is_file($file) ? self::read($file, "data/packs/$pack/taglines.json") : [];
        if ($siteDir === null) {
            return new self($taglines);
        }
        $file = $siteDir . '/' . DocumentLibrary::LOCAL_FOLDER . '/taglines.json';
        if (!is_file($file)) {
            return new self($taglines);
        }
        try {
            $local = self::read($file, 'sites/' . basename($siteDir) . '/' . DocumentLibrary::LOCAL_FOLDER . '/taglines.json');
        } catch (\InvalidArgumentException $e) {
            return new self($taglines, [$e->getMessage()]);
        }
        return new self(array_merge($taglines, $local));
    }

    /**
     * The taglines in print order: the page's own language first, then the others.
     *
     * @return list<string>
     */
    public function forPage(string $language): array
    {
        $first = isset($this->taglines[$language]) ? [$this->taglines[$language]] : [];
        return array_values(array_merge($first, array_diff_key($this->taglines, [$language => true])));
    }

    /** @return array<string, string> */
    private static function read(string $file, string $where): array
    {
        try {
            $data = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("$where: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data) || !is_array($data['taglines'] ?? null) || array_diff(array_keys($data), ['about', 'taglines']) !== []) {
            throw new \InvalidArgumentException("$where: needs \"taglines\", an object of language code to tagline");
        }
        $out = [];
        foreach ($data['taglines'] as $code => $text) {
            if (!is_string($code) || preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code) !== 1 || !is_string($text) || trim($text) === '') {
                throw new \InvalidArgumentException("$where: \"$code\" must be a language code (vi, zh-Hant…) with its tagline as text");
            }
            foreach (Renderer::placeholders($text) as $p) {
                if (!str_starts_with($p, 'facility.')) {
                    throw new \InvalidArgumentException("$where $code: only {facility.…} placeholders can be used in a tagline");
                }
            }
            $out[$code] = $text;
        }
        return $out;
    }
}
