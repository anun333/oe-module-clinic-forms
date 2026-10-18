<?php

/**
 * A validated instrument as an OpenMRS 3 form (the JSON the O3 Form Builder takes), its questions
 * and answers saved as CIEL concepts, the dictionary most OpenMRS sites load. data/ciel.json holds
 * the concepts (written from a CIEL release by the openhealthcare workspace's dev/ciel_map.py).
 *
 * Only an instrument CIEL can carry gets a form: one that matches its reference, every question of
 * which that counts (scored, or named by a published rule, as the ASQ's are) has a CIEL concept whose
 * answers are the instrument's. A question that counts for nothing and that CIEL lacks (PHQ-9's
 * closing "how difficult…") is left out, and the form's description says so.
 * The patient and the visit are the encounter's, so a document's name and date fields aren't
 * questions here, nor are its office-use lines; its text is shown as markdown.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\OpenMrs;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\Instrument;

final class O3FormExport
{
    /** @var ?array{ciel_release: string, questions: array<string, array{ciel: string, map: string, answers: array<string, string>}>} */
    private static ?array $ciel = null;

    /** @return array{ciel_release: string, questions: array<string, array>} */
    public static function ciel(): array
    {
        return self::$ciel ??= json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/ciel.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    /** A CIEL concept's UUID, as OpenMRS stores CIEL: its number padded with A to 36 characters. */
    public static function uuid(string $ciel): string
    {
        return str_pad($ciel, 36, 'A');
    }

    /**
     * The document as an O3 form, or null when CIEL can't carry it.
     *
     * @param ?Document $english a translation's English document, whose instrument it must keep
     * @return ?array<string, mixed>
     */
    public static function form(Document $doc, ?Document $english = null): ?array
    {
        $matched = Instrument::matched($doc, $english);
        if ($matched === null) {
            return null;
        }
        $concepts = self::ciel()['questions'];
        $ruled = array_merge(...array_map(static fn(array $r): array => $r['questions'] ?? [], $matched['reference']['rules'] ?? [[]]));
        $left = [];
        foreach ($matched['map'] as $ref) {
            if (!isset($concepts[$ref['code']])) {
                $scored = array_filter(array_column($ref['answers'], 'score'), static fn($s): bool => $s !== null) !== [];
                if ($scored || in_array($ref['code'], $ruled, true)) {
                    return null;  // it counts, so without it the result wouldn't be the instrument's
                }
                $left[] = $ref['code'];
            }
        }
        if (count($left) === count($matched['map'])) {
            return null;
        }

        $sections = [];
        $section = ['label' => $doc->title, 'isExpanded' => 'true', 'questions' => []];
        $asked = 0;
        $flush = static function () use (&$sections, &$section): void {
            if ($section['questions'] !== []) {
                $sections[] = $section;
            }
        };
        foreach ($doc->blocks as $b => $block) {
            switch ($block['type']) {
                case 'heading':
                    $flush();
                    $section = ['label' => (string)$block['text'], 'isExpanded' => 'true', 'questions' => []];
                    break;
                case 'paragraph':
                    $section['questions'][] = ['id' => 'text' . ($b + 1), 'type' => 'markdown', 'questionOptions' => ['rendering' => 'markdown'], 'value' => [(string)$block['text']]];
                    break;
                case 'scale':
                    $flush();
                    $section = ['label' => (string)$block['label'], 'isExpanded' => 'true', 'questions' => []];
                    foreach ($block['items'] as $n => $item) {
                        $asked++;
                        $question = self::question($matched['map'][$asked] ?? null, (($block['start'] ?? 1) + $n) . '. ' . $item, $block['columns'], $concepts);
                        if ($question !== null) {
                            $section['questions'][] = $question;
                        }
                    }
                    break;
                case 'checklist':
                    if (($block['label'] ?? '') === '') {
                        break;
                    }
                    $asked++;
                    $labels = array_map(static fn(string $i): string => (string)preg_replace('/\s*\(\d+(?:\.\d+)?\)\s*$/', '', $i), $block['items']);
                    $question = self::question($matched['map'][$asked] ?? null, (string)$block['label'], $labels, $concepts);
                    if ($question !== null) {
                        $section['questions'][] = $question;
                    }
                    break;
            }
        }
        $flush();

        $about = $doc->about;
        if ($left !== []) {
            $about .= ' Left out, as CIEL has no concept for ' . (count($left) === 1 ? 'it' : 'them') . ' (unscored): LOINC ' . implode(', ', $left) . '.';
        }
        return [
            'name' => $doc->title . ($doc->language !== 'en' ? ' (' . $doc->language . ')' : ''),
            'version' => (string)max(1, $doc->version),
            'description' => $about . ' From Clinic Forms (' . $doc->stamp() . '); concepts from CIEL ' . self::ciel()['ciel_release'] . '. Choose the encounter type in the Form Builder.',
            'processor' => 'EncounterFormProcessor',
            'referencedForms' => [],
            'pages' => [['label' => $doc->title, 'sections' => $sections]],
        ];
    }

    /**
     * A question saved as its CIEL concept, its answers in the document's order and words, or null
     * for one with no concept (left out) or none of the instrument's.
     *
     * @param list<string> $labels the answers as the document prints them
     * @return ?array<string, mixed>
     */
    private static function question(?array $ref, string $label, array $labels, array $concepts): ?array
    {
        $concept = $ref === null ? null : ($concepts[$ref['code']] ?? null);
        if ($concept === null) {
            return null;
        }
        $answers = [];
        foreach ($ref['answers'] as $i => $answer) {
            $answers[] = ['concept' => self::uuid($concept['answers'][$answer['code'] ?? $answer['text']]), 'label' => $labels[$i] ?? $answer['text']];
        }
        return [
            'label' => $label,
            'type' => 'obs',
            'id' => 'loinc_' . str_replace('-', '_', $ref['code']),
            'questionOptions' => ['rendering' => 'radio', 'concept' => self::uuid($concept['ciel']), 'answers' => $answers],
        ];
    }
}
