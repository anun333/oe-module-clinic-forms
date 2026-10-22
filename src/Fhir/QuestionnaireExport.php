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
 * differs from its instrument claims no codes.
 *
 * Every LOINC code goes with LOINC's own name for it (data/loinc-names.json), as the LOINC licence
 * asks of content passed on: a document's, question's or total's code with its long common name, in
 * Spanish where LOINC has one, and an answer's code with LOINC's answer text, which an English form
 * then shows (AUDIT-C's "2-4 times a month" where WHO's paper says "2 to 4 times a month"). A
 * translation's answers are in its own words, the instrument owner's, so they aren't LOINC's: they're
 * coded in the module's own system, by position, with the same points, and its questions keep LOINC's
 * codes and names.
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
    /** Marks an item whose answer becomes an Observation, coded with the item's code, when a form filler extracts. */
    public const EXTRACT = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-observationExtract';
    /** The SDC profile every exported Questionnaire claims, at the version the HL7 validator checks it against. */
    public const SDC_PROFILE = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire|4.0.0';
    /** A translated instrument's answers, coded by their position in the question ("1", "2"...). */
    public const ANSWER_SYSTEM = 'https://github.com/anun333/oe-module-clinic-forms/answers';
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
        $codes = Instrument::matched($doc, $english);
        $q = ['resourceType' => 'Questionnaire', 'id' => $doc->id . ($doc->language !== 'en' ? '-' . $doc->language : '')];
        // As OpenEMR's own SDC questionnaires do (contrib/portal_templates/assessments/SDC FHIR).
        $q['meta'] = ['profile' => [self::SDC_PROFILE]];
        $q['language'] = $doc->language;
        if ($doc->source !== 'clinic') {
            $q['url'] = self::CANONICAL . $doc->id;
        }
        // Names the document, wherever the Questionnaire goes: OpenEMR replaces the id and url of one it is given.
        $q['identifier'] = [QuestionnaireRegistration::identifier($q['id'])];
        if ($doc->version > 0) {
            $q['version'] = (string)$doc->version;
            // Versions are whole numbers, raised when the wording changes; SDC asks a versioned Questionnaire to say so (sdc-2).
            $q['extension'] = [['url' => 'http://hl7.org/fhir/StructureDefinition/artifact-versionAlgorithm',
                'valueCoding' => ['system' => 'http://hl7.org/fhir/version-algorithm', 'code' => 'integer', 'display' => 'Integer']]];
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
            $q['code'] = [self::loincCoding((string)$doc->code['code'], $doc->language)];
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
        $q = array_slice($q, 0, 3) + ['text' => ['status' => 'generated', 'div' => '<div xmlns="http://www.w3.org/1999/xhtml" lang="' . $lang . '" xml:lang="' . $lang . '"><p><b>'
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
        $targets = [];  // what a later show_if can name, by id: a question's linkId and answers, or a scale's rows
        $when = [];  // the block's skip logic, as enableWhen, on every item the block adds
        $add = static function (array $item) use (&$root, &$group, &$when): void {
            $item += $when;
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
            $when = isset($block['show_if']) ? self::enableWhen($block['show_if'], $targets) : [];
            switch ($block['type']) {
                case 'heading':
                    $close();
                    $group = ['linkId' => $id, 'text' => $this->text($block['text']), 'type' => 'group'] + $when + ['item' => []];
                    $when = [];
                    break;
                case 'paragraph':
                    $add(['linkId' => $id, 'text' => $this->text($block['text']), 'type' => 'display']);
                    break;
                case 'fields':
                    foreach ($block['fields'] as $n => $field) {
                        $item = $this->field($field, "$id.$n", $codes, $doc->language);
                        if ($item !== null) {
                            $add($item + (isset($field['show_if']) ? self::enableWhen($field['show_if'], $targets) : []));
                            if (isset($field['id'])) {
                                $targets[$field['id']] = ['linkId' => $item['linkId']];
                            }
                        }
                    }
                    break;
                case 'checklist':
                    if (isset($block['label'])) {
                        $item = $this->choice("$id", (string)$block['label'], array_map(fn(string $i): array => self::scored($this->text($i)), $block['items']), $codes['map'][++$asked] ?? null, !$doc->validated, $doc->language);
                        $add($item);
                        if (isset($block['id'])) {
                            $targets[$block['id']] = ['linkId' => $item['linkId'], 'answers' => $item['answerOption']];
                        }
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
                        $children[] = $this->choice("$id.$n", (($block['start'] ?? 1) + $n) . '. ' . $question, $answers, $codes['map'][++$asked] ?? null, false, $doc->language);
                    }
                    if (isset($block['id'])) {
                        $rows = [];
                        foreach ($children as $n => $child) {
                            $rows[$n + 1] = ['linkId' => $child['linkId'], 'answers' => $child['answerOption']];
                        }
                        $targets[$block['id']] = ['rows' => $rows];
                    }
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'group', 'item' => $children]);
                    if (isset($block['total'])) {
                        $add(self::total("$id.total", $this->text($block['total']), $codes !== null, null, self::totalCode($codes), $doc->language));
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
    private function field(array $field, string $linkId, ?array $codes, string $language = 'en'): ?array
    {
        $value = $field['value'] ?? '';
        preg_match_all('/\{([a-z]+)\.[a-z]+\}/', $value, $m);
        if ($m[1] !== [] && array_unique($m[1]) === ['patient']) {
            return null;
        }
        $label = $this->text($field['label']);
        if ($codes !== null && preg_match('/^(Total score|Puntuación total|Puntaje total)/iu', $label) === 1) {
            return self::total($linkId, $label, true, null, self::totalCode($codes), $language);
        }
        // A part's score line (TAPS-2's "Alcohol score") adds up that part's answers only.
        foreach ($codes['reference']['subscales'] ?? [] as $subscale) {
            if (($subscale['field'] ?? null) === $field['label']) {
                return self::total($linkId, $label, true, $subscale['questions']);
            }
        }
        if (isset($field['number'])) {
            // A count (BSTAD's days of use): a whole number in its range, which skip logic can compare.
            return ['linkId' => $linkId, 'text' => $label, 'type' => 'integer', 'extension' => [
                ['url' => 'http://hl7.org/fhir/StructureDefinition/minValue', 'valueInteger' => $field['number'][0]],
                ['url' => 'http://hl7.org/fhir/StructureDefinition/maxValue', 'valueInteger' => $field['number'][1]],
            ]];
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
     * @param string $language the document's language: an English one shows LOINC's answer text, a translation its own words
     * @return array<string, mixed>
     */
    private function choice(string $linkId, string $label, array $answers, ?array $coded, bool $repeats, string $language): array
    {
        $text = $this->text($label);
        $item = ['linkId' => $coded !== null ? '/' . $coded['code'] : $linkId];
        if ($coded !== null) {
            $item['code'] = [self::loincCoding($coded['code'], $language)];
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
            $option = match (true) {
                $coded === null => $ordinal + ['valueString' => $answer],
                $language === 'en' => ['valueCoding' => $ordinal + self::loincCoding((string)$coded['answers'][$i]['code'], 'en')],
                default => ['valueCoding' => $ordinal + ['system' => self::ANSWER_SYSTEM, 'code' => (string)($i + 1), 'display' => $answer]],
            };
            $item['answerOption'][] = $option;
        }
        return $item;
    }

    /**
     * A total line. On an instrument whose answers carry their points, it adds up the points of the
     * answers given, at any depth a question can sit (a scale's rows are inside its group, which may
     * be inside a heading's).
     *
     * @return array<string, mixed>
     */
    private static function total(string $linkId, string $text, bool $calculated, ?array $questions = null, ?string $loinc = null, string $language = 'en'): array
    {
        // The instrument's own total carries LOINC's term for it; a part's score has none here.
        $item = ['linkId' => $linkId] + ($loinc !== null ? ['code' => [self::loincCoding($loinc, $language)]] : [])
            + ['text' => $text, 'type' => 'decimal', 'readOnly' => true];
        if ($calculated) {
            $points = ".answer.valueCoding.extension.where(url = '" . self::ORDINAL . "').valueDecimal.sum()";
            $depths = ['%resource.item', '%resource.item.item', '%resource.item.item.item'];
            // All the answers given, or only those of the named questions (a part's), by their linkIds.
            $terms = $questions === null
                ? array_map(static fn(string $d): string => $d . $points, $depths)
                : array_merge(...array_map(static fn(string $code): array => array_map(
                    static fn(string $d): string => "$d.where(linkId = '/$code')$points", $depths), $questions));
            $item['extension'] = [['url' => self::CALCULATED, 'valueExpression' => ['language' => 'text/fhirpath', 'expression' => implode(' + ', $terms)]]];
            if ($loinc !== null) {
                // The instrument's total, coded, becomes an Observation where a form filler extracts (SDC),
                // as OpenEMR's own SDC questionnaires mark theirs; CMS159 reads the PHQ-9's.
                $item['extension'][] = ['url' => self::EXTRACT, 'valueBoolean' => true];
            }
        }
        return $item;
    }

    /**
     * A block's or field's show_if as FHIR enableWhen, from the questions above it: an answer by its
     * position, as the question's own answer option (its coding with its display, or its text), or a
     * count above a value.
     * Checked when the document was read; a target the export leaves out asks nothing, so it's dropped.
     *
     * @param array<string, mixed> $showIf
     * @param array<string, array<string, mixed>> $targets
     * @return array<string, mixed> enableWhen and, for several, enableBehavior any; or nothing
     */
    private static function enableWhen(array $showIf, array $targets): array
    {
        $out = [];
        foreach (array_keys($showIf) === ['any'] ? $showIf['any'] : [$showIf] as $c) {
            $target = $targets[$c['id']] ?? null;
            if (isset($c['row'])) {
                $target = $target['rows'][$c['row']] ?? null;
            }
            if ($target === null) {
                continue;
            }
            if (isset($c['above'])) {
                $out[] = ['question' => $target['linkId'], 'operator' => '>', 'answerInteger' => $c['above']];
                continue;
            }
            $option = $target['answers'][$c['answer'] - 1] ?? null;
            if ($option === null) {
                continue;
            }
            $out[] = ['question' => $target['linkId'], 'operator' => '='] + (isset($option['valueCoding'])
                // With its display: a LOINC answer code goes with LOINC's text for it, as everywhere else.
                ? ['answerCoding' => array_intersect_key($option['valueCoding'], ['system' => true, 'code' => true, 'display' => true])]
                : ['answerString' => $option['valueString']]);
        }
        return $out === [] ? [] : ['enableWhen' => $out] + (count($out) > 1 ? ['enableBehavior' => 'any'] : []);
    }

    /**
     * LOINC's term for the instrument's total, from its reference, or null.
     *
     * @param ?array{reference: array<string, mixed>, map: array<int, string>} $codes
     */
    private static function totalCode(?array $codes): ?string
    {
        $code = $codes['reference']['total_code'] ?? null;
        return is_string($code) && Loinc::isValid($code) ? $code : null;
    }

    /**
     * A LOINC code with LOINC's name for it: in Spanish where LOINC has one and the document is Spanish.
     *
     * @return array{system: string, code: string, display: string}
     */
    private static function loincCoding(string $code, string $language): array
    {
        $name = Loinc::name($code, $language);
        if ($name === null) {
            throw new \LogicException("LOINC code $code has no name in data/loinc-names.json; run tools/loinc-names.php");
        }
        return ['system' => Loinc::SYSTEM, 'code' => $code, 'display' => $name];
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
