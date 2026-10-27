<?php

/**
 * Answers a document can carry from what the clinic already has: a question ("fields" field, or a
 * single-answer checklist) that says "fill": "chart.email" prints the patient's email from the
 * chart, and one that says "fill": "clinic.coordinator" prints the clinic's Section 1557
 * coordinator from settings.json.
 *
 *   {"label": "Email", "fill": "chart.email"}
 *   {"type": "checklist", "single": true, "items": ["Yes", "No"], "fill": "chart.veteran", "fill_options": ["veteran", "not_veteran"]}
 *
 * A carried chart answer is printed for the patient to check, never silently reused: the document
 * says at the top that some answers are filled in from what's on file and asks for corrections
 * beside them. A checklist ticks the item whose "fill_options" entry is the option id stored in
 * the chart. Nothing is written back to the chart: staff correct Demographics themselves.
 *
 * Like skip logic and codes, "fill" isn't wording: it changes no version, and a translation copies
 * it. What never takes a fill (DocumentLibrary refuses it): a validated instrument's question, since
 * an older answer reported as current would invalidate the score; and, in the documents a patient
 * signs for confidential care (Title X, Part 2, a minor's own consent), the facts that would carry
 * a family's details into them (guardian, insurance).
 *
 * On screen, the chart facts a FHIR Patient resource carries become SDC initialExpressions on the
 * patient (%patient), for a host that passes one, such as a SMART app; OpenEMR's own forms pass
 * none, so the question is asked as before.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Fill
{
    /**
     * Where each chart fact comes from: the Demographics field(s) it is read from, through the site's
     * own layout (so its list titles and labels), in order of preference.
     */
    public const CHART_FIELDS = [
        'email' => ['email'],
        'mobile' => ['phone_cell'],
        'language' => ['language'],
        'interpreter' => ['interpreter_needed', 'interpreter'],
        'race' => ['race'],
        'ethnicity' => ['ethnicity'],
        'emergency' => ['contact_relationship'],
        'emergencyphone' => ['phone_contact'],
        'pharmacy' => ['pharmacy_id'],
        'guardian' => ['guardiansname'],
        'guardianrelationship' => ['guardianrelationship'],
        'guardianphone' => ['guardianphone'],
        'pcp' => ['care_team_provider'],
        'householdsize' => ['family_size'],
        'monthlyincome' => ['monthly_income'],
        'housing' => ['uds_housing_status', 'homeless'],
        'veteran' => ['uds_veteran_status'],
        'agricultural' => ['uds_agricultural_worker', 'migrantseasonal'],
    ];

    /** The chart facts read from insurance_data (the primary plan in force), not Demographics. */
    public const INSURANCE = ['insurance', 'memberid'];

    /** The chart facts a checklist ticks: the UDS Report module's lists (uds_*), whose option ids the document names. */
    public const CODED = ['housing' => 'uds_housing_status', 'veteran' => 'uds_veteran_status', 'agricultural' => 'uds_agricultural_worker'];

    /** Facts that would carry a family's details into a confidential document: never filled there. */
    public const NOT_IN_CONFIDENTIAL = ['chart.guardian', 'chart.guardianrelationship', 'chart.guardianphone', 'chart.insurance', 'chart.memberid', 'chart.emergency', 'chart.emergencyphone'];

    /** Documents a patient signs for confidential care (42 CFR 59.10; 42 CFR 2.12-2.13; state minor-consent law). */
    public const CONFIDENTIAL = [
        'title-x-visit-record', 'title-x-confidential-services', 'part-2-consent', 'part-2-consent-revocation', 'part-2-notice',
        'minor-consent-ca', 'minor-consent-statement-tx',
    ];

    /**
     * What a FHIR Patient carries for a chart fact, as FHIRPath on %patient (US Core 6.1, as OpenEMR's
     * FhirPatientService builds it). Household size, income, housing and farm work aren't in it.
     */
    public const FHIRPATH = [
        'email' => "%patient.telecom.where(system = 'email').value.first()",
        'mobile' => "%patient.telecom.where(system = 'phone' and use = 'mobile').value.first()",
        'language' => "%patient.communication.language.select(text | coding.display).first()",
        'race' => "%patient.extension.where(url = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-race').extension.where(url = 'text').value.first()",
        'ethnicity' => "%patient.extension.where(url = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-ethnicity').extension.where(url = 'text').value.first()",
    ];

    /** The placeholder a question carries, or null: "chart.email", "clinic.coordinator". */
    public static function of(array $block): ?string
    {
        return isset($block['fill']) && is_string($block['fill']) ? $block['fill'] : null;
    }

    /** Whether the fill is the patient's chart (printed to check and correct), not the clinic's own. */
    public static function isChart(string $fill): bool
    {
        return str_starts_with($fill, 'chart.');
    }

    /**
     * A field's value as text to fill: its own "value", or its fill as a placeholder, or null.
     *
     * @param array{label?: string, value?: string, fill?: string} $field
     */
    public static function fieldValue(array $field): ?string
    {
        if (isset($field['value'])) {
            return $field['value'];
        }
        $fill = self::of($field);
        return $fill === null ? null : '{' . $fill . '}';
    }

    /** The index of the checklist item the chart's stored option ticks, or null. */
    public static function tickedItem(array $block, Context $context): ?int
    {
        $fill = self::of($block);
        if ($fill === null || !isset($block['fill_options']) || !is_array($block['fill_options'])) {
            return null;
        }
        $code = $context->code($fill);
        if ($code === '') {
            return null;
        }
        $i = array_search($code, $block['fill_options'], true);
        return is_int($i) ? $i : null;
    }

    /** Whether any chart answer is carried into this document for this patient: the page then asks for corrections. */
    public static function carries(Document $doc, Context $context): bool
    {
        foreach ($doc->blocks as $block) {
            $fill = self::of($block);
            if ($fill !== null && self::isChart($fill) && self::tickedItem($block, $context) !== null) {
                return true;
            }
            foreach ($block['type'] === 'fields' ? $block['fields'] : [] as $field) {
                $f = is_array($field) ? self::of($field) : null;
                if ($f !== null && self::isChart($f) && $context->value($f) !== '') {
                    return true;
                }
            }
        }
        return false;
    }
}
