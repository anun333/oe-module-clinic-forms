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
 * codes and names. So is every other answer with no code of its own: a document that claims no codes
 * still answers with Codings, never plain strings.
 *
 * For OpenEMR's questionnaire runtime 0.4.0 and later (rich()), it says more: a scale is one answer
 * grid (the table item control), a table a grid of rows (gtable), the patient's signature is
 * required and saved as an R4 signature (signatureRequired), and a row asked of one sex only
 * (TAPS-1's questions 2 and 3, "item_sex") is skipped by an enableWhenExpression on the patient's
 * gender. Before 0.4.0, and on LHC-Forms, it exports as 0.38.1 did: an older renderer shows the
 * plain forms anyway, and drops a required signature entirely, leaving no signature line at all.
 * OpenEMR's pages pass the form no patient yet (launchContext), so the expression shows the row
 * whenever the patient's gender is unknown, as today; a host that passes one gets the skip.
 *
 * A LOINC question, and an instrument's total, carries the item LOINC's own FHIR questionnaire defines
 * it by as its "definition" (Loinc::definition(), SDC's Questionnaire library reference), the same in
 * every form that asks it.
 *
 * A shared block of questions (Blocks) that carry linkIds of their own, their LOINC codes, stands on its
 * own in FHIR, as SDC 4.0.0's Modular Forms have it: forms() gives the block's items, which
 * blockQuestionnaire() makes a Questionnaire of its own (canonical url and version), and the document's
 * modular form, which names the block by a subQuestionnaire where its items go. The form fillers get the
 * assembled form (questionnaire()): the same items as before blocks, with assembledFrom naming the blocks
 * it was assembled from. Blocks whose items' linkIds say where they sit in the document (b5.0) stay
 * written out: SDC's linkIdPrefix can only put text in front of a block's linkIds, so a block used in
 * two places couldn't give each its own, and the linkIds are what saved responses hold.
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
use OpenHealthcare\Common\QuestionnaireRuntime;

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
    /** SDC's profile for a form that names others (a modular form) or is named by one (a block). */
    public const MODULAR_PROFILE = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-modular|4.0.0';
    public const SUB_QUESTIONNAIRE = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-subQuestionnaire';
    public const ASSEMBLED_FROM = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-assembledFrom';
    public const ASSEMBLE_EXPECTATION = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-assemble-expectation';
    /** Tells the two forms of one url and version apart, as SDC asks when both are published: computable (modular) or executable (assembled). */
    public const KNOWLEDGE_CAPABILITY = 'http://hl7.org/fhir/StructureDefinition/cqf-knowledgeCapability';
    private const BLANK = '__________';
    private const SNOMED = 'http://snomed.info/sct';
    private const SNOMED_INTERNATIONAL_EDITION = 'http://snomed.info/sct/900000000000207008';
    private const ITEM_CONTROL = 'http://hl7.org/fhir/StructureDefinition/questionnaire-itemControl';
    private const ENABLE_WHEN_EXPRESSION = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-enableWhenExpression';
    private const LAUNCH_CONTEXT = 'http://hl7.org/fhir/uv/sdc/StructureDefinition/sdc-questionnaire-launchContext';
    private const SIGNATURE_REQUIRED = 'http://hl7.org/fhir/StructureDefinition/questionnaire-signatureRequired';
    /** A version that is a whole number (artifact-versionAlgorithm integer). */
    private const VERSION_ALGORITHM = ['url' => 'http://hl7.org/fhir/StructureDefinition/artifact-versionAlgorithm',
        'valueCoding' => ['system' => 'http://hl7.org/fhir/version-algorithm', 'code' => 'integer', 'display' => 'Integer']];
    /** ASTM E1762's signature types, as FHIR R4's Signature.type names them. */
    private const SIGNATURE_TYPE = 'urn:iso-astm:E1762-95:2013';

    private Context $context;

    /**
     * @param Context $context the clinic; a patient in it is dropped, since a Questionnaire is a blank form
     * @param list<string> $taglines language assistance taglines, shown at the end of patient documents
     * @param bool $rich the richer forms of runtime 0.4.0 and later (rich()); the plain ones otherwise
     */
    public function __construct(Context $context, private string $language = 'en', private array $taglines = [], private bool $rich = false)
    {
        $this->context = $context->withoutPatient();
    }

    /**
     * Whether this site's questionnaire runtime takes the richer forms: OpenEMR's native runtime 0.4.0
     * or later, read from the file it serves (8.5.0 shipped 0.2.0 and gained 0.4.0 in a backport).
     */
    public static function rich(?string $projectDir = null): bool
    {
        return QuestionnaireRuntime::atLeast(QuestionnaireRuntime::COMPLETES, $projectDir);
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
     * The form fillers' Questionnaire: the assembled form, when the document includes a block that stands on its own.
     *
     * @param ?Document $english a translation's English document, whose instrument it must keep to carry codes
     * @return array<string, mixed>
     */
    public function questionnaire(Document $doc, ?Document $english = null): array
    {
        return $this->forms($doc, $english)['assembled'];
    }

    /**
     * The document as form fillers run it ("assembled"), and, when it includes a shared block that
     * stands on its own in FHIR, its modular form, which names each such block by a subQuestionnaire
     * in place of its items, with each block's items ("blocks", for blockQuestionnaire()).
     *
     * @param ?Document $english a translation's English document, whose instrument it must keep to carry codes
     * @return array{assembled: array<string, mixed>, modular: ?array<string, mixed>, blocks: list<array{include: array<string, mixed>, language: string, items: list<array<string, mixed>>, copyright: ?string}>}
     */
    public function forms(Document $doc, ?Document $english = null): array
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
            $q['extension'] = [self::VERSION_ALGORITHM];
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
        $modules = [];
        $q['item'] = $this->items($doc, $codes, $modules);
        if ($this->rich && array_filter($doc->blocks, static fn(array $b): bool => array_filter($b['item_sex'] ?? []) !== []) !== []) {
            // The patient, for the rows asked of one sex: a host that has one supplies it as %patient.
            $q['extension'][] = ['url' => self::LAUNCH_CONTEXT, 'extension' => [
                ['url' => 'name', 'valueCoding' => ['system' => 'http://hl7.org/fhir/uv/sdc/CodeSystem/launchContext', 'code' => 'patient', 'display' => 'Patient']],
                ['url' => 'type', 'valueCode' => 'Patient'],
                ['url' => 'description', 'valueString' => 'The patient filling in the form, whose gender decides the rows asked of one sex'],
            ]];
        }
        $q = self::withNarrative($q, $doc->language);
        if ($modules === []) {
            return ['assembled' => $q, 'modular' => null, 'blocks' => []];
        }
        $assembled = $q;
        $modular = $q;
        // The same url and version in both forms, as SDC has an assembled form keep its modular form's.
        $modular['id'] = $q['id'] . '-modular';
        $modular['meta'] = ['profile' => [self::MODULAR_PROFILE]];
        foreach (array_unique(array_map(static fn(array $m): string => self::blockCanonical($m['include'], $doc->language), $modules)) as $canonical) {
            $assembled['extension'][] = ['url' => self::ASSEMBLED_FROM, 'valueCanonical' => $canonical];
        }
        $assembled['extension'][] = ['url' => self::KNOWLEDGE_CAPABILITY, 'valueCode' => 'executable'];
        $modular['extension'][] = ['url' => self::ASSEMBLE_EXPECTATION, 'valueCode' => 'assemble-root'];
        $modular['extension'][] = ['url' => self::KNOWLEDGE_CAPABILITY, 'valueCode' => 'computable'];
        $modular['item'] = self::withModules($q['item'], $modules, fn(array $m): array => [
            'linkId' => $m['parent'] . '.' . $m['include']['block'],
            // Shown only by a filler that can't assemble the form, as SDC asks.
            'text' => Words::t('Some questions are missing: this form needs SDC assembly to show the block', $doc->language) . ' "' . $this->text($m['include']['title']) . '".',
            'type' => 'display',
            'extension' => [['url' => self::SUB_QUESTIONNAIRE, 'valueCanonical' => self::blockCanonical($m['include'], $doc->language)]],
        ]);
        $blocks = array_map(static fn(array $m): array => ['include' => $m['include'], 'language' => $doc->language, 'items' => $m['items'], 'copyright' => $q['copyright'] ?? null], $modules);
        return ['assembled' => $assembled, 'modular' => $modular, 'blocks' => $blocks];
    }

    /**
     * A shared block's Questionnaire: its items as forms() gave them, its own canonical url and version,
     * and "independent-child", since it is filled only as part of another form but needs no assembly
     * itself. Its copyright is that of the document its items came from (an instrument's, with LOINC's notice).
     *
     * @param array{block: string, version: int, title: string, about: string} $include
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function blockQuestionnaire(array $include, string $language, array $items, ?string $copyright = null): array
    {
        $q = ['resourceType' => 'Questionnaire', 'id' => 'block-' . $include['block'] . ($language !== 'en' ? '-' . $language : '')];
        $q['meta'] = ['profile' => [self::MODULAR_PROFILE]];
        $q['language'] = $language;
        $q['url'] = explode('|', self::blockCanonical($include, $language))[0];
        $q['version'] = (string)$include['version'];
        $q['extension'] = [self::VERSION_ALGORITHM, ['url' => self::ASSEMBLE_EXPECTATION, 'valueCode' => 'independent-child']];
        $q += [
            'name' => self::name($include['title']),
            'title' => $this->text($include['title']),
            'status' => 'active',
            'subjectType' => ['Patient'],
            'publisher' => 'Clinic Forms',
            'description' => $this->text($include['about']),
        ];
        if ($copyright !== null && $copyright !== '') {
            $q['copyright'] = $copyright;
        }
        $q['item'] = $items;
        return self::withNarrative($q, $language);
    }

    /** A block's canonical reference, url and version: the module's repository names it, one url per language. */
    private static function blockCanonical(array $include, string $language): string
    {
        return self::CANONICAL . 'block/' . $include['block'] . ($language !== 'en' ? '-' . $language : '') . '|' . $include['version'];
    }

    /**
     * The items with each block's items replaced by the display item that names it.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array{parent: string, linkIds: list<string>}> $modules
     * @return list<array<string, mixed>>
     */
    private static function withModules(array $items, array $modules, callable $display): array
    {
        foreach ($items as &$item) {
            foreach ($modules as $m) {
                if ($item['linkId'] === $m['parent']) {
                    $at = array_search($m['linkIds'][0], array_column($item['item'], 'linkId'), true);
                    array_splice($item['item'], (int)$at, count($m['linkIds']), [$display($m)]);
                }
            }
            if (isset($item['item'])) {
                $item['item'] = self::withModules($item['item'], $modules, $display);
            }
        }
        unset($item);
        return $items;
    }

    /**
     * A short narrative, as FHIR recommends for every resource, after the id, meta and language.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    private static function withNarrative(array $q, string $language): array
    {
        $lang = htmlspecialchars($language, ENT_XML1);
        return array_slice($q, 0, 3) + ['text' => ['status' => 'generated', 'div' => '<div xmlns="http://www.w3.org/1999/xhtml" lang="' . $lang . '" xml:lang="' . $lang . '"><p><b>'
            . htmlspecialchars($q['title'], ENT_XML1) . '</b></p><p>' . htmlspecialchars($q['description'], ENT_XML1) . '</p></div>']] + $q;
    }

    /**
     * The document's blocks as items. A heading opens a group that holds what follows it, up to the
     * next heading.
     *
     * @param ?array{reference: array, map: array<int, array>} $codes
     * @param list<array{include: array<string, mixed>, parent: string, linkIds: list<string>, items: list<array<string, mixed>>}> $modules
     *        filled with the shared blocks of questions that stand on their own (forms())
     * @return list<array<string, mixed>>
     */
    private function items(Document $doc, ?array $codes, array &$modules = []): array
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
                    if (isset($block['label'], $block['code'])) {
                        $item = $this->codedChoice((string)$block['label'], $block, $doc->language);
                        $add($item);
                        if (isset($block['id'])) {
                            $targets[$block['id']] = ['linkId' => $item['linkId'], 'answers' => $item['answerOption']];
                        }
                    } elseif (isset($block['label'])) {
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
                        $child = $this->choice("$id.$n", (($block['start'] ?? 1) + $n) . '. ' . $question, $answers, $codes['map'][++$asked] ?? null, false, $doc->language);
                        $sex = $block['item_sex'][$n] ?? null;
                        if ($this->rich && is_string($sex)) {
                            $child['extension'][] = self::askedOf($sex);
                        }
                        $children[] = $child;
                    }
                    foreach ($doc->includes as $include) {
                        if (($include['row'] ?? null) !== 0 || $include['at'] !== $b) {
                            continue;
                        }
                        // A block of questions stands on its own when its questions carry linkIds of their own
                        // (their LOINC codes) and their numbers are the block's own (the top of a scale numbered
                        // from 1), so the same items come back wherever it's assembled: in the module's documents,
                        // whose blocks the module publishes.
                        $run = array_slice($children, 0, $include['count']);
                        if (($block['start'] ?? 1) === 1 && $doc->source !== 'clinic' && $include['source'] === 'shipped'
                            && array_filter($run, static fn(array $c): bool => !str_starts_with($c['linkId'], '/')) === []) {
                            $modules[] = ['include' => $include, 'parent' => $id, 'linkIds' => array_column($run, 'linkId'), 'items' => $run];
                        }
                    }
                    if (isset($block['id'])) {
                        $rows = [];
                        foreach ($children as $n => $child) {
                            $rows[$n + 1] = ['linkId' => $child['linkId'], 'answers' => $child['answerOption']];
                        }
                        $targets[$block['id']] = ['rows' => $rows];
                    }
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'group']
                        + ($this->rich ? ['extension' => [self::itemControl('table')]] : []) + ['item' => $children]);
                    if (isset($block['total'])) {
                        $add(self::total("$id.total", $this->text($block['total']), $codes !== null, null, self::totalCode($codes), $doc->language));
                    }
                    break;
                case 'lines':
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'text']);
                    break;
                case 'signature':
                    foreach ($block['signers'] as $n => $signer) {
                        // The patient's own signature, drawn or typed, is required on screen; staff sign as before.
                        $signed = $this->rich && self::patientSigns($signer) ? ['extension' => [self::signatureRequired($doc)]] : [];
                        $add(['linkId' => "$id.$n", 'text' => $this->text($signer), 'type' => 'group'] + $signed + ['item' => [
                            ['linkId' => "$id.$n.name", 'text' => Words::t('Name', $this->language), 'type' => 'string'],
                            ['linkId' => "$id.$n.date", 'text' => Words::t('Date', $this->language), 'type' => 'date'],
                        ]]);
                    }
                    break;
                case 'table':
                    $add(['linkId' => $id, 'text' => $this->text($block['label']), 'type' => 'group', 'repeats' => true]
                        + ($this->rich ? ['extension' => [self::itemControl('gtable')]] : []) + ['item' => array_map(
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
        return self::guardTotals($root);
    }

    /**
     * Each worked-out total, guarded so it shows only once every scored question it adds up has an
     * answer. A blank answer isn't 0 points: a PHQ-9 with two items left blank would otherwise show a
     * lower total, perhaps a lower band. A question every patient is asked must be answered; one under
     * skip logic (enableWhen) must be answered when its condition holds, so AUDIT-C's "Never" needs no
     * more answers but a drinker's blank second question still blanks the total. A question asked only
     * of one sex (an expression) isn't required. A blank total is safe; the clinician sees it isn't
     * complete.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function guardTotals(array $items): array
    {
        // linkId => true when every patient is asked it, a FHIRPath test of when it's asked (its own
        // and its groups' enableWhen), or false when an expression decides (not required).
        $scored = [];
        $collect = static function (array $items, array $tests, bool $byExpression) use (&$collect, &$scored): void {
            foreach ($items as $item) {
                $here = $tests;
                if (isset($item['enableWhen'])) {
                    $here[] = self::askedTest($item['enableWhen'], $item['enableBehavior'] ?? 'all');
                }
                $expression = $byExpression || array_filter(
                    $item['extension'] ?? [],
                    static fn(array $e): bool => ($e['url'] ?? '') === self::ENABLE_WHEN_EXPRESSION
                ) !== [] || in_array(null, $here, true);
                $points = array_filter($item['answerOption'] ?? [], static fn(array $o): bool => array_filter(
                    $o['valueCoding']['extension'] ?? [],
                    static fn(array $e): bool => ($e['url'] ?? '') === self::ORDINAL
                ) !== []);
                if ($points !== []) {
                    $scored[$item['linkId']] = $expression ? false : ($here === [] ? true : implode(' and ', $here));
                }
                $collect($item['item'] ?? [], $here, $expression);
            }
        };
        $collect($items, [], false);
        $guard = static function (array $items) use (&$guard, $scored): array {
            foreach ($items as &$item) {
                if (array_key_exists('_scope', $item)) {
                    $scope = $item['_scope'];
                    unset($item['_scope']);
                    $inScope = array_filter(
                        $scored,
                        static fn(string $linkId): bool => $scope === null
                            || in_array($linkId, array_map(static fn(string $c): string => "/$c", $scope), true),
                        ARRAY_FILTER_USE_KEY
                    );
                    $always = array_keys(array_filter($inScope, static fn(bool|string $s): bool => $s === true));
                    $tests = [];
                    if ($always !== []) {
                        // The answers among those questions, at each depth a question can sit; count()
                        // is 0 for none in every engine, so no guard is needed on it.
                        $which = '.where(' . implode(' or ', array_map(static fn(string $id): string => "linkId = '$id'", $always)) . ')';
                        $tests[] = implode(' + ', array_map(
                            static fn(string $d): string => "$d$which.answer.count()",
                            self::DEPTHS
                        )) . ' = ' . count($always);
                    }
                    foreach (array_filter($inScope, 'is_string') as $linkId => $asked) {
                        // iif, not not(): OpenEMR 8.3 and 8.4's runtime (0.2.0) has iif but no not().
                        $tests[] = "iif($asked, " . self::anyDepth($linkId, '.answer.exists()') . ', true)';
                    }
                    if ($tests !== []) {
                        $expression = &$item['extension'][0]['valueExpression']['expression'];
                        $expression = 'iif(' . implode(' and ', $tests) . ", $expression, {})";
                        unset($expression);
                    }
                }
                if (isset($item['item'])) {
                    $item['item'] = $guard($item['item']);
                }
            }
            unset($item);
            return $items;
        };
        return $guard($items);
    }

    /** Where a question can sit: at the top, inside a group, or inside a group inside a heading's. */
    private const DEPTHS = ['%resource.item', '%resource.item.item', '%resource.item.item.item'];

    /** A test that holds when the question with this linkId, at any depth, meets the condition. */
    private static function anyDepth(string $linkId, string $condition): string
    {
        $id = self::fhirPathString($linkId);
        return '(' . implode(' or ', array_map(static fn(string $d): string => "$d.where(linkId = $id)$condition", self::DEPTHS)) . ')';
    }

    /**
     * An item's enableWhen as a FHIRPath test of the response: the same answers the runtime checks
     * (an answer chosen, by its coding, or a count above a value). Null for anything else, which the
     * guard then doesn't require.
     *
     * @param list<array<string, mixed>> $enableWhen
     */
    private static function askedTest(array $enableWhen, string $behavior): ?string
    {
        $tests = [];
        foreach ($enableWhen as $c) {
            $question = (string)($c['question'] ?? '');
            if (($c['operator'] ?? '') === '=' && isset($c['answerCoding']['code'])) {
                $match = 'code = ' . self::fhirPathString((string)$c['answerCoding']['code'])
                    . (isset($c['answerCoding']['system']) ? ' and system = ' . self::fhirPathString((string)$c['answerCoding']['system']) : '');
                $tests[] = self::anyDepth($question, ".answer.valueCoding.where($match).exists()");
            } elseif (($c['operator'] ?? '') === '>' && is_int($c['answerInteger'] ?? null)) {
                $tests[] = self::anyDepth($question, ".answer.where(valueInteger > {$c['answerInteger']}).exists()");
            } else {
                return null;
            }
        }
        return $tests === [] ? null : '(' . implode($behavior === 'any' ? ' or ' : ' and ', $tests) . ')';
    }

    /** A FHIRPath string literal. */
    private static function fhirPathString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
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
            $item += self::definition($coded['code']);
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
            // Every answer is a Coding, whose points a response's answer copies: LOINC's for an
            // instrument in English, else the module's own, by position. Not a string option:
            // LHC-Forms (OpenEMR 7.0.2 to 8.2) saves one as a Coding with only its display, which
            // OpenEMR's native runtime doesn't match to the option, and reloading a string answer
            // into LHC-Forms 33 drops it.
            $option = $coded !== null && $language === 'en'
                ? ['valueCoding' => $ordinal + self::loincCoding((string)$coded['answers'][$i]['code'], 'en')]
                : ['valueCoding' => $ordinal + ['system' => self::ANSWER_SYSTEM, 'code' => (string)($i + 1), 'display' => $answer]];
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
        $item = ['linkId' => $linkId] + ($loinc !== null ? self::definition($loinc) + ['code' => [self::loincCoding($loinc, $language)]] : [])
            + ['text' => $text, 'type' => 'decimal', 'readOnly' => true];
        if ($calculated) {
            // The points of all the answers given, or only those of the named questions (a part's),
            // by their linkIds, at each depth a question can sit. Each depth's sum is guarded,
            // since an empty sum is 0 in FHIRPath 2.0.0, OpenEMR's runtime and fhirpath.js 3
            // (LHC-Forms 33) but empty in fhirpath.js 5, where empty plus anything is empty.
            // Not repeat(item): OpenEMR 8.3 and 8.4's runtime (0.2.0) doesn't have it.
            $points = ".answer.valueCoding.extension.where(url = '" . self::ORDINAL . "').valueDecimal";
            $which = $questions === null
                ? ''
                : '.where(' . implode(' or ', array_map(static fn(string $code): string => "linkId = '/$code'", $questions)) . ')';
            $depths = ['%resource.item', '%resource.item.item', '%resource.item.item.item'];
            $terms = array_map(static fn(string $d): string => "iif($d$which$points.exists(), $d$which$points.sum(), 0)", $depths);
            $item['extension'] = [['url' => self::CALCULATED, 'valueExpression' => ['language' => 'text/fhirpath', 'expression' => implode(' + ', $terms)]]];
            // Which questions it adds up, for guardTotals() once every item is built: all, or a part's.
            $item['_scope'] = $questions;
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

    /** @return array{definition?: string} where LOINC's own questionnaire defines the question, when it does */
    private static function definition(string $code): array
    {
        $definition = Loinc::definition($code);
        return $definition !== null ? ['definition' => $definition] : [];
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

    /**
     * A checklist that says what it asks in code (DOCUMENT-FORMAT.md, coded questions): the question's
     * LOINC code, as the item's code and linkId, and each answer's code, so a saved response holds the
     * codes a report reads. Each answer shows its code system's own name for it, LOINC's from the names
     * file, as everywhere else; the paper keeps the document's wording.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function codedChoice(string $label, array $block, string $language): array
    {
        $text = $this->text($label);
        $item = ['linkId' => '/' . $block['code']['code']] + self::definition($block['code']['code']) + ['code' => [self::loincCoding($block['code']['code'], $language)]];
        if (preg_match('/^\s*(\d+[.)])\s+(.*)$/su', $text, $m) === 1) {
            $item['prefix'] = $m[1];
            $text = $m[2];
        }
        $item += ['text' => $text, 'type' => 'choice', 'repeats' => ($block['single'] ?? false) !== true, 'answerOption' => []];
        foreach ($block['answer_codes'] as $a) {
            $item['answerOption'][] = ['valueCoding' => match ($a['system']) {
                Loinc::SYSTEM => self::loincCoding($a['code'], $language),
                // SNOMED CT's International Edition: the documents carry only its codes, under the Global
                // Patient Set's licence (US Extension codes can't be shipped worldwide; Conformance holds
                // any document that has one).
                self::SNOMED => ['system' => self::SNOMED, 'version' => self::SNOMED_INTERNATIONAL_EDITION, 'code' => $a['code'], 'display' => $a['display']],
                default => ['system' => $a['system'], 'code' => $a['code'], 'display' => $a['display']],
            }];
        }
        return $item;
    }

    /** @return array<string, mixed> FHIR's item control extension, for the code (table, gtable) */
    private static function itemControl(string $code): array
    {
        return ['url' => self::ITEM_CONTROL, 'valueCodeableConcept' => ['coding' => [
            ['system' => 'http://hl7.org/fhir/questionnaire-item-control', 'code' => $code, 'display' => $code === 'gtable' ? 'Group Table' : 'Vertical Answer Table'],
        ]]];
    }

    /**
     * A row asked of one sex only: shown when the patient's gender says so, and whenever it is unknown
     * (no patient passed, or none recorded), so a form filled without a patient asks every row, as today.
     *
     * @return array<string, mixed>
     */
    private static function askedOf(string $sex): array
    {
        $other = $sex === 'male' ? 'female' : 'male';
        return ['url' => self::ENABLE_WHEN_EXPRESSION, 'valueExpression' => ['language' => 'text/fhirpath',
            'expression' => "iif(%patient.gender.exists(), %patient.gender != '$other', true)"]];
    }

    /** Whether a signer is the patient, or someone signing for them: the signature the screen asks for. */
    private static function patientSigns(string $signer): bool
    {
        return preg_match('/^(Patient|Parent or guardian|I received a copy)/', $signer) === 1;
    }

    /** @return array<string, mixed> signatureRequired: a consent's signature on a consent, else the author's */
    private static function signatureRequired(Document $doc): array
    {
        [$code, $display] = str_contains($doc->id, 'consent')
            ? ['1.2.840.10065.1.12.1.7', 'Consent Signature']
            : ['1.2.840.10065.1.12.1.1', "Author's Signature"];
        return ['url' => self::SIGNATURE_REQUIRED, 'valueCodeableConcept' => ['coding' => [
            ['system' => self::SIGNATURE_TYPE, 'code' => $code, 'display' => $display],
        ]]];
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
