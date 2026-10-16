<?php

/**
 * Which wording each version of a shipped document had (data/document-versions.json), so a version
 * number always means one wording: a document whose wording changed without its "version" being
 * raised is reported, and so is a version that was never recorded. tools/document-versions.php
 * records new versions; the tests run the check. The file keeps every version's fingerprint, so a
 * release can list the documents that changed since the one before (changes()).
 *
 *   {"documents": {"documents/consent-to-treat": {"1": "<fingerprint>", "2": "<fingerprint>"}}}
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class DocumentVersions
{
    public const FILE = 'data/document-versions.json';

    /**
     * Every shipped document, by its path under data/ without .json ("documents/phq-9",
     * "packs/us/documents/release-of-information"), core and packs and regions.
     *
     * @return array<string, Document>
     */
    public static function shipped(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2);
        $out = [];
        $dirs = array_merge([$root . '/data/documents'], glob($root . '/data/packs/*/documents') ?: [], glob($root . '/data/packs/*/regions/*/documents') ?: []);
        foreach ($dirs as $dir) {
            $label = substr($dir, strlen($root) + 1);
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                $out[substr($label, strlen('data/')) . '/' . basename($file, '.json')] = DocumentLibrary::read($file, $label);
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, array<string, string>> */
    public static function load(?string $file = null): array
    {
        $file ??= dirname(__DIR__, 2) . '/' . self::FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        /** @var array<string, array<string, string>> $documents */
        $documents = is_array($data) && is_array($data['documents'] ?? null) ? $data['documents'] : [];
        return $documents;
    }

    /**
     * What doesn't match: a document without a version, a version not recorded, or wording changed
     * under a recorded version.
     *
     * @param array<string, array<string, string>> $recorded
     * @param array<string, Document>              $documents
     * @return list<string>
     */
    public static function problems(array $recorded, array $documents): array
    {
        $out = [];
        foreach ($documents as $key => $doc) {
            $fingerprint = Translation::fingerprint($doc);
            $was = $recorded[$key][(string)$doc->version] ?? null;
            if ($doc->version < 1) {
                $out[] = "$key: has no version";
            } elseif ($was === null) {
                $out[] = "$key: version $doc->version is not recorded; run php tools/document-versions.php";
            } elseif ($was !== $fingerprint) {
                $out[] = "$key: its wording changed since version $doc->version was recorded; raise its version to " . (max(array_map('intval', array_keys($recorded[$key]))) + 1) . ' and run php tools/document-versions.php';
            }
        }
        return $out;
    }

    /**
     * The record with each document's current version added. Never rewrites a recorded version.
     *
     * @param array<string, array<string, string>> $recorded
     * @param array<string, Document>              $documents
     * @return array<string, array<string, string>>
     */
    public static function record(array $recorded, array $documents): array
    {
        foreach ($documents as $key => $doc) {
            if ($doc->version >= 1 && !isset($recorded[$key][(string)$doc->version])) {
                $recorded[$key][(string)$doc->version] = Translation::fingerprint($doc);
                ksort($recorded[$key], SORT_NUMERIC);
            }
        }
        ksort($recorded);
        return $recorded;
    }

    /**
     * What changed between two records (an earlier release's file and now): documents added,
     * removed, and those whose latest version moved.
     *
     * @param array<string, array<string, string>> $before
     * @param array<string, array<string, string>> $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $latest = static fn(array $versions): int => $versions === [] ? 0 : max(array_map('intval', array_keys($versions)));
        $out = [];
        foreach ($after as $key => $versions) {
            $was = $latest($before[$key] ?? []);
            $now = $latest($versions);
            if ($was === 0) {
                $out[] = "$key: new (version $now)";
            } elseif ($now !== $was) {
                $out[] = "$key: version $was to $now";
            }
        }
        foreach (array_diff_key($before, $after) as $key => $unused) {
            $out[] = "$key: removed";
        }
        return $out;
    }
}
