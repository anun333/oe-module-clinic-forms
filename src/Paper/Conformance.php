<?php

/**
 * Whether each document is held to what it claims to follow, by the kind of its source:
 *
 *   rule        its rule's elements (requirements.json), every one present;
 *   instrument  a validated instrument matches LOINC's reference (data/instruments); any other
 *               instrument carries its publisher's wording unchanged (data/references/<id>.json);
 *   written     our own wording, with no outside text to hold it to.
 *
 * And whether it is cleared for public release: a document on the release gates list
 * (data/release-gates.json) is held, with its reason, and must be a draft; a draft must be held; a
 * document whose answers carry SNOMED CT US Extension codes is held whether listed or not, since
 * those codes' only licence doesn't cover a public, worldwide download. The tests fail on any
 * problem this finds, and tools/conformance.php writes CONFORMANCE.md from it.
 *
 * @package   OpenHealthcare\ClinicForms
 * @link      https://github.com/anun333/oe-module-clinic-forms
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Conformance
{
    private const SNOMED = 'http://snomed.info/sct';

    /** @var array<string, string>|null held document id => why */
    private static ?array $held = null;
    /** @var array<string, array{from: string, texts: list<string>}>|null by document id */
    private static ?array $references = null;

    public static function dataDir(): string
    {
        return dirname(__DIR__, 2) . '/data';
    }

    /** @return array<string, string> documents not cleared for public release, and why */
    public static function held(): array
    {
        if (self::$held === null) {
            $data = json_decode((string)file_get_contents(self::dataDir() . '/release-gates.json'), true);
            $held = is_array($data['held'] ?? null) ? $data['held'] : [];
            self::$held = array_map('strval', $held);
        }
        return self::$held;
    }

    /** @return array<string, array{from: string, texts: list<string>}> publishers' wording, by document id */
    public static function references(): array
    {
        if (self::$references === null) {
            self::$references = [];
            foreach (glob(self::dataDir() . '/references/*.json') ?: [] as $file) {
                $ref = json_decode((string)file_get_contents($file), true);
                if (is_array($ref) && is_string($ref['document'] ?? null) && is_array($ref['texts'] ?? null)) {
                    self::$references[$ref['document']] = ['from' => (string)($ref['from'] ?? ''), 'texts' => array_values(array_map('strval', $ref['texts']))];
                }
            }
        }
        return self::$references;
    }

    /** SNOMED CT US Extension codes (namespace 1000124) among a document's coded answers. */
    public static function usExtensionCodes(Document $doc): array
    {
        $codes = [];
        foreach ($doc->blocks as $block) {
            foreach ($block['answer_codes'] ?? [] as $a) {
                if (($a['system'] ?? null) === self::SNOMED && preg_match('/^\d+1000124\d{3}$/', (string)$a['code']) === 1) {
                    $codes[] = (string)$a['code'];
                }
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * What the document is held to and how, and anything wrong.
     *
     * @return array{kind: string, spec: string, checks: list<string>, problems: list<string>, held: ?string}
     */
    public static function of(Document $doc, Requirements $requirements): array
    {
        $kind = (string)($doc->origin['kind'] ?? 'written');
        $spec = (string)($doc->origin['name'] ?? 'Written for Clinic Forms');
        $checks = [];
        $problems = [];
        switch ($kind) {
            case 'rule':
                $elements = $requirements->documents[$doc->id]['elements'] ?? null;
                if (!is_array($elements) || $elements === []) {
                    $problems[] = 'written to a rule, with no elements to hold it to in requirements.json';
                } else {
                    $missing = $requirements->missing($doc);
                    $checks[] = count($elements) . ' elements of its rule (requirements.json)';
                    foreach ($missing as $name) {
                        $problems[] = "missing its rule's element: $name";
                    }
                }
                break;
            case 'instrument':
                if ($doc->validated) {
                    $matched = Instrument::matched($doc);
                    if ($matched === null) {
                        $problems[] = 'a validated instrument that matches no LOINC reference in data/instruments';
                    } else {
                        $checks[] = 'LOINC ' . (string)($matched['reference']['loinc'] ?? $doc->code['code'] ?? '') . "'s questions, answers, points and range (data/instruments)";
                    }
                } elseif (!isset(self::references()[$doc->id])) {
                    $problems[] = "a published instrument with no copy of its publisher's wording in data/references";
                } else {
                    $ref = self::references()[$doc->id];
                    $text = self::flat(Requirements::text($doc));
                    $absent = array_values(array_filter($ref['texts'], static fn(string $t): bool => !str_contains($text, self::flat($t))));
                    $checks[] = count($ref['texts']) . " texts of the publisher's wording, unchanged (data/references/{$doc->id}.json)";
                    foreach ($absent as $t) {
                        $problems[] = 'not as published: "' . mb_substr($t, 0, 80) . '"';
                    }
                }
                break;
            case 'written':
                $checks[] = 'our own wording; no outside text to hold it to';
                break;
            default:
                $problems[] = "an unknown source kind \"$kind\"";
        }
        if (isset($requirements->documents[$doc->id]) && $kind !== 'rule') {
            $checks[] = count($requirements->documents[$doc->id]['elements'] ?? []) . ' further elements (requirements.json)';
        }
        $coded = count(array_filter($doc->blocks, static fn(array $b): bool => isset($b['code'])));
        if ($coded > 0) {
            $checks[] = "$coded coded questions";
        }
        if ($doc->audience === 'patient') {
            $checks[] = 'reading level grade ' . number_format(Readability::measure($doc)['grade'], 1);
        }
        $held = self::held()[$doc->id] ?? null;
        $usExtension = self::usExtensionCodes($doc);
        if ($usExtension !== [] && $held === null) {
            $problems[] = count($usExtension) . ' SNOMED CT US Extension codes, not held for release (data/release-gates.json)';
        }
        if ($held !== null && $doc->status !== 'draft') {
            $problems[] = 'held for release, but its status is ' . $doc->status . ', not draft';
        }
        if ($held === null && $doc->status === 'draft') {
            $problems[] = 'a draft with no reason in data/release-gates.json';
        }
        return ['kind' => $kind, 'spec' => $spec, 'checks' => $checks, 'problems' => $problems, 'held' => $held];
    }

    /** Spaces collapsed, and curly quotes counted as straight ones: typography, not wording. */
    private static function flat(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $text)));
    }
}
