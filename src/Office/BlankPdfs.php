<?php

/**
 * Blank documents as accessible PDFs made when the module is released (tools/blank-pdfs.php), for
 * OpenEMR servers without LibreOffice: LibreOffice made each one from the module's own .odt with
 * PDF/UA on, and veraPDF checked it. They are the US pack's documents on US Letter, in each language
 * a document is written in, with the language assistance taglines a patient document prints, and
 * with no letterhead, since the clinic's name and address aren't known when they are made.
 *
 *   data/pdf/manifest.json    {"documents": {"en/consent-to-treat": {"fingerprint": "…", "sha256": "…"}}}
 *   data/pdf/en/consent-to-treat.pdf
 *
 * A PDF is used only while it still matches: the document's wording and its taglines are what they
 * were when it was made (the fingerprint), so a clinic's own version of a document, or its own
 * taglines, never gets the shipped PDF of another wording.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Translation;
use OpenHealthcare\ClinicForms\Paper\Words;

final class BlankPdfs
{
    public const DIR = 'data/pdf';
    public const PACK = 'us';
    public const PAPER = 'letter';

    /** @var array<string, array{fingerprint: string, sha256: string}> */
    private array $documents;

    /** @param string|null $root the module's folder (tests pass their own) */
    public function __construct(private readonly ?string $root = null)
    {
        $file = $this->dir() . '/manifest.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        /** @var array<string, array{fingerprint: string, sha256: string}> $documents */
        $documents = is_array($data) && is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $this->documents = $documents;
    }

    public function dir(): string
    {
        return ($this->root ?? dirname(__DIR__, 2)) . '/' . self::DIR;
    }

    public static function key(Document $doc): string
    {
        return $doc->language . '/' . $doc->id;
    }

    /**
     * What a blank PDF of the document is made from: its wording and, for a patient document, the
     * taglines printed at its end.
     *
     * @param list<string> $taglines the page's taglines in the document's language
     */
    public static function fingerprint(Document $doc, array $taglines): string
    {
        $json = json_encode([Translation::fingerprint($doc), $doc->audience === 'patient' ? $taglines : []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return substr(hash('sha256', $json), 0, 16);
    }

    /**
     * The shipped PDF of each document, by file name, or null if any of them has none that still
     * matches (then none is given: a packet is all accessible or not offered this way).
     *
     * @param list<Document> $documents
     * @param list<string>   $taglines
     * @return array<string, string>|null
     */
    public function files(array $documents, array $taglines): ?array
    {
        $out = [];
        foreach ($documents as $doc) {
            $entry = $this->documents[self::key($doc)] ?? null;
            $file = $this->dir() . '/' . self::key($doc) . '.pdf';
            if ($entry === null || $doc->source !== 'shipped' || $entry['fingerprint'] !== self::fingerprint($doc, $taglines) || !is_file($file)) {
                return null;
            }
            $bytes = (string)file_get_contents($file);
            if (hash('sha256', $bytes) !== $entry['sha256']) {
                return null;
            }
            $out[$doc->id . ($doc->language !== 'en' ? '.' . $doc->language : '') . '.pdf'] = $bytes;
        }
        return $out;
    }

    /**
     * Every document that gets a blank PDF, with its taglines, by key: the US pack's active documents
     * in each language they are written in, as a site with nothing of its own sees them.
     *
     * @param string $emptySite a site folder with no Clinic Forms folder of its own
     * @return array<string, array{0: Document, 1: list<string>}>
     */
    public static function shipped(string $emptySite): array
    {
        $out = [];
        foreach (array_keys(Words::LANGUAGES) as $language) {
            $taglines = Taglines::forSite($emptySite, self::PACK)->forPage($language);
            foreach (DocumentLibrary::forSite($emptySite, $language, self::PACK)->all() as $doc) {
                if ($doc->language === $language && $doc->status === 'active' && $doc->source === 'shipped') {
                    $out[self::key($doc)] = [$doc, $taglines];
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Which shipped documents have no PDF, or one made from other wording: what tools/blank-pdfs.php
     * must make again before a release.
     *
     * @param array<string, array{0: Document, 1: list<string>}> $documents each document and its taglines, by key
     * @return list<string>
     */
    public function stale(array $documents): array
    {
        $out = [];
        foreach ($documents as $key => [$doc, $taglines]) {
            $entry = $this->documents[$key] ?? null;
            if ($entry === null) {
                $out[] = "$key: no PDF";
            } elseif ($entry['fingerprint'] !== self::fingerprint($doc, $taglines)) {
                $out[] = "$key: its PDF was made from other wording";
            } elseif (!is_file($this->dir() . "/$key.pdf") || hash_file('sha256', $this->dir() . "/$key.pdf") !== $entry['sha256']) {
                $out[] = "$key: its PDF is missing or not the one recorded";
            }
        }
        foreach (array_diff(array_keys($this->documents), array_keys($documents)) as $key) {
            $out[] = "$key: a PDF of a document that is no longer shipped";
        }
        return $out;
    }
}
