<?php

/**
 * Clinic Forms documents as FHIR R4 Questionnaires, for systems that fill forms on screen: OpenEMR's
 * own questionnaires (LHC-Forms), OpenMRS and others. A Questionnaire is a blank form, so it carries
 * the clinic's details and never a patient's: a field that would print the patient's name or date
 * of birth is left out, since the response names its patient.
 *
 * A validated instrument that matches its reference (data/instruments) carries LOINC's question
 * and answer codes, its published answer order, and each answer's points as an ordinalValue on the
 * answer's Coding, where a response's answer carries it too, so its total line can add them up
 * (an SDC calculatedExpression, in the FHIRPath OpenEMR's questionnaire runtime evaluates); a
 * translation carries them when it keeps its English document's instrument. A document that
 * differs from its instrument claims no codes. An answer shows the paper form's wording (AUDIT-C's
 * "2 to 4 times a month", WHO's, where LOINC's display says "2-4"; a translation's own words), with
 * LOINC's code: the HL7 validator reports such a display as differing from LOINC's, as warnings with
 * -display-issues-are-warnings.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Fhir;

use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\Instrument;
use OpenHealthcare\ClinicForms\Paper\Loinc;
use OpenHealthcare\ClinicForms\Paper\Words;

final readonly class QuestionnaireExport
{
    /** Where a shipped document's Questionnaire is named: the module's repository, which owns these ids. */
    public const CANONICAL = 'https://github.com/anun333/oe-module-clinic-forms/questionnaire/';
    public const ORDINAL = 'http://hl7.org/fhir/StructureDefinition/ordinalValue';
    public const CALCULATED = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-calculatedExpression';
    private const BLANK = '__________';

    private Context $context;

    /**
     * @param Context $context the clinic; a patient in it is dropped, since a Questionnaire is a blank form
     * @param list<string> $taglines language assistance taglines, shown at the end of patient documents
     */
    public function __construct(Context $context, private string $language = 'en', private array $taglines = [])
    {
        $this->context = $context->withoutPatient();
    }

    /**
     * @param list<Document> $documents
     * @param array<string, Document> $english each translation's English document, by id
     * @return array<string, mixed> one Questionnaire, or a collection Bundle of several
     */
    public function export(array $documents, array $english = []): array
    {
        if (count($documents) === 1) {
            return $this->questionnaire($documents[0], $english[$documents[0]->id] ?? null);
        }
        return [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => array_map(fn(Document $d): array => ['resource' => $this->questionnaire($d, $english[$d->id] ?? null)], $documents),
        ];
    }

    /**
     * @param ?Document $english a translation's English document, whose instrument it must keep to carry codes
     * @return array<string, mixed>
     */
    public function questionnaire(Document $doc, ?Document $english = null): array
    {
        $codes = self::instrumentCodes($doc, $english);
        $q = ['resourceType' => 'Questionnaire', 'id' => $doc->id . ($doc->language !== 'en' ? '-' . $doc->language : '')];
        $q['language'] = $doc->language;
        if ($doc->source !== 'clinic') {
            $q['url'] = self::CANONICAL . $doc->id;
        }
        if ($doc->version > 0) {
            $q['version'] = (string)$doc->version;
        }
        $q += [
            'name' => self::name($doc->title),
            'title' => $this->text($doc->title),
            'status' => $doc->status === 'draft' ? 'draft' : 'active',
            'subjectType' => ['Patient'],
            'publisher' => $doc->source === 'clinic' ? $this->context->value('facility.name') : 'Clinic Forms',
            'description' => $this->text($doc->about),
        ];
        if ($codes !== null && !isset($codes['reference']['questions_from'])) {
            $q['derivedFrom'] = ['http://loinc.org/q/' . $doc->code['code']];
        }
        if (($doc->code['system'] ?? '') !== '' && ($doc->code['code'] ?? '') !== '') {
            // Its display is the code system's to give (LOINC's term name), so it is left out.
            $q['code'] = [['system' => $doc->code['system'], 'code' => $doc->code['code']]];
        }
        $copyright = implode('. ', array_filter([rtrim(trim((string)($doc->licence['holder'] ?? '')), '.'), trim((string)($doc->licence['terms'] ?? ''))]));
        if ($codes !== null) {
            $copyright .= ($copyright !== '' ? '. ' : '') . (string)($codes['reference']['notice'] ?? '');
        }
        if ($copyright !== '') {
            $q['copyright'] = $copyright;
        }
        $q['item'] = $this->items($doc, $codes);
        // A short narrative, as FHIR recommends for every resource.
        $lang = htmlspecialchars($doc->language, ENT_XML1);
        $q = array_slice($q, 0, 2) + ['text' => ['status' => 'generated', 'div' => '<div xmlns="http://www.w3.org/1999/xhtml" lang="' . $lang . '" xml:lang="' . $lang . '"><p><b>'
            . htmlspecialchars($q['title'], ENT_XML1) . '</b></p><p>' . htmlspecialchars($q['description'], ENT_XML1) . '</p></div>']] + $q;
        return $q;
    }

    /**
     * The document's blocks as items. A heading opens a group that holds what follows it, up to the
     * next heading.
     *
     * @param ?array{reference: array, map: array<int, array>} $codes
     * @return list<array<string, mixed>>
     */
    private function items(Document $doc, ?array $codes): array
    {
        $asked = 0;  // the document's questions so far, as Instrument::questions() counts them
        $root = [];
        $group = null;
        $add = static function (array $item) use (&$root, &$group): void {
            if ($group !== null) {
                $group['item'][] = $item;
            } else {
                $root[] = $item;
            }
        };
        $close = static function () use (&$root, &$group): void {
            if ($group !== null) {
                // A heading with nothing under it is shown as text: a group must hold items.
                $root[] = ($group['item'] ?? []) === [] ? ['linkId' => $group['linkId'], 'text' => $group['text'], 'type' => 'display'] : $group;
                $group = null;
            }
        };
        foreach ($doc->blocks as $b => $block) {
            $id = 'b' . ($b + 1);
            switch ($block['type']) {
                case 'heading':
                    $close();
                    $group = ['linkId' => $id, 'text' => $this->text($block['text']), 'type' => 'group', 'item' => []];
                    break;
                case 'paragraph':
                    $add(['linkId' => $id, 'text' => $this->text($block['text']), 'type' => 'display']);
                    break;
                case 'fields':
                    foreach ($block['fields'] as $n => $field) {
                        $item = $this->field($field, "$id.$n", $codes !== null);
                        if ($item !== null) {
                            $add($item);
                        }
                    }
                    break;
                case 'checklist':
                    if (isset($block['label'])) {
                        $add($this->choice("$id", (string)$block['label'], array_map(fn(string $i): array => self::scored($this->text($i)), $block['items']), $codes['map'][++$asked] ?? null, !$doc->validated));
                    } else {
                        // Statements to tick, each on its own.
                        foreach ($block['items'] as $n => $statement) {
                            $add(['linkId' => "$id.$n", 'text' => $this->text($statement), 'type' => 'boolean']);
                        }
                    }
                    break;
                case 'scale':
                    $children = [];
                    foreach ($block['items'] as $n => $question) {
                        $answers = [];
                        foreach ($block['columns'] as $i => $column) {
                            $score = $block['item_scores'][$n][$i] ?? $block['scores'][$i] ?? null;
                            $answers[] = [$this->text($column), is_numeric($score) ? $score + 0 : null];
                        }
                        $children[] = $this->choice("$id.$n", ($n + 1) . '. ' . $question, $answers, $codes['map'][++$asked] ?? null, false);
                    }
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'group', 'item' => $children]);
                    if (isset($block['total'])) {
                        $add(self::total("$id.total", $this->text($block['total']), $codes !== null));
                    }
                    break;
                case 'lines':
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'text']);
                    break;
                case 'signature':
                    foreach ($block['signers'] as $n => $signer) {
                        $add(['linkId' => "$id.$n", 'text' => $this->text($signer), 'type' => 'group', 'item' => [
                            ['linkId' => "$id.$n.name", 'text' => Words::t('Name', $this->language), 'type' => 'string'],
                            ['linkId' => "$id.$n.date", 'text' => Words::t('Date', $this->language), 'type' => 'date'],
                        ]]);
                    }
                    break;
                case 'table':
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'group', 'repeats' => true, 'item' => array_map(
                        fn(string $column, int $n): array => ['linkId' => "$id.$n", 'text' => $this->text($column), 'type' => 'string'],
                        $block['columns'],
                        array_keys($block['columns']),
                    )]);
                    break;
                case 'taglines':
                    $this->taglineItems($id, $add);
                    break;
            }
        }
        $close();
        $hasBlock = array_filter($doc->blocks, static fn(array $b): bool => $b['type'] === 'taglines') !== [];
        if ($doc->audience === 'patient' && !$hasBlock) {
            $this->taglineItems('taglines', $add);
            $close();
        }
        return $root;
    }

    private function taglineItems(string $id, callable $add): void
    {
        foreach ($this->taglines as $n => $tagline) {
            $add(['linkId' => "$id.$n", 'text' => $this->text($tagline), 'type' => 'display']);
        }
    }

    /**
     * A field, or null for one the patient's record fills (name, date of birth, chart number): the
     * response names its patient. A total line on a validated instrument is a number to work out.
     *
     * @param array{label: string, value?: string} $field
     * @return ?array<string, mixed>
     */
    private function field(array $field, string $linkId, bool $coded): ?array
    {
        $value = $field['value'] ?? '';
        preg_match_all('/\{([a-z]+)\.[a-z]+\}/', $value, $m);
        if ($m[1] !== [] && array_unique($m[1]) === ['patient']) {
            return null;
        }
        $label = $this->text($field['label']);
        if ($coded && preg_match('/^(Total score|Puntuación total|Puntaje total)/iu', $label) === 1) {
            return self::total($linkId, $label, true);
        }
        $item = ['linkId' => $linkId, 'text' => $label, 'type' => preg_match('/\b(date|fecha)\b/iu', $label) === 1 ? 'date' : 'string'];
        $filled = $this->value($value);
        if ($filled !== '' && $item['type'] === 'string') {
            $item['initial'] = [['valueString' => $filled]];
        }
        return $item;
    }

    /**
     * A question with one answer to choose, or several when $repeats (a document's own checklist, which
     * may ask for all that apply). Numbering in the text ("5. Are you…") becomes its prefix.
     *
     * @param list<array{0: string, 1: int|float|null}> $answers text and points
     * @param ?array{code: string, text: string, answers: list<array>} $coded the instrument's question, when the document is the instrument
     * @return array<string, mixed>
     */
    private function choice(string $linkId, string $label, array $answers, ?array $coded, bool $repeats): array
    {
        $text = $this->text($label);
        $item = ['linkId' => $coded !== null ? '/' . $coded['code'] : $linkId];
        if ($coded !== null) {
            // LOINC's display for a question is its term's full name, not the form's wording: left out.
            $item['code'] = [['system' => Loinc::SYSTEM, 'code' => $coded['code']]];
        }
        if (preg_match('/^\s*(\d+[.)])\s+(.*)$/su', $text, $m) === 1) {
            $item['prefix'] = $m[1];
            $text = $m[2];
        }
        $item += ['text' => $text, 'type' => 'choice', 'repeats' => $repeats && $coded === null];
        $item['answerOption'] = [];
        foreach ($answers as $i => [$answer, $points]) {
            $ordinal = $points === null ? [] : ['extension' => [['url' => self::ORDINAL, 'valueDecimal' => $points]]];
            // The points ride on a Coding, which a response's answer copies; a plain string answer can't carry them, so its option does.
            $option = $coded !== null
                ? ['valueCoding' => $ordinal + ['system' => Loinc::SYSTEM, 'code' => $coded['answers'][$i]['code'], 'display' => $answer]]
                : $ordinal + ['valueString' => $answer];
            $item['answerOption'][] = $option;
        }
        return $item;
    }

    /**
     * The instrument's question for each of the document's questions, by the document's question
     * number (1 for the first scale row or labelled checklist), when the document is the instrument:
     * a validated document whose check against its reference finds nothing, or a translation of one
     * that keeps it. Questions the reference accepts as extra have none.
     *
     * @return ?array{reference: array, map: array<int, array>}
     */
    private static function instrumentCodes(Document $doc, ?Document $english): ?array
    {
        $checked = $doc->language !== 'en' ? $english : $doc;
        if ($checked === null || !$checked->validated || ($checked->code['system'] ?? '') !== Loinc::SYSTEM) {
            return null;
        }
        $reference = Instrument::reference((string)$checked->code['code']);
        if ($reference === null || Instrument::differences($checked, $reference) !== []
            || ($checked !== $doc && Instrument::translationDifferences($checked, $doc) !== [])) {
            return null;
        }
        $extra = array_map('intval', array_keys($reference['accepted']['extra'] ?? []));
        $map = [];
        $next = 0;
        foreach (array_keys(Instrument::questions($doc)) as $i) {
            if (!in_array($i + 1, $extra, true) && isset($reference['questions'][$next])) {
                $map[$i + 1] = $reference['questions'][$next++];
            }
        }
        return ['reference' => $reference, 'map' => $map];
    }

    /**
     * A total line. On an instrument whose answers carry their points, it adds up the points of the
     * answers given, at any depth a question can sit (a scale's rows are inside its group, which may
     * be inside a heading's).
     *
     * @return array<string, mixed>
     */
    private static function total(string $linkId, string $text, bool $calculated): array
    {
        $item = ['linkId' => $linkId, 'text' => $text, 'type' => 'decimal', 'readOnly' => true];
        if ($calculated) {
            $points = ".answer.valueCoding.extension.where(url = '" . self::ORDINAL . "').valueDecimal.sum()";
            $item['extension'] = [['url' => self::CALCULATED, 'valueExpression' => ['language' => 'text/fhirpath', 'expression' => implode(' + ', [
                '%resource.item' . $points, '%resource.item.item' . $points, '%resource.item.item.item' . $points,
            ])]]];
        }
        return $item;
    }

    /** @return array{0: string, 1: int|float|null} "Never (0)" as its text and points */
    private static function scored(string $item): array
    {
        return preg_match('/^(.*?)\s*\((\d+(?:\.\d+)?)\)\s*$/u', $item, $m) === 1 ? [$m[1], $m[2] + 0] : [$item, null];
    }

    /** A computer-usable name, as FHIR asks: "Patient Health Questionnaire (PHQ-9)" is PatientHealthQuestionnairePHQ9. */
    public static function name(string $title): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = implode('', array_map(static fn(string $w): string => ucfirst($w), $words));
        return preg_match('/^[A-Z]/', $name) === 1 ? $name : 'Q' . $name;
    }

    /** Running text: a placeholder with no value becomes a blank, as on paper. */
    private function text(string $text): string
    {
        return (string)preg_replace_callback('/\{([a-z]+\.[a-z]+)\}/', function (array $m): string {
            $value = $this->context->value($m[1]);
            return $value !== '' ? $value : self::BLANK;
        }, $text);
    }

    /** A field's value: placeholders with no value become nothing. */
    private function value(string $text): string
    {
        return trim((string)preg_replace_callback('/\{([a-z]+\.[a-z]+)\}/', fn(array $m): string => $this->context->value($m[1]), $text));
    }
}
