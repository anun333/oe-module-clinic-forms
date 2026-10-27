<?php

/**
 * Reads the answers kept on file (Paper\Kept) back from a patient's saved questionnaire responses:
 * for each fact, the latest answer in a response that counts, from a document that asks it.
 *
 * A response counts as the modules count one: openhealthcare-common's QuestionnaireStatus (from
 * runtime 0.4.0 a response saved after the site's first finished one counts only when finished;
 * entered-in-error never), never one "stopped" (R4: abandoned), and never one whose encounter form
 * was deleted. The question is found by the definition its item carries (0.44.0 and later) or, in
 * a response saved from an older copy of the form, by its wording and the heading it sits under,
 * as the document asks it today.
 *
 * A representative's facts come together from the latest Personal Representative response, so a
 * newer form that leaves the representative out isn't filled from an older one. Other facts take
 * the latest answer that still holds (Kept::holds).
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\ClinicForms\Fhir\QuestionnaireExport;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Kept;
use OpenHealthcare\Common\QuestionnaireStatus;

final class KeptAnswers
{
    /** @var array<string, array<string, array<string, string>>> document id => language => normalized wording path => fact */
    private array $paths = [];

    /** @var array<string, string> document id => title, as the page prints it */
    private array $titles = [];

    /**
     * @param list<Document> $sources the documents that define facts, in each language a response may be in
     * @param array<string, string> $titles document id => title in the page's language
     */
    public function __construct(array $sources, array $titles = [])
    {
        foreach ($sources as $doc) {
            $paths = self::paths($doc);
            if ($paths !== []) {
                $this->paths[$doc->id][$doc->language] = $paths;
                $this->titles[$doc->id] = $titles[$doc->id] ?? ($this->titles[$doc->id] ?? $doc->title);
            }
        }
    }

    /**
     * The reader for a site: the module's documents that define facts, in each language a response
     * may have been saved in (English and Spanish), with their titles as the page prints them.
     *
     * @param list<DocumentLibrary> $libraries
     */
    public static function forLibraries(array $libraries, DocumentLibrary $page): self
    {
        $sources = [];
        foreach ($libraries as $library) {
            foreach ($library->all() as $doc) {
                // Only the module's own documents have a canonical url a saved copy can be matched by.
                if ($doc->source !== 'clinic' && self::paths($doc) !== []) {
                    $sources[] = $doc;
                }
            }
        }
        $titles = [];
        foreach ($sources as $doc) {
            $titles[$doc->id] ??= $page->get($doc->id)?->title ?? $doc->title;
        }
        return new self($sources, $titles);
    }

    /** Whether any document defines a fact. */
    public function hasSources(): bool
    {
        return $this->paths !== [];
    }

    /**
     * The facts a document asks, by the wording path its item has on screen: the heading it sits
     * under, then its own label, both normalized.
     *
     * @return array<string, string> path => fact
     */
    public static function paths(Document $doc): array
    {
        $heading = '';
        $out = [];
        foreach ($doc->blocks as $block) {
            if ($block['type'] === 'heading') {
                $heading = $block['text'];
                continue;
            }
            $asks = match ($block['type']) {
                'fields' => array_map(static fn(array $f): array => [$f['label'], $f['defines'] ?? null], $block['fields']),
                'lines', 'checklist' => [[$block['label'] ?? '', $block['defines'] ?? null]],
                default => [],
            };
            foreach ($asks as [$label, $fact]) {
                if (is_string($fact) && $label !== '') {
                    $out[self::path($heading === '' ? [$label] : [$heading, $label])] = $fact;
                }
            }
        }
        return $out;
    }

    /**
     * The kept answers for a patient, as of $today.
     *
     * @param \Closure(string): string $formatDate a stored date (Y-m-d) as the site prints dates
     * @return array<string, array{value: string, from: string, date: string}>
     */
    public function forPatient(int $pid, \DateTimeImmutable $today, \Closure $formatDate): array
    {
        if ($pid <= 0 || $this->paths === []) {
            return [];
        }
        [$counted, $countedBinds] = QuestionnaireStatus::condition('qr');
        $likes = [];
        $binds = [$pid];
        foreach (array_keys($this->paths) as $id) {
            $likes[] = 'COALESCE(NULLIF(qr.questionnaire, \'\'), rep.questionnaire) LIKE ?';
            $binds[] = '%clinic-forms%questionnaire%' . $id . '%';
        }
        $rows = QueryUtils::fetchRecords(
            "SELECT qr.id, COALESCE(qr.last_updated, qr.create_time) AS answered,
                    COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) AS questionnaire_json, qr.questionnaire_response AS response_json
               FROM questionnaire_response AS qr
               LEFT JOIN questionnaire_repository AS rep ON rep.id = qr.questionnaire_foreign_id
              WHERE qr.patient_id = ?
                AND (" . implode(' OR ', $likes) . ")
                AND COALESCE(qr.status, '') <> 'stopped'
                AND NOT EXISTS (
                    SELECT 1 FROM form_questionnaire_assessments AS dq
                    JOIN forms AS df ON df.form_id = dq.id AND df.pid = dq.pid AND df.formdir = 'questionnaire_assessments'
                    WHERE dq.response_id = qr.response_id AND df.deleted = 1)
                AND $counted
              ORDER BY answered DESC, qr.id DESC",
            [...$binds, ...$countedBinds]
        );
        $responses = [];
        foreach ($rows as $row) {
            $questionnaire = json_decode((string)($row['questionnaire_json'] ?? ''), true);
            $response = json_decode((string)($row['response_json'] ?? ''), true);
            $answered = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)($row['answered'] ?? ''));
            if (is_array($questionnaire) && is_array($response) && $answered !== false) {
                $responses[] = ['questionnaire' => $questionnaire, 'response' => $response, 'answered' => $answered];
            }
        }
        return $this->pick($responses, $today, $formatDate);
    }

    /**
     * The facts from responses already read, newest first: what forPatient() does after its query.
     *
     * @param list<array{questionnaire: array<string, mixed>, response: array<string, mixed>, answered: \DateTimeImmutable}> $responses
     * @param \Closure(string): string $formatDate
     * @return array<string, array{value: string, from: string, date: string}>
     */
    public function pick(array $responses, \DateTimeImmutable $today, \Closure $formatDate): array
    {
        $kept = [];
        $authorityRead = false;
        foreach ($responses as $r) {
            $id = $this->documentOf($r['questionnaire']);
            if ($id === null) {
                continue;
            }
            $answers = $this->read($id, $r['questionnaire'], $r['response'], $formatDate);
            $hasAuthority = array_intersect(Kept::AUTHORITY, $this->factsOf($id)) !== [];
            $date = $formatDate($r['answered']->format('Y-m-d'));
            foreach ($answers as $fact => $value) {
                $isAuthority = in_array($fact, Kept::AUTHORITY, true);
                if (isset($kept[$fact]) || ($isAuthority && $authorityRead) || !Kept::holds($fact, $r['answered'], $today)) {
                    continue;
                }
                $kept[$fact] = ['value' => $value, 'from' => $this->titles[$id], 'date' => $date];
            }
            // The latest response that asks about a representative says who it is, even when it names none.
            $authorityRead = $authorityRead || $hasAuthority;
        }
        return Kept::complete($kept, $today);
    }

    /**
     * The facts a saved response answers, found by definition or, in an older copy, by wording.
     *
     * @param array<string, mixed> $questionnaire the copy of the form the response was saved against
     * @param array<string, mixed> $response
     * @param \Closure(string): string $formatDate
     * @return array<string, string> fact => answer as text
     */
    public function read(string $id, array $questionnaire, array $response, \Closure $formatDate): array
    {
        $language = is_string($questionnaire['language'] ?? null) ? $questionnaire['language'] : 'en';
        $paths = $this->paths[$id][$language] ?? $this->paths[$id]['en'] ?? [];
        $facts = array_flip($this->factsOf($id));
        $byLink = [];
        self::walk($questionnaire['item'] ?? [], [], static function (array $item, array $path) use (&$byLink, $paths, $facts): void {
            $definition = is_string($item['definition'] ?? null) ? $item['definition'] : '';
            $fact = str_starts_with($definition, Kept::DEFINITION) ? substr($definition, strlen(Kept::DEFINITION)) : null;
            if ($fact === null || !isset($facts[$fact])) {
                $fact = $paths[self::path($path)] ?? null;
            }
            if ($fact !== null && is_string($item['linkId'] ?? null)) {
                $byLink[$item['linkId']] = $fact;
            }
        });
        $out = [];
        self::answers($response['item'] ?? [], static function (string $linkId, array $answers) use (&$out, $byLink, $formatDate): void {
            $fact = $byLink[$linkId] ?? null;
            if ($fact === null || isset($out[$fact])) {
                return;
            }
            $values = array_values(array_filter(array_map(static fn(mixed $a): string => is_array($a) ? self::answer($a, $formatDate) : '', $answers), static fn(string $v): bool => $v !== ''));
            if ($values !== []) {
                $out[$fact] = implode('; ', $values);
            }
        });
        return $out;
    }

    /** The source document a saved form is a copy of, by its canonical url, or null. */
    private function documentOf(array $questionnaire): ?string
    {
        $url = is_string($questionnaire['url'] ?? null) ? $questionnaire['url'] : '';
        if (!str_starts_with($url, QuestionnaireExport::CANONICAL)) {
            return null;
        }
        $id = substr($url, strlen(QuestionnaireExport::CANONICAL));
        return isset($this->paths[$id]) ? $id : null;
    }

    /** @return list<string> the facts a document asks */
    private function factsOf(string $id): array
    {
        $facts = [];
        foreach ($this->paths[$id] ?? [] as $byPath) {
            array_push($facts, ...array_values($byPath));
        }
        return array_values(array_unique($facts));
    }

    /** @param \Closure(array<string, mixed>, list<string>): void $visit */
    private static function walk(mixed $items, array $groups, \Closure $visit): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = is_string($item['text'] ?? null) ? $item['text'] : '';
            if (($item['type'] ?? '') === 'group') {
                self::walk($item['item'] ?? [], [...$groups, $text], $visit);
                continue;
            }
            $visit($item, [...$groups, $text]);
        }
    }

    /** @param \Closure(string, list<mixed>): void $visit */
    private static function answers(mixed $items, \Closure $visit): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_string($item['linkId'] ?? null) && is_array($item['answer'] ?? null)) {
                $visit($item['linkId'], $item['answer']);
                foreach ($item['answer'] as $a) {
                    if (is_array($a)) {
                        self::answers($a['item'] ?? [], $visit);
                    }
                }
            }
            self::answers($item['item'] ?? [], $visit);
        }
    }

    /** @param array<string, mixed> $answer */
    private static function answer(array $answer, \Closure $formatDate): string
    {
        foreach (['valueString', 'valueText'] as $key) {
            if (is_string($answer[$key] ?? null)) {
                return trim($answer[$key]);
            }
        }
        if (is_string($answer['valueDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer['valueDate']) === 1) {
            return $formatDate($answer['valueDate']);
        }
        if (is_int($answer['valueInteger'] ?? null) || is_float($answer['valueDecimal'] ?? null) || is_int($answer['valueDecimal'] ?? null)) {
            return (string)($answer['valueInteger'] ?? $answer['valueDecimal']);
        }
        if (is_array($answer['valueCoding'] ?? null)) {
            $c = $answer['valueCoding'];
            return trim((string)($c['display'] ?? $c['code'] ?? ''));
        }
        return '';
    }

    /** Wording compared without case, punctuation or extra spaces. @param list<string> $parts */
    public static function path(array $parts): string
    {
        return implode(' / ', array_map(
            static fn(string $p): string => trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($p)))),
            $parts,
        ));
    }
}
